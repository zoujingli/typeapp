<?php

declare(strict_types=1);

use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisConnection;
use Type\Redis\RedisException;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function redisExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 通过真实 Stream 核对阻塞用途边界；参数换序不能先消费消息或提交批次中的其他写入。
 *
 * GROUP 的组名/消费者名、STREAMS 之后的键均是数据，允许与选项名称相同。
 * 所有键只属于本轮演练，成功或失败均清理；连接仍由调用者的作用域持有。
 */
function redisStreamChecks(RedisConnection $command, RedisConnection $blocking, RedisConnection $pipeline, RedisConnection $transaction, string $prefix): void
{
    $stream = $prefix . 'stream';
    $marker = $prefix . 'stream-write';
    try {
        $command->command('XADD', [$stream, '1-0', 'value', '首条']);
        $command->command('XGROUP', ['CREATE', $stream, 'group', '0']);
        $variants = [
            ['BLOCK', 1, 'GROUP', 'group', 'reader', 'STREAMS', $stream, '>'],
            ['COUNT', 1, 'BLOCK', 1, 'GROUP', 'group', 'reader', 'STREAMS', $stream, '>'],
            ['GROUP', 'group', 'reader', 'COUNT', 1, 'BLOCK', 1, 'STREAMS', $stream, '>'],
        ];
        foreach ($variants as $arguments) {
            foreach (['command', 'pipeline', 'transaction'] as $purpose) {
                $rejected = false;
                try {
                    if ($purpose === 'command') {
                        $command->command('XREADGROUP', $arguments);
                    } elseif ($purpose === 'pipeline') {
                        $pipeline->pipeline([['SET', [$marker, '不能写入']], ['XREADGROUP', $arguments]]);
                    } else {
                        $transaction->transaction([$marker], static fn (RedisConnection $connection): array =>
                            [['SET', [$marker, '不能写入']], ['XREADGROUP', $arguments]]);
                    }
                } catch (RedisException $error) {
                    $rejected = $error->errorCode() === 'blocking_command' && $error->outcome() === 'NOT_STARTED';
                }
                redisExpect($rejected, 'XREADGROUP 参数换序绕过用途隔离：' . $purpose);
                redisExpect($command->command('EXISTS', [$marker]) === 0
                    && $command->command('XPENDING', [$stream, 'group'])[0] === 0, '被拒绝的批次产生写入或消费消息');
            }
        }
        $received = $blocking->blocking('XREADGROUP', $variants[0]);
        redisExpect(is_array($received) && $received[0][1][0][0] === '1-0', '专用阻塞入口不接受合法换序参数');
        foreach ([['BLOCK', 'STREAMS'], ['STREAMS', 'BLOCK']] as $names) {
            $command->command('XGROUP', ['CREATE', $stream, $names[0], '0']);
            $ordinary = $command->command('XREADGROUP', ['COUNT', 1, 'GROUP', $names[0], $names[1], 'STREAMS', $stream, '>']);
            redisExpect(is_array($ordinary) && $ordinary[0][1][0][0] === '1-0', '组名或消费者名被误判为阻塞选项');
        }
        // 非分组选项读取同样只在 STREAMS 之前识别 BLOCK。
        $read = $command->command('XREAD', ['COUNT', 1, 'STREAMS', $stream, '0']);
        redisExpect(is_array($read) && $read[0][1][0][0] === '1-0', '非阻塞 XREAD 不能读取消息');
    } finally {
        $command->command('DEL', [$stream, $marker]);
    }
}

