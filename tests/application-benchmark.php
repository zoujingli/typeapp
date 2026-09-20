<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;
use Type\Runtime\Arguments;
use Type\Testing\HttpClient;
use Type\Testing\Process;

/** 真实持有目标行/SQLite写锁，释放由本进程负责；父进程仅提供专用测试环境。 */
function benchmarkHoldLock(): void
{
    $driver = (string) getenv('DB_DRIVER');
    $dsn = $driver === 'sqlite' ? 'sqlite:' . getenv('APP_BASE_PATH') . '/data/benchmark.sqlite'
        : $driver . ':host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE');
    $pdo = new PDO($dsn, getenv('DB_USERNAME') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($driver === 'sqlite') {
        $pdo->exec('BEGIN IMMEDIATE');
    } else {
        $pdo->beginTransaction();
    }
    try {
        $statement = $pdo->prepare('UPDATE admin_users SET version = version WHERE id = ?');
        $statement->execute([(string) getenv('TYPE_BENCHMARK_USER')]);
        file_put_contents((string) getenv('TYPE_BENCHMARK_READY'), 'locked');
        usleep(50000);
    } finally {
        $driver === 'sqlite' ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
    }
}

/** @return array{rss_bytes:int,cpu_seconds:float,processes:int} 观测被测进程及其后代，不把控制器计入服务。 */
function benchmarkUsage(Process $process): array
{
    $pid = $process->pid();
    expect($pid !== null, '被测进程已退出');
    $text = successful(['ps', '-axo', 'pid=,ppid=,rss=,time=']);
    $rows = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(?:(\d+)-)?([0-9:.]+)\s*$/D', $line, $match) === 1) {
            $parts = array_reverse(explode(':', $match[5]));
            $rows[(int) $match[1]] = ['parent' => (int) $match[2], 'rss' => (int) $match[3] * 1024,
                'cpu' => (float) $parts[0] + (float) ($parts[1] ?? 0) * 60 + (float) ($parts[2] ?? 0) * 3600 + (float) ($match[4] ?? 0) * 86400];
        }
    }
    expect(isset($rows[$pid]), '无法读取实际CPU/RSS');
    $selected = [$pid => true];
    do {
        $before = count($selected);
        foreach ($rows as $child => $row) {
            if (isset($selected[$row['parent']])) {
                $selected[$child] = true;
            }
        }
    } while (count($selected) !== $before);
    $rss = 0;
    $cpu = 0.0;
    foreach (array_keys($selected) as $child) {
        $rss += $rows[$child]['rss'];
        $cpu += $rows[$child]['cpu'];
    }
    return ['rss_bytes' => $rss, 'cpu_seconds' => $cpu, 'processes' => count($selected)];
}

/** @param Closure():float $operation 返回实际请求路径耗时，准备和采样开销另记在总窗口。 */
function benchmarkSample(Process $server, Closure $operation, int $warmup, int $iterations): array
{
    for ($index = 0; $index < $warmup; $index++) {
        expect($server->running(), '预热期间服务退出');
        $operation();
    }
    $before = benchmarkUsage($server);
    $samples = [$before];
    $latencies = [];
    $started = hrtime(true);
    for ($index = 0; $index < $iterations; $index++) {
        expect($server->running(), '测量期间服务退出');
        $milliseconds = $operation();
        expect(is_finite($milliseconds) && $milliseconds >= 0, '无效延迟样本');
        $latencies[] = $milliseconds;
        if (($index + 1) % 10 === 0) {
            $samples[] = benchmarkUsage($server);
        }
    }
    $elapsed = (hrtime(true) - $started) / 1e9;
    $after = benchmarkUsage($server);
    $samples[] = $after;
    sort($latencies, SORT_NUMERIC);
    return ['warmup' => $warmup, 'iterations' => $iterations, 'concurrency' => 1, 'wall_seconds' => $elapsed,
        'operations_per_second' => $iterations / $elapsed, 'latencies_ms' => $latencies,
        'p50_ms' => $latencies[(int) ceil($iterations * 0.5) - 1], 'p95_ms' => $latencies[(int) ceil($iterations * 0.95) - 1],
        'p99_ms' => $latencies[(int) ceil($iterations * 0.99) - 1], 'cpu_seconds' => $after['cpu_seconds'] - $before['cpu_seconds'],
        'max_sampled_rss_bytes' => max(array_column($samples, 'rss_bytes')), 'resource_samples' => $samples,
        'note' => '延迟仅含请求路径；吞吐窗口包含锁准备及外部采样开销，RSS是被测进程树采样和的最大值，不是操作系统峰值。'];
}

