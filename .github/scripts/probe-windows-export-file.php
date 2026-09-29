<?php

declare(strict_types=1);

// 原 EXE 导出故障的最小文件语义诊断；只使用本轮目录，不访问应用数据。
$directory = dirname(__DIR__, 2) . '/build/static-windows-application-evidence';
$path = $directory . '/export-file-probe.bin';
$payload = str_repeat("export-file-probe\n", 512);
$expected = hash('sha256', $payload);
$results = [];
$probe = static function (string $mode) use ($path, $payload, $expected, &$results): void {
    $file = fopen($path, 'x+b');
    if ($file === false) {
        throw new RuntimeException('probe_open_failed');
    }
    $warnings = [];
    try {
        if (fwrite($file, $payload) !== strlen($payload) || !fflush($file) || !fsync($file)) {
            throw new RuntimeException('probe_write_failed');
        }
        $before = hash_file('sha256', $path);
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('probe_lock_failed');
        }
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $separate = hash_file('sha256', $path);
            if (fseek($file, 0) !== 0) {
                throw new RuntimeException('probe_seek_failed');
            }
            $hash = hash_init('sha256');
            $read = hash_update_stream($hash, $file);
            $same = hash_final($hash);
        } finally {
            restore_error_handler();
        }
        flock($file, LOCK_UN);
        $after = hash_file('sha256', $path);
        $results[$mode] = ['before-lock-valid' => $before === $expected,
            'separate-handle-while-locked-valid' => $separate === $expected,
            'same-handle-while-locked-valid' => $same === $expected,
            'same-handle-bytes' => $read, 'expected-bytes' => strlen($payload),
            'after-unlock-valid' => $after === $expected, 'warnings' => $warnings];
    } finally {
        fclose($file);
        unlink($path);
    }
};
$probe('plain');
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_FILE);
$scheduler = new Swoole\Coroutine\Scheduler();
$scheduler->set(['hook_flags' => SWOOLE_HOOK_FILE]);
$scheduler->add(static function () use ($probe): void {
    $probe('coroutine-file-hook');
});
$scheduler->start();
$report = ['php' => PHP_VERSION, 'swoole' => phpversion('swoole'), 'platform' => PHP_OS_FAMILY, 'results' => $results];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
file_put_contents($directory . '/export-file-probe.json', $json);
echo $json;
