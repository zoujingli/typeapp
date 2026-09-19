<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

use Type\Testing\Process;
use TypeApp\ModelExample\Drivers;

// 同一MySQL协议代理观察生成服务；只在本轮新建数据库中核对持久化结果。
$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$environment = getenv();
$backendHost = getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1';
$backendPort = getenv('TYPE_MYSQL_PORT') ?: '3306';
$name = 'type_operation_fault_' . bin2hex(random_bytes(6));
$admin = Drivers::create('mysql')->connect();
$created = false;
$probe = null;
try {
    $admin->exec('CREATE DATABASE ' . $name);
    $created = true;
    $probe = Drivers::create('mysql', $name)->connect();
    $probe->exec('CREATE TABLE operation_users (id INTEGER PRIMARY KEY, name VARCHAR(100) NOT NULL)');
    $probe->exec("INSERT INTO operation_users VALUES (1, 'before')");
    foreach (['before', 'after'] as $mode) {
        $probe->exec("UPDATE operation_users SET name = 'before'");
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
                expect($proxy->running() && microtime(true) < $deadline, '生成服务故障代理未就绪');
                usleep(1000);
            } while (true);
            $port = trim(explode("\n", $output)[0]);
            expect(ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535, '故障代理没有返回有效端口');
            $applicationEnvironment = array_replace($environment, ['TYPE_MYSQL_HOST' => '127.0.0.1', 'TYPE_MYSQL_PORT' => $port, 'TYPE_MYSQL_DATABASE' => $name]);
            $application = new Process([PHP_BINARY, $root . '/tests/operations.php', $target, 'mysql', 'unknown'], $root, $applicationEnvironment);
            $result = $application->wait(60);
            expect($result->successful() && $result->stderr === '', '生成服务没有按未知结果返回：' . $result->stdout . $result->stderr);
            expect(json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR) === ['outcome' => 'UNKNOWN', 'cached' => 'before', 'calls' => 1], '生成服务误确认提交、清理缓存或重做业务');
            $proxied = $proxy->wait(10);
            $marker = $mode === 'before' ? 'dropped_before_commit' : 'commit_confirmed_and_dropped';
            expect($proxied->successful() && $proxied->stderr === '' && trim($proxied->stdout) === $port . "\n" . $marker, '没有真实切断指定COMMIT边界');
            expect($probe->query('SELECT name FROM operation_users WHERE id = 1')->fetchColumn() === ($mode === 'before' ? 'before' : 'after'), '未知提交没有保留可核对的真实持久化结果');
        } finally {
            try {
                $application?->stop();
            } finally {
                $proxy->stop();
            }
        }
    }
    echo "生成服务的真实COMMIT断线、单次业务与未知提交缓存保留通过。\n";
} finally {
    $probe = null;
    if ($created) {
        $admin->exec('DROP DATABASE ' . $name);
    }
}
