<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Server as CoroutineHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Type\Core\WebSocket\Client;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

/**
 * 在已有协程中通过真实 HTTP/WS 核对升级拒绝、额度复用及握手停止；不使用外部服务。
 * @return list<string> 已通过的公开行为场景。
 */
function websocketClientUpgradeChecks(): array
{
    $server = new CoroutineHttpServer('127.0.0.1', 0);
    $entered = new Channel(1);
    $release = new Channel(1);
    foreach ([200, 301, 401, 403, 500] as $status) {
        $server->handle('/status/' . $status, static function (Request $request, Response $response) use ($status): void {
            $response->status($status);
            $response->end('not-upgraded');
        });
    }
    $server->handle('/aborted', static function (Request $request, Response $response): void {
        $response->close();
    });
    foreach (['missing-upgrade', 'other-upgrade', 'bad-accept'] as $invalid) {
        $server->handle('/' . $invalid, static function (Request $request, Response $response) use ($invalid): void {
            $response->status(101);
            $response->header('Connection', $invalid === 'bad-accept' ? 'Upgrade' : 'keep-alive');
            if ($invalid !== 'missing-upgrade') {
                $response->header('Upgrade', $invalid === 'bad-accept' ? 'websocket' : 'h2c');
            }
            $response->header('Sec-WebSocket-Accept', 'invalid');
            $response->end();
        });
    }
    $server->handle('/accepted', static function (Request $request, Response $response): void {
        if ($response->upgrade()) {
            $frame = $response->recv(1.0);
            if ($frame instanceof Frame) {
                $response->push($frame->data, $frame->opcode);
            }
        }
    });
    $server->handle('/pending', static function (Request $request, Response $response) use ($entered, $release): void {
        $entered->push(true);
        if ($release->pop(1.0) === true) {
            $response->upgrade();
        }
    });
    Coroutine::create(static function () use ($server): void {
        $server->start();
    });
    $checks = [];
    try {
        foreach (['/status/200', '/status/301', '/status/401', '/status/403', '/status/500', '/aborted',
            '/missing-upgrade', '/other-upgrade', '/bad-accept'] as $path) {
            $budget = new ResourceBudget(1);
            $client = Client::create($budget, '127.0.0.1', $server->port, $path);
            $failure = '';
            try {
                $client->start();
            } catch (TaskException $error) {
                $failure = $error->errorCode();
            } finally {
                $allocated = $budget->statistics()['allocated'];
                $state = $client->statistics()['state'];
                $client->stop();
                $client->stop();
            }
            if ($failure !== 'websocket_upgrade_failed' || $state !== 'closed' || $allocated !== 0 || $budget->statistics()['allocated'] !== 0) {
                throw new RuntimeException('WebSocket 升级拒绝或额度归还错误：' . $path);
            }
            // 相同的一份预算必须能立即服务下一条真实 WS 连接。
            $next = Client::create($budget, '127.0.0.1', $server->port, '/accepted');
            try {
                $next->start();
                $next->send('slot-reused');
                if ($next->receive(1.0) !== 'slot-reused') {
                    throw new RuntimeException('WebSocket 升级拒绝后无法复用额度通信');
                }
            } finally {
                $next->stop();
            }
            if ($budget->statistics()['allocated'] !== 0) {
                throw new RuntimeException('WebSocket 成功连接未归还额度');
            }
            $checks[] = $path;
        }
        $budget = new ResourceBudget(1);
        $client = Client::create($budget, '127.0.0.1', $server->port, '/pending');
        $done = new Channel(1);
        $started = '';
        Coroutine::create(static function () use ($client, $done, &$started): void {
            try {
                $client->start();
                $started = 'active';
            } catch (TaskException $error) {
                $started = $error->errorCode();
            } finally {
                $done->push(true);
            }
        });
        try {
            if ($entered->pop(1.0) !== true || $budget->statistics()['allocated'] !== 1) {
                throw new RuntimeException('WebSocket 未进入真实握手等待');
            }
            $duplicate = false;
            try {
                $client->start();
            } catch (TaskException $error) {
                $duplicate = $error->errorCode() === 'websocket_client_stopped';
            }
            $client->stop();
            $release->push(true);
            if (!$duplicate || $done->pop(1.0) !== true || $started !== 'websocket_upgrade_failed'
                || $client->statistics()['state'] !== 'closed' || $budget->statistics()['allocated'] !== 0) {
                throw new RuntimeException('WebSocket 握手重复进入或停止后重新激活');
            }
            $checks[] = 'pending-stop';
        } finally {
            $client->stop();
            $done->close();
        }
    } finally {
        $release->close();
        $entered->close();
        $server->shutdown();
    }
    return $checks;
}
