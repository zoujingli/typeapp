<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\Migrator;
use Type\Orm\Outbox\Relay;
use Type\Orm\Outbox\Store;
use Type\Queue\JobContext;
use Type\Queue\Queue;
use Type\Queue\Registry;
use Type\Queue\Worker;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use TypeApp\ModelExample\Drivers;
use TypeApp\OutboxExample\Delivered;
use TypeApp\OutboxExample\QueuePublisher;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function outboxExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 在 Swoole 协程内运行显式 Outbox 角色，每轮有界处理并收尾资源。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argc, $argv): void {
        outboxScenario($argc, $argv);
    });
}

/**
 * 按角色选择事务意图、发布、消费、重放或回收，故障阶段不伪造提交结果。
 *
 * @param list<string> $argv 程序路径、驱动和明确角色。
 */
function outboxScenario(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === 'help' || ($argv[2] ?? '') === 'help') {
        echo "Outbox 独立角色：<mysql|pgsql|sqlite> setup|relay|consume|replay|consume-replay|collect|tokens；每轮有界执行并关闭资源。\n";
        return;
    }
    $driver = Drivers::create((string) ($argv[1] ?? 'sqlite'));
    $mode = (string) ($argv[2] ?? 'setup');
    // 正向领取需要容纳 I/O 与调度延迟；tokens 单独构造旧租约过期，不能让新领取也只有 100ms。
    $forward = in_array($mode, ['setup', 'relay', 'consume', 'replay', 'consume-replay'], true)
        || getenv('TYPE_OUTBOX_DEPLOYMENT_CHECK') === '1' && in_array($mode, ['setup', 'relay', 'consume-replay'], true);
    $store = new Store('type_outbox', $forward || $mode === 'tokens' ? 5000 : 100, $forward ? 60 : 1);
    $database = new Database($driver, 2, 0);
    $scope = new ExecutionScope();
    try {
        if ($mode === 'setup') {
            (new Migrator($driver))->run([$store->migration($driver->name(), '2026090901'),
                new Migration('2026090902', '演练业务与消费效果', ['CREATE TABLE type_outbox_business (id VARCHAR(128) PRIMARY KEY, value VARCHAR(255))',
                    'CREATE TABLE type_outbox_effects (id VARCHAR(128) PRIMARY KEY, value VARCHAR(255))'], $driver->name() !== 'mysql')]);
            $connection = $database->connect($scope);
            try {
                $connection->transaction(static function (Connection $transaction) use ($store): void {
                    $transaction->table('type_outbox_business')->insert(['id' => 'rolled-back', 'value' => '不能发布']);
                    $store->enqueue($transaction, 'rolled-back', 'delivered', 1, ['value' => '不能发布']);
                    throw new RuntimeException('回滚业务');
                });
            } catch (RuntimeException $error) {
                outboxExpect($error->getMessage() === '回滚业务', '回滚异常错误');
            }
            outboxExpect($store->status($connection, 'rolled-back') === null && $connection->table('type_outbox_business')->first() === null, '业务回滚仍留下 Outbox 意图');
            $connection->transaction(static function (Connection $transaction) use ($store): void {
                $transaction->table('type_outbox_business')->insert(['id' => 'stable-business', 'value' => '已提交']);
                $store->enqueue($transaction, 'stable-business', 'delivered', 1, ['value' => '已提交'], ['request_id' => 'request-1']);
            });
            echo "业务与消息意图同事务提交通过。\n";
            return;
        }
        if ($mode === 'collect' || $mode === 'tokens') {
            $connection = $database->connect($scope);
            if ($mode === 'collect') {
                outboxExpect($store->collect($connection) === 1 && $store->status($connection, 'stable-business') === null, '超过重放窗口未回收已消费记录');
                echo "已消费消息保留期回收通过。\n";
                return;
            }
            $connection->transaction(static fn (Connection $transaction): bool => $store->enqueue($transaction, 'unconsumed', 'delivered', 1, ['value' => '保留对账']));
            $old = $store->claim($connection, 1)[0];
            // 仅修改本轮专用数据库的故障夹具，按旧 token 精确构造过期，不依赖调度等待。
            outboxExpect($connection->table('type_outbox')->where('id', '=', $old->id())->where('token', '=', $old->token())
                ->update(['lease_until' => 0]) === 1, '无法构造旧领取过期');
            outboxExpect(!$store->accepted($connection, $old, 'expired-receipt'), '已过期领取仍能登记发布凭据');
            $new = $store->claim($connection, 1)[0];
            outboxExpect($old->id() === $new->id() && $old->token() !== $new->token(), '过期重领未更换同一消息的 token');
            // 覆盖超过旧 100ms 测试窗口的暂停；新租约仍须真实有效，不能跳过期限检查。
            usleep(150000);
            outboxExpect(!$store->accepted($connection, $old, 'old-receipt'), '旧 relay token 仍能覆盖新领取');
            outboxExpect($store->accepted($connection, $new, 'new-receipt'), '新领取未能在有效租约内登记发布凭据');
            usleep(1100000);
            outboxExpect($store->collect($connection) === 0 && $store->status($connection, 'unconsumed') !== null, '没有消费凭据就删除了消息意图');
            echo "Outbox 旧 token 拒绝与未消费意图保留通过。\n";
            return;
        }
        // 仅发布/消费角色需要 Redis；help/setup/collect/tokens 不得构造连接管理器。
        $manager = new RedisManager(['default' => new RedisConfiguration((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379))]);
        try {
            $queue = new Queue($manager->connection($scope, 'default', Purpose::SCRIPT), (string) getenv('TYPE_OUTBOX_APPLICATION'));
            if ($mode === 'crash' || $mode === 'relay' || $mode === 'replay') {
                if ($mode === 'replay') {
                    $connection = $database->connect($scope);
                    outboxExpect($store->replay($connection, 'stable-business', '验证人工对账重放'), '重放窗口内不能重放');
                    $connection->close();
                }
                $count = (new Relay($database, $store, new QueuePublisher($queue, $mode === 'crash')))->runOnce(10);
                outboxExpect($count === 1, 'relay 没有发布保留意图');
                echo "消息发布及 token 标记通过。\n";
                return;
            }
            $connection = $database->connect($scope);
            if ($mode === 'consume' || $mode === 'consume-replay') {
                $registry = new Registry();
                $registry->register('delivered', 1, static fn (JobContext $context): Delivered => new Delivered($driver, $store));
                $worker = new Worker($queue, $registry, 'outbox-worker');
                $processed = 0;
                while ($worker->runOnce()) {
                    if (++$processed > 10) {
                        throw new RuntimeException('消费数量异常');
                    }
                }
                outboxExpect($processed === ($mode === 'consume' ? 2 : 1) && (int) $connection->table('type_outbox_effects')->aggregate('COUNT') === 1, '重发消息产生重复效果');
                $record = $store->status($connection, 'stable-business');
                outboxExpect($record['state'] === 'published' && $record['consumed_receipt'] === 'receipt:stable-business' && $record['accepted_receipt'] !== null, '没有保留接受和消费凭据');
                outboxExpect($store->collect($connection) === 0, '保留窗口内删除了消息意图');
                echo "重复投递幂等消费与保留凭据通过。\n";
                return;
            }
            throw new RuntimeException('未知 Outbox 演练命令');
        } finally {
            $manager->close();
        }
    } finally {
        $scope->close();
        $database->close();
    }
}
