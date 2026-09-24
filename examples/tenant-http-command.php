<?php

declare(strict_types=1);

use Type\Core\Http\Authentication;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Pipeline;
use Type\Core\Http\RequestPolicy;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Core\Http\Tenant;
use Type\Core\Http\TenantResolver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Orm\Driver;
use Type\Orm\DatabaseManager;
use Type\Log\LogManager;
use Type\Log\Channel;
use Type\Log\Output;
use Type\Runtime\ExecutionScope;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use TypeApp\TenantExample\Endpoint;

/** 仅按启动时已声明的租户配置创建驱动，HTTP输入不能进入连接字符串。 */
function tenantDriver(string $kind, string $tenant, int $generation): Driver
{
    $prefix = 'TYPE_TENANT_' . strtoupper($tenant) . '_';
    return match ($kind) {
        'mysql' => new MysqlDriver(
            (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
            (string) getenv($prefix . 'DATABASE'),
            (string) getenv($prefix . 'USER'),
            (string) getenv($prefix . 'PASSWORD'),
            $generation
        ),
        'pgsql' => new PgsqlDriver(
            (string) (getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_PGSQL_PORT') ?: 5432),
            (string) getenv($prefix . 'DATABASE'),
            (string) getenv($prefix . 'USER'),
            (string) getenv($prefix . 'PASSWORD'),
            $generation,
            'writer',
            (string) getenv($prefix . 'SCHEMA')
        ),
        'sqlite' => new SqliteDriver((string) getenv($prefix . 'DATABASE'), 1000, true, $generation),
        default => throw new InvalidArgumentException('租户测试驱动无效'),
    };
}

/**
 * 装配多租户 HTTP、数据库、缓存与日志；每个请求只使用已授权租户资源。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $factory = new Factory();
    $router = new Router($factory, $factory);
    $drivers = [];
    $replacements = [];
    $tenants = [];
    $application = (string) getenv('TYPE_TENANT_APPLICATION');
    $kind = getenv('TYPE_TENANT_DRIVER') ?: 'mysql';
    foreach (['alpha', 'beta'] as $tenant) {
        $drivers[$tenant . '-db'] = tenantDriver($kind, $tenant, 1);
        $replacements[$tenant . '-db'] = tenantDriver($kind, $tenant, 2);
        $tenants[] = new Tenant($tenant, $tenant . '-db', $application . ':' . $tenant);
    }
    $redis = new RedisManager(['default' => new RedisConfiguration((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379))], [Purpose::SCRIPT => 16]);
    $databases = new DatabaseManager($drivers, 8, 8);
    \Type\Orm\Db::configure($databases);
    $logFile = getenv('TYPE_TENANT_LOG');
    $logs = new LogManager('tenant-test', ['app' => new Channel($logFile === false ? Output::stderr() : Output::file($logFile))]);
    $router->add('GET', '/tenant', static fn (): Endpoint => new Endpoint($databases, $replacements, $redis, $logs));
    $authentication = new Authentication(
        static fn (string $token): ?Identity => in_array($token, ['alpha-token', 'beta-token'], true) ? new Identity(substr($token, 0, -6)) : null,
        static fn (Identity $identity, CanonicalRequest $request, string $method): bool => true,
        $factory,
        $factory
    );
    $policy = new RequestPolicy(['localhost']);
    $resolver = new TenantResolver($tenants, static fn (Identity $identity, Tenant $tenant): bool => $identity->subject() === $tenant->id());
    $pipeline = new Pipeline([static fn (): RequestPolicy => $policy, static fn (): Authentication => $authentication, static fn (): TenantResolver => $resolver], $router);
    // Swoole的worker不会返回主进程的serve调用；每个进程必须关闭自己实际创建的资源。
    $state = new stdClass();
    $state->closed = false;
    $cleanup = static function () use ($databases, $redis, $logs, $state): void {
        if ($state->closed) {
            return;
        }
        $state->closed = true;
        $before = $databases->statistics();
        $databases->close();
        $redis->close();
        $shutdown = new ExecutionScope();
        try {
            $logs->logger($shutdown)->info('tenant-stopped', ['before' => $before, 'after' => $databases->statistics()]);
        } finally {
            $shutdown->close();
            $logs->stop();
        }
    };
    $server = new SwooleServer($pipeline, $factory, $factory, $factory, onWorkerStop: $cleanup);
    try {
        $server->serve('127.0.0.1', (int) getenv('TYPE_HTTP_PORT'));
    } finally {
        $cleanup();
    }
}
