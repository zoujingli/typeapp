<?php

declare(strict_types=1);

use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Connection;
use Type\Orm\AfterCommitException;
use Type\Orm\TransactionException;
use Type\Orm\TransactionOutcome;
use Type\Cache\JsonCodec;
use Type\Cache\NamespaceStore;
use Type\Cache\TypedCache;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use TypeApp\Operations\UserOperations;
use TypeApp\Operations\UserService;
use TypeApp\ModelExample\Drivers;

/**
 * 将生成服务组合入口的断言失败转为明确异常。
 *
 * @throws RuntimeException 断言不成立。
 */
function operationsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 故障代理先由控制器准备持久表；只观察生成入口、缓存值和真实业务次数。 */
function operationsUnknown(UserOperations $operations, TypedCache $cache): void
{
    operationsAssert($operations->cached($cache, 1) === 'before', '未知提交前缓存没有准备好');
    $outcome = '';
    try {
        $operations->rename($cache, 1, 'after');
    } catch (TransactionException $failure) {
        $outcome = $failure->outcome();
    }
    operationsAssert($outcome === TransactionOutcome::UNKNOWN && $operations->changes() === 1, '生成服务错误确认或重试了未知提交');
    $cached = $operations->cached($cache, 1);
    operationsAssert($cached === 'before', '提交未知时执行了缓存失效回调');
    echo json_encode(['outcome' => $outcome, 'cached' => $cached, 'calls' => $operations->changes()], JSON_THROW_ON_ERROR) . "\n";
}

/**
 * 在专属三库与 Redis 验证生成的事务、缓存与提交后失效操作。
 *
 * @param list<string> $argv 程序路径与数据库驱动参数。
 */
