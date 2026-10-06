<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/windows-native-sandbox.php';

use Type\Build\ArtifactManifest;
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

/** @return array<string,int|float> 观测被测进程树；每次捕获耗时单独保存，控制器不计入服务。 */
function benchmarkUsage(Process $process, ?Process $sampler = null, ?string $directory = null): array
{
    $started = hrtime(true);
    $pid = $process->pid();
    expect($pid !== null, '被测进程已退出');
    if (PHP_OS_FAMILY === 'Windows') {
        expect($sampler !== null && is_string($directory), 'Windows 测量缺少本轮常驻资源采样器');
        $request = bin2hex(random_bytes(6));
        $temporary = $directory . '/' . $request . '.request';
        expect(file_put_contents($temporary, $request) === strlen($request) && rename($temporary, $directory . '/request'), '无法提交资源采样请求');
        $response = $directory . '/' . $request . '.json';
        $until = microtime(true) + 15;
        while (!is_file($response)) {
            expect($sampler->running() && $process->running() && microtime(true) < $until, 'Windows 资源采样失败或超时：' . $sampler->stderr());
            usleep(1000);
        }
        $sample = json_decode((string) file_get_contents($response), true, 512, JSON_THROW_ON_ERROR);
        expect(($sample['request'] ?? '') === $request && ($sample['pid'] ?? null) === $pid
            && is_int($sample['rss_bytes'] ?? null) && $sample['rss_bytes'] > 0
            && is_numeric($sample['cpu_seconds'] ?? null) && $sample['cpu_seconds'] >= 0
            && is_int($sample['processes'] ?? null) && $sample['processes'] > 0
            && is_numeric($sample['capture_seconds'] ?? null) && $sample['capture_seconds'] > 0, 'Windows CPU/RSS 采样身份或计数无效');
        unset($sample['request']);
        $sample['sampling_seconds'] = (hrtime(true) - $started) / 1e9;
        return $sample;
    }
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
    $elapsed = (hrtime(true) - $started) / 1e9;
    return ['rss_bytes' => $rss, 'cpu_seconds' => $cpu, 'processes' => count($selected), 'capture_seconds' => $elapsed, 'sampling_seconds' => $elapsed];
}

/**
 * @param Closure():float $operation 返回实际请求路径耗时，准备和采样开销另记在总窗口。
 * @param bool $diagnostic 仅额外诊断保存正式操作的原顺序；预热仍在测量窗口之外。
 */
