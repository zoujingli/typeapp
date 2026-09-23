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

function outboxExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argc, $argv): void {
        outboxScenario($argc, $argv);
    });
}

function outboxScenario(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === 'help' || ($argv[2] ?? '') === 'help') {
        echo "Outbox 独立角色：<mysql|pgsql|sqlite> setup|relay|consume|replay|consume-replay|collect|tokens；每轮有界执行并关闭资源。\n";
        return;
    }
    $driver = Drivers::create((string) ($argv[1] ?? 'sqlite'));
    $mode = (string) ($argv[2] ?? 'setup');
    // 正向角色需要容纳 Redis/调度延迟；crash/tokens/collect 故障演练仍用短租约与短保留期。
    $forward = in_array($mode, ['setup', 'relay', 'consume', 'replay', 'consume-replay'], true)
        || getenv('TYPE_OUTBOX_DEPLOYMENT_CHECK') === '1' && in_array($mode, ['setup', 'relay', 'consume-replay'], true);
    $store = new Store('type_outbox', $forward ? 5000 : 100, $forward ? 60 : 1);
    $database = new Database($driver, 2, 0);
    $scope = new ExecutionScope();
    $manager = new RedisManager(['default' => new RedisConfiguration((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379))]);
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
        if ($mode === 'collect') {
            outboxExpect($store->collect($connection) === 1 && $store->status($connection, 'stable-business') === null, '超过重放窗口未回收已消费记录');
            echo "已消费消息保留期回收通过。\n";
            return;
        }
        if ($mode === 'tokens') {
            $connection->transaction(static fn (Connection $transaction): bool => $store->enqueue($transaction, 'unconsumed', 'delivered', 1, ['value' => '保留对账']));
            $old = $store->claim($connection, 1)[0];
            usleep(150000);
            $new = $store->claim($connection, 1)[0];
            outboxExpect($old->id() === $new->id() && $old->token() !== $new->token()
                && !$store->accepted($connection, $old, 'old-receipt') && $store->accepted($connection, $new, 'new-receipt'), '旧 relay token 仍能覆盖新领取');
            usleep(1100000);
            outboxExpect($store->collect($connection) === 0 && $store->status($connection, 'unconsumed') !== null, '没有消费凭据就删除了消息意图');
            echo "Outbox 旧 token 拒绝与未消费意图保留通过。\n";
            return;
        }
        throw new RuntimeException('未知 Outbox 演练命令');
    } finally {
        $scope->close();
        $database->close();
        $manager->close();
    }
}
