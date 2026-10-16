<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tests/support.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/tests/windows-native-sandbox.php';
require dirname(__DIR__, 2) . '/tests/native-rollout-redis.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

expect(PHP_OS_FAMILY === 'Windows' && getenv('GITHUB_ACTIONS') === 'true', '只接受受控 Windows 复演');
$root = BuildPlatform::resolve(dirname(__DIR__, 2));
$original = $root . '/build/original-pgsql-tutorial/tutorial-delivery/pgsql/artifacts';
$program = $original . '/build/type-project.exe';
$digest = hash_file('sha256', $program);
expect($digest === '2704318aad29041e0893acffba82ce4320aa96bd53a68d827af55c363af55346', 'RC16 原教程程序摘要不符');
$base = $root . '/build/release-blockers-pgsql';
expect(mkdir($base, 0700, true), '无法建立诊断目录');
$redis = new NativeRolloutRedis($base . '/redis', getenv('TYPE_REDIS_SERVER'));
$dsn = 'pgsql:host=' . getenv('TYPE_PGSQL_HOST') . ';port=' . getenv('TYPE_PGSQL_PORT') . ';dbname=' . getenv('TYPE_PGSQL_DATABASE');
$database = new PDO($dsn, getenv('TYPE_PGSQL_USER'), getenv('TYPE_PGSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$report = ['program-sha256' => $digest, 'source' => '1386d9af43ba746b8804111159cc8e4d358abecf', 'cases' => []];
try {
    foreach (['direct', 'sandbox'] as $mode) {
        for ($round = 1; $round <= 3; $round++) {
            $work = $base . '/' . $mode . '-' . $round;
            expect(mkdir($work . '/package', 0700, true) && mkdir($work . '/runtime/var', 0700, true), '无法创建独立运行根');
            expect(copy($program, $work . '/package/app.exe'), '无法复制原程序');
            $schema = 'blocker_' . bin2hex(random_bytes(6));
            $namespace = 'blocker-' . bin2hex(random_bytes(12));
            $database->exec('CREATE DATABASE ' . $schema);
            $observer = new PDO(
                'pgsql:host=' . getenv('TYPE_PGSQL_HOST') . ';port=' . getenv('TYPE_PGSQL_PORT') . ';dbname=' . $schema,
                getenv('TYPE_PGSQL_USER'),
                getenv('TYPE_PGSQL_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $sandbox = null;
            $settings = array_replace(getenv(), $redis->environment(), WindowsNativeSandbox::environment($work . '/runtime'), [
                'APP_BASE_PATH' => $work . '/runtime', 'APP_ENV' => 'prod', 'APP_DEBUG' => 'false',
                'APP_API_TOKEN' => 'controlled-release-probe-token', 'DB_DRIVER' => 'pgsql', 'DB_HOST' => getenv('TYPE_PGSQL_HOST'),
                'DB_PORT' => getenv('TYPE_PGSQL_PORT'), 'DB_DATABASE' => $schema, 'DB_USERNAME' => getenv('TYPE_PGSQL_USER'),
                'DB_PASSWORD' => getenv('TYPE_PGSQL_PASSWORD'), 'CATALOG_NAMESPACE' => $namespace,
            ]);
            unset($settings['GH_TOKEN'], $settings['GITHUB_TOKEN']);
            try {
                $command = [$work . '/package/app.exe'];
                if ($mode === 'sandbox') {
                    $sandbox = new WindowsNativeSandbox($root, $work . '/package', $work . '/runtime');
                    $command = $sandbox->command();
                }
                $steps = [['migrate', 'run'], ['catalog', 'cache'], ['catalog', 'relay-fail'], ['catalog', 'status'],
                    ['catalog', 'relay'], ['catalog', 'relay'], ['catalog', 'consume'], ['catalog', 'queue-invalid'],
                    ['catalog', 'queue-cancel'], ['catalog', 'queue-cleanup']];
                foreach ($steps as $index => $arguments) {
                    if ($index === 4) {
                        usleep(400000);
                    }
                    $started = microtime(true);
                    $process = new Process([...$command, ...$arguments], $work . '/runtime', $settings);
                    try {
                        $result = $process->wait(15);
                    } finally {
                        $process->stop();
                    }
                    $record = ['command' => $arguments, 'exit' => $result->exitCode, 'timeout' => $result->timedOut,
                        'seconds' => round(microtime(true) - $started, 4), 'stdout' => $result->stdout, 'stderr' => $result->stderr];
                    if ($index > 0) {
                        $record['outbox'] = $observer->query('SELECT state, attempts, lease_until, floor(extract(epoch from clock_timestamp()) * 1000)::bigint AS observed_at FROM catalog_outbox ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC);
                    }
                    $report['cases'][$mode . '-' . $round][] = $record;
                    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
                }
            } finally {
                $sandbox?->close();
                $observer = null;
                $database->exec('DROP DATABASE ' . $schema);
                $client = new Redis();
                $redisEnvironment = $redis->environment();
                $client->connect($redisEnvironment['TYPE_REDIS_HOST'], (int) $redisEnvironment['TYPE_REDIS_PORT']);
                foreach (['type:queue:{' . hash('sha256', $namespace . "\0catalog") . '}*',
                    'type:cache:{' . hash('sha256', json_encode([$namespace, 'tutorial', 'json-data-v1'], JSON_THROW_ON_ERROR)) . '}*'] as $pattern) {
                    foreach ($client->keys($pattern) as $key) {
                        $client->del($key);
                    }
                }
                $client->close();
                removeTestDirectory($work);
            }
        }
    }
} finally {
    $database = null;
    $redis->close();
    $report['redis'] = $redis->evidence();
    removeTestDirectory($base . '/redis');
    $report['cleanup-complete'] = true;
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
