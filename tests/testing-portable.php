<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

// 公开进程接口的同一套行为在三个 OS 执行；不伪造 PHP_OS_FAMILY。
$command = [PHP_BINARY, '-r', 'echo $argv[1]; for ($i = 0; $i < 80; $i++) { fwrite(STDOUT, str_repeat("a", 8192)); fwrite(STDERR, str_repeat("b", 8192)); } exit(7);', '中文 空格 $(不执行) "引号"'];
$process = new Process($command);
$result = $process->wait(5);
expect($result->exitCode === 7 && $result->stdout === $command[3] . str_repeat('a', 655360)
    && $result->stderr === str_repeat('b', 655360), '双输出、原样参数或退出码丢失');
expect($process->wait() === $result && $process->stop() === $result && !$process->running(), '结束后的进程状态不稳定');
$started = microtime(true);
$process = new Process([PHP_BINARY, '-r', 'while (true) { usleep(10000); }']);
$childPid = $process->pid();
expect(is_int($childPid) && $childPid > 0 && $childPid !== getmypid(), '运行进程没有提供实际子进程PID');
$timed = $process->wait(0.1);
expect($timed->timedOut && !$process->running() && microtime(true) - $started < 4, '无输出进程阻塞了等待截止');
expect($process->pid() === null, '结束后的进程仍返回可能已被复用的PID');
$process = new Process([PHP_BINARY, '-r', 'while (true) { fwrite(STDOUT, str_repeat("x", 8192)); fwrite(STDERR, str_repeat("y", 8192)); }'], maximumBytes: 1024);
$limited = $process->wait(3);
expect($limited->outputExceeded && strlen($limited->stdout) + strlen($limited->stderr) === 1024 && !$process->running(), '输出超过限制后未确认终止');
$inputDirectory = dirname(__DIR__) . '/build/process-input-' . bin2hex(random_bytes(6));
expect(mkdir($inputDirectory . '/child', 0700, true), '无法创建文件输入测试目录');
$inputFile = $inputDirectory . '/输入 空格 $(literal).bin';
$inputBytes = "\0\r\n\xff" . random_bytes(262144) . "\n\0";
file_put_contents($inputFile, $inputBytes);
chmod($inputFile, 0600);
$inputHash = hash('sha256', $inputBytes);
$process = new Process([PHP_BINARY, '-r', '$h=hash_init("sha256");hash_update_stream($h,STDIN);echo hash_final($h);'], $inputDirectory . '/child', null, 1024, $inputFile);
try {
    $read = $process->wait(5);
    expect($read->successful() && $read->stdout === $inputHash && $read->stderr === '', '标准输入没有原样传递完整二进制文件');
} finally {
    $process->stop();
}
expect(hash_file('sha256', $inputFile) === $inputHash, '子进程改写了输入文件');
foreach ([$inputDirectory, $inputDirectory . '/missing', "bad\0path"] as $invalidInput) {
    $rejected = false;
    try {
        new Process([PHP_BINARY, '-r', 'exit(0);'], null, null, 1024, $invalidInput);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    expect($rejected, '不存在、目录或NUL输入路径没有在启动前拒绝');
}
file_put_contents($inputDirectory . '/verification.json', json_encode(['platform' => PHP_OS_FAMILY, 'input-sha256' => $inputHash,
    'checks' => ['literal-arguments', 'bounded-dual-output', 'deadline-and-stop', 'binary-file-stdin', 'child-cwd-independent', 'input-unchanged', 'invalid-stdin-rejected']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo PHP_OS_FAMILY . ' 进程参数、双输出、截止、只读文件输入与清理通过：' . $inputDirectory . "/verification.json\n";
