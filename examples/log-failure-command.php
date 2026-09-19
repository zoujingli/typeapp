<?php

declare(strict_types=1);

use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Runtime\ExecutionScope;

function failureExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    $child = proc_open(['/bin/sleep', '10'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    failureExpect(is_resource($child), '无法准备不读取输入的真实管道进程');
    $pipe = $pipes[0];
    stream_set_blocking($pipe, false);
    $filled = false;
    for ($index = 0; $index < 8192; $index++) {
        if (fwrite($pipe, str_repeat('F', 4096)) === 0) {
            $filled = true;
            break;
        }
    }
    failureExpect($filled, '没有填满日志管道');
    $output = Output::stream($pipe, maxRecords: 3, maxBytes: 2048, maxRecordBytes: 1024);
    $logs = new LogManager('failure-test', ['app' => new Channel($output)], stopSeconds: 0.03);
    $scope = new ExecutionScope();
    try {
        $logger = $logs->logger($scope);
        for ($index = 0; $index < 20; $index++) {
            $logger->info('blocked-output', ['index' => $index]);
        }
        $logger->info(str_repeat('large-record', 1024));
        $stats = $output->stats();
        failureExpect($stats['pending_records'] === 3 && $stats['pending_bytes'] <= 2048 && $stats['dropped_full'] === 17
            && $stats['dropped_oversize'] === 1 && $stats['high_water_records'] === 3, '阻塞输出没有遵守条数、字节与单条边界');
        $started = hrtime(true);
        $logs->stop();
        $elapsed = (hrtime(true) - $started) / 1e9;
        $stats = $output->stats();
        failureExpect($elapsed >= 0.015 && $elapsed < 0.5 && $stats['dropped_stop'] === 3 && $stats['pending_records'] === 0
            && $stats['drain_timeouts'] === 1, '停止排空没有遵守总预算或丢弃计数');
    } finally {
        $scope->close();
        $logs->stop();
        fclose($pipe);
        proc_terminate($child);
        proc_close($child);
    }
    $recover = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    failureExpect($recover !== false, '无法准备恢复输出连接');
    stream_set_blocking($recover[0], false);
    stream_set_blocking($recover[1], false);
    $filled = false;
    for ($index = 0; $index < 8192; $index++) {
        if (fwrite($recover[0], str_repeat('F', 4096)) === 0) {
            $filled = true;
            break;
        }
    }
    failureExpect($filled, '没有填满恢复验证连接');
    $recovery = Output::stream($recover[0], maxRecords: 3, maxBytes: 2048, maxRecordBytes: 1024);
    $logs = new LogManager('recovery-test', ['app' => new Channel($recovery)]);
    $scope = new ExecutionScope();
    try {
        $logger = $logs->logger($scope);
        for ($index = 0; $index < 5; $index++) {
            $logger->info('queued', ['index' => $index]);
        }
        failureExpect($recovery->stats()['pending_records'] === 3 && $recovery->stats()['dropped_full'] === 2, '恢复前容量策略错误');
        while (fread($recover[1], 8192) !== '') {
        }
        $logs->drain(0.05);
        $wire = (string) stream_get_contents($recover[1]);
        $rows = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", rtrim($wire, "\n")));
        failureExpect(array_column(array_column($rows, 'context'), 'index') === [0, 1, 2]
            && $recovery->stats()['written'] === 3 && $recovery->stats()['pending_bytes'] === 0, '恢复后记录顺序、完整性或缓冲释放错误');
    } finally {
        $scope->close();
        $logs->stop();
        fclose($recover[0]);
        fclose($recover[1]);
    }
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    failureExpect($pair !== false, '无法准备断开的输出连接');
    $broken = Output::stream($pair[0]);
    fclose($pair[1]);
    $logs = new LogManager('failure-test', ['app' => new Channel($broken)]);
    $scope = new ExecutionScope();
    try {
        $logger = $logs->logger($scope);
        $logger->error('broken-output');
        $logger->error('retired-output');
        $stats = $broken->stats();
        failureExpect($stats['failed'] && $stats['write_failures'] === 1 && $stats['dropped_failure'] === 2 && $stats['pending_records'] === 0, '断开输出没有退役或失败计数错误');
    } finally {
        $scope->close();
        $logs->stop();
        fclose($pair[0]);
    }

    if (is_file('/dev/full') || file_exists('/dev/full')) {
        $full = fopen('/dev/full', 'wb');
        failureExpect(is_resource($full), '无法准备真实满设备');
        $output = Output::stream($full);
        $logs = new LogManager('failure-test', ['app' => new Channel($output)]);
        $scope = new ExecutionScope();
        try {
            $logs->logger($scope)->critical('disk-full');
            failureExpect($output->stats()['write_failures'] === 1 && $output->stats()['dropped_failure'] === 1, '满设备错误没有计数');
        } finally {
            $scope->close();
            $logs->stop();
            fclose($full);
        }
    }
    $missing = sys_get_temp_dir() . '/type_log_missing_' . bin2hex(random_bytes(8)) . '/log.jsonl';
    $output = Output::file($missing);
    $logs = new LogManager('failure-test', ['app' => new Channel($output)]);
    $scope = new ExecutionScope();
    try {
        $logs->logger($scope)->error('unavailable-file');
        failureExpect($output->stats()['failed'] && $output->stats()['write_failures'] === 1 && !is_dir(dirname($missing)), '不可用文件没有退役或偷偷创建输出目录');
    } finally {
        $scope->close();
        $logs->stop();
    }
    echo "日志真实管道满载、停止预算、断开输出与文件失败计数通过。\n";
}
