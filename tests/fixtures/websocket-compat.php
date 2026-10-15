<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Type\Core\WebSocket\Server;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

/** PHP 与 AOT 共用的协议夹具；只记录测试连接生命周期，不持有请求或数据库资源。 */
final class WebSocketCompatibilityState
{
    public array $events = [];

    public function __construct(private string $file)
    {
    }

    /** 每次写入都可从独立客户端观察，服务停止后仍能核对 close 的次数。 */
    public function record(string $kind, int $fd, array $details = []): void
    {
        $this->events[] = ['kind' => $kind, 'fd' => $fd, 'details' => $details];
        file_put_contents($this->file, json_encode($this->events, JSON_THROW_ON_ERROR));
    }
}

/** 同一夹具接受显式端口、结果路径及配置；生产源码通过 TypePHP 全量编译。 */
function main(int $argc, array $argv): void
{
    if ($argc !== 4) {
        throw new InvalidArgumentException('用法：websocket-compat <端口> <记录路径> <JSON选项>');
    }
    $options = json_decode($argv[3], true, 16, JSON_THROW_ON_ERROR);
    if ($argv[1] === 'validate') {
        try {
            $candidate = Server::create(new ResourceBudget(1), '127.0.0.1', 0, $options);
            echo json_encode(['accepted' => true, 'statistics' => $candidate->statistics()], JSON_THROW_ON_ERROR), "\n";
        } catch (TaskException $error) {
            echo json_encode(['accepted' => false, 'error' => $error->errorCode()], JSON_THROW_ON_ERROR), "\n";
        }
        return;
    }
    $state = new WebSocketCompatibilityState($argv[2]);
    $budget = new ResourceBudget(1);
    $server = Server::create($budget, '127.0.0.1', (int) $argv[1], $options);
    $server->onOpen(function (int $fd, array $headers, bool $verified) use ($server, $state): void {
        $snapshot = $server->handshake($fd);
        $state->record('open', $fd, $snapshot);
        if ($snapshot['path'] === '/open-fail') {
            throw new RuntimeException('test_open_failure');
        }
        if ($snapshot['path'] === '/open-close') {
            $server->disconnect($fd);
            return;
        }
        $server->send($fd, json_encode($snapshot, JSON_THROW_ON_ERROR), false);
    });
    $server->onControl(function (int $fd, int $opcode, string $data, ExecutionScope $scope) use ($server, $state): void {
        if (ExecutionScope::current() !== $scope) {
            throw new RuntimeException('control_scope_missing');
        }
        $state->record('control', $fd, ['opcode' => $opcode, 'bytes' => strlen($data)]);
        $server->send($fd, 'observed:' . $opcode, false);
    });
    $server->onMessage(function (int $fd, string $data, bool $binary, ExecutionScope $scope) use ($server, $state): void {
        $scope->assertActive();
        $state->record('message', $fd, ['bytes' => strlen($data)]);
        if ($data === 'close') {
            $server->disconnect($fd);
            $server->disconnect($fd);
        } elseif ($data === 'fail') {
            throw new RuntimeException('test_message_failure');
        } elseif ($data === 'stop') {
            $server->stop();
        } elseif ($data === 'server-ping') {
            $server->ping($fd, "ping\0payload");
        } elseif ($data === 'server-pong') {
            $server->pong($fd, "pong\0payload");
        } elseif ($data === 'invalid-control') {
            try {
                $server->pong($fd, str_repeat('x', 126));
            } catch (\Type\Runtime\TaskException $error) {
                $server->send($fd, $error->getMessage(), false);
            }
        } elseif ($data === 'big-send') {
            $server->send($fd, str_repeat('z', (int) $server->statistics()['max_frame_bytes']));
        } elseif (strlen($data) > 1024) {
            $server->send($fd, json_encode(['bytes' => strlen($data), 'sha256' => hash('sha256', $data)], JSON_THROW_ON_ERROR), false);
        } else {
            $server->send($fd, $data, $binary);
        }
    });
    $server->onClose(function (int $fd, int $reactorId) use ($state): void {
        $state->record('close', $fd, ['reactor_id' => $reactorId]);
    });
    $server->onRequest(function (Request $request, Response $response) use ($server): void {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($server->statistics(), JSON_THROW_ON_ERROR));
        if (($request->server['request_uri'] ?? '') === '/stop') {
            $server->stop();
        }
    });
    $server->start();
    $server->awaitClosed();
    $state->record('stopped', 0, $server->statistics());
}
