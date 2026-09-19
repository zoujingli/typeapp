<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-rollout-redis.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

/** 复用消费者与故障场景，在独立原生Redis上运行并保存真实输出。 */
function reliabilityRun(array $command, string $root, array $environment, string $log, float $timeout = 60): string
{
    $process = new Process($command, $root, $environment, 16777216);
    try {
        $result = $process->wait($timeout);
        file_put_contents($log, $result->stdout . $result->stderr);
        expect($result->successful(), '可靠性验证失败，见 ' . substr($log, strlen($root) + 1));
        return $result->stdout;
    } finally {
        $process->stop();
    }
}

$root = realpath(dirname(__DIR__));
$server = realpath($argv[1] ?? '');
expect($argc === 2 && $server !== false, '用法：php tests/native-task-reliability.php <原生redis-server>');
$base = $root . '/build/native-task-reliability-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700) && mkdir($base . '/composer-home', 0700), '无法创建可靠性验收目录');
$environment = (new BuildPlatform())->environment(getenv('PHP_HOME'), getenv('PHPX_HOME'));
$environment['PATH'] = getenv('PATH');
$environment['PHPRC'] = getenv('PHPRC') ?: '';
$environment['PHP_INI_SCAN_DIR'] = getenv('PHP_INI_SCAN_DIR') ?: '';
$environment['COMPOSER_BINARY'] = getenv('COMPOSER_BINARY') ?: 'composer';
$environment['COMPOSER_HOME'] = $base . '/composer-home';
$environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
$environment['COMPOSER_PROCESS_TIMEOUT'] = '1800';
$report = ['status' => 'running', 'path-base' => 'project-root', 'platform' => PHP_OS_FAMILY,
    'architecture' => php_uname('m'), 'toolchain-lock-sha256' => hash_file('sha256', $root . '/toolchain.lock.json'), 'runs' => []];
try {
    $output = reliabilityRun([PHP_BINARY, $root . '/tests/task-reliability-consumer.php', '--native'], $root, $environment, $base . '/consumer.log', 1800);
    $consumer = trim($output);
    expect(str_starts_with($consumer, $root . '/build/reliability-consumer-') && is_file($consumer . '/build/type-app.build.json'), '无法识别独立可靠性消费者');
    $build = json_decode(file_get_contents($consumer . '/build/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $consumer . '/build/type-app') === $build['sha256'], '消费者产物摘要不符');
    $report['consumer'] = substr($consumer, strlen($root) + 1);
    $report['artifact-sha256'] = $build['sha256'];
    $report['build-id'] = $build['build-id'];
    $report['build-report-sha256'] = hash_file('sha256', $consumer . '/build/type-app.build.json');
    foreach (['php', 'native'] as $mode) {
        $work = $base . '/' . $mode;
        expect(mkdir($work, 0700), '无法创建本轮运行目录');
        $redis = new NativeRolloutRedis($work . '/redis', $server);
        $run = ['status' => 'running'];
        try {
            $settings = $redis->environment();
            $settings['TYPE_RELIABLE_HOST'] = $settings['TYPE_REDIS_HOST'];
            $settings['TYPE_RELIABLE_PORT'] = $settings['TYPE_REDIS_PORT'];
            $settings['TYPE_CACHE_HOST'] = $settings['TYPE_ROLLOUT_CACHE_HOST'];
            $settings['TYPE_CACHE_PORT'] = $settings['TYPE_ROLLOUT_CACHE_PORT'];
            $settings['TYPE_RELIABILITY_APP'] = 'native-reliability-' . $mode;
            $runEnvironment = array_replace($environment, $settings);
            if ($mode === 'native') {
                $runEnvironment['TYPE_NATIVE_PHP_INI'] = $build['runtime-profile']['ini'];
            }
            $command = $mode === 'native' ? ['env', 'PHPRC=' . $build['runtime-profile']['ini'],
                'PHP_INI_SCAN_DIR=' . dirname($build['runtime-profile']['ini']) . '/php.d', $consumer . '/build/type-app'] : [PHP_BINARY, $consumer . '/run.php'];
            echo reliabilityRun([...$command, 'seed'], $root, $runEnvironment, $work . '/seed.log');
            $redis->crashAndRestartReliable();
            echo reliabilityRun([...$command, 'recover'], $root, $runEnvironment, $work . '/recover.log');
            echo reliabilityRun([...$command, 'pressure'], $root, $runEnvironment, $work . '/pressure.log');
            echo reliabilityRun([PHP_BINARY, $root . '/tests/task-reliability-process.php', $consumer, '--' . $mode], $root, $runEnvironment, $work . '/stop.log');
            $run['status'] = 'passed';
        } finally {
            if ($run['status'] !== 'passed') {
                $run['status'] = 'failed';
            }
            try {
                $redis->close();
            } catch (Throwable $failure) {
                $run['status'] = 'failed';
                throw $failure;
            } finally {
                $run['redis'] = $redis->evidence();
                $report['runs'][$mode] = $run;
            }
        }
    }
    expect(hash_file('sha256', $consumer . '/build/type-app') === $report['artifact-sha256'], '验收期间产物变化');
    $report['status'] = 'passed';
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
echo '原生Redis崩溃恢复、容量压力与任务停止的PHP/AOT对照通过：' . substr($base, strlen($root) + 1) . "/verification.json\n";
