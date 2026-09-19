<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Orm\Database;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\Arguments;
use Type\Runtime\CapacityException;
use Type\Runtime\ExecutionScope;

/**
 * @param Closure(): mixed $operation 返回资源的释放不计入本阶段，操作内显式收尾仍计入。
 * @return array 样本保留原顺序；驱动execute含传输/缓冲，不能标作纯服务器SQL时间。
 */
function ioPhaseSamples(Closure $operation, int $samples, int $warmup): array
{
    $values = [];
    for ($index = 0; $index < $warmup + $samples; $index++) {
        $started = hrtime(true);
        $value = $operation();
        $elapsed = (hrtime(true) - $started) / 1e6;
        unset($value);
        if ($index >= $warmup) {
            $values[] = $elapsed;
        }
    }
    $ordered = $values;
    sort($ordered, SORT_NUMERIC);
    return ['samples_ms' => $values, 'p50_ms' => $ordered[(int) ceil($samples * 0.5) - 1],
        'p95_ms' => $ordered[(int) ceil($samples * 0.95) - 1], 'p99_ms' => $ordered[(int) ceil($samples * 0.99) - 1]];
}

/** 使用既有日志公开接口和真实满载流；不修改日志实现以加入测量钩子。 */
function ioBlockedLog(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair !== false, '无法准备独立日志流');
    $scope = new ExecutionScope();
    $logs = null;
    try {
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $filled = false;
        for ($index = 0; $index < 8192; $index++) {
            if (fwrite($pair[0], str_repeat('F', 4096)) === 0) {
                $filled = true;
                break;
            }
        }
        expect($filled, '未形成真实输出背压');
        $output = Output::stream($pair[0], maxRecords: 3, maxBytes: 2048, maxRecordBytes: 1024);
        $logs = new LogManager('io-baseline', ['app' => new Channel($output)], stopSeconds: 0.03);
        $logger = $logs->logger($scope);
        $enqueue = ioPhaseSamples(static fn () => $logger->info('blocked-log'), 20, 0);
        $before = $output->stats();
        expect($before['pending_records'] === 3 && $before['dropped_full'] === 17, '日志容量或丢弃计数改变');
        $started = hrtime(true);
        $logs->stop();
        $stop = (hrtime(true) - $started) / 1e6;
        $after = $output->stats();
        expect($after['pending_records'] === 0 && $after['dropped_stop'] === 3 && $after['drain_timeouts'] === 1, '日志未在停止预算后完成清理');
        return ['enqueue' => $enqueue, 'stop_ms' => $stop, 'before' => $before, 'after' => $after];
    } finally {
        $scope->close();
        $logs?->stop();
        fclose($pair[0]);
        fclose($pair[1]);
    }
}

/** 仅回收本控制器新建目录中的成功测试数据，原始报告和脱敏服务日志继续保留。 */
function ioPhaseCleanData(string $base): int
{
    $expectedParent = realpath(dirname(__DIR__) . '/build');
    expect(realpath(dirname($base)) === $expectedParent && preg_match('/^io-phases-[a-f0-9]{12}$/D', basename($base)) === 1
        && is_dir($base) && !is_link($base), '清理目标不是本控制器的专用目录');
    $database = $base . '/database';
    foreach (['server.log', 'initialize.log', 'socket-directory.log', 'parent-pid.log'] as $name) {
        if (is_file($database . '/' . $name)) {
            expect(rename($database . '/' . $name, $base . '/' . $name), '无法保全数据库日志');
        }
    }
    $bytes = 0;
    if (is_dir($database) && !is_link($database)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($database, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                expect(rmdir($file->getPathname()), '无法回收数据库目录');
            } else {
                $bytes += $file->isLink() ? 0 : $file->getSize();
                expect(unlink($file->getPathname()), '无法回收数据库文件');
            }
        }
        expect(rmdir($database), '无法移除数据库数据根');
    }
    foreach (['baseline.sqlite', 'baseline.sqlite-wal', 'baseline.sqlite-shm', 'durable.bin'] as $name) {
        $file = $base . '/' . $name;
        if (is_file($file) || is_link($file)) {
            $bytes += is_link($file) ? 0 : filesize($file);
            expect(unlink($file), '无法回收阶段测量文件');
        }
    }
    return $bytes;
}

