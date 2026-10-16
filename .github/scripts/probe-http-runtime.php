<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tests/support.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Build\RuntimeProfile;
use Type\Testing\Process;

// 临时诊断只复演已保存的原 EXE，不重新编译应用或改变运行库。
expect(PHP_OS_FAMILY === 'Windows' && getenv('GITHUB_ACTIONS') === 'true', '需要独立 Windows runner');
$root = BuildPlatform::resolve(dirname(__DIR__, 2));
$work = $root . '/build/http-runtime-control';
expect(!file_exists($work) && mkdir($work, 0700), '诊断目录已存在');
$original = $root . '/build/original-http-evidence/http-assembly-f1ae264231cf/build/http-assembly.exe';
$digest = '4e7d783fa45b18fb9441d2556d0073fb46daa04ed3e922589fde20545f1fb114';
expect(hash_file('sha256', $original) === $digest, '原程序字节不一致');
$build = json_decode(file_get_contents($original . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
$libraryChecks = [];
foreach ($build['identity']['description']['inputs'] as $records) {
    foreach ($records as $path => $record) {
        $name = basename(str_replace('\\', '/', $path));
        if (in_array($name, ['php_swoole.dll', 'php_curl.dll', 'php_sockets.dll'], true)) {
            $file = getenv('PHP_HOME') . '/ext/' . $name;
            expect(hash_file('sha256', $file) === $record['sha256'], '原运行库字节不一致：' . $name);
            $libraryChecks[$name] = $record['sha256'];
        }
    }
}
expect(count($libraryChecks) === 3, '原报告未覆盖全部共享扩展');
$report = ['run' => 38844063772, 'source' => '6de842c694cc2a7a5dc025b5633b2038179c18fe',
    'program-sha256' => $digest, 'module-sha256' => $libraryChecks, 'cases' => [], 'passed' => false];
$profiles = [
    'shared' => ['openssl', 'pdo', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'sockets', 'swoole'],
    'selected' => array_keys($build['runtime-profile']['extensions']),
];
try {
    foreach ($profiles as $label => $requirements) {
        $profile = (new RuntimeProfile())->prepare($root, $work . '/' . $label, getenv('PHP_HOME'), getenv('PHPX_HOME'), $requirements);
        $environment = getenv();
        $environment['PHPRC'] = $profile['ini'];
        $environment['PHP_INI_SCAN_DIR'] = dirname($profile['ini']) . '/php.d';
        expect(array_values(array_diff(scandir($environment['PHP_INI_SCAN_DIR']), ['.', '..'])) === [], '扫描目录非空');
        $control = (new Process([PHP_BINARY, '-r', 'echo "controller_ready";'], sys_get_temp_dir(), $environment))->wait(10);
        expect($control->successful() && $control->stdout === 'controller_ready' && $control->stderr === '', '控制器无法加载该运行配置');
        foreach ([1, 2] as $round) {
            $started = microtime(true);
            $result = (new Process([$original, 'show'], sys_get_temp_dir(), $environment))->wait(10);
            file_put_contents($work . '/' . $label . '-' . $round . '.log', $result->stdout . $result->stderr);
            $report['cases'][] = ['profile' => $label, 'round' => $round, 'seconds' => microtime(true) - $started,
                'exit' => $result->exitCode, 'timed-out' => $result->timedOut, 'signal' => $result->signal,
                'stdout' => $result->stdout, 'stderr' => $result->stderr, 'ini-sha256' => hash_file('sha256', $profile['ini'])];
            expect(!$result->timedOut && !$result->outputExceeded, '对照没有正常结束');
            if ($label === 'shared') {
                expect(!$result->successful() && str_contains($result->stdout . $result->stderr, 'Cannot redefine class constant PDO::PGSQL_ATTR_DISABLE_PREPARES'), '未复现原启动失败');
            } else {
                expect($result->successful() && trim($result->stdout) === 'application' && $result->stderr === '', '程序自身配置未通过');
            }
        }
        if ($label === 'selected') {
            $scopes = (new Process([$original, 'scopes'], sys_get_temp_dir(), $environment))->wait(15);
            expect($scopes->successful() && trim($scopes->stdout) === 'child scopes passed' && $scopes->stderr === '', '子作用域行为未通过');
        }
    }
    expect(hash_file('sha256', $original) === $digest, '对照期间原程序发生改变');
    $report['passed'] = true;
    echo "原程序两轮全量配置失败、两轮自身配置成功；子作用域通过，程序与共享模块摘要不变。\n";
} finally {
    file_put_contents($work . '/result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