function benchmarkSample(Process $server, Closure $operation, int $warmup, int $iterations, ?Process $sampler = null, ?string $directory = null, bool $diagnostic = false): array
{
    for ($index = 0; $index < $warmup; $index++) {
        expect($server->running(), '预热期间服务退出');
        $operation();
    }
    $before = benchmarkUsage($server, $sampler, $directory);
    $samples = [$before];
    $latencies = [];
    $started = hrtime(true);
    for ($index = 0; $index < $iterations; $index++) {
        expect($server->running(), '测量期间服务退出');
        $milliseconds = $operation();
        expect(is_finite($milliseconds) && $milliseconds >= 0, '无效延迟样本');
        $latencies[] = $milliseconds;
        if (($index + 1) % 10 === 0) {
            $samples[] = benchmarkUsage($server, $sampler, $directory);
        }
    }
    $finished = hrtime(true);
    $elapsed = ($finished - $started) / 1e9;
    $after = benchmarkUsage($server, $sampler, $directory);
    $samples[] = $after;
    $ordered = $diagnostic ? $latencies : [];
    sort($latencies, SORT_NUMERIC);
    $measurement = ['warmup' => $warmup, 'iterations' => $iterations, 'concurrency' => 1, 'wall_seconds' => $elapsed,
        'operations_per_second' => $iterations / $elapsed, 'latencies_ms' => $latencies,
        'p50_ms' => $latencies[(int) ceil($iterations * 0.5) - 1], 'p95_ms' => $latencies[(int) ceil($iterations * 0.95) - 1],
        'p99_ms' => $latencies[(int) ceil($iterations * 0.99) - 1], 'cpu_seconds' => $after['cpu_seconds'] - $before['cpu_seconds'],
        'max_sampled_rss_bytes' => max(array_column($samples, 'rss_bytes')), 'resource_samples' => $samples,
        'sampling_seconds' => array_sum(array_column($samples, 'sampling_seconds')),
        'window_sampling_seconds' => array_sum(array_column(array_slice($samples, 1, -1), 'sampling_seconds')),
        'note' => '延迟仅含请求路径；吞吐窗口包含锁准备及外部采样开销，RSS是被测进程树采样和的最大值，不是操作系统峰值。'];
    if ($diagnostic) {
        $measurement['diagnostic'] = ['started_monotonic_ns' => $started, 'finished_monotonic_ns' => $finished,
            'ordered_latencies_ms' => $ordered, 'sequence_base' => 1];
    }
    return $measurement;
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
$arguments = new Arguments($argv, ['binary', 'driver', 'database-tools', 'repetitions', 'iterations', 'warmup', 'static-profile'], ['external-database', 'diagnostic']);
$artifact = realpath($arguments->text('binary', ''));
$staticProfile = $arguments->has('static-profile') ? $arguments->text('static-profile', '') : null;
$driver = $arguments->text('driver', $staticProfile ?? 'sqlite');
$external = $arguments->has('external-database');
$diagnostic = $arguments->has('diagnostic');
$repetitions = $arguments->integer('repetitions', $diagnostic ? 1 : 3, 1, 10);
$iterations = $arguments->integer('iterations', $diagnostic ? 100 : 30, 1, 500);
$warmup = $arguments->integer('warmup', $diagnostic ? 10 : 5, 0, 100);
expect(!$diagnostic || ($repetitions === 1 && $iterations === 100 && $warmup === 10), '额外诊断固定为1轮、100次操作和10次预热，不能作为正式比较');
expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux', 'Windows'], true) && in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '本入口只对受支持原生目标和三库测量');
expect($staticProfile === null || ($staticProfile === $driver && in_array($staticProfile, ['mysql', 'pgsql', 'sqlite'], true)), '静态 profile 必须与实际测量数据库一致');
expect(!$external || ($staticProfile !== null && $driver !== 'sqlite' && !$arguments->has('database-tools')), '外部数据库仅供静态 profile 的专用服务器实例');
expect(PHP_OS_FAMILY !== 'Windows' || ($staticProfile !== null && ($driver === 'sqlite' || $external)), 'Windows 测量需要静态程序及匹配的专用数据库装置');
expect(is_string($artifact), '需要真实标准应用产物');
(new BuildPlatform())->assertArtifact($artifact);
$artifactHash = hash_file('sha256', $artifact);
if ($staticProfile !== null) {
    $manifest = (new ArtifactManifest())->read($artifact, null, $artifactHash);
    expect(($manifest['runtime-linkage'] ?? '') === 'static' && ($manifest['profile']['database'] ?? null) === $staticProfile
        && ($manifest['native-libraries'] ?? null) === [] && ($manifest['extension-modules'] ?? null) === [], '静态测量必须使用匹配 profile 的完整单程序');
}
$tools = $driver === 'sqlite' || $external ? [] : NativeDatabase::tools($driver, $arguments->text('database-tools', ''));
$externalSettings = [];
if ($external) {
    expect(getenv('TYPE_BENCHMARK_EXTERNAL_DATABASE') === '1', '外部性能数据库必须由本轮专用装置持有');
    $prefix = 'TYPE_' . strtoupper($driver) . '_';
    foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD'] as $key) {
        $value = getenv($prefix . $key);
        expect(is_string($value) && $value !== '', '缺少本轮专用数据库参数：' . $prefix . $key);
        $externalSettings[$key] = $value;
    }
    expect($externalSettings['HOST'] === '127.0.0.1' && $externalSettings['DATABASE'] === 'type_app_test'
        && ctype_digit($externalSettings['PORT']) && (int) $externalSettings['PORT'] > 0 && (int) $externalSettings['PORT'] < 65536, '外部数据库必须是本轮回环专用实例');
}
$base = $root . '/build/application-benchmark-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建基准目录');
$report = ['status' => 'running', 'path_base' => 'project-root', 'driver' => $driver, 'transport' => 'swoole',
    'delivery' => $staticProfile === null ? 'shared-runtime-benchmark' : 'static-profile-benchmark', 'static_profile' => $staticProfile,
    'sampling_protocol' => PHP_OS_FAMILY === 'Windows' ? 3 : 2,
    'sampling' => ['method' => PHP_OS_FAMILY === 'Windows' ? 'windows-cim-system-diagnostics-v1' : 'unix-ps-process-tree-v2', 'every_operations' => 10],
    'host' => ['os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'kernel' => php_uname('r'), 'controller_php' => PHP_VERSION],
    'artifact_sha256' => $artifactHash, 'repetitions' => [], 'lifecycle' => []];
if ($diagnostic) {
    // 只保留控制器与 INI 字节身份，配置内容和环境秘密不进入诊断。
    $controllerIni = php_ini_loaded_file();
    $scannedIni = [];
    foreach (preg_split('/,\s*/', php_ini_scanned_files() ?: '', -1, PREG_SPLIT_NO_EMPTY) as $iniFile) {
        $scannedIni[] = hash_file('sha256', trim($iniFile));
    }
    $programIni = $staticProfile === null ? (getenv('PHPRC') ?: null) : null;
    expect($programIni === null || is_file($programIni), '诊断需要明确的程序 INI 文件');
    $report['diagnostic'] = ['started_monotonic_ns' => hrtime(true), 'finished_monotonic_ns' => null,
        'controller' => ['script_sha256' => hash_file('sha256', __FILE__), 'php_binary_sha256' => hash_file('sha256', PHP_BINARY),
            'loaded_ini_sha256' => $controllerIni === false ? null : hash_file('sha256', $controllerIni), 'scanned_ini_sha256' => $scannedIni],
        'program_ini_sha256' => $programIni === null ? null : hash_file('sha256', $programIni),
        'note' => '只用于定位顺序与请求阶段差异；计时区间及三类业务断言沿用正式测量，不进入 benchmark-compare。'];
}
try {
    for ($round = 0; $round < $repetitions; $round++) {
        $work = $base . '/round-' . $round;
        expect(mkdir($work . '/app', 0700, true) && mkdir($work . '/php.d', 0700), '无法准备本轮数据目录');
        $database = null;
        $databaseAdmin = null;
        $databaseName = null;
        $databaseCreated = false;
        $server = null;
        $sampler = null;
        $samplingDirectory = $work . '/sampling';
        $secrets = [];
        $lifecycle = ['database_scope' => $driver === 'sqlite' ? 'round-local-file' : ($external ? 'owned-external-instance' : 'round-native-instance'),
            'database_removed' => false, 'server_stopped' => false, 'sampler' => null];
        try {
            $databaseEnvironment = [];
            if ($driver !== 'sqlite' && !$external) {
                $database = new NativeDatabase($work . '/database', $driver, $tools);
                $databaseEnvironment = $database->environment();
            }
            if ($staticProfile !== null) {
                $environment = PHP_OS_FAMILY === 'Windows' ? WindowsNativeSandbox::environment($work . '/app') : ['PATH' => '/usr/bin:/bin'];
            } else {
                $environment = array_replace((new BuildPlatform())->environment(getenv('PHP_HOME'), getenv('PHPX_HOME')), $databaseEnvironment);
                $environment['PHPRC'] = getenv('PHPRC') ?: '';
                $environment['PHP_INI_SCAN_DIR'] = $work . '/php.d';
            }
            $adminPassword = 'Benchmark-admin-password-2026';
            $customerPassword = 'Benchmark-customer-password-2026';
            $secrets = [$adminPassword, $customerPassword];
            $environment += ['APP_BASE_PATH' => $work . '/app', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_CACHE_ENABLED' => 'false',
                'APP_ADMIN_PASSWORD' => $adminPassword, 'APP_CUSTOMER_PASSWORD' => $customerPassword,
                'DB_DRIVER' => $driver, 'DB_SQLITE_FILE' => 'data/benchmark.sqlite'];
            if ($driver !== 'sqlite') {
                $prefix = 'TYPE_' . strtoupper($driver) . '_';
                if ($external) {
                    $dsn = $driver . ':host=' . $externalSettings['HOST'] . ';port=' . $externalSettings['PORT'] . ';dbname=' . $externalSettings['DATABASE'];
                    if ($driver === 'pgsql') {
                        $dsn .= ';connect_timeout=5';
                    }
                    $databaseAdmin = new PDO($dsn, $externalSettings['USER'], $externalSettings['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $databaseName = 'type_benchmark_' . bin2hex(random_bytes(6));
                    $databaseAdmin->exec('CREATE DATABASE ' . $databaseName);
                    $databaseCreated = true;
                    $lifecycle['database'] = $databaseName;
                    foreach ($externalSettings as $key => $value) {
                        $databaseEnvironment[$prefix . $key] = $key === 'DATABASE' ? $databaseName : $value;
                    }
                }
                foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USER' => 'USERNAME', 'PASSWORD' => 'PASSWORD'] as $from => $to) {
                    $environment['DB_' . $to] = $databaseEnvironment[$prefix . $from];
                }
                $secrets[] = $environment['DB_PASSWORD'];
            }
            // 锁持有者是测量控制端 PHP；静态程序的最小环境不能替代其 PDO/运行库配置。
            $controllerEnvironment = array_replace(getenv(), $environment, ['PATH' => getenv('PATH') ?: '']);
            $migration = new Process([$artifact, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '基准租户'], $root, $environment);
            try {
                $migrationResult = $migration->wait(30);
                file_put_contents($work . '/installation.log', str_replace($secrets, '<REDACTED>', $migrationResult->stdout . $migrationResult->stderr));
                expect($migrationResult->successful(), '基准应用安装失败：exit=' . $migrationResult->exitCode
                    . '; stdout=' . trim(str_replace($secrets, '<REDACTED>', $migrationResult->stdout))
                    . '; stderr=' . trim(str_replace($secrets, '<REDACTED>', $migrationResult->stderr)));
            } finally {
                $migration->stop();
            }
            [$server, $client] = benchmarkServer($artifact, $environment, $root);
            if (PHP_OS_FAMILY === 'Windows') {
                expect(mkdir($samplingDirectory, 0700), '无法创建本轮资源采样目录');
                $samplerStarted = hrtime(true);
                $sampler = new Process(
                    [(string) getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive',
                    '-ExecutionPolicy', 'Bypass', '-File', $root . '/tests/windows-benchmark-sampler.ps1', '-TargetProcessId', (string) $server->pid(), '-Directory', $samplingDirectory],
                    $root,
                    (new BuildPlatform())->phpEnvironment()
                );
                $until = microtime(true) + 15;
                while (!is_file($samplingDirectory . '/ready')) {
                    expect($sampler->running() && microtime(true) < $until, 'Windows 资源采样器未就绪：' . $sampler->stderr());
                    usleep(1000);
                }
                expect(file_get_contents($samplingDirectory . '/ready') === (string) $server->pid(), '资源采样器绑定了不同程序');
                $lifecycle['sampler'] = ['startup_seconds' => (hrtime(true) - $samplerStarted) / 1e9,
                    'controller_sha256' => hash_file('sha256', $root . '/tests/windows-benchmark-sampler.ps1')];
            }
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
            }, $warmup, $iterations, $sampler, $samplingDirectory, $diagnostic);
            $sequence = 0;
            $crudTimings = [];
            // 正式闭包保持原请求与变量生命周期；仅在测量窗口外选择额外诊断实现。
            $crudOperation = static function () use ($client, $headers, &$sequence): float {
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
            };
            if ($diagnostic) {
                $crudOperation = static function () use ($client, $headers, &$sequence, &$crudTimings): float {
                    $started = hrtime(true);
                    $login = 'bench-' . (++$sequence);
                    $requestStarted = hrtime(true);
                    $created = $client->request('POST', '/admin/users', $headers, json_encode([
                        'login' => $login, 'name' => '基准用户', 'password' => 'Benchmark-user-password-2026',
                    ], JSON_THROW_ON_ERROR));
                    $createMs = (hrtime(true) - $requestStarted) / 1e6;
                    expect($created->status === 200, '基准创建失败');
                    $user = $created->json()['data'];
                    $path = '/admin/users/' . $user['id'];
                    $requestStarted = hrtime(true);
                    $read = $client->request('GET', $path, $headers);
                    $readMs = (hrtime(true) - $requestStarted) / 1e6;
                    expect($read->json()['data']['items'][0]['name'] === '基准用户', '基准读取错误');
                    $requestStarted = hrtime(true);
                    $updated = $client->request('PATCH', $path, $headers, json_encode(['login' => $login . '-u', 'name' => '基准用户-更新', 'version' => $user['version']], JSON_THROW_ON_ERROR));
                    $updateMs = (hrtime(true) - $requestStarted) / 1e6;
                    expect($updated->status === 200 && $updated->json()['data']['name'] === '基准用户-更新', '基准更新错误');
                    $requestStarted = hrtime(true);
                    $disabled = $client->request('POST', $path . '/status', $headers, json_encode(['version' => $updated->json()['data']['version'], 'enabled' => false], JSON_THROW_ON_ERROR));
                    $statusMs = (hrtime(true) - $requestStarted) / 1e6;
                    expect($disabled->status === 200, '基准停用失败');
                    $latency = (hrtime(true) - $started) / 1e6;
                    $crudTimings[] = ['create_ms' => $createMs, 'read_ms' => $readMs, 'update_ms' => $updateMs, 'status_ms' => $statusMs];
                    return $latency;
                };
            }
            $measurements['crud'] = benchmarkSample($server, $crudOperation, $warmup, $iterations, $sampler, $samplingDirectory, $diagnostic);
            if ($diagnostic) {
                // 四次 HTTP 的记录与排序前操作序列逐项对应；十次预热不属于正式一百条。
                $measurements['crud']['diagnostic']['http_operations_ms'] = array_slice($crudTimings, $warmup);
            }
            $created = $client->request('POST', '/admin/users', $headers, json_encode([
                'login' => 'lock-user', 'name' => '锁等待用户', 'password' => 'Benchmark-user-password-2026',
            ], JSON_THROW_ON_ERROR));
            expect($created->status === 200, '无法准备锁等待数据');
            $lockUser = $created->json()['data'];
            $slowStatuses = [];
            $measurements['slow-database'] = benchmarkSample($server, static function () use ($client, $headers, $work, $root, $controllerEnvironment, $driver, &$lockUser, &$slowStatuses): float {
                $ready = $work . '/lock-ready';
                $childEnvironment = $controllerEnvironment + ['TYPE_BENCHMARK_USER' => (string) $lockUser['id'], 'TYPE_BENCHMARK_READY' => $ready];
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
                    $holderResult = $holder->stop();
                    file_put_contents($work . '/lock-holder-' . bin2hex(random_bytes(6)) . '.log', str_replace(
                        array_filter([$childEnvironment['DB_PASSWORD'] ?? '']),
                        '<REDACTED>',
                        $holderResult->stdout . $holderResult->stderr
                    ));
                    if (is_file($ready)) {
                        unlink($ready);
                    }
                }
            }, $warmup, $iterations, $sampler, $samplingDirectory, $diagnostic);
            $measurements['slow-database']['initial_statuses_including_warmup'] = $slowStatuses;
            $measurements['slow-database']['contention'] = '持有真实写锁50ms；SQLite允许BUSY后确认无写入并由客户端重试一次，其余驱动等待行锁。';
            $report['repetitions'][] = $measurements;
        } catch (Throwable $error) {
            $lifecycle['failure'] = str_replace($secrets, '<REDACTED>', $error->getMessage());
            throw $error;
        } finally {
            $cleanupError = null;
            if ($sampler !== null) {
                try {
                    expect(file_put_contents($samplingDirectory . '/stop', 'stop') === 4, '无法请求资源采样器停止');
                    $samplerResult = $sampler->wait(15);
                    $receipt = json_decode(trim($samplerResult->stdout), true, 512, JSON_THROW_ON_ERROR);
                    $lifecycle['sampler'] ??= [];
                    $lifecycle['sampler']['stop'] = $receipt;
                    $lifecycle['sampler']['exit_code'] = $samplerResult->exitCode;
                    $lifecycle['sampler']['timed_out'] = $samplerResult->timedOut;
                    expect($samplerResult->successful() && ($receipt['status'] ?? '') === 'stopped'
                        && ($receipt['pid'] ?? null) === $server?->pid()
                        && ($receipt['samples'] ?? null) === count(glob($samplingDirectory . '/*.json')), 'Windows 资源采样器未正常结束');
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
                try {
                    $samplerResult = $sampler->stop();
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
                try {
                    $samplerLog = $sampler->stdout() . $sampler->stderr();
                    expect(file_put_contents($work . '/sampler.log', $samplerLog) === strlen($samplerLog), '无法保留资源采样器日志');
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
            }
            foreach ([$server] as $process) {
                if ($process !== null) {
                    try {
                        $stopped = $process->stop(10);
                        file_put_contents($work . '/server.log', str_replace($secrets, '<REDACTED>', $stopped->stdout . $stopped->stderr));
                        $lifecycle['server_stopped'] = $stopped->successful();
                        $lifecycle['server_stop'] = ['exit_code' => $stopped->exitCode, 'signal' => $stopped->signal, 'timed_out' => $stopped->timedOut];
                        expect($stopped->successful(), '基准服务未正常退出');
                    } catch (Throwable $error) {
                        $cleanupError ??= $error;
                    }
                }
            }
            try {
                if ($databaseCreated) {
                    $databaseAdmin->exec('DROP DATABASE ' . $databaseName);
                    $lifecycle['database_removed'] = true;
                }
                if ($database !== null) {
                    $database->close();
                    $lifecycle['database_server_stopped'] = $database->evidence()['owned-server-stopped'];
                    // close 已保全数据库日志和身份；只回收本轮专用实例的数据文件。
                    removeTestDirectory($work . '/database/data');
                    $lifecycle['database_removed'] = true;
                }
                if ($driver === 'sqlite' && ($server === null || $lifecycle['server_stopped'])) {
                    removeTestDirectory($work . '/app');
                    $lifecycle['database_removed'] = true;
                }
            } catch (Throwable $error) {
                $cleanupError ??= $error;
            }
            $databaseAdmin = null;
            if ($cleanupError !== null) {
                $lifecycle['cleanup_failure'] = str_replace($secrets, '<REDACTED>', $cleanupError->getMessage());
            }
            $report['lifecycle'][] = $lifecycle;
            if ($cleanupError !== null) {
                throw $cleanupError;
            }
        }
    }
    expect(hash_file('sha256', $artifact) === $artifactHash, '测量期间产物变化');
    $report['status'] = $diagnostic ? 'diagnostic-not-compared' : 'passed';
} finally {
    if ($report['status'] === 'running') {
        $report['status'] = 'failed';
    }
    if ($diagnostic) {
        $report['diagnostic']['finished_monotonic_ns'] = hrtime(true);
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
echo '真实应用三类负载测量完成：' . substr($base, strlen($root) + 1) . "/verification.json\n";
