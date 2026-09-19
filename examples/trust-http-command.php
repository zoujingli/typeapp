<?php

declare(strict_types=1);

use Type\Core\Http\Authentication;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\Cors;
use Type\Core\Http\Identity;
use Type\Core\Http\HttpControl;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Pipeline;
use Type\Core\Http\RequestPolicy;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use TypeApp\TrustExample\Endpoint;

function main(int $argc, array $argv): void
{
    $factory = new Factory();
    $router = new Router($factory, $factory);
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, '/secure', static fn (): Endpoint => new Endpoint());
    }
    $trusted = getenv('TYPE_TRUST_PROXY') === '1' ? ['127.0.0.1/32'] : [];
    $policy = new RequestPolicy(['localhost', 'public.example', 'localhost:80'], $trusted);
    $cors = new Cors($factory, ['https://client.example'], ['GET', 'POST'], ['authorization', 'content-type'], true);
    $authentication = new Authentication(static function (string $token): ?Identity {
        if ($token === 'valid-test-token') {
            return new Identity('user-7', ['reader']);
        }
        if ($token === 'blocked-test-token') {
            return new Identity('user-8', []);
        }
        return null;
    }, static fn (Identity $identity, CanonicalRequest $request, string $method): bool => in_array('reader', $identity->roles(), true), $factory, $factory);
    $pipeline = new Pipeline([static fn (): RequestPolicy => $policy, static fn (): Cors => $cors, static fn (): Authentication => $authentication], $router);
    $driver = getenv('TYPE_HTTP_DRIVER') ?: 'swoole';
    $server = match ($driver) {
        'swoole' => new SwooleServer($pipeline, $factory, $factory, $factory, control: new HttpControl(probes: true)),
        default => throw new InvalidArgumentException('HTTP 测试引擎无效'),
    };
    $server->serve('127.0.0.1', (int) (getenv('TYPE_HTTP_PORT') ?: 19503));
}
