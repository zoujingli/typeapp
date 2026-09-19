<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

/** 返回实际 embed 的函数表，不借用 PHP CLI 的静态扩展清单。 */
function embedProbe(string $probe, string $directory): array
{
    return execute(['env', 'PHPRC=' . $directory . '/php.ini', 'PHP_INI_SCAN_DIR=' . $directory . '/php.d', $probe]);
}

function embedExpectReady(string $probe, string $directory): void
{
    [$status, $stdout, $stderr] = embedProbe($probe, $directory);
    expect($status === 0 && trim($stdout) === '1 1 1 1' && $stderr === '', 'embed 信号模块/函数缺失或存在启动警告：' . $stdout . $stderr);
}

$root = dirname(__DIR__);
expect(PHP_OS_FAMILY === 'Linux' && PHP_VERSION === '8.5.10' && PHP_ZTS, 'embed 验收必须使用锁定 Linux PHP ZTS');
$sdk = getenv('PHP_HOME');
expect($sdk !== false && is_file($sdk . '/lib/libphp.so'), '需要当前 PHP embed SDK');
$prepared = trim(successful(['bash', $root . '/tools/prepare-embed-runtime.sh']));
$preparedDirectory = dirname($prepared);
$probe = $preparedDirectory . '/probe';
embedExpectReady($probe, $preparedDirectory);
[$extensionsStatus, $extensionsOutput, $extensionsError] = execute(['env', 'PHPRC=' . $prepared,
    'PHP_INI_SCAN_DIR=' . $preparedDirectory . '/php.d', $probe, '--extensions']);
expect($extensionsStatus === 0 && $extensionsError === '' && in_array('pcntl', explode("\n", trim($extensionsOutput)), true), '实际 embed 扩展清单未包含 PCNTL');
[$repeatStatus, $repeatOutput, $repeatError] = execute(['bash', $root . '/tools/prepare-embed-runtime.sh']);
expect($repeatStatus === 0 && trim($repeatOutput) === $prepared && $repeatError === '', '重复准备运行配置不稳定或产生启动警告：' . $repeatOutput . $repeatError);
embedExpectReady($probe, $preparedDirectory);

$directory = $root . '/build/embed-runtime-check-' . bin2hex(random_bytes(5));
expect(mkdir($directory . '/php.d', 0700, true), '无法创建独立 embed 配置目录');
$fixtureDirectory = $directory . '/chinese-ini';
expect(mkdir($fixtureDirectory . '/php.d', 0700, true), '无法创建中文注释回归目录');
$chineseComment = '; TypePHP 原生进程的共享信号模块';
expect(file_put_contents($fixtureDirectory . '/php.ini', $chineseComment . "\r\ndate.timezone=UTC\n") !== false, '无法保存中文配置夹具');
[$chineseStatus, $chineseOutput, $chineseError] = execute(['env', 'PHPRC=' . $fixtureDirectory . '/php.ini',
    'PHP_INI_SCAN_DIR=' . $fixtureDirectory . '/php.d', PHP_BINARY, $root . '/tools/configure-embed-runtime.php', $directory, 'base']);
expect($chineseStatus === 0 && $chineseError === '', '中文配置复制失败：' . $chineseOutput . $chineseError);
$chineseIni = file_get_contents($directory . '/php.ini');
expect(str_contains($chineseIni, $chineseComment . "\n") && parse_ini_string($chineseIni) !== false, 'UTF-8 中文注释被错误拆为物理行');
successful([PHP_BINARY, $root . '/tools/configure-embed-runtime.php', $directory, 'base']);
[$baseStatus, $baseOutput, $baseError] = embedProbe($probe, $directory);
expect($baseStatus === 0 && $baseError === '' && in_array(trim($baseOutput), ['0 0 0 0', '1 1 1 1'], true), '基础 embed 配置存在部分模块、残留动态 PCNTL 或启动警告');
$baseIni = file_get_contents($directory . '/php.ini');
expect(preg_match('/^\s*extension\s*=.*pcntl/mi', $baseIni) === 0, '基础配置仍包含 PCNTL 加载指令');

$preloadedFixture = false;
if (trim($baseOutput) === '0 0 0 0') {
    expect(copy($preparedDirectory . '/pcntl.so', $directory . '/pcntl.so'), '无法复制锁定共享 PCNTL');
    successful([PHP_BINARY, $root . '/tools/configure-embed-runtime.php', $directory, 'shared']);
    embedExpectReady($probe, $directory);
    $sharedIni = file_get_contents($directory . '/php.ini');
    expect(preg_match_all('/^\s*extension\s*=.*pcntl/mi', $sharedIni) === 1, '共享配置应只加载一次 PCNTL');

    // 原来无条件加载共享模块的方案在预置 PCNTL 的 embed 上必然产生真实重复模块警告。
    $includes = preg_split('/\s+/', trim(successful([$sdk . '/bin/php-config', '--includes'])));
    $preloadedProbe = $directory . '/preloaded-probe';
    successful(['cc', ...$includes, $root . '/tests/fixtures/embed-preloaded-pcntl.c', '-L' . $sdk . '/lib',
        '-Wl,-rpath,' . $sdk . '/lib', '-lphp', $directory . '/pcntl.so', '-Wl,-rpath,' . $directory, '-o', $preloadedProbe]);
    [$duplicateStatus, $duplicateOutput, $duplicateError] = embedProbe($preloadedProbe, $directory);
    expect(str_contains($duplicateOutput . $duplicateError, 'Module "pcntl" is already loaded'), '预置模块场景没有重现重复 PCNTL 加载缺陷');
    expect(file_put_contents($directory . '/php.ini', $baseIni) === strlen($baseIni), '无法恢复基础 embed 配置');
    embedExpectReady($preloadedProbe, $directory);
    $preloadedFixture = true;

    expect(file_put_contents($directory . '/php.ini', $sharedIni) === strlen($sharedIni), '无法恢复共享 embed 配置');
} else {
    embedExpectReady($probe, $directory);
}

// PCNTL 模块存在但必要函数被禁用，也不能误判为原生可用。
$validIni = file_get_contents($directory . '/php.ini');
$disabledIni = $validIni . "\ndisable_functions=pcntl_fork\n";
expect(file_put_contents($directory . '/php.ini', $disabledIni) === strlen($disabledIni), '无法准备函数禁用配置');
[$disabledStatus, $disabledOutput, $disabledError] = embedProbe($probe, $directory);
expect($disabledStatus === 0 && trim($disabledOutput) === '1 0 1 1' && $disabledError === '', 'embed 探测未准确区分模块存在与函数缺失');
expect(file_put_contents($directory . '/php.ini', $validIni) === strlen($validIni), '无法恢复可用 embed 配置');
embedExpectReady($probe, $directory);

echo '实际 embed 的 PCNTL、fork、signal、async_signals 与重复准备验收通过；预置模块回归：'
    . ($preloadedFixture ? '真实模块夹具已覆盖' : '当前 embed 原生预置已覆盖') . "。\n";
