<?php

declare(strict_types=1);

use Type\Core\Http\Message\Factory;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Orm\Mysql\MysqlDriver;
use Type\Runtime\CoroutineRuntime;
use TypeApp\TaskExample\Connections;
use TypeApp\TaskExample\Endpoint;

/**
 * 启动请求子任务与数据库延迟示例，验证响应后仍在途资源的收尾。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    CoroutineRuntime::enableIo();
    $connections = new Connections(new MysqlDriver(
        (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
        (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
        (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
        (string) (getenv('TYPE_MYSQL_PASSWORD') ?: '')
    ));
    $factory = new Factory();
    $router = new Router($factory, $factory);
    $trace = (string) getenv('TYPE_TASK_TRACE');
    foreach (['timeout', 'disconnect', 'probe', 'stats'] as $mode) {
        $router->add('GET', '/' . $mode, static fn (): Endpoint => new Endpoint($connections, $mode, $trace));
    }
    (new SwooleServer($router, $factory, $factory, $factory))->serve('127.0.0.1', (int) (getenv('TYPE_HTTP_PORT') ?: 19504));
}
