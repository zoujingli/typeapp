<?php

declare(strict_types=1);

use Type\Core\Http\Message\Factory;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use TypeApp\LogExample\Handler;
use TypeApp\LogExample\LogMiddleware;
use TypeApp\LogExample\PreviousLogger;

function main(int $argc, array $argv): void
{
    $port = filter_var(getenv('TYPE_HTTP_PORT') ?: '19503', FILTER_VALIDATE_INT);
    $file = getenv('TYPE_HTTP_LOG_FILE');
    if (!is_int($port) || $port < 1 || $port > 65535 || !is_string($file) || $file === '') {
        throw new InvalidArgumentException('HTTP 日志验证配置无效');
    }
    $messages = new Factory();
    $router = new Router($messages, $messages);
    $previous = new PreviousLogger();
    $router->middleware(static fn (): LogMiddleware => new LogMiddleware($file));
    foreach (['/log', '/fail', '/previous'] as $path) {
        $router->add('GET', $path, static fn (): Handler => new Handler($previous));
    }
    $server = new SwooleServer($router, $messages, $messages, $messages);
    $server->serve('127.0.0.1', $port);
}