$root = dirname(__DIR__);
$arguments = new Arguments($argv, ['driver', 'database-tools', 'samples', 'warmup', 'rows'], []);
$name = $arguments->text('driver', 'sqlite');
expect(in_array($name, ['mysql', 'pgsql', 'sqlite'], true), '必须明确选择三库之一');
$samples = $arguments->integer('samples', 30, 2, 500);
$warmup = $arguments->integer('warmup', 5, 0, 100);
$rows = $arguments->integer('rows', 5000, 1, 100000);
$base = $root . '/build/io-phases-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建阶段基线目录');
$native = null;
$pool = null;
$scope = null;
$pdo = null;
$statement = null;
$report = ['protocol' => 1, 'status' => 'running', 'execution' => 'php-public-components', 'controller_sha256' => hash_file('sha256', __FILE__),
    'driver' => $name, 'host' => ['os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'php' => PHP_VERSION, 'zts' => PHP_ZTS],
    'seed' => 'type-io-baseline-v1', 'rows' => $rows, 'payload_bytes_per_row' => 256,
    'samples' => $samples, 'warmup' => $warmup, 'concurrency' => 1, 'application_connection_limit' => 1,
    'limitations' => ['PHP公开组件的分阶段测量，不能代替AOT行为或线程并发验收。',
        '建连包含驱动基线初始化；execute包含驱动传输及缓冲，fetchAll为后续结果物化。',
        '文件测量是PHP文件API的持久化成本，不是ExportService完整事务或持久回执延迟。',
        '同步池没有等待队列，饱和时测量拒绝返回耗时；不能编造排队等待样本。',
        '本机工作负载不受控制，这些样本仅作诊断；不据此宣称性能提升。']];
try {
    $tools = $name === 'sqlite' ? [] : NativeDatabase::tools($name, $arguments->text('database-tools', ''));
    $native = new NativeDatabase($base . '/database', $name, $tools);
    $environment = $native->environment();
    $prefix = 'TYPE_' . strtoupper($name) . '_';
    $driver = match ($name) {
        'mysql' => new MysqlDriver($environment[$prefix . 'HOST'], (int) $environment[$prefix . 'PORT'], $environment[$prefix . 'DATABASE'], $environment[$prefix . 'USER'], $environment[$prefix . 'PASSWORD']),
        'pgsql' => new PgsqlDriver($environment[$prefix . 'HOST'], (int) $environment[$prefix . 'PORT'], $environment[$prefix . 'DATABASE'], $environment[$prefix . 'USER'], $environment[$prefix . 'PASSWORD']),
        'sqlite' => new SqliteDriver($base . '/baseline.sqlite'),
    };
    $report['phases']['connect_and_initialize'] = ioPhaseSamples(static fn (): PDO => $driver->connect(), $samples, $warmup);
    $pdo = $driver->connect();
    $report['server_version'] = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $pdo->exec('CREATE TABLE io_baseline (id INTEGER PRIMARY KEY, payload VARCHAR(256) NOT NULL)');
    $statement = $pdo->prepare('INSERT INTO io_baseline (id, payload) VALUES (?, ?)');
    $expectedHash = hash_init('sha256');
    $pdo->beginTransaction();
    for ($index = 1; $index <= $rows; $index++) {
        $payload = str_repeat(hash('sha256', 'type-io-baseline-v1/' . $index), 4);
        $statement->execute([$index, $payload]);
        hash_update($expectedHash, $index . ':' . $payload . "\n");
    }
    $pdo->commit();
    $statement->closeCursor();
    $report['dataset_sha256'] = hash_final($expectedHash);
    $statement = $pdo->prepare('SELECT id, payload FROM io_baseline ORDER BY id');
    $executions = [];
    $materializations = [];
    for ($iteration = 0; $iteration < $samples + $warmup; $iteration++) {
        $started = hrtime(true);
        $statement->execute();
        $executed = hrtime(true);
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        $fetched = hrtime(true);
        $statement->closeCursor();
        $actualHash = hash_init('sha256');
        foreach ($result as $row) {
            hash_update($actualHash, $row['id'] . ':' . $row['payload'] . "\n");
        }
        expect(count($result) === $rows && hash_final($actualHash) === $report['dataset_sha256'], '真实结果内容或顺序不一致');
        unset($result);
        if ($iteration >= $warmup) {
            $executions[] = ($executed - $started) / 1e6;
            $materializations[] = ($fetched - $executed) / 1e6;
        }
    }
    $report['phases']['statement_execute'] = ['samples_ms' => $executions];
    $report['phases']['result_fetch_all'] = ['samples_ms' => $materializations];
    $statement = null;
    $pdo = null;
    $pool = new Database($driver, 1, 1);
    $scope = new ExecutionScope();
    $connection = $pool->connect($scope);
    $other = new ExecutionScope();
    try {
        $report['phases']['saturated_pool_rejection'] = ioPhaseSamples(static function () use ($pool, $other): void {
            try {
                $pool->connect($other);
            } catch (CapacityException) {
                return;
            }
            throw new RuntimeException('饱和同步池未拒绝');
        }, $samples, $warmup);
    } finally {
        $other->close();
    }
    $report['phases']['orm_stream_250'] = ioPhaseSamples(static function () use ($connection, $rows, $report): void {
        $stream = $connection->stream('SELECT id, payload FROM io_baseline ORDER BY id', [], 250);
        $actual = hash_init('sha256');
        $count = 0;
        try {
            while (($row = $stream->next()) !== null) {
                hash_update($actual, $row['id'] . ':' . $row['payload'] . "\n");
                $count++;
            }
            expect($count === $rows && hash_final($actual) === $report['dataset_sha256'], '公开ORM流结果错误');
        } finally {
            $stream->close();
        }
    }, $samples, $warmup);
    $scope->close();
    $report['pool_after_close'] = $pool->statistics();
    $backendIds = [];
    $report['phases']['pool_reborrow_identify_close'] = ioPhaseSamples(static function () use ($pool, $name, &$backendIds): void {
        $leaseScope = new ExecutionScope();
        try {
            $lease = $pool->connect($leaseScope);
            if ($name !== 'sqlite') {
                $backendIds[] = (int) $lease->query($name === 'mysql' ? 'SELECT CONNECTION_ID() AS id' : 'SELECT pg_backend_pid() AS id')[0]['id'];
            }
        } finally {
            $leaseScope->close();
        }
    }, $samples, $warmup);
    $report['physical_connections'] = ['samples_include_warmup' => true, 'backend_ids' => $backendIds,
        'distinct' => $name === 'sqlite' ? null : count(array_unique($backendIds)),
        'sqlite_note' => $name === 'sqlite' ? '没有用PHP对象ID冒充SQLite原生句柄身份' : null];
    $pool->close();
    $scope = null;
    $filePayload = str_repeat(hash('sha256', 'type-io-baseline-v1/file'), 8192);
    $report['file_bytes'] = strlen($filePayload);
    $report['phases']['file_write_flush_fsync'] = ioPhaseSamples(static function () use ($base, $filePayload): void {
        $file = fopen($base . '/durable.bin', 'wb');
        expect(is_resource($file), '无法创建独立持久化文件');
        try {
            $offset = 0;
            while ($offset < strlen($filePayload)) {
                $written = fwrite($file, substr($filePayload, $offset));
                expect(is_int($written) && $written > 0, '文件写入失败');
                $offset += $written;
            }
            expect(fflush($file) && fsync($file), '文件未完成真实持久化');
        } finally {
            fclose($file);
        }
    }, $samples, $warmup);
    expect(hash_file('sha256', $base . '/durable.bin') === hash('sha256', $filePayload), '持久化文件内容错误');
    $report['blocked_log'] = ioBlockedLog();
    $report['status'] = 'passed';
} finally {
    try {
        $statement = null;
        $pdo = null;
        $cleanupFailures = [];
        foreach ([$scope, $pool, $native] as $owned) {
            try {
                $owned?->close();
            } catch (Throwable $cleanup) {
                $cleanupFailures[] = get_class($cleanup);
            }
        }
        $report['database'] = $native?->evidence();
        if ($cleanupFailures !== []) {
            $report['status'] = 'cleanup-failed';
            $report['cleanup_failures'] = $cleanupFailures;
            throw new RuntimeException('阶段基线清理失败，保留现场');
        }
        if ($report['status'] === 'passed') {
            try {
                $report['cleanup'] = ['removed_data_bytes' => ioPhaseCleanData($base), 'reports_and_logs_retained' => true];
            } catch (Throwable $cleanup) {
                $report['status'] = 'cleanup-failed';
                throw $cleanup;
            }
        }
    } finally {
        if ($report['status'] === 'running') {
            $report['status'] = 'failed';
        }
        file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
}
echo 'I/O阶段基线：' . substr($base, strlen($root) + 1) . "/verification.json\n";
