<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Type\Core\TcpSocket;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

/** 有截止的 TCP echo；TLS 可在 listener/client 的 options 中配置 open_ssl 和证书。 */
function main(int $argc, array $argv): void
{
    if ($argc < 4 || !in_array($argv[1], ['client', 'server'], true)) {
        throw new InvalidArgumentException('用法：type-app client|server <主机> <端口> [消息]');
    }
    CoroutineRuntime::assertAvailable();
    Coroutine::create(static function () use ($argv): void {
        $plan = new DeploymentBudget(16, 1, 0, 1, 0, 1);
        $scope = new ExecutionScope();
        $socket = $argv[1] === 'client'
            ? TcpSocket::client($plan->poolBudget(), $argv[2], (int) $argv[3])
            : TcpSocket::listener($plan->poolBudget(), $argv[2], (int) $argv[3]);
        try {
            $scope->open($socket);
            if ($argv[1] === 'server') {
                $connection = $socket->accept();
                $scope->open($connection);
                $data = '';
                while (($part = $connection->receive()) !== '') {
                    if (strlen($data) + strlen($part) > 65536) {
                        throw new RuntimeException('示例请求超过 64 KiB');
                    }
                    $data .= $part;
                    $connection->send($part);
                }
                $connection->shutdownWrite();
            } else {
                $socket->send($argv[4] ?? 'hello');
                $socket->shutdownWrite();
                $data = '';
                while (($part = $socket->receive()) !== '') {
                    if (strlen($data) + strlen($part) > 65536) {
                        throw new RuntimeException('示例响应超过 64 KiB');
                    }
                    $data .= $part;
                }
            }
            echo json_encode(['data' => $data], JSON_THROW_ON_ERROR) . "\n";
        } finally {
            $scope->close();
            $socket->awaitClosed();
        }
    });
    Swoole\Event::wait();
}
