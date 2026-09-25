<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';

use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisConnection;
use Type\Redis\RedisManager;
use Type\Redis\ScriptGuard;
use Type\Runtime\ExecutionScope;
use Type\Scheduler\LeaseException;
use Type\Scheduler\RedisLease;
use Type\Scheduler\RedisStateStore;
use Type\Scheduler\TaskContext;

/**
 * 要求租约操作抛出指定稳定原因的 LeaseException，其他结果均使断言失败。
 *
 * @param Closure(): mixed $operation
 */
function coordinationReject(Closure $operation, string $reason): void
{
    $rejected = false;
    try {
        $operation();
    } catch (LeaseException $error) {
        $rejected = $error->reason() === $reason;
    }
    expect($rejected, '租约操作未按预期拒绝：' . $reason);
}

/** 等待本轮测试键在 Redis 中实际到期；不把本机睡眠时长当作服务端租约状态。 */
function coordinationWaitExpired(RedisConnection $redis, string $key): void
{
    $deadline = hrtime(true) + 5000000000;
    do {
        $remaining = $redis->command('PTTL', [$key]);
        if ($remaining === -2) {
            return;
        }
        expect(is_int($remaining) && $remaining >= 0, '租约测试键缺少真实到期时间');
        usleep(10000);
    } while (hrtime(true) < $deadline);
    throw new RuntimeException('租约测试键未在有界等待内到期');
}

