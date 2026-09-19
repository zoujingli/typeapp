<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Type\Core\WebSocket\Client;
use Type\Core\WebSocket\Server;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

/**
 * 有界 WebSocket 回显。服务端处理一条消息后停止；客户端完成一次收发。
 * WSS：服务传入证书与私钥，客户端传入用于校验的 CA 文件。
 */
function main(int $argc, array $argv): void
{
    if ($argc < 4 || ($argv[1] !== 'client' && $argv[1] !== 'server')) {
        throw new InvalidArgumentException(
            '用法：type-app server <主机> <端口> [证书 私钥] 或 type-app client <主机> <端口> <路径> <消息> [CA]'
        );
    }
    CoroutineRuntime::assertAvailable();
    $role = $argv[1];
    $host = $argv[2];
    $port = (int) $argv[3];
    $plan = new DeploymentBudget(16, 1, 0, 1, 0, 1);
    if ($role === 'server') {
        if ($argc !== 4 && $argc !== 6) {
            throw new InvalidArgumentException('服务端用法：type-app server <主机> <端口> [证书 私钥]');
        }
        $options = [];
        if ($argc === 6) {
            $options = ['open_ssl' => true, 'ssl_cert_file' => $argv[4], 'ssl_key_file' => $argv[5]];
        }
        $server = Server::create($plan->poolBudget(), $host, $port, $options);
        $server->onMessage(function (int $fd, string $data, bool $binary, ExecutionScope $scope) use ($server): void {
            $server->send($fd, $data, $binary);
            $server->stop();
        });
        $server->onRequest(function ($request, $response): void {
            $response->status(200);
            $response->end('http-ok');
        });
        $server->start();
        return;
    }
    if ($argc !== 6 && $argc !== 7) {
        throw new InvalidArgumentException('客户端用法：type-app client <主机> <端口> <路径> <消息> [CA]');
    }
    $tls = $argc === 7;
    $cafile = $tls ? $argv[6] : '';
    Coroutine::create(static function () use ($plan, $host, $port, $argv, $tls, $cafile): void {
        $scope = new ExecutionScope();
        $client = Client::create(
            $plan->poolBudget(),
            $host,
            $port,
            $argv[4],
            $tls,
            [],
            65536,
            5.0,
            $tls ? ['ssl_cafile' => $cafile, 'ssl_host_name' => $host] : []
        );
        try {
            $scope->open($client);
            $client->send($argv[5], true);
            $echo = $client->receive(5.0);
            echo json_encode(['data' => $echo], JSON_THROW_ON_ERROR) . "\n";
        } finally {
            $scope->close();
        }
    });
    Swoole\Event::wait();
}
