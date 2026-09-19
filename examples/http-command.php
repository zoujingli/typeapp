<?php

declare(strict_types=1);

use Type\Core\Http\Message\Factory;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use TypeApp\HttpExample\FailingHandler;
use TypeApp\HttpExample\Greeting;
use TypeApp\HttpExample\HealthHandler;
use TypeApp\HttpExample\MarkerMiddleware;
use TypeApp\ValidationExample\Handler as ValidationHandler;

function main(int $argc, array $argv): void
{
    $port = filter_var(getenv('TYPE_HTTP_PORT') ?: '19501', FILTER_VALIDATE_INT);
    if (!is_int($port) || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException('HTTP 端口无效');
    }
    $trace = (string) (getenv('TYPE_HTTP_TRACE') ?: '');
    $responses = new Factory();
    $requests = $responses;
    $streams = $responses;
    $router = new Router($responses, $streams);
    $router->middleware(static fn (): MarkerMiddleware => new MarkerMiddleware('A'));
    $router->middleware(static fn (): MarkerMiddleware => new MarkerMiddleware('B'));
    $router->add('GET', '/health', static fn (): HealthHandler => new HealthHandler(new Greeting('原生 HTTP'), $responses, $streams, $trace));
    $router->add('GET', '/fail', static fn (): FailingHandler => new FailingHandler($trace));
    $router->add('POST', '/validate', static fn (): ValidationHandler => new ValidationHandler($responses, $streams));
    $router->add('PATCH', '/validate', static fn (): ValidationHandler => new ValidationHandler($responses, $streams));
    (new SwooleServer($router, $requests, $responses, $streams))->serve('127.0.0.1', (int) $port);
}
