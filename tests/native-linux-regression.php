<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/native-rollout-redis.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;

$root = dirname(__DIR__);
$suite = $argv[1] ?? '';
expect(PHP_OS_FAMILY === 'Linux' && posix_geteuid() > 0 && $argc === 2 && in_array($suite, ['http', 'redis'], true), '用法：非root Linux PHP tests/native-linux-regression.php <http|redis>');
$base = $root . '/build/native-linux-' . $suite . '-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮Linux专项目录');
expect(mkdir($base . '/composer-home', 0700), '无法创建本轮独立Composer目录');
$controlIni = getenv('PHPRC') ?: '';
$controlScan = getenv('PHP_INI_SCAN_DIR') ?: '';
expect(is_file($controlIni) && is_dir($controlScan), '需要明确的完整控制器INI及独立扫描目录');
$environment = array_replace((new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''), [
    'PATH' => getenv('PATH') ?: '', 'PHPRC' => $controlIni, 'PHP_INI_SCAN_DIR' => $controlScan,
    'COMPOSER_BINARY' => getenv('COMPOSER_BINARY') ?: 'composer', 'COMPOSER_HOME' => $base . '/composer-home',
    'COMPOSER_CACHE_DIR' => $root . '/.cache/composer', 'COMPOSER_PROCESS_TIMEOUT' => '1800',
]);
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'suite' => $suite,
    'toolchain-lock-sha256' => hash_file('sha256', $root . '/toolchain.lock.json'),
    'control-ini-sha256' => hash_file('sha256', $controlIni), 'runs' => [], 'artifacts' => []];
