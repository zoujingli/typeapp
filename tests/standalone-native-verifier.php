<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$directory = $root . '/build/standalone-verifier-' . bin2hex(random_bytes(6));
expect(mkdir($directory . '/tests', 0700, true) && mkdir($directory . '/plugin/type-build/src', 0700, true), '无法准备独立控制器');
copy(__DIR__ . '/support.php', $directory . '/tests/support.php');
copy($root . '/plugin/type-build/src/BuildPlatform.php', $directory . '/plugin/type-build/src/BuildPlatform.php');
$binary = PHP_OS_FAMILY === 'Windows' ? getenv('SystemRoot') . '/System32/whoami.exe' : '/bin/echo';
$arguments = PHP_OS_FAMILY === 'Windows' ? [] : ['standalone-verifier'];
$code = 'require ' . var_export($directory . '/tests/support.php', true)
    . '; $command=nativeCommand(' . var_export($binary, true) . '); $result=execute([...$command,...' . var_export($arguments, true) . ']); exit($result[0]);';
expect(!is_dir($directory . '/vendor'), '独立控制器不能安装Composer依赖');
successful([PHP_BINARY, '-r', $code], $directory);
echo "无Composer的独立原生格式校验与命令执行通过。\n";
