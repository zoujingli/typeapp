<?php

declare(strict_types=1);
require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

$root = realpath(dirname(__DIR__));
expect($argc === 5 && in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true), '用法：PHP tests/benchmark-pairs.php <旧版准备根> <新版准备根> <MySQL工具根> <PostgreSQL工具根>');
$old = realpath($argv[1]);
$new = realpath($argv[2]);
$mysql = realpath($argv[3]);
$pgsql = realpath($argv[4]);
expect(is_string($old) && is_string($new) && is_string($mysql) && is_string($pgsql) && $old !== $new, '需要不同版本的已准备目录及真实数据库工具');
foreach ([$old, $new] as $directory) {
    foreach (['project', 'stream'] as $role) {
        $artifact = $directory . '/' . $role . '/build/benchmark/type-app';
        (new BuildPlatform())->assertArtifact($artifact);
        $build = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
        expect(hash_file('sha256', $artifact) === $build['sha256'], '成对测量产物摘要不符');
    }
}
$base = $root . '/build/benchmark-pairs-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建成对测量目录');
$record = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'controller_sha256' => hash_file('sha256', $root . '/tests/application-benchmark.php'), 'runs' => []];
try {
    foreach (['stream', 'swoole'] as $engine) {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            foreach (['old' => $old, 'new' => $new] as $version => $directory) {
                $phpx = is_dir($directory . '/phpx') ? $directory . '/phpx' : realpath(getenv('PHPX_HOME'));
                $environment = (new BuildPlatform())->environment(realpath(getenv('PHP_HOME')), $phpx);
                $environment['PHPRC'] = realpath(getenv('PHPRC'));
                $environment['PHP_INI_SCAN_DIR'] = $base;
                $command = [PHP_BINARY, $root . '/tests/application-benchmark.php',
                    '--binary', $directory . '/project/build/benchmark/type-app',
                    '--stream-binary', $directory . '/stream/build/benchmark/type-app',
                    '--driver', $driver, '--engine', $engine, '--repetitions', '3', '--iterations', '100', '--warmup', '10'];
                if ($driver !== 'sqlite') {
                    $command = [...$command, '--database-tools', $driver === 'mysql' ? $mysql : $pgsql];
                }
                $label = $engine . '-' . $driver . '-' . $version;
                echo '正式成对测量：' . $label . "\n";
                $process = new Process($command, $root, $environment, 4194304);
                try {
                    $result = $process->wait(600);
                } finally {
                    $process->stop();
                }
                file_put_contents($base . '/' . $label . '.log', $result->stdout . $result->stderr);
                expect($result->successful(), '正式测量失败，见本轮日志：' . $label);
                expect(preg_match('#真实应用四类负载测量完成：(build/[^\\r\\n]+)#u', $result->stdout, $matches) === 1, '缺少测量报告');
                $report = trim($matches[1]);
                $measurement = json_decode(file_get_contents($root . '/' . $report), true, 512, JSON_THROW_ON_ERROR);
                expect($measurement['status'] === 'passed' && $measurement['engine'] === $engine && $measurement['driver'] === $driver, '测量身份不符');
                $record['runs'][] = ['version' => $version, 'engine' => $engine, 'driver' => $driver,
                    'report' => $report, 'sha256' => hash_file('sha256', $root . '/' . $report), 'measurement' => $measurement];
                file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            }
        }
    }
    $record['status'] = 'measured-not-compared';
} finally {
    if ($record['status'] === 'running') {
        $record['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
echo '成对正式测量完成：' . substr($base, strlen($root) + 1) . "/verification.json\n";