$runtimeInis = [];
$redis = null;
try {
    if ($suite === 'redis') {
        $redis = new NativeRolloutRedis($base . '/redis', getenv('TYPE_REDIS_SERVER') ?: '');
        $environment = array_replace($environment, $redis->environment());
    }
    $cases = $suite === 'http'
        ? [
            ['http-message-native', 'http-message', ['native'], [], []],
            ['http-native', 'http', ['php', 'native'], [], ['swoole']],
            ['validation', 'validation', ['php', 'native'], [], []],
            ['routing', 'routing', ['php', 'native'], [], []],
            ['routing-http', 'routing-http', ['php', 'native'], ['explicit'], ['swoole']],
            ['routing-http', 'routing-attributes', ['php', 'native'], ['attributes'], ['swoole']],
            ['http-trust', 'trust-http', ['php', 'native'], [], ['swoole']],
            ['file-http', 'file-http', ['php', 'native'], [], ['swoole']],
            ['log', 'log', ['php', 'native'], [], []],
            ['log-behavior', 'log-behavior', ['php', 'native'], [], []],
            ['log-failures', 'log-failures', ['php', 'native'], [], []],
            ['log-http', 'log-http', ['php', 'native'], [], ['swoole']],
        ]
        : array_map(static fn (string $name): array => [$name, $name, ['php', 'native'], [], []], ['redis', 'cache', 'psr-cache', 'queue', 'queue-leases', 'queue-retries']);
    foreach ($cases as [$test, $artifactName, $modes, $arguments, $engines]) {
        $artifact = $root . '/build/' . $artifactName . '/type-app';
        $manifest = (new ArtifactManifest())->read($artifact);
        $built = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($manifest['runtime']['os'] === PHP_OS_FAMILY && $manifest['runtime']['architecture'] === php_uname('m'), '不能使用其他平台专项产物');
        expect($built['sha256'] === hash_file('sha256', $artifact) && $built['build-id'] === $manifest['build-id'], '专项产物与构建报告不一致');
        $runtimeInis[$artifactName] = $built['runtime-profile']['ini'];
        $report['artifacts'][$artifactName] = ['sha256' => hash_file('sha256', $artifact), 'build-id' => $manifest['build-id'],
            'build-report-sha256' => hash_file('sha256', $artifact . '.build.json'),
            'source-count' => count($built['identity']['description']['inputs']['sources']), 'production-packages' => $built['production-packages']];
        foreach ($engines === [] ? ['none'] : $engines as $engine) {
            foreach ($modes as $mode) {
                $name = implode('-', [$artifactName, $engine, $mode]);
                $runEnvironment = $environment;
                if ($engine === 'swoole') {
                    $runEnvironment['TYPE_HTTP_DRIVER'] = $engine;
                }
                if ($mode === 'native') {
                    $runEnvironment['TYPE_NATIVE_PHP_INI'] = $built['runtime-profile']['ini'];
                }
                $target = $mode === 'native' ? [$artifact] : ($test === 'validation' ? [] : ['--php']);
                $log = $base . '/' . $name . '.log';
                echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/' . $test . '.php', ...$target, ...$arguments], $runEnvironment, [], $log, 180);
                $report['runs'][] = ['test' => $test, 'artifact' => $artifactName, 'mode' => $mode, 'engine' => $engine,
                    'log' => basename($log), 'log-sha256' => hash_file('sha256', $log), 'status' => 'passed'];
            }
        }
    }
    $consumers = $suite === 'http' ? ['log' => 'log'] : ['redis' => 'redis', 'cache' => 'psr-cache', 'queue' => 'queue'];
    foreach ($consumers as $consumer => $runtimeArtifact) {
        foreach (['php', 'native'] as $mode) {
            $name = $consumer . '-consumer-' . $mode;
            $log = $base . '/' . $name . '.log';
            $runEnvironment = $environment;
            if ($mode === 'native') {
                // 复用相同生产依赖所需的已探测模块；消费者仍独立安装并全量构建自己的应用。
                $runEnvironment['TYPE_NATIVE_PHP_INI'] = $runtimeInis[$runtimeArtifact];
            }
            echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/' . $consumer . '-consumer.php', '--' . $mode], $runEnvironment, [], $log, 1800);
            $report['runs'][] = ['test' => $consumer . '-consumer', 'mode' => $mode, 'engine' => 'none', 'status' => 'passed',
                'log' => basename($log), 'log-sha256' => hash_file('sha256', $log)];
        }
    }
    if ($suite === 'redis') {
        $securityLog = $base . '/redis-security-php.log';
        echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/redis-security.php'], $environment, [], $securityLog, 120);
        $report['runs'][] = ['test' => 'redis-security', 'mode' => 'php', 'engine' => 'none', 'status' => 'passed',
            'log' => basename($securityLog), 'log-sha256' => hash_file('sha256', $securityLog)];
        // 独立调度及队列组合消费者自己安装完整生产依赖；内部对照PHP与各自全量AOT产物。
        foreach (['scheduler-consumer', 'scheduler-coordination-consumer'] as $consumer) {
            $log = $base . '/' . $consumer . '.log';
            $output = nativeDatabaseCommand([PHP_BINARY, $root . '/tests/' . $consumer . '.php', '--native'], $environment, [], $log, 1800);
            expect(preg_match('#通过：(.*?/verification\\.json)\\s*$#u', $output, $matches) === 1, '调度消费者没有返回实际验收报告');
            $evidence = json_decode(file_get_contents($matches[1]), true, 512, JSON_THROW_ON_ERROR);
            expect($evidence['status'] === 'passed' && $evidence['native'] === true, '调度消费者没有完成PHP及原生行为');
            $report['runs'][] = ['test' => $consumer, 'mode' => 'php-and-native', 'engine' => 'none', 'status' => 'passed',
                'log' => basename($log), 'log-sha256' => hash_file('sha256', $log),
                'report' => substr($matches[1], strlen($root) + 1), 'report-sha256' => hash_file('sha256', $matches[1]), 'consumer' => $evidence];
            echo $output;
        }
    }
    $report['status'] = 'passed';
} finally {
    try {
        if ($redis !== null) {
            $redis->close();
            $report['redis'] = $redis->evidence();
        }
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        throw $failure;
    } finally {
        if ($report['status'] !== 'passed') {
            $report['status'] = 'failed';
        }
        file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
echo 'Linux原生' . $suite . '专项通过：' . $base . "/verification.json\n";
