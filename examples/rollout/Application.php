<?php

declare(strict_types=1);

namespace TypeApp\Rollout;

use Type\Cache\NamespaceStore;
use Type\Cache\TypedCache;
use Type\Core\Http\HttpControl;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Core\Http\HttpServerInterface;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Orm\Migration\Migrator;
use Type\Orm\Outbox\Relay;
use Type\Orm\Outbox\Store;
use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Queue\Registry;
use Type\Queue\Worker;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ReleaseCompatibility;
use Type\Runtime\ProcessSignals;
use TypeApp\ModelExample\Drivers;
use TypeApp\OutboxExample\Delivered;

final class Application
{
    public static function compatibility(int $release): ReleaseCompatibility
    {
        return new ReleaseCompatibility($release === 1 ? '1.0.0' : '1.1.0', [
            'schema' => ['users' => $release === 1 ? [1, 2] : [2, 3]],
            'messages' => ['user.changed' => $release === 1 ? [1] : [1, 2]],
            'cache' => ['users' => [$release]],
        ]);
    }
    public static function run(int $release, array $arguments): void
    {
        if (($arguments[1] ?? '') === 'serve') {
            self::execute($release, $arguments);
            return;
        }
        \Type\Runtime\CoroutineRuntime::run(static function () use ($release, $arguments): void {
            self::execute($release, $arguments);
        });
    }

