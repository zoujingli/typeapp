<?php

declare(strict_types=1);

use Type\Core\WebSocket\Server;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;
use Type\Runtime\ResourceBudget;

/**
 * 有界 WebSocket 回显进程：用于握手、WSS、消息作用域与停止验收。
 * 消息 hold 不发送回显；消息 stop 回显后停止；GET /stop 请求停止。
 */
if ($argc < 2 || !is_file($argv[1])) {
    fwrite(STDERR, "用法：php websocket-echo.php <autoload>\n");
    exit(1);
}
require $argv[1];

$port = (int) getenv('TYPE_WS_PORT');
$encoded = getenv('TYPE_WS_OPTIONS');
$options = is_string($encoded) && $encoded !== '' ? json_decode($encoded, true) : [];
if (!is_array($options)) {
    fwrite(STDERR, "TYPE_WS_OPTIONS 必须是 JSON 对象\n");
    exit(1);
}
$lease = (string) getenv('TYPE_WS_LEASE');
$server = Server::create(new ResourceBudget(8), '127.0.0.1', $port, $options);
$server->onMessage(function (int $fd, string $data, bool $binary, ExecutionScope $scope) use ($server, $lease): void {
    if ($lease !== '') {
        $scope->open(new class ($lease) implements ManagedResource {
            public function __construct(private string $path)
            {
            }

            public function start(): void
            {
                file_put_contents($this->path, 'open');
            }

            public function stop(): void
            {
                file_put_contents($this->path, 'closed');
            }
        });
    }
    if ($data === 'hold') {
        return;
    }
    $server->send($fd, $data, $binary);
    if ($data === 'stop') {
        $server->stop();
    }
});
$server->onRequest(function ($request, $response) use ($server): void {
    $target = (string) ($request->server['request_uri'] ?? '/');
    if ($target === '/stop') {
        $response->status(200);
        $response->end('stopping');
        $server->stop();
        return;
    }
    $response->status(200);
    $response->end('http-ok');
});
$server->start();