/**
 * 使用受控 Redis 验证命名用途、事务、pipeline、超时和失效连接恢复。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === '--help') {
        echo "Redis 验证命令：使用 TYPE_REDIS_HOST 与 TYPE_REDIS_PORT 选择受控测试服务。\n";
        return;
    }
    $host = (string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('TYPE_REDIS_PORT') ?: 6379);
    $redis = new RedisManager([
        'default' => new RedisConfiguration($host, $port),
        'other' => new RedisConfiguration($host, $port, 1),
        'timeout' => new RedisConfiguration($host, $port, 0, null, null, 1.0, 0.05),
    ], [Purpose::COMMAND => 1]);
    $prefix = 'type_redis_test_' . bin2hex(random_bytes(8)) . ':';
    $scope = new ExecutionScope();
    try {
        $command = $redis->connection($scope);
        $id = $command->identity();
        $command->command('SET', [$prefix . 'value', '普通值']);
        redisExpect($command->command('GET', [$prefix . 'value']) === '普通值', 'Redis 普通命令往返失败');
        $full = false;
        try {
            $redis->connection($scope, 'default', Purpose::COMMAND, 0);
        } catch (\Type\Runtime\CapacityException $error) {
            $full = true;
        }
        redisExpect($full, '普通用途池容量没有生效');
        $other = $redis->connection($scope, 'other');
        redisExpect($other->command('GET', [$prefix . 'value']) === false, '命名数据库发生串用');
        $other->command('SET', [$prefix . 'value', '第二个数据库']);
        redisExpect($command->command('GET', [$prefix . 'value']) === '普通值', '另一个数据库覆盖当前命名连接');
        $blocking = $redis->connection($scope, 'default', Purpose::BLOCKING);
        redisExpect($blocking->identity() !== $id, '阻塞用途复用了普通连接');
        foreach ([['SELECT', [1]], ['BLPOP', [$prefix . 'list', 1]], ['EVAL', ['return 1', 0]]] as [$name, $arguments]) {
            $rejected = false;
            try {
                $command->command($name, $arguments);
            } catch (RedisException $error) {
                $rejected = $error->outcome() === 'NOT_STARTED';
            }
            redisExpect($rejected, '不受管命令没有拒绝');
        }
        $pipeline = $redis->connection($scope, 'default', Purpose::PIPELINE);
        $transaction = $redis->connection($scope, 'default', Purpose::TRANSACTION);
        redisStreamChecks($command, $blocking, $pipeline, $transaction, $prefix);
        $replies = $pipeline->pipeline([['SET', [$prefix . 'number', '1']], ['INCR', [$prefix . 'number']], ['GET', [$prefix . 'number']]]);
        redisExpect(count($replies) === 3 && $replies[1] === 2 && $replies[2] === '2', 'pipeline 顺序或结果错误');
        $partial = false;
        try {
            $pipeline->pipeline([['SET', [$prefix . 'partial', '先写入']], ['LPUSH', [$prefix . 'partial', '类型冲突']]]);
        } catch (RedisException $error) {
            $partial = in_array($error->outcome(), ['MAY_HAVE_APPLIED', 'UNKNOWN'], true);
        }
        redisExpect($partial && $command->command('GET', [$prefix . 'partial']) === '先写入', 'pipeline 错误被伪装为全部回滚');
        $pipeline->close();
        $calls = 0;
        $conflict = $transaction->transaction([$prefix . 'number'], static function (RedisConnection $connection) use ($command, $prefix, &$calls): array {
            $calls++;
            redisExpect($connection->command('GET', [$prefix . 'number']) === '2', 'WATCH 读取状态错误');
            $command->command('INCR', [$prefix . 'number']);
            return [['SET', [$prefix . 'number', '不能生效']]];
        });
        redisExpect(!$conflict->committed() && $calls === 1 && $command->command('GET', [$prefix . 'number']) === '3', 'WATCH 冲突没有保持明确结果');
        $committed = $transaction->transaction([$prefix . 'number'], static fn (RedisConnection $connection): array =>
            [['SET', [$prefix . 'number', '4']], ['GET', [$prefix . 'number']]]);
        redisExpect($committed->committed() && $committed->replies()[1] === '4', 'MULTI/EXEC 没有提交');
        try {
            $transaction->transaction([$prefix . 'number'], static function (RedisConnection $connection): array {
                throw new RuntimeException('中断 WATCH');
            });
        } catch (RuntimeException $error) {
            redisExpect($error->getMessage() === '中断 WATCH', '原事务回调异常丢失');
        }
        $command->command('INCR', [$prefix . 'number']);
        $result = $transaction->transaction([], static fn (RedisConnection $connection): array => [['GET', [$prefix . 'number']]]);
        redisExpect($result->committed() && $result->replies()[0] === '5', 'WATCH 状态污染后续执行');
        $script = $redis->connection($scope, 'default', Purpose::SCRIPT);
        redisExpect($script->script("return redis.call('INCR', KEYS[1])", [$prefix . 'number']) === 6, '原生脚本入口失败');
        $partial = false;
        try {
            $script->script("redis.call('SET', KEYS[1], '脚本已写入'); return redis.call('LPUSH', KEYS[1], '错误')", [$prefix . 'script-partial']);
        } catch (RedisException $error) {
            $partial = in_array($error->outcome(), ['MAY_HAVE_APPLIED', 'UNKNOWN'], true);
        }
        redisExpect($partial && $command->command('GET', [$prefix . 'script-partial']) === '脚本已写入', '脚本错误没有保留可能已写入的语义');
        $timeout = $redis->connection($scope, 'timeout', Purpose::BLOCKING);
        $oldId = $timeout->identity();
        $started = microtime(true);
        $unknown = false;
        try {
            $timeout->blocking('BLPOP', [$prefix . 'wait', 1]);
        } catch (RedisException $error) {
            $unknown = $error->outcome() === 'UNKNOWN';
        }
        redisExpect($unknown && microtime(true) - $started < 0.8, '超时被自动重试或没有报告未知结果');
        $timeout->close();
        $recovered = $redis->connection($scope, 'timeout', Purpose::BLOCKING);
        redisExpect($recovered->identity() !== $oldId, '失败连接被重新复用');
        $command->command('LPUSH', [$prefix . 'wait', '恢复值']);
        $value = $recovered->blocking('BLPOP', [$prefix . 'wait', 1]);
        redisExpect(is_array($value) && $value[1] === '恢复值', '超时后不能恢复阻塞读取');
        $command->command('DEL', [$prefix . 'value', $prefix . 'number', $prefix . 'wait', $prefix . 'partial', $prefix . 'script-partial']);
        $other->command('DEL', [$prefix . 'value']);
        $command->close();
        $borrowed = $redis->connection($scope);
        redisExpect($borrowed->identity() === $id, '正常归还没有复用干净会话');
        redisExpect($borrowed->command('GET', [$prefix . 'value']) === false, '重复调用污染已清理数据');
        echo "Redis 命名用途、租约、pipeline、事务、超时与恢复通过。\n";
    } finally {
        $scope->close();
        $redis->close();
    }
    foreach ($redis->statistics() as $statistics) {
        redisExpect($statistics['created'] === 0, 'Redis 会话未清理');
    }
}