    private static function execute(int $release, array $arguments): void
    {
        try {
            $compatibility = self::compatibility($release);
            $mode = $arguments[1] ?? 'metadata';
            if (class_exists(\Type\Generated\BuildIdentity::class, false)) {
                \Type\Generated\BuildIdentity::verifyRuntime();
                $build = \Type\Generated\BuildIdentity::info();
                $declared = new ReleaseCompatibility($build['version'], $build['capabilities']);
                if ($declared->metadata() != $compatibility->metadata()) {
                    throw new \RuntimeException('二进制版本与运行协议声明不一致');
                }
            }
            if ($mode === 'metadata') {
                self::output($compatibility->metadata());
                return;
            }
            if ($mode === 'consumer-check') {
                $compatibility->assertConsumer('user.changed', (int) ($arguments[2] ?? 1));
                self::output(['compatible' => true]);
                return;
            }
            $driver = Drivers::create((string) (getenv('TYPE_MODEL_DRIVER') ?: 'sqlite'));
            $migrator = new Migrator($driver);
            if ($mode === 'migrate') {
                $phase = (int) ($arguments[2] ?? 1);
                if ($phase > ($release === 1 ? 1 : 2)) {
                    throw new \InvalidArgumentException('普通迁移不能隐式执行破坏性收缩');
                }
                self::output($migrator->run(Schema::plan($driver, $phase)));
                return;
            }
            if ($mode === 'repair') {
                $scope = new ExecutionScope();
                $database = new Database($driver, 1, 0);
                try {
                    $connection = $database->connect($scope);
                    if ($driver->name() === 'mysql' && in_array('nickname', array_column($connection->columns('rollout_users'), 'name'), true)) {
                        // 演练仅在新版尚未接收写入时补偿未完成的可空字段扩展。
                        $connection->raw('ALTER TABLE rollout_users DROP COLUMN nickname');
                    }
                    $connection->raw('CREATE TABLE IF NOT EXISTS rollout_gate (id VARCHAR(64) PRIMARY KEY)');
                } finally {
                    $scope->close();
                    $database->close();
                }
                $migrator->recover(Schema::plan($driver, 2), '200_expand', 'retry', '已核对并修复演练前置表及非事务 DDL 残留');
                self::output($migrator->run(Schema::plan($driver, 2)));
                return;
            }
            $schema = Schema::current($driver);
            $compatibility->assertSchema('users', $schema);
            if ($mode === 'check') {
                self::output(['version' => $compatibility->metadata()['version'], 'schema' => $schema]);
                return;
            }
            if ($mode === 'contract') {
                if ($release !== 2 || ($arguments[2] ?? '') !== '--old-processes-drained') {
                    throw new \InvalidArgumentException('结构收缩需要明确确认旧结构进程已经排空');
                }
                self::output($migrator->run(Schema::plan($driver, 3)));
                return;
            }
            if ($mode === 'serve') {
                $port = filter_var(getenv('TYPE_HTTP_PORT'), FILTER_VALIDATE_INT);
                if (!is_int($port) || $port < 1 || $port > 65535) {
                    throw new \InvalidArgumentException('部署端口配置错误');
                }
                $messages = new Factory();
                $router = new Router($messages, $messages);
                $router->add('GET', '/user', static fn (): Endpoint => new Endpoint($driver, $release, $schema));
                $server = self::server($router, $messages);
                $server->serve((string) (getenv('TYPE_HTTP_LISTEN') ?: '127.0.0.1'), $port);
                return;
            }
            $scope = new ExecutionScope();
            $database = new Database($driver, 2, 0);
            $manager = self::redis();
            $store = new Store();
            try {
                $queue = new Queue($manager->connection($scope, 'queue', Purpose::SCRIPT), (string) getenv('TYPE_ROLLOUT_APP'), 'rollout', 2000, 100);
                if ($mode === 'write') {
                    $id = $arguments[2] ?? '';
                    $name = $arguments[3] ?? '';
                    if ($id === '' || $name === '') {
                        throw new \InvalidArgumentException('写入需要稳定操作 ID 与用户名');
                    }
                    $connection = $database->connect($scope);
                    $connection->transaction(static function (Connection $transaction) use ($store, $id, $name, $release, $schema): void {
                        $values = $schema === 3 ? ['nickname' => $name] : ['name' => $name];
                        if ($release === 2 && $schema === 2) {
                            $values['nickname'] = $name;
                        }
                        if ($transaction->table('rollout_users')->where('id', '=', 'user')->first() === null) {
                            $transaction->table('rollout_users')->insert(['id' => 'user'] + $values);
                        } else {
                            $transaction->table('rollout_users')->where('id', '=', 'user')->update($values);
                        }
                        $store->enqueue($transaction, $id, 'user.changed', $release, ['value' => $name]);
                    });
                    self::cache($manager, $scope, $release)->delete('user');
                    self::output(['committed' => true, 'message_version' => $release]);
                } elseif ($mode === 'relay') {
                    $delayMilliseconds = filter_var($arguments[2] ?? '5000', FILTER_VALIDATE_INT);
                    if (!is_int($delayMilliseconds)) {
                        throw new \InvalidArgumentException('发布演练延迟必须为整数毫秒');
                    }
                    self::output(['published' => (new Relay($database, $store, new Publisher($queue, $delayMilliseconds)))->runOnce()]);
                } elseif ($mode === 'unknown') {
                    $queue->publish(new Message('unknown-future', 'user.changed', 3, []));
                    self::output(['published' => true]);
                } elseif ($mode === 'stats') {
                    $connection = $database->connect($scope);
                    self::output(['queue' => $queue->statistics(), 'effects' => $connection->table('type_outbox_effects')->orderBy('id')->get(),
                        'quarantine' => $queue->quarantined(), 'queue_identity' => $queue->identity(),
                        'cache_identity' => (new NamespaceStore($manager->connection($scope, 'cache', Purpose::SCRIPT), (string) getenv('TYPE_ROLLOUT_APP'), 'test', 'users'))->identity()]);
                } elseif ($mode === 'worker') {
                    $registry = new Registry();
                    foreach ($compatibility->metadata()['capabilities']['messages']['user.changed'] as $version) {
                        $registry->register('user.changed', $version, static fn (JobContext $context): Delivered => new Delivered($driver, $store));
                    }
                    $worker = new Worker($queue, $registry, 'release-' . $release . '-' . getmypid());
                    $signals = new ProcessSignals();
                    $signals->attach(static function () use ($worker): void {
                        $worker->stop(0.5);
                        self::output(['stopping' => $worker->statistics()]);
                    });
                    try {
                        self::output(['ready' => $worker->ready(), 'release' => $release]);
                        // 覆盖最多两分钟的演练延迟及进程切换；仍保留有限退出上界。
                        $deadline = new Deadline(180);
                        while ($worker->ready() && !$deadline->expired()) {
                            $signals->dispatch();
                            if (!$worker->runOnce()) {
                                usleep(10000);
                            }
                        }
                        $worker->stop();
                        self::output(['worker' => $worker->statistics()]);
                    } finally {
                        $signals->close();
                    }
                } else {
                    throw new \InvalidArgumentException('未知发布演练角色');
                }
            } finally {
                $scope->close();
                $database->close();
                $manager->close();
            }
        } catch (\Throwable $error) {
            fwrite(STDERR, '发布演练停止：' . $error->getMessage() . "\n");
            exit(70);
        }
    }
    public static function redis(): RedisManager
    {
        $queue = (string) getenv('TYPE_REDIS_HOST');
        $cache = (string) getenv('TYPE_ROLLOUT_CACHE_HOST');
        $queuePort = (int) (getenv('TYPE_REDIS_PORT') ?: 6379);
        $cachePort = (int) (getenv('TYPE_ROLLOUT_CACHE_PORT') ?: 6379);
        if ($queue === '' || $cache === '' || ($queue === $cache && $queuePort === $cachePort)) {
            throw new \InvalidArgumentException('演练缓存与可靠队列需要独立 Redis 实例');
        }
        return new RedisManager(['queue' => new RedisConfiguration($queue, $queuePort), 'cache' => new RedisConfiguration($cache, $cachePort)]);
    }
    /** 演练共用同一HTTP业务；服务端统一使用Swoole并启用协程I/O。 */
    private static function server(Router $router, Factory $messages): HttpServerInterface
    {
        \Type\Runtime\CoroutineRuntime::enableIo();
        return new SwooleServer($router, $messages, $messages, $messages, null, new HttpControl(probes: true));
    }
    public static function cache(RedisManager $manager, ExecutionScope $scope, int $release): TypedCache
    {
        return new TypedCache(new NamespaceStore($manager->connection($scope, 'cache', Purpose::SCRIPT), (string) getenv('TYPE_ROLLOUT_APP'), 'test', 'users'), new Codec($release), 2000);
    }
    private static function output(array $data): void
    {
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
