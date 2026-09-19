<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\BuildPlatform;

$privateNames = ['APP_API_TOKEN', 'PHP_HOME', 'PHPX_HOME', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_PRELOAD', 'DYLD_INSERT_LIBRARIES'];
$names = [...$privateNames, 'PATH', 'LD_LIBRARY_PATH', 'DYLD_LIBRARY_PATH'];
$previous = [];
$previousTemporary = getenv('TMPDIR');
$temporary = dirname(__DIR__) . '/build/php-tool-tmp-' . bin2hex(random_bytes(6));
expect(mkdir($temporary, 0700), '无法创建工具专用临时目录');
if (PHP_OS_FAMILY !== 'Windows') {
    putenv('TMPDIR=' . $temporary);
}
foreach ($names as $name) {
    $previous[$name] = getenv($name);
    putenv($name . '=php-tool-secret-sentinel');
}
try {
    $environment = (new BuildPlatform())->phpEnvironment();
    foreach ($privateNames as $name) {
        expect(!isset($environment[$name]), 'PHP工具环境泄漏业务值或把运行前缀当作构建SDK');
    }
    expect(!str_contains(json_encode($environment, JSON_THROW_ON_ERROR), 'php-tool-secret-sentinel'), 'PHP工具环境夹带外部哨兵值');
    $output = (new BuildEnvironment())->run([PHP_BINARY, '-r', 'echo json_encode([PHP_VERSION,PHP_ZTS,getenv("APP_API_TOKEN"),getenv("PHP_HOME"),getenv("PHPRC"),getenv("LD_PRELOAD")]);'], dirname(__DIR__), $environment, 10, null, true);
    expect(json_decode($output, true, 8, JSON_THROW_ON_ERROR) === [PHP_VERSION, PHP_ZTS, false, false, false, false], '当前PHP不能启动或运行环境没有隔离');
    if (PHP_OS_FAMILY !== 'Windows') {
        $actualTemporary = (new BuildEnvironment())->run([PHP_BINARY, '-r', 'echo realpath(sys_get_temp_dir());'], dirname(__DIR__), $environment, 10, null, true);
        expect($actualTemporary === realpath($temporary), '嵌套工具没有使用显式私有临时目录');
        putenv('TMPDIR=' . $temporary . '/missing');
        $invalidTemporary = false;
        try {
            (new BuildPlatform())->phpEnvironment();
        } catch (RuntimeException) {
            $invalidTemporary = true;
        }
        expect($invalidTemporary, '不存在的临时目录被传给构建工具');
        putenv('TMPDIR=' . $temporary);
    }
    $foreignRejected = false;
    try {
        (new BuildPlatform(PHP_OS_FAMILY === 'Linux' ? 'Darwin' : 'Linux'))->phpEnvironment();
    } catch (RuntimeException) {
        $foreignRejected = true;
    }
    expect($foreignRejected, '当前PHP环境被错误用于另一个平台');
    echo PHP_OS_FAMILY . " 当前PHP运行前缀、无业务环境与无SDK伪注入通过。\n";
} finally {
    foreach ($previous as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    putenv($previousTemporary === false ? 'TMPDIR' : 'TMPDIR=' . $previousTemporary);
    rmdir($temporary);
}
