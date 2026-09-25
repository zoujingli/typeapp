<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

// 发布包按同一help功能及固定文件规模成对测量；校验本身不纳入计时控制器开销。
if (($argv[1] ?? '') === '--package') {
    $directory = realpath($argv[2] ?? '');
    $budget = (float) ($argv[3] ?? '3');
    $samples = (int) ($argv[4] ?? '7');
    expect($directory !== false && is_dir($directory), '必须传入真实发布目录');
    expect(is_finite($budget) && $budget > 0 && $budget <= 60 && $samples >= 5 && $samples <= 100, '发布启动预算或样本数无效');
    $publisher = new Type\Build\NativePackage();
    $releaseHash = hash_file('sha256', $directory . '/release.json');
    $release = $publisher->verify($directory, $releaseHash);
    $environment = getenv();
    $environment['TYPE_APP_RELEASE_SHA256'] = $releaseHash;
    $command = [$directory . (PHP_OS_FAMILY === 'Windows' ? '/run.cmd' : '/run'), 'help'];
    $warmups = [];
    $measurements = [];
    $expectedOutput = null;
    for ($sample = 0; $sample < $samples + 2; $sample++) {
        $started = hrtime(true);
        $process = new Process($command, $directory, $environment);
        try {
            $result = $process->wait(60);
            $seconds = (hrtime(true) - $started) / 1000000000;
            expect($result->successful() && $result->stderr === '' && str_contains($result->stdout, 'help'), '发布启动没有完成help及完整性校验：' . $result->stderr);
            $expectedOutput ??= $result->stdout;
            expect($result->stdout === $expectedOutput, '相同发布输入的help输出改变');
            if ($sample < 2) {
                $warmups[] = $seconds;
            } else {
                $measurements[] = $seconds;
            }
        } finally {
            $process->stop();
        }
    }
    $publisher->verify($directory, $releaseHash);
    $sorted = $measurements;
    sort($sorted);
    $p50 = $sorted[(int) ceil($samples * 0.5) - 1];
    $p95 = $sorted[(int) ceil($samples * 0.95) - 1];
    $p99 = $sorted[(int) ceil($samples * 0.99) - 1];
    echo json_encode(['mode' => 'package-help', 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
        'release-sha256' => $releaseHash, 'artifact' => $release['artifact'], 'files' => count($release['files']),
        'file-bytes' => array_sum(array_column($release['files'], 'bytes')), 'warmups-seconds' => $warmups,
        'seconds' => $measurements, 'p50' => $p50, 'p95' => $p95, 'p99' => $p99,
        'sequential-starts-per-second' => $samples / array_sum($measurements), 'budget' => $budget,
        'output-sha256' => hash('sha256', $expectedOutput), 'passed' => $p50 <= $budget], JSON_THROW_ON_ERROR) . "\n";
    expect($p50 <= $budget, '相同发布包原生启动中位耗时超出显式验收预算');
    exit(0);
}

// 此入口只接受 build-platform-native.php 的身份验证产物，不用 PHP 替身测量启动。
$artifact = $argv[1] ?? '';
expect(is_file($artifact), '必须传入平台原生身份验证产物');
$budget = (float) ($argv[2] ?? '3');
expect(is_finite($budget) && $budget > 0 && $budget <= 60, '启动验收预算必须在0至60秒之间');
$started = hrtime(true);
$result = (new Process([$artifact]))->wait($budget);
$seconds = (hrtime(true) - $started) / 1000000000;
echo json_encode(['seconds' => $seconds, 'budget' => $budget, 'timeout' => $result->timedOut, 'exit' => $result->exitCode], JSON_THROW_ON_ERROR) . "\n";
expect(!$result->timedOut, '原生身份校验超出启动预算');
expect($result->successful() && $result->stdout === "本机AOT与实际加载运行库身份通过。\n" && $result->stderr === '', '原生启动没有完成实际加载与完整性验证');
