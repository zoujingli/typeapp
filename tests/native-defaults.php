<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$fixture = $argv[1] ?? 'nested-default';
expect(in_array($fixture, ['nested-default', 'interface-constants', 'declared-tostring'], true), '未知的值语义回归夹具');
$work = $root . '/build/native-values-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700, true), '无法创建原生值语义回归目录');
$source = $work . '/input.php';
$binary = $work . '/defaults' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
$complete = false;
try {
    expect(copy(__DIR__ . '/fixtures/' . $fixture . '.php', $source), '无法复制值语义行为夹具');
    [$phpStatus, $expected, $phpError] = execute([PHP_BINARY, '-n', '-r', 'require $argv[1]; main();', $source], $work);
    expect($phpStatus === 0 && $phpError === '', '值语义 PHP 对照失败：' . $expected . $phpError);
    file_put_contents($work . '/php.json', $expected);
    [$compileStatus, $compileOutput, $compileError] = execute([
        PHP_BINARY, $root . '/vendor/bin/type-compiler', $source,
        '--mode', 'bin', '--output', $binary, '--build-dir', $work . '/compiler',
        '-O2', '--job', '2', '--no-progress', '--no-color',
    ], $root);
    file_put_contents($work . '/build.stdout.log', $compileOutput);
    file_put_contents($work . '/build.stderr.log', $compileError);
    expect($compileStatus === 0, '值语义原生编译失败，证据保留于 ' . $work);
    // 成功编译后删除本次输入，再从不同目录执行同一字节产物，不能回读 PHP 夹具。
    expect(unlink($source), '无法删除已编译的测试输入');
    expect(mkdir($work . '/php.d', 0700) && file_put_contents($work . '/runtime.ini', "display_errors=stderr\n") !== false, '无法创建隔离运行配置');
    $environment = getenv();
    $environment['PHPRC'] = $work . '/runtime.ini';
    $environment['PHP_INI_SCAN_DIR'] = $work . '/php.d';
    [$nativeStatus, $actual, $nativeError] = execute(nativeCommand($binary), $work, $environment);
    file_put_contents($work . '/native.stdout.log', $actual);
    file_put_contents($work . '/native.stderr.log', $nativeError);
    expect($nativeStatus === 0 && $nativeError === '' && $actual === $expected, '原生值语义与 PHP 不符，证据保留于 ' . $work);
    $complete = true;
    echo $fixture . '：PHP/AOT 公开行为与无输入源码运行检查通过。' . PHP_EOL;
} finally {
    if ($complete) {
        removeTestDirectory($work);
    }
}
