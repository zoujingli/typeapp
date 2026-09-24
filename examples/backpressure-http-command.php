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

/**
 * 启动连接预算与 HTTP 背压示例；数据库、日志路径与监听参数来自受控测试环境。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
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
    // 等待时间为 0：额度用尽时立即拒绝。默认 1 秒会盖过 0.25 秒的租约占用，第三条连接会在释放后成功。
    $databases = new DatabaseManager(['default' => $driver, 'alternate' => $driver], 4, 0, $budget, 64, 0.0);
    $control = new HttpControl(4, 128, 0.5, 1.0, 0.02, 2, true);
    $factory = new Factory();
    $router = new Router($factory, $factory);
    foreach (['state', 'slow', 'alternate', 'quick', 'hold', 'deadline', 'leak', 'stop'] as $path) {
        $router->add('GET', '/' . $path, static fn (): Endpoint => new Endpoint($databases, $control, $budget));
    }
    (new SwooleServer($router, $factory, $factory, $factory, null, $control))->serve('127.0.0.1', (int) getenv('TYPE_HTTP_PORT'));
}
