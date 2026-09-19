<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$command = nativeCommand($argv[1] ?? '');
$driver = $argv[2] ?? 'mysql';
$labels = ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'];
expect(isset($labels[$driver]), '未知数据库验证目标');
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === $labels[$driver] . " 原生读写、事务与连接池验证通过。\n" && $stderr === '', '原生数据库验证失败：' . $stdout . $stderr);
echo $stdout;
if ($driver === 'sqlite') {
    $file = tempnam(sys_get_temp_dir(), 'type_app_recovery_');
    expect($file !== false, '无法创建恢复测试文件');
    try {
        $capture = tmpfile();
        expect($capture !== false, '无法建立崩溃进程输出缓冲');
        $process = proc_open([...$command, 'test-crash-write', $file], [0 => ['file', '/dev/null', 'r'], 1 => $capture, 2 => $capture], $pipes);
        expect(is_resource($process), '无法启动崩溃测试进程');
        $deadline = microtime(true) + 10.0;
        do {
            $state = proc_get_status($process);
            if ($state['running']) {
                usleep(10000);
            }
        } while ($state['running'] && microtime(true) < $deadline);
        if ($state['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
        rewind($capture);
        $crashOutput = stream_get_contents($capture);
        fclose($capture);
        expect(!$state['running'] && $state['signaled'] && $state['termsig'] === 9, '没有证明确实发生 SIGKILL：' . $crashOutput);
        expect(successful([...$command, 'test-recover', $file]) === "SQLite WAL 崩溃恢复验证通过。\n", 'SQLite 无法恢复已提交 WAL');
        echo "SQLite WAL 崩溃恢复验证通过。\n";
    } finally {
        foreach ([$file, $file . '-wal', $file . '-shm'] as $generated) {
            if (is_file($generated)) {
                unlink($generated);
            }
        }
    }
}
