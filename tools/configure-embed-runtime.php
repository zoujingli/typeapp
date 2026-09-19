<?php

declare(strict_types=1);

try {
    $directory = realpath($argv[1] ?? '') ?: throw new RuntimeException('需要已准备的 embed 运行库目录');
    $pcntl = $directory . '/pcntl.so';
    $mode = $argv[2] ?? '';
    if (!in_array($mode, ['base', 'shared'], true) || ($mode === 'shared' && !is_file($pcntl)) || PHP_VERSION !== '8.5.10' || !PHP_ZTS) {
        throw new RuntimeException('embed 运行库必须匹配锁定 PHP ZTS');
    }
    $ini = (string) (php_ini_loaded_file() === false ? '' : file_get_contents(php_ini_loaded_file()));
    foreach (explode(',', (string) php_ini_scanned_files()) as $file) {
        if (trim($file) !== '') {
            $ini .= "\n" . file_get_contents(trim($file));
        }
    }
    // 保留加载顺序；由真实 embed 探测决定是否需要额外共享 PCNTL。
    $filtered = '';
    foreach (preg_split('/\r\n|\r|\n/', $ini) as $line) {
        if (preg_match('/^\s*extension\s*=/i', $line)) {
            $directive = parse_ini_string($line);
            if ($directive === false) {
                throw new RuntimeException('无法解析原运行扩展声明');
            }
            if (in_array(strtolower(basename((string) array_values($directive)[0])), ['pcntl', 'pcntl.so'], true)) {
                continue;
            }
        }
        $filtered .= $line . "\n";
    }
    $ini = $filtered . "\nswoole.enable_library=Off\n";
    if ($mode === 'shared') {
        $ini .= "; TypePHP 原生进程的共享信号模块\nextension=" . $pcntl . "\n";
    }
    $file = $directory . '/php.ini';
    if (file_put_contents($file, $ini) !== strlen($ini) || !chmod($file, 0600)) {
        throw new RuntimeException('无法保存原生进程 ini');
    }
    echo $file . "\n";
} catch (Throwable $failure) {
    fwrite(STDERR, '原生运行配置失败：' . $failure->getMessage() . "\n");
    exit(1);
}
