<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/native-rollout-redis.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;

$root = BuildPlatform::resolve(dirname(__DIR__));
expect(in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && posix_geteuid() > 0 && in_array($argc, [4, 5, 6], true), '用法：非root Linux/macOS PHP tests/native-database-failures.php <专项build根目录> <MySQL根目录> <PostgreSQL根目录> [all|query|models|lifecycle|connections|transactions|tasks] [mysql|pgsql|sqlite]');
$suites = match ($argv[4] ?? 'all') {
    'all' => ['transactions', 'outcomes', 'read-write', 'pagination'],
    'query' => ['query', 'pagination'],
    'models' => ['models', 'exact-fields', 'relations', 'pivots'],
    'lifecycle' => ['lifecycle', 'optimistic'],
    'connections' => ['identities', 'read-write'],
    'transactions' => ['transactions', 'outcomes'],
    'tasks' => ['tasks'],
    'task-http' => ['task-http'],
    'combinations' => ['operations', 'cache-consistency'],
    'outbox' => ['outbox'],
    'tenant' => ['tenant-http'],
    'migrations' => ['migrations', 'migrations-core'],
    'pressure' => ['backpressure-http'],
    default => throw new InvalidArgumentException('未知数据库验收分组'),
};
$drivers = isset($argv[5]) ? [$argv[5]] : ['mysql', 'pgsql', 'sqlite'];
expect(array_diff($drivers, ['mysql', 'pgsql', 'sqlite']) === [], '未知数据库验收驱动');
expect(array_intersect($suites, ['tasks', 'task-http', 'backpressure-http']) === [] || $drivers === ['mysql'], '受管任务与慢依赖专项需要明确选择已有MySQL慢SQL场景');
$needsRedis = in_array('operations', $suites, true) || in_array('outbox', $suites, true) || in_array('tenant-http', $suites, true);
$redisServer = $needsRedis ? realpath(getenv('TYPE_REDIS_SERVER') ?: '') : null;
expect(!$needsRedis || is_string($redisServer), '组合验收需要通过TYPE_REDIS_SERVER提供原生Redis程序');
$buildRoot = realpath($argv[1]);
expect($buildRoot !== false && is_dir($buildRoot), '需要已完成专项编译的目录');
$artifacts = [];
foreach ($suites as $suite) {
    $file = $buildRoot . '/' . $suite . '/type-app';
    (new BuildPlatform())->assertArtifact($file);
    $manifest = (new ArtifactManifest())->read($file);
    $built = json_decode(file_get_contents($file . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['runtime']['os'] === PHP_OS_FAMILY && $manifest['runtime']['architecture'] === php_uname('m'), '专项产物与当前平台不匹配');
    $artifacts[$suite] = ['file' => $file, 'sha256' => hash_file('sha256', $file), 'build-id' => $manifest['build-id'], 'runtime-ini' => $built['runtime-profile']['ini']];
}
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[2]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[3])];
$phpHome = getenv('PHP_HOME') ?: '';
$phpxHome = getenv('PHPX_HOME') ?: '';
expect(is_dir($phpHome) && is_dir($phpxHome), '需要明确的PHP_HOME和PHPX_HOME');
$buildEnvironment = (new BuildPlatform())->environment($phpHome, $phpxHome);
$base = $root . '/build/native-database-failures-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮专项验证目录');
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'database-execution' => 'native',
    'docker-used' => false, 'artifacts' => $artifacts, 'drivers' => []];
try {
    foreach ($drivers as $driver) {
        $work = $base . '/' . $driver;
        $database = null;
        $redis = null;
        $entry = ['status' => 'running', 'checks' => []];
        $failed = null;
        try {
            $database = new NativeDatabase($work, $driver, $tools[$driver] ?? []);
            $environment = array_replace($buildEnvironment, $database->environment());
            $environment['PATH'] = $phpHome . '/bin:' . $environment['PATH'];
            $environment['PHPRC'] = getenv('PHPRC') ?: '';
            $environment['PHP_INI_SCAN_DIR'] = getenv('PHP_INI_SCAN_DIR') ?: '';
            if (in_array('tenant-http', $suites, true)) {
                $environment['TYPE_HTTP_DRIVER'] = 'swoole';
            }
            if ($needsRedis) {
                $redis = new NativeRolloutRedis($work . '/redis', $redisServer);
                $environment = array_replace($environment, $redis->environment());
            }
            $secrets = $driver === 'sqlite' ? [] : [$environment['TYPE_' . strtoupper($driver) . '_PASSWORD']];
            $cases = $suites;
            if ($driver === 'mysql' && in_array('outcomes', $suites, true)) {
                $cases[] = 'commit-failure';
            }
            if ($driver !== 'sqlite' && in_array('identities', $suites, true)) {
                $cases[] = 'identity-credentials';
            }
            if ($driver === 'mysql' && in_array('operations', $suites, true)) {
                $cases[] = 'operations-commit-failure';
            }
            foreach ($cases as $suite) {
                foreach ($suite === 'migrations-core' ? ['native'] : ['php', 'native'] as $mode) {
                    echo '本机原生数据库专项：' . $driver . '/' . $suite . '/' . $mode . "\n";
                    $program = match ($suite) {
                        'commit-failure' => 'outcomes',
                        'identity-credentials' => 'identities',
                        'operations-commit-failure' => 'operations',
                        default => $suite,
                    };
                    $command = $mode === 'php' ? '--php' : $artifacts[$program]['file'];
                    $test = in_array($suite, ['migrations', 'migrations-core'], true) ? 'migrations-native' : $suite;
                    $arguments = $suite === 'migrations-core' ? ['core'] : [];
                    $log = $work . '/' . $suite . '-' . $mode . '.log';
                    $runEnvironment = $environment;
                    if ($mode === 'native') {
                        $runEnvironment['TYPE_NATIVE_PHP_INI'] = $artifacts[$program]['runtime-ini'];
                    }
                    $output = nativeDatabaseCommand([PHP_BINARY, $root . '/tests/' . $test . '.php', $command, $driver, ...$arguments], $runEnvironment, $secrets, $log, 240);
                    $entry['checks'][] = ['suite' => $suite, 'mode' => $mode, 'log' => $log, 'log-sha256' => hash_file('sha256', $log), 'result' => 'passed'];
                    echo $output;
                }
            }
            $entry['status'] = 'passed';
        } catch (Throwable $failure) {
            $failed = $failure;
            $entry['status'] = 'failed';
        } finally {
            if ($database !== null) {
                try {
                    $database->close();
                } catch (Throwable $cleanupFailure) {
                    $entry['status'] = 'failed';
                    $failed ??= $cleanupFailure;
                }
                $entry = array_replace($entry, $database->evidence());
            }
            if ($redis !== null) {
                try {
                    $redis->close();
                    $entry['redis-stopped'] = true;
                } catch (Throwable $cleanupFailure) {
                    $entry['status'] = 'failed';
                    $failed ??= $cleanupFailure;
                }
                $entry['redis'] = $redis->evidence();
            }
            $report['drivers'][$driver] = $entry;
        }
        if ($failed !== null) {
            throw $failed;
        }
    }
    $report['status'] = 'passed';
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo implode('/', $drivers) . ' 数据库专项 ' . implode('/', $suites) . ' 通过：' . $base . "/verification.json\n";
