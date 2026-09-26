<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/release/Plan.php';

use TypeApp\Distribution\Process;
use TypeApp\Release\Plan;

try {
    $root = dirname(__DIR__);
    $version = $argv[1] ?? '';
    Plan::version($version);
    $directory = $root . '/build/template-packagist-' . bin2hex(random_bytes(6));
    $phar = getenv('TYPE_COMPOSER_PHAR');
    $composer = is_string($phar) && $phar !== '' ? [PHP_BINARY, $phar] : [getenv('COMPOSER_BINARY') ?: 'composer'];
    Process::output([...$composer, 'create-project', '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist',
        'zoujingli/type-project', $directory, substr($version, 1)], $root);
    // 不设置repositories；下游在配置前逐文件核对同一tag的模板树，再从默认Packagist安装依赖。
    echo $directory . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
