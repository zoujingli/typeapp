<?php

declare(strict_types=1);

use Type\Core\Http\Message\Factory;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use TypeApp\ModelExample\Drivers;
use TypeApp\ModelExample\Handler;
use TypeApp\ModelExample\DatabaseAction;
use TypeApp\ModelExample\TransactionExercise;
use TypeApp\ModelExample\LifecycleExercise;
use Type\Orm\Connection;
use TypeApp\ModelExample\TagHandler;
use TypeApp\ModelExample\InvoiceHandler;
use TypeApp\ModelExample\CounterHandler;

/**
 * 装配模型 HTTP 路由与所选驱动；SQLite 服务必须使用显式持久文件。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $driverName = (string) (getenv('TYPE_MODEL_DRIVER') ?: 'mysql');
    if ($driverName === 'sqlite' && !getenv('TYPE_SQLITE_FILE')) {
        throw new InvalidArgumentException('HTTP SQLite 需要持久文件路径');
    }
    $driver = Drivers::create($driverName);
    \Type\Orm\Db::configure(new \Type\Orm\DatabaseManager(['default' => $driver]));
    $factory = new Factory();
    $router = new Router($factory, $factory);
    foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
        $router->add($method, '/users', static fn (): Handler => new Handler($factory, $factory));
        $router->add($method, '/article-tags', static fn (): TagHandler => new TagHandler($factory, $factory));
    }
    $router->add('POST', '/transaction-check', static fn (): DatabaseAction => new DatabaseAction(
        $factory,
        $factory,
        static fn (Connection $connection): array => TransactionExercise::verify($connection)
    ));
    $router->add('POST', '/lifecycle-check', static fn (): DatabaseAction => new DatabaseAction(
        $factory,
        $factory,
        static fn (Connection $connection): array => LifecycleExercise::verify($connection)
    ));
    foreach (['GET', 'POST', 'PATCH'] as $method) {
        $router->add($method, '/invoices', static fn (): InvoiceHandler => new InvoiceHandler($factory, $factory));
        $router->add($method, '/counters', static fn (): CounterHandler => new CounterHandler($factory, $factory));
    }
    (new SwooleServer($router, $factory, $factory, $factory))->serve('127.0.0.1', (int) (getenv('TYPE_HTTP_PORT') ?: 19502));
}
