<?php

declare(strict_types=1);

use Type\Core\Http\Message\Factory;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use TypeApp\Generated\Routes;
use TypeApp\RoutingExample\BooksController;
use TypeApp\RoutingExample\TraceMiddleware;

function main(int $argc, array $argv): void
{
    $port = filter_var(getenv('TYPE_HTTP_PORT') ?: '19502', FILTER_VALIDATE_INT);
    if (!is_int($port) || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException('HTTP 端口无效');
    }
    $messages = new Factory();
    $router = new Router($messages, $messages);
    $router->middleware(static fn (): TraceMiddleware => new TraceMiddleware('G'));
    Routes::register($router, [BooksController::class => static fn (): BooksController => new BooksController('生成路由')], [
        'group' => static fn (): TraceMiddleware => new TraceMiddleware('P'),
        'route' => static fn (): TraceMiddleware => new TraceMiddleware('R'),
    ]);
    (new SwooleServer($router, $messages, $messages, $messages))->serve('127.0.0.1', $port);
}
