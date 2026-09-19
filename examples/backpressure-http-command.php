<?php

declare(strict_types=1);

use Type\Core\Http\HttpControl;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Orm\DatabaseManager;
use Type\Orm\Mysql\MysqlDriver;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use TypeApp\Backpressure\Endpoint;

function main(int $argc, array $argv): void
{
    CoroutineRuntime::enableIo();
    $driver = new MysqlDriver(
        (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
        (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
        (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
        (string) (getenv('TYPE_MYSQL_PASSWORD') ?: '')
    );
    $budget = new DeploymentBudget(60, 3, 1, 6, 12); // 每进程至多 2 条，含全部身份和轮换代次。
    $databases = new DatabaseManager(['default' => $driver, 'alternate' => $driver], 4, 0, $budget);
    $control = new HttpControl(4, 128, 0.5, 1.0, 0.02, 2, true);
    $factory = new Factory();
    $router = new Router($factory, $factory);
    foreach (['state', 'slow', 'alternate', 'quick', 'hold', 'deadline', 'leak', 'stop'] as $path) {
        $router->add('GET', '/' . $path, static fn (): Endpoint => new Endpoint($databases, $control, $budget));
    }
    (new SwooleServer($router, $factory, $factory, $factory, null, $control))->serve('127.0.0.1', (int) getenv('TYPE_HTTP_PORT'));
}