function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argv): void {
        $database = new DatabaseManager(['default' => Drivers::create((string) ($argv[1] ?? 'sqlite'))]);
        $redisManager = new RedisManager(['default' => new RedisConfiguration(
            (string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
        )], [Purpose::SCRIPT => 1]);
        Db::configure($database);
        $scope = new ExecutionScope();
        $cache = null;
        try {
            $scope->run(static function (ExecutionScope $current) use ($scope, $redisManager, $argv, &$cache): void {
                $connection = Db::connection('default', true);
                $service = new UserService();
                $operations = new UserOperations($service);
                $redis = $redisManager->connection($scope, 'default', Purpose::SCRIPT);
                $cacheApplication = 'operations-' . bin2hex(random_bytes(12));
                $cache = new TypedCache(new NamespaceStore($redis, $cacheApplication, 'test', 'v1'), JsonCodec::data());
                if (($argv[2] ?? '') === 'unknown') {
                    operationsUnknown($operations, $cache);
                    return;
                }
                $operations->initialize();
                $operations->create(1, '中文事务');
                operationsAssert($operations->read(1) === '中文事务', '事务成功后未读取到业务结果');
                $failed = false;
                try {
                    $operations->create(2, '应回滚', true);
                } catch (RuntimeException $error) {
                    $failed = $error->getMessage() === '预期业务失败';
                }
                operationsAssert($failed && $operations->read(2) === null, '事务异常没有回滚或改变原始异常');
                try {
                    $service->create(3, '普通调用', true);
                } catch (RuntimeException) {
                }
                operationsAssert($operations->read(3) === '普通调用', '原业务方法不应被隐式拦截');
                operationsAssert($operations->cached($cache, 1) === '中文事务'
                    && $operations->cached($cache, 1) === '中文事务' && $operations->loads() === 1, '生成操作没有复用缓存命中');
                operationsAssert($operations->cached($cache, 9) === null && $operations->cached($cache, 9) === null
                    && $operations->loads() === 2, '缓存 null 被当作未命中');
                $operations->shortLived($cache, 1);
                usleep(60000);
                $operations->shortLived($cache, 1);
                operationsAssert($operations->loads() === 4, '缓存过期没有重新回源');
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    $originFailed = false;
                    try {
                        $operations->failing($cache, 1);
                    } catch (RuntimeException $failure) {
                        $originFailed = $failure->getMessage() === '预期回源失败';
                    }
                    operationsAssert($originFailed, '回源异常被改变');
                }
                operationsAssert($operations->loads() === 6, '回源异常错误进入缓存');
                try {
                    $operations->rename($cache, 1, '失败修改', true);
                } catch (RuntimeException) {
                }
                operationsAssert($operations->cached($cache, 1) === '中文事务' && $operations->loads() === 6, '业务回滚时错误清理了缓存');
                $outerFailed = false;
                try {
                    Db::transaction(static function () use ($operations, $cache): void {
                        $operations->rename($cache, 1, '嵌套回滚');
                        operationsAssert($operations->cached($cache, 1) === '中文事务', '内层提交提前清理外层事务缓存');
                        throw new RuntimeException('预期外层回滚');
                    });
                } catch (RuntimeException $outerError) {
                    $outerFailed = $outerError->getMessage() === '预期外层回滚';
                }
                operationsAssert($outerFailed && $operations->read(1) === '中文事务'
                    && $operations->cached($cache, 1) === '中文事务' && $operations->loads() === 6, '外层回滚没有丢弃缓存失效回调');
                Db::transaction(static function () use ($operations, $cache): void {
                    operationsAssert($operations->rename($cache, 1, '提交后更新') === '提交后更新', '事务方法返回值错误');
                    operationsAssert($operations->cached($cache, 1) === '中文事务', '缓存未等待最外层提交');
                });
                operationsAssert($operations->cached($cache, 1) === '提交后更新' && $operations->loads() === 7, '最外层提交后未失效缓存');
                try {
                    $operations->evict($cache, 1, true);
                } catch (RuntimeException) {
                }
                $operations->cached($cache, 1);
                operationsAssert($operations->loads() === 7, '无事务失败方法错误清理缓存');
                $operations->evict($cache, 1);
                $operations->cached($cache, 1);
                operationsAssert($operations->loads() === 8, '无事务成功方法没有清理缓存');
                $operations->clearAfterWrite($cache, 1, ['name' => '按代次清理']);
                operationsAssert($operations->cached($cache, 1) === '按代次清理' && $operations->loads() === 9, '事务成功后没有清理当前命名空间');
                $typeCases = [[null, 'null:null'], [false, 'bool:false'], [1, 'int:1'], [1.0, 'float:1.0'], ['1', 'string:"1"']];
                foreach ($typeCases as $typeCase) {
                    operationsAssert($operations->typedKey($cache, $typeCase[0]) === $typeCase[1]
                        && $operations->typedKey($cache, $typeCase[0]) === $typeCase[1], '缓存 key 混淆 null、整数、浮点或字符串');
                }
                operationsAssert($operations->loads() === 14, '缓存键类型区分或复用次数错误');
                operationsAssert($operations->tenantKey($cache, null, 1) === '[null,1]' && $operations->tenantKey($cache, '', 1) === '["",1]'
                    && $operations->tenantKey($cache, 'tenant-a', 1) === '["tenant-a",1]', '可空租户参数未参与缓存隔离');
                operationsAssert($operations->greet() === '你好，开发者！' && $operations->greet(repeat: 2, name: '中文') === '你好，中文！你好，中文！'
                    && $operations->collision('合法参数') === '合法参数', '默认参数、命名参数或内部生成变量与业务参数冲突');
                $admin = new Redis();
                try {
                    $admin->connect((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379));
                    $admin->rawCommand('CLIENT', 'KILL', 'ID', $redis->identity());
                } finally {
                    $admin->close();
                }
                $committedFailure = false;
                try {
                    $operations->rename($cache, 1, '已提交但失效失败');
                } catch (AfterCommitException $failure) {
                    $committedFailure = $failure->outcome() === TransactionOutcome::COMMITTED && count($failure->errors()) === 1;
                }
                $redis->close();
                $redis = $redisManager->connection($scope, 'default', Purpose::SCRIPT);
                $cache = new TypedCache(new NamespaceStore($redis, $cacheApplication, 'test', 'v1'), JsonCodec::data());
                operationsAssert($committedFailure && $operations->read(1) === '已提交但失效失败'
                    && $operations->cached($cache, 1) === '按代次清理', '失效回调失败没有保留已提交事实和旧缓存');
                echo "显式生成操作的事务、缓存、回滚保护与最外层提交后失效通过。\n";
            });
        } finally {
            if ($cache !== null) {
                $cache->clear();
                $cache->collect(1000);
            }
            $scope->close();
            $database->close();
            $redisManager->close();
        }
    });
}
