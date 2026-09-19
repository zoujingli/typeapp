<?php

declare(strict_types=1);

use Type\Core\Http\Message\Factory;
use Type\Core\Http\HttpControl;
use Type\Core\Http\RequestLimits;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use TypeApp\FileExample\Endpoint;

function main(int $argc, array $argv): void
{
    $factory = new Factory();
    $router = new Router($factory, $factory);
    foreach (['save', 'temporary', 'fail-upload', 'form'] as $path) {
        $router->add('POST', '/' . $path, static fn (): Endpoint => new Endpoint());
    }
    foreach (['stats', 'file', 'download', 'fail-stream', 'fail-first', 'timeout-stream', 'disconnect'] as $path) {
        $router->add('GET', '/' . $path, static fn (): Endpoint => new Endpoint());
    }
    $router->add('HEAD', '/download', static fn (): Endpoint => new Endpoint());
    $temporary = getenv('TYPE_HTTP_UPLOAD_TEMP');
    $limits = new RequestLimits(262144, 4, 4, 2, 131072, 1024, $temporary === false ? null : $temporary);
    $control = new HttpControl(requestSeconds: 1.0);
    $driver = getenv('TYPE_HTTP_DRIVER') ?: 'swoole';
    $server = match ($driver) {
        'swoole' => new SwooleServer($router, $factory, $factory, $factory, $limits, $control),
        default => throw new InvalidArgumentException('HTTP 测试引擎无效'),
    };
    $server->serve('127.0.0.1', (int) getenv('TYPE_HTTP_PORT'));
}
