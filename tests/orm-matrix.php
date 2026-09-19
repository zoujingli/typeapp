<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

// 一次只验收一种真实驱动；随机数据库、进程和输出目录由本轮拥有。
$root = dirname(__DIR__);
$driver = $argv[1] ?? 'sqlite';
$mode = $argv[2] ?? '--php';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '请选择真实数据库驱动');
expect(in_array($mode, ['--php', '--native'], true), '请选择 PHP 或原生独立消费模式');
$tools = $driver === 'sqlite' ? [] : NativeDatabase::tools($driver, $argv[3] ?? '');
$work = $root . '/build/orm-matrix-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700), '无法创建独立矩阵目录');
$database = null;
try {
    $database = new NativeDatabase($work . '/database', $driver, $tools);
    $environment = array_replace(getenv(), $database->environment());
    $environment['PATH'] = dirname(PHP_BINARY) . PATH_SEPARATOR . (string) getenv('PATH');
    $tests = $mode === '--native' ? [] : ['models', 'relations', 'exact-fields', 'lifecycle', 'optimistic', 'pivots', 'transactions', 'outcomes', 'pagination', 'model-http'];
    foreach ($tests as $test) {
        if ($test === 'model-http' && $driver === 'pgsql') {
            continue; // 既有 HTTP 入口只接受 MySQL/SQLite，PostgreSQL 运行同一模型与独立业务回归。
        }
        $process = new Type\Testing\Process([PHP_BINARY, $root . '/tests/' . $test . '.php', '--php', $driver], $root, $environment);
        try {
            $result = $process->wait(90);
            file_put_contents($work . '/' . $test . '.log', $result->stdout . $result->stderr);
            expect($result->successful(), $test . ' 回归失败：' . $result->stdout . $result->stderr);
            echo $result->stdout;
        } finally {
            $process->stop();
        }
    }
    // 原生编译可能超过普通 Process 的输出上限，原始构建日志由消费者保存在专用目录。
    $consumer = new Type\Testing\Process([PHP_BINARY, $root . '/tests/orm-suite-consumer.php', $driver, $mode], $root, $environment);
    try {
        $result = $consumer->wait(1800);
        file_put_contents($work . '/consumer.log', $result->stdout . $result->stderr);
        expect($result->successful(), '独立消费者失败：' . $result->stdout . $result->stderr);
        echo $result->stdout;
    } finally {
        $consumer->stop();
    }
} finally {
    $database?->close();
}