$manager = new RedisManager(['default' => new RedisConfiguration(
    getenv('TYPE_REDIS_HOST') ?: '127.0.0.1',
    (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
)], [Purpose::SCRIPT => 4]);
$scope = new ExecutionScope();
$application = 'type_coordination_' . bin2hex(random_bytes(8));
try {
    $first = $manager->connection($scope, 'default', Purpose::SCRIPT);
    $second = $manager->connection($scope, 'default', Purpose::SCRIPT);
    $ordinary = $manager->connection($scope);
    $oldStore = new RedisStateStore($first, $application, 'leases', 5000);
    $newStore = new RedisStateStore($second, $application, 'leases', 5000);
    $oldStore->acquire();
    $old = $oldStore->lease();
    $oldGeneration = $old->generation();
    expect($old instanceof RedisLease, 'Redis 存储没有提供实际租约');
    $lockKey = $oldStore->identity() . ':lock';
    // 只缩短本轮测试键，随后由真实续租接口恢复；无效续租不能靠宽松等待通过。
    expect($ordinary->command('PEXPIRE', [$lockKey, 1000]) === 1, '无法准备续租前的实际 TTL');
    $old->renew();
    $renewed = $ordinary->command('PTTL', [$lockKey]);
    expect(is_int($renewed) && $renewed > 1000 && $renewed <= 5000, '续租没有恢复实际 Redis TTL');
    coordinationReject(static fn () => $newStore->acquire(), 'busy');
    // 明确推进本轮故障夹具到过期状态；保留真实 Redis 到期与旧 token 拒绝路径。
    expect($ordinary->command('PEXPIRE', [$lockKey, 25]) === 1, '无法准备租约到期');
    coordinationWaitExpired($ordinary, $lockKey);
    $newStore->acquire();
    $new = $newStore->lease();
    expect((int) $new->generation() > (int) $oldGeneration, '租约替换没有单调增加代次');
    coordinationReject(static fn () => $old->renew(), 'lease_lost');
    coordinationReject(static fn () => $old->effect("return redis.call('SET',KEYS[1],'old')", [$application . ':effect']), 'lease_lost');
    coordinationReject(static fn () => $oldStore->save(['protocol' => 1, 'cursors' => [], 'records' => []]), 'lease_lost');
    coordinationReject(static fn () => $oldStore->release(), 'lease_lost');
    $new->assertOwned();
    $new->effect("return redis.call('SET',KEYS[1],'new')", [$application . ':effect']);
    expect($ordinary->command('GET', [$application . ':effect']) === 'new', '旧执行者删除或污染了当前租约的效果');

    $foreignQueue = new Queue($first, $application, 'guarded');
    $queue = new Queue($second, $application, 'guarded');
    $message = new Message('stable-occurrence', 'scheduled-report', 1, ['value' => 1]);
    coordinationReject(static fn () => $foreignQueue->publish($message, $new), 'target_mismatch');
    coordinationReject(static fn () => $foreignQueue->publish($message, $old), 'lease_lost');
    $new->renew();
    $taskScope = new ExecutionScope();
    $context = new TaskContext('reports.minute', 600, $taskScope, $new);
    $guard = $context->lease();
    expect($guard instanceof ScriptGuard, '调度上下文没有提供目标保护接口');
    $receipt = $queue->publish($message, $guard);
    expect($receipt !== '' && $queue->statistics()['messages'] === 1, '当前租约不能原子投递');
    $taskScope->close();
    $closed = false;
    try {
        $queue->publish($message, $guard);
    } catch (RuntimeException $error) {
        $closed = str_contains($error->getMessage(), '作用域');
    }
    expect($closed && $queue->statistics()['messages'] === 1, '旧任务作用域仍能借用同一调度租约投递');
    $reservation = $queue->reserve('probe');
    expect($reservation !== null && $reservation->message()->id() === 'stable-occurrence', '投递改变了稳定消息身份');
    $reservation->acknowledge();
    $newStore->release();
    $publisher = RedisLease::acquire($second, $application . ':publisher', 50);
    coordinationWaitExpired($ordinary, $application . ':publisher:lock');
    $replacement = RedisLease::acquire($second, $application . ':publisher', 5000);
    // 首次旧 token 操作直接进入投递保护脚本，验证真实 XADD 目标拒绝。
    coordinationReject(static fn () => $queue->publish($message, $publisher), 'lease_lost');
    expect($queue->statistics()['messages'] === 0, '已过期发布者的脚本仍加入了消息');
    coordinationReject(static fn () => $publisher->release(), 'lease_lost');
    $replacement->assertOwned();
    $replacement->release();

    $ordinary->command('DEL', [$newStore->identity() . ':state']);
    coordinationReject(static fn () => $newStore->acquire(), 'store');

    $fullLease = RedisLease::acquire($second, $application . ':capacity', 5000);
    $fullQueue = new Queue($second, $application, 'capacity', 30000, 1);
    $fullQueue->publish($message, $fullLease);
    $refused = false;
    try {
        $fullQueue->publish(new Message('second', 'scheduled-report', 1, []), $fullLease);
    } catch (LeaseException $error) {
        $refused = $error->reason() === 'effect_unknown';
    }
    expect($refused && $ordinary->command('XLEN', [$fullQueue->identity() . ':stream']) === 1, '受保护投递吞掉容量拒绝或返回了虚假成功');
    try {
        $fullLease->release();
    } catch (LeaseException) {
    }

    // 真实断开已持有租约的客户端；续期不能假装成功或自动重试。
    $brokenStore = new RedisStateStore($first, $application, 'network', 5000);
    $brokenStore->acquire();
    $broken = $brokenStore->lease();
    $clientId = $first->identity();
    $admin = new Redis();
    expect($admin->connect(getenv('TYPE_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_REDIS_PORT') ?: 6379)), '无法建立故障注入客户端');
    $admin->rawCommand('CLIENT', 'KILL', 'ID', $clientId);
    coordinationReject(static fn () => $broken->renew(), 'lease_lost');
    coordinationReject(static fn () => $broken->effect("return redis.call('SET',KEYS[1],'invalid')", [$application . ':effect']), 'lease_lost');
    coordinationReject(static fn () => $brokenStore->release(), 'lease_lost');
    $admin->close();
    echo "Redis 调度租约验证通过：续期、过期代次、旧 token 拒绝、目标保护、任务作用域与真实连接故障。\n";
} finally {
    // 仅清理本次唯一应用命名空间内的受控测试键。
    if (isset($ordinary)) {
        $roots = [(new RedisStateStore($second, $application, 'leases'))->identity(), (new RedisStateStore($second, $application, 'network'))->identity()];
        foreach ($roots as $root) {
            $ordinary->command('DEL', [$root . ':lock', $root . ':generation', $root . ':state']);
        }
        if (isset($queue)) {
            $ordinary->command('DEL', [$queue->identity() . ':stream', $queue->identity() . ':leases']);
        }
        $ordinary->command('DEL', [$application . ':effect']);
        $ordinary->command('DEL', [$application . ':publisher:lock', $application . ':publisher:generation']);
        $ordinary->command('DEL', [$application . ':capacity:lock', $application . ':capacity:generation']);
        if (isset($fullQueue)) {
            $ordinary->command('DEL', [$fullQueue->identity() . ':stream']);
        }
    }
    $scope->close();
    $manager->close();
}
