<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/outbox-application.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

use Type\Testing\Process;
use TypeApp\ModelExample\Drivers;

// 用真实 MySQL COMMIT 断线区分未提交与已提交，业务只执行一次；持久意图由独立 Relay 恢复。
$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$environment = getenv();
$backendHost = getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1';
$backendPort = getenv('TYPE_MYSQL_PORT') ?: '3306';
$name = 'type_outbox_fault_' . bin2hex(random_bytes(6));
$admin = Drivers::create('mysql')->connect();
$created = false;
$probe = null;
$applicationFiles = $target === '--php' ? outboxApplication($root) : null;
$command = $applicationFiles['command'] ?? nativeCommand($target);
$queueName = 'type_outbox_fault_' . bin2hex(random_bytes(8));
try {
    $admin->exec('CREATE DATABASE ' . $name);
    $created = true;
    $probe = Drivers::create('mysql', $name)->connect();
    $directEnvironment = array_replace($environment, ['TYPE_MYSQL_DATABASE' => $name, 'TYPE_OUTBOX_APPLICATION' => $queueName]);
    $run = static function (string $role, array $settings) use ($command, $root): string {
        $child = new Process([...$command, 'mysql', $role], $root, $settings);
        try {
            $result = $child->wait(60);
            expect($result->successful() && $result->stderr === '', 'Outbox故障角色失败：' . $role . ' ' . $result->stdout . $result->stderr);
            return $result->stdout;
        } finally {
            $child->stop();
        }
    };
    expect($run('setup-schema', $directEnvironment) === '', 'Outbox故障场景迁移失败');
    foreach (['before', 'after'] as $mode) {

        $proxyEnvironment = array_replace($environment, ['TYPE_PROXY_HOST' => $backendHost, 'TYPE_PROXY_PORT' => $backendPort, 'TYPE_PROXY_MODE' => $mode]);
        $proxy = new Process([PHP_BINARY, $root . '/tests/mysql-commit-proxy.php'], $root, $proxyEnvironment);
        $application = null;
        try {
            $deadline = microtime(true) + 5;
            do {
                $output = $proxy->stdout();
                if (str_contains($output, "\n")) {
                    break;
                }
                expect($proxy->running() && microtime(true) < $deadline, 'Outbox业务故障代理未就绪');
                usleep(1000);
            } while (true);
            $port = trim(explode("\n", $output)[0]);
            expect(ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535, '故障代理没有返回有效端口');
            $applicationEnvironment = array_replace($directEnvironment, ['TYPE_MYSQL_HOST' => '127.0.0.1', 'TYPE_MYSQL_PORT' => $port, 'TYPE_MYSQL_DATABASE' => $name]);
            $application = new Process([...$command, 'mysql', 'unknown'], $root, $applicationEnvironment);
            $result = $application->wait(60);
            expect($result->successful() && $result->stderr === '', 'Outbox业务没有按未知结果返回：' . $result->stdout . $result->stderr);
            expect(json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR) === ['outcome' => 'UNKNOWN', 'calls' => 1], 'Outbox业务误确认提交或重做业务');
            $proxied = $proxy->wait(10);
            $marker = $mode === 'before' ? 'dropped_before_commit' : 'commit_confirmed_and_dropped';
            expect($proxied->successful() && $proxied->stderr === '' && trim($proxied->stdout) === $port . "\n" . $marker, '没有真实切断指定COMMIT边界');
            $expected = $mode === 'before' ? 0 : 1;
            expect((int) $probe->query('SELECT COUNT(*) FROM type_outbox_business')->fetchColumn() === $expected
                && (int) $probe->query('SELECT COUNT(*) FROM type_outbox')->fetchColumn() === $expected, '未知提交的业务和消息意图未保持原子持久化');
            if ($mode === 'after') {
                expect($run('relay', $directEnvironment) === "消息发布及 token 标记通过。\n", '未知提交的持久意图未由Relay恢复');
                expect($run('consume-unknown', $directEnvironment) === "重复投递幂等消费与保留凭据通过。\n", '未知提交消息没有实际消费');
                expect((int) $probe->query('SELECT COUNT(*) FROM type_outbox_effects')->fetchColumn() === 1, '未知提交自动重做业务或未产生消费效果');
            }
        } finally {
            try {
                $application?->stop();
            } finally {
                $proxy->stop();
            }
        }
    }
    echo "Outbox真实COMMIT断线、UNKNOWN原子结果与独立Relay恢复通过。\n";
} finally {
    if ($applicationFiles !== null) {
        removeTestDirectory($applicationFiles['directory']);
    }
    cleanupOutboxQueue($queueName);
    $probe = null;
    if ($created) {
        $admin->exec('DROP DATABASE ' . $name);
    }
}
