<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Type\Core\UdpSocket;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

/**
 * 一个有截止的 UDP echo 服务端/客户端；由调用者提供数字 IP、端口与角色。
 * 每个业务线程独立创建同一部署计划的一份预算，不能复制进程总额度。
 */
function main(int $argc, array $argv): void
{
    if ($argc < 4 || !in_array($argv[1], ['server', 'client'], true)) {
        throw new InvalidArgumentException('用法：type-app server|client <数字 IP> <端口> [报文]');
    }
    CoroutineRuntime::assertAvailable();
    Coroutine::create(static function () use ($argv): void {
        $budget = new DeploymentBudget(16, 1, 0, 1, 0, 1);
        $scope = new ExecutionScope();
        $server = $argv[1] === 'server';
        $socket = new UdpSocket($budget->poolBudget(), $argv[2], $server ? (int) $argv[3] : 0);
        try {
            $scope->open($socket);
            if (!$server) {
                $socket->sendTo($argv[2], (int) $argv[3], $argv[4] ?? 'hello');
            }
            $packet = $socket->receive(5.0);
            if ($server) {
                $socket->sendTo($packet['address'], $packet['port'], $packet['data']);
            }
            echo json_encode($packet, JSON_THROW_ON_ERROR) . "\n";
        } finally {
            $scope->close();
        }
    });
    Swoole\Event::wait();
}