/** @return array{Process,HttpClient} 就绪由真实HTTP响应证明。 */
function benchmarkServer(string $artifact, array $environment, string $root): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    expect(is_resource($listener), '无法分配基准端口');
    $address = stream_socket_get_name($listener, false);
    $port = substr(strrchr($address, ':'), 1);
    fclose($listener);
    $environment['APP_PORT'] = $port;
    $environment['APP_ALLOWED_HOSTS'] = $address;
    $process = new Process([$artifact, 'serve'], $root, $environment, 16777216);
    $client = new HttpClient('http://' . $address, 10, 4194304);
    $deadline = microtime(true) + 15;
    try {
        do {
            expect($process->running(), '基准服务未启动：' . $process->stderr());
            try {
                if ($client->request('GET', '/readyz')->status === 200) {
                    return [$process, $client];
                }
            } catch (RuntimeException) {
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('基准服务未就绪');
    } catch (Throwable $error) {
        $process->stop(5);
        throw $error;
    }
}

if (($argv[1] ?? '') === '--hold-lock') {
    benchmarkHoldLock();
    exit(0);
}

$root = realpath(dirname(__DIR__));
$arguments = new Arguments($argv, ['binary', 'driver', 'database-tools', 'repetitions', 'iterations', 'warmup'], []);
$artifact = realpath($arguments->text('binary', ''));
$driver = $arguments->text('driver', 'sqlite');
expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) && in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '本入口只对实际Unix原生目标和三库测量');
expect(is_string($artifact), '需要真实标准应用产物');
(new BuildPlatform())->assertArtifact($artifact);
$artifactHash = hash_file('sha256', $artifact);
$repetitions = $arguments->integer('repetitions', 3, 1, 10);
$iterations = $arguments->integer('iterations', 30, 1, 500);
$warmup = $arguments->integer('warmup', 5, 0, 100);
$tools = $driver === 'sqlite' ? [] : NativeDatabase::tools($driver, $arguments->text('database-tools', ''));
$base = $root . '/build/application-benchmark-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建基准目录');
$report = ['status' => 'running', 'path_base' => 'project-root', 'driver' => $driver, 'transport' => 'swoole', 'sampling_protocol' => 2,
    'host' => ['os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'kernel' => php_uname('r'), 'controller_php' => PHP_VERSION],
    'artifact_sha256' => $artifactHash, 'repetitions' => []];
try {
    for ($round = 0; $round < $repetitions; $round++) {
        $work = $base . '/round-' . $round;
        expect(mkdir($work . '/app', 0700, true) && mkdir($work . '/php.d', 0700), '无法准备本轮数据目录');
        $database = new NativeDatabase($work . '/database', $driver, $tools);
        $server = null;
        try {
            $environment = array_replace((new BuildPlatform())->environment(getenv('PHP_HOME'), getenv('PHPX_HOME')), $database->environment());
            $environment['PHPRC'] = getenv('PHPRC') ?: '';
            $environment['PHP_INI_SCAN_DIR'] = $work . '/php.d';
            $adminPassword = 'Benchmark-admin-password-2026';
            $customerPassword = 'Benchmark-customer-password-2026';
            $environment += ['APP_BASE_PATH' => $work . '/app', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_CACHE_ENABLED' => 'false',
                'APP_ADMIN_PASSWORD' => $adminPassword, 'APP_CUSTOMER_PASSWORD' => $customerPassword,
                'DB_DRIVER' => $driver, 'DB_SQLITE_FILE' => 'data/benchmark.sqlite'];
            if ($driver !== 'sqlite') {
                $prefix = 'TYPE_' . strtoupper($driver) . '_';
                foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USER' => 'USERNAME', 'PASSWORD' => 'PASSWORD'] as $from => $to) {
                    $environment['DB_' . $to] = $environment[$prefix . $from];
                }
            }
            $migration = new Process([$artifact, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '基准租户'], $root, $environment);
            try {
                expect($migration->wait(30)->successful(), '基准应用安装失败');
            } finally {
                $migration->stop();
            }
            [$server, $client] = benchmarkServer($artifact, $environment, $root);
            $login = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
                'login' => 'platform-admin', 'password' => $adminPassword,
            ], JSON_THROW_ON_ERROR));
            expect($login->status === 200 && isset($login->json()['data']['accessToken']), '基准管理端登录失败');
            $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
            $measurements = [];
            $measurements['short-json'] = benchmarkSample($server, static function () use ($client, $headers): float {
                $started = hrtime(true);
                $response = $client->request('GET', '/admin/profile', $headers);
                expect($response->status === 200 && is_array($response->json()), '短JSON业务结果错误');
                return (hrtime(true) - $started) / 1e6;
            }, $warmup, $iterations);
            $sequence = 0;
            $measurements['crud'] = benchmarkSample($server, static function () use ($client, $headers, &$sequence): float {
                $started = hrtime(true);
                $login = 'bench-' . (++$sequence);
                $created = $client->request('POST', '/admin/users', $headers, json_encode([
                    'login' => $login, 'name' => '基准用户', 'password' => 'Benchmark-user-password-2026',
                ], JSON_THROW_ON_ERROR));
                expect($created->status === 200, '基准创建失败');
                $user = $created->json()['data'];
                $path = '/admin/users/' . $user['id'];
                expect($client->request('GET', $path, $headers)->json()['data']['items'][0]['name'] === '基准用户', '基准读取错误');
                $updated = $client->request('PATCH', $path, $headers, json_encode(['login' => $login . '-u', 'name' => '基准用户-更新', 'version' => $user['version']], JSON_THROW_ON_ERROR));
                expect($updated->status === 200 && $updated->json()['data']['name'] === '基准用户-更新', '基准更新错误');
                expect($client->request('POST', $path . '/status', $headers, json_encode(['version' => $updated->json()['data']['version'], 'enabled' => false], JSON_THROW_ON_ERROR))->status === 200, '基准停用失败');
                return (hrtime(true) - $started) / 1e6;
            }, $warmup, $iterations);
            $created = $client->request('POST', '/admin/users', $headers, json_encode([
                'login' => 'lock-user', 'name' => '锁等待用户', 'password' => 'Benchmark-user-password-2026',
            ], JSON_THROW_ON_ERROR));
            expect($created->status === 200, '无法准备锁等待数据');
            $lockUser = $created->json()['data'];
            $slowStatuses = [];
            $measurements['slow-database'] = benchmarkSample($server, static function () use ($client, $headers, $work, $root, $environment, $driver, &$lockUser, &$slowStatuses): float {
                $ready = $work . '/lock-ready';
                $childEnvironment = $environment + ['TYPE_BENCHMARK_USER' => (string) $lockUser['id'], 'TYPE_BENCHMARK_READY' => $ready];
                $holder = new Process([PHP_BINARY, __FILE__, '--hold-lock'], $root, $childEnvironment);
                try {
                    $deadline = microtime(true) + 5;
                    while (!is_file($ready)) {
                        expect($holder->running() && microtime(true) < $deadline, '真实锁持有者未就绪');
                        usleep(1000);
                    }
                    expect($holder->running(), '错过锁持有窗口，不能当作慢依赖样本');
                    $name = $lockUser['name'] === '锁等待用户' ? '锁等待用户-更新' : '锁等待用户';
                    $started = hrtime(true);
                    $response = $client->request('PATCH', '/admin/users/' . $lockUser['id'], $headers, json_encode(['login' => 'lock-user', 'name' => $name, 'version' => $lockUser['version']], JSON_THROW_ON_ERROR));
                    $slowStatuses[] = $response->status;
                    if ($driver === 'sqlite' && $response->status === 500) {
                        // SQLite读事务升级为写事务可立即BUSY；确认没有提交后，由测试客户端发起新请求。
                        expect($response->json()['error'] === 'internal_error', 'SQLite锁竞争没有保持公开错误语义');
                        expect($holder->wait(5)->successful(), '锁持有者未正常释放');
                        $unchanged = $client->request('GET', '/admin/users/' . $lockUser['id'], $headers);
                        expect($unchanged->status === 200 && $unchanged->json()['data']['items'][0]['version'] === $lockUser['version']
                            && $unchanged->json()['data']['items'][0]['name'] === $lockUser['name'], '失败请求发生了未确认写入，不能重试');
                        $response = $client->request('PATCH', '/admin/users/' . $lockUser['id'], $headers, json_encode(['login' => 'lock-user', 'name' => $name, 'version' => $lockUser['version']], JSON_THROW_ON_ERROR));
                    }
                    $latency = (hrtime(true) - $started) / 1e6;
                    expect(
                        $response->status === 200 && $response->json()['data']['name'] === $name && $latency >= 20,
                        '锁释放后业务未完成或没有实际等待：' . json_encode(['status' => $response->status, 'body' => $response->json(), 'ms' => $latency], JSON_THROW_ON_ERROR)
                    );
                    $lockUser = $response->json()['data'];
                    expect($holder->wait(5)->successful(), '锁持有者未正常释放');
                    return $latency;
                } finally {
                    $holder->stop();
                    if (is_file($ready)) {
                        unlink($ready);
                    }
                }
            }, $warmup, $iterations);
            $measurements['slow-database']['initial_statuses_including_warmup'] = $slowStatuses;
            $measurements['slow-database']['contention'] = '持有真实写锁50ms；SQLite允许BUSY后确认无写入并由客户端重试一次，其余驱动等待行锁。';
            $report['repetitions'][] = $measurements;
        } finally {
            $cleanupError = null;
            foreach ([$server] as $process) {
                if ($process !== null) {
                    try {
                        expect($process->stop(10)->successful(), '基准服务未正常退出');
                    } catch (Throwable $error) {
                        $cleanupError ??= $error;
                    }
                }
            }
            try {
                $database->close();
            } catch (Throwable $error) {
                $cleanupError ??= $error;
            }
            if ($cleanupError !== null) {
                throw $cleanupError;
            }
        }
    }
    expect(hash_file('sha256', $artifact) === $artifactHash, '测量期间产物变化');
    $report['status'] = 'passed';
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
echo '真实应用三类负载测量完成：' . substr($base, strlen($root) + 1) . "/verification.json\n";
