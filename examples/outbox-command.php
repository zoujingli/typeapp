<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\ModelException;
use Type\Orm\AfterCommitException;
use Type\Orm\TransactionOutcome;
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
use TypeApp\OutboxExample\Business;

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
    \Type\Runtime\CoroutineRuntime::enableIo();
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
    $forward = in_array($mode, ['setup', 'relay', 'consume', 'replay', 'consume-replay', 'consume-unknown', 'prepare-concurrent', 'concurrent-relay', 'consume-early', 'verify-concurrent'], true)
        || getenv('TYPE_OUTBOX_DEPLOYMENT_CHECK') === '1' && in_array($mode, ['setup', 'relay', 'consume-replay'], true);
    $store = new Store('type_outbox', $forward || $mode === 'tokens' ? 5000 : 100, $forward ? 60 : 1);
    $database = new DatabaseManager(['default' => $driver, 'outbox' => $driver], 2, 0);
    Db::configure($database);
    $scope = new ExecutionScope();
    try {
        $scope->run(static function (ExecutionScope $scope) use ($driver, $mode, $store, $database): void {
            if ($mode === 'setup' || $mode === 'setup-schema') {
                (new Migrator($driver))->run([$store->migration($driver->name(), '2026090901'),
                    new Migration('2026090902', '演练业务与消费效果', ['CREATE TABLE type_outbox_business (id VARCHAR(128) PRIMARY KEY, value VARCHAR(255))',
                        'CREATE TABLE type_outbox_effects (id VARCHAR(128) PRIMARY KEY, value VARCHAR(255))'], $driver->name() !== 'mysql')]);
                if ($mode === 'setup-schema') {
                    return;
                }
                foreach (['outbox', 'missing', 'invalid:reader'] as $source) {
                    $rejected = false;
                    try {
                        $store->enqueue('outside', 'delivered', 1, [], database: $source);
                    } catch (ModelException) {
                        $rejected = true;
                    }
                    outboxExpect($rejected && $database->statistics()['active'] === [], '无活动事务的Outbox访问意外借用连接');
                }
                $connection = $database->connect($scope, 'outbox');
                try {
                    Db::transaction(static function () use ($store): void {
                        (new Business(['id' => 'rolled-back', 'value' => '不能发布']))->save();
                        $store->enqueue('rolled-back', 'delivered', 1, ['value' => '不能发布'], database: 'outbox');
                        throw new RuntimeException('回滚业务');
                    }, 'outbox');
                } catch (RuntimeException $error) {
                    outboxExpect($error->getMessage() === '回滚业务', '回滚异常错误');
                }
                outboxExpect($store->status($connection, 'rolled-back') === null && $connection->table('type_outbox_business')->first() === null, '业务回滚仍留下 Outbox 意图');
                $committed = false;
                try {
                    Db::transaction(static function () use ($store): void {
                        $cross = false;
                        try {
                            $store->enqueue('cross-source', 'delivered', 1, [], database: 'default');
                        } catch (ModelException $error) {
                            $cross = $error->errorCode() === 'cross_database_transaction';
                        }
                        outboxExpect($cross, '活动命名事务允许Outbox串入默认源');
                        (new Business(['id' => 'stable-business', 'value' => '已提交']))->save();
                        outboxExpect($store->enqueue('stable-business', 'delivered', 1, ['value' => '已提交'], ['request_id' => 'request-1'], 'outbox'), '首次意图没有写入');
                        outboxExpect(!$store->enqueue('stable-business', 'delivered', 1, ['value' => '已提交'], ['request_id' => 'request-1'], 'outbox'), '同内容意图没有幂等返回');
                        $conflict = false;
                        try {
                            $store->enqueue('stable-business', 'delivered', 1, ['value' => '冲突'], database: 'outbox');
                        } catch (\Type\Orm\DatabaseException) {
                            $conflict = true;
                        }
                        outboxExpect($conflict, '冲突内容覆盖了原消息意图');
                        Db::afterCommit(static function (): void {
                            throw new RuntimeException('受控提交后故障');
                        }, 'outbox');
                    }, 'outbox');
                } catch (AfterCommitException $error) {
                    $committed = $error->outcome() === TransactionOutcome::COMMITTED;
                }
                outboxExpect($committed && $store->status($connection, 'stable-business') !== null, '回调失败被误报回滚或丢失持久意图');
                echo "业务与消息意图同事务提交通过。\n";
                return;
            }
            if ($mode === 'unknown') {
                $calls = 0;
                $outcome = '';
                try {
                    Db::transaction(static function () use ($store, &$calls): void {
                        $calls++;
                        (new Business(['id' => 'stable-business', 'value' => '未知提交']))->save();
                        $store->enqueue('stable-business', 'delivered', 1, ['value' => '未知提交'], database: 'outbox');
                    }, 'outbox');
                } catch (\Type\Orm\TransactionException $error) {
                    $outcome = $error->outcome();
                }
                outboxExpect($outcome === 'UNKNOWN' && $calls === 1, '提交结果未知被误确认或自动重做业务');
                echo json_encode(['outcome' => $outcome, 'calls' => $calls], JSON_THROW_ON_ERROR) . "\n";
                return;
            }
            if ($mode === 'prepare-concurrent') {
                Db::transaction(static function () use ($store): void {
                    (new Business(['id' => 'concurrent-business', 'value' => '先消费后登记']))->save();
                    $store->enqueue('concurrent-business', 'delivered', 1, ['value' => '先消费后登记'], database: 'outbox');
                }, 'outbox');
                echo "并发消息意图已提交。\n";
                return;
            }
            if ($mode === 'verify-concurrent') {
                $connection = $database->connect($scope, 'outbox');
                $record = $store->status($connection, 'concurrent-business');
                outboxExpect(
                    $record['state'] === 'published' && $record['consumed_receipt'] !== null && $record['accepted_receipt'] !== null,
                    '后登记的接受凭据覆盖或丢失了先到消费凭据'
                );
                echo "接受与消费凭据按真实先后独立保留。\n";
                return;
            }
            if ($mode === 'collect' || $mode === 'tokens') {
                $connection = $database->connect($scope, 'outbox');
                if ($mode === 'collect') {
                    outboxExpect($store->collect($connection) === 1 && $store->status($connection, 'stable-business') === null, '超过重放窗口未回收已消费记录');
                    echo "已消费消息保留期回收通过。\n";
                    return;
                }
                Db::transaction(static fn (): bool => $store->enqueue('unconsumed', 'delivered', 1, ['value' => '保留对账'], database: 'outbox'), 'outbox');
                $old = $store->claim($connection, 1)[0];
                $foreign = new DatabaseManager(['other' => Drivers::create($driver->name(), (string) getenv('TYPE_OUTBOX_OTHER_DATABASE'))]);
                try {
                    $rejected = false;
                    try {
                        $store->accepted($foreign->connect($scope, 'other'), $old, 'wrong-source');
                    } catch (\Type\Orm\DatabaseException $error) {
                        $rejected = str_contains($error->getMessage(), '原领取数据源');
                    }
                    outboxExpect($rejected, '另一真实数据库接受了原来源的领取凭据');
                } finally {
                    $foreign->close();
                }
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
            if ($mode === 'lifecycle') {
                foreach (['exception', 'timeout', 'cleanup', 'stop'] as $fault) {
                    $id = 'fault-' . $fault;
                    $preparation = new ExecutionScope();
                    try {
                        $preparation->run(static fn (ExecutionScope $scope): bool => Db::transaction(static fn (): bool => $store->enqueue($id, 'delivered', 1, [], database: 'outbox'), 'outbox'));
                    } finally {
                        $preparation->close();
                    }
                    $publisher = new \TypeApp\OutboxExample\FaultPublisher($fault);
                    $relay = new Relay($database, $store, $publisher, 'outbox', $fault === 'timeout' ? 100 : 1000);
                    $publisher->bind($relay);
                    $failure = '';
                    try {
                        $relay->runOnce(1);
                    } catch (Throwable $error) {
                        $failure = $error->getMessage();
                    }
                    outboxExpect($failure !== '' && !$relay->ready() && $relay->runOnce(1) === 0, 'Relay故障后继续接单或误报成功');
                    if ($fault === 'exception' || $fault === 'cleanup') {
                        outboxExpect(str_contains($failure, $fault === 'exception' ? '受控Outbox发布失败' : '受控Outbox收尾失败'), 'Relay吞掉了原始故障');
                    } else {
                        outboxExpect($failure !== '执行预算没有生效', 'Relay预算没有限制当前发布作用域');
                    }
                    outboxExpect($relay->statistics()['in_flight'] === ($fault === 'cleanup' ? 1 : 0), 'Relay未保持真实清理所有权');
                    $publisher->recover();
                    outboxExpect($relay->statistics()['in_flight'] === 0, '资源恢复后Relay仍持有在途额度');
                    foreach ($database->statistics()['active'] as $statistics) {
                        outboxExpect($statistics['leased'] === 0, 'Relay故障遗留数据库租约');
                    }
                    $connection = $database->connect($scope, 'outbox');
                    $record = $store->status($connection, $id);
                    outboxExpect($record['state'] === 'claimed' && $record['accepted_receipt'] === null, 'Relay故障被误标成已接受');
                    // 每个故障使用独立意图；仅回收该轮测试数据，不改变生产恢复协议。
                    $connection->table('type_outbox')->where('id', '=', $id)->delete();
                    $connection->close();
                }
                echo "Relay异常、超时、停止与清理所有权通过。\n";
                return;
            }
            // 仅发布/消费角色需要 Redis；help/setup/collect/tokens 不得构造连接管理器。
            $manager = new RedisManager(['default' => new RedisConfiguration((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379))]);
            try {
                $queue = new Queue($manager->connection($scope, 'default', Purpose::SCRIPT), (string) getenv('TYPE_OUTBOX_APPLICATION'));
                if ($mode === 'crash' || $mode === 'relay' || $mode === 'replay' || $mode === 'concurrent-relay') {
                    if ($mode === 'replay') {
                        $connection = $database->connect($scope, 'outbox');
                        outboxExpect($store->replay($connection, 'stable-business', '验证人工对账重放'), '重放窗口内不能重放');
                        $connection->close();
                    }
                    $relay = new Relay($database, $store, new QueuePublisher($queue, $database, $mode === 'crash', $mode === 'concurrent-relay' ? $manager->connection($scope) : null, (string) getenv('TYPE_OUTBOX_APPLICATION')), 'outbox');
                    $count = $relay->runOnce(10);
                    outboxExpect($count === 1, 'relay 没有发布保留意图');
                    $relay->stop();
                    outboxExpect(!$relay->ready() && $relay->runOnce(10) === 0 && $relay->statistics()['in_flight'] === 0, '停止的Relay继续领取或没有归还资源');
                    echo "消息发布及 token 标记通过。\n";
                    return;
                }
                $connection = $database->connect($scope, 'outbox');
                if ($mode === 'consume' || $mode === 'consume-replay' || $mode === 'consume-early' || $mode === 'consume-unknown') {
                    $registry = new Registry();
                    $registry->register('delivered', 1, static fn (JobContext $context): Delivered => new Delivered($store));
                    $worker = new Worker($queue, $registry, 'outbox-worker');
                    $processed = 0;
                    while ($worker->runOnce()) {
                        if (++$processed > 10) {
                            throw new RuntimeException('消费数量异常');
                        }
                    }
                    if ($mode === 'consume-early') {
                        $early = $store->status($connection, 'concurrent-business');
                        outboxExpect($processed === 1 && $early['state'] === 'claimed' && $early['accepted_receipt'] === null
                            && $early['consumed_receipt'] === 'receipt:concurrent-business', '消费被Outbox接受凭据阻塞或提前假报发布完成');
                        $manager->connection($scope)->command('SET', [(string) getenv('TYPE_OUTBOX_APPLICATION') . ':consumed', 'yes', 'EX', 30]);
                        echo "实际消费先于Outbox接受登记完成。\n";
                        return;
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
        });
        outboxExpect(!isset($database->statistics()['active']['default']), 'Outbox 便捷入口或Relay错误借用了默认来源');
    } finally {
        $scope->close();
        $database->close();
    }
}
