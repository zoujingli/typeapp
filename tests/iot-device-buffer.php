<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

/** 同一公开缓存调用者分别作为PHP及全量AOT入口，测试只使用隔离的真实文件SQLite。 */
function deviceBufferApplication(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

use app\iot\service\DeviceBuffer;
use app\iot\service\IngestionService;

function bufferCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function bufferOpen(string $name, int $records = 8640, int $bytes = 8640000, int $exceptions = 128, int $exceptionBytes = 1048576): DeviceBuffer
{
    return new DeviceBuffer((string) getenv('BUFFER_TEST_DIRECTORY') . '/' . $name . '.sqlite', str_repeat('a', 32), str_repeat('b', 32), $records, $bytes, $exceptions, $exceptionBytes);
}

function bufferReceipt(array $message, string $code = 'accepted'): array
{
    return ['app_version' => 1, 'type' => 'ingestion_receipt', 'device_id' => str_repeat('a', 32), 'ownership_id' => str_repeat('b', 32),
        'sequence' => $message['sequence'], 'content_hash' => $message['content_hash'], 'status' => $code === 'accepted' ? 'accepted' : 'rejected',
        'code' => $code, 'received_at' => 1800000000];
}

/** 固定总JSON字节用于观察纯载荷额度；序号变化时重新计算测试采样的字符串长度。 */
function bufferValues(string $sequence, int $bytes): stdClass
{
    $values = (object) ['note' => ''];
    $sample = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => str_repeat('a', 32), 'ownership_id' => str_repeat('b', 32),
        'model_version' => 1, 'sequence' => $sequence, 'sampled_at' => 1, 'values' => $values];
    $values->note = str_repeat('x', $bytes - strlen(json_encode($sample, JSON_THROW_ON_ERROR)));
    return $values;
}

function bufferCases(): array
{
    $buffer = bufferOpen('cases', 4, 100000, 2);
    $values = (object) ['temperature' => 20.0, 'note' => '跨重启原内容'];
    $first = $buffer->enqueue(1, 'telemetry', 1, $values);
    $event = $buffer->enqueue(2, 'event', 2, (object) ['code' => 42], 'fault');
    $values->temperature = 99;
    bufferCheck($first !== null && $event !== null && $first['sequence'] === '1' && $event['sequence'] === '2', '持久序号或入队失败');
    bufferCheck($buffer->pending(2) === [$first, $event], '调用方对象修改改变原字节');
    bufferCheck($first['content_hash'] === IngestionService::contentHash($first['payload']), '生产双方摘要不一致');
    bufferCheck(IngestionService::contentHash('{"z":20.0,"a":1}') === IngestionService::contentHash('{"a":1e0,"z":2e1}'), '精确规范化未复用');
    $original = $buffer->pending(100);
    $buffer->close();
    $buffer = bufferOpen('cases', 4, 100000, 2);
    bufferCheck($buffer->pending(100) === $original, '超48小时的本地记录不能自行删除或改变模型');
    foreach ([['device_id' => str_repeat('c', 32)], ['ownership_id' => str_repeat('d', 32)], ['content_hash' => str_repeat('0', 64)],
        ['type' => 'PUBACK'], ['status' => 'retry'], ['received_at' => '1800000000'], ['sequence' => '01'],
        ['status' => 'rejected', 'code' => 'unknown_reason']] as $change) {
        $denied = false;
        try {
            $buffer->applyReceipt(array_replace(bufferReceipt($first), $change));
        } catch (InvalidArgumentException) {
            $denied = true;
        }
        bufferCheck($denied && $buffer->pending(100) === $original, '无效回执改变了原缓存');
    }
    bufferCheck(!$buffer->applyReceipt(array_replace(bufferReceipt($first), ['sequence' => '100'])), '未知序号错误清理缓存');
    bufferCheck($buffer->applyReceipt(bufferReceipt($first)) && !$buffer->applyReceipt(bufferReceipt($first)), '成功回执未幂等清理');
    bufferCheck($buffer->applyReceipt(bufferReceipt($event, 'sample_expired')), '永久拒绝没有终局化');
    for ($index = 0; $index < 2; $index++) {
        $message = $buffer->enqueue(1, 'telemetry', 1, (object) ['temperature' => $index]);
        bufferCheck($message !== null && $buffer->applyReceipt(bufferReceipt($message, 'clock_ahead')), '异常没有入独立记录');
    }
    $stats = $buffer->statistics();
    bufferCheck($stats['pending_count'] === 0 && $stats['accepted_total'] === 1 && $stats['rejected_total'] === 3
        && $stats['exception_count'] === 2 && $stats['exceptions_dropped'] === 1, '终局异常额度或累计事实不符');
    bufferCheck(count($buffer->exceptions()) === 2 && $buffer->exceptions()[0]['code'] === 'clock_ahead', '异常没有保留原因及原件');
    $buffer->close();
    $buffer = bufferOpen('cases', 4, 100000, 2);
    bufferCheck($buffer->statistics() === $stats, '永久拒绝状态和计数没有跨重启持久');
    $buffer->close();
    $closed = false;
    try { $buffer->pending(); } catch (LogicException) { $closed = true; }
    bufferCheck($closed, '关闭后仍能使用已释放租约');
    foreach ([[str_repeat('c', 32), str_repeat('b', 32), 4], [str_repeat('a', 32), str_repeat('c', 32), 4], [str_repeat('a', 32), str_repeat('b', 32), 5]] as $configuration) {
        $mismatch = false;
        try {
            $wrong = new DeviceBuffer((string) getenv('BUFFER_TEST_DIRECTORY') . '/cases.sqlite', $configuration[0], $configuration[1], $configuration[2], 100000, 2);
            $wrong->close();
        } catch (LogicException) { $mismatch = true; }
        bufferCheck($mismatch, '同一文件静默替换身份或额度');
    }
    $bytes = bufferOpen('bytes', 10, 1000);
    $record = $bytes->enqueue(1, 'telemetry', 1, bufferValues('1', 700));
    bufferCheck($record !== null && strlen($record['payload']) === 700, '固定字节装置无效');
    bufferCheck($bytes->enqueue(1, 'telemetry', 1, bufferValues('2', 700)) === null, '字节超限未拒绝');
    $full = $bytes->statistics();
    bufferCheck($full['full'] && $full['capacity_reason'] === 'bytes' && $full['not_admitted'] === 1 && $full['last_sequence'] === '1', '字节拒绝未持久说明或消耗了序号');
    $bytes->close();
    $bytes = bufferOpen('bytes', 10, 1000);
    bufferCheck($bytes->statistics() === $full && $bytes->pending() === [$record], '满额覆盖未确认原记录');
    bufferCheck($bytes->applyReceipt(bufferReceipt($record)) && !$bytes->statistics()['full'], '回执未释放字节额度');
    bufferCheck($bytes->enqueue(1, 'telemetry', 1, bufferValues('2', 700))['sequence'] === '2', '容量恢复后序号不连续');
    $bytes->close();
    $count = bufferOpen('count', 2, 100000);
    $count->enqueue(1, 'telemetry', 1, (object) ['value' => 1]);
    $count->enqueue(1, 'telemetry', 1, (object) ['value' => 2]);
    bufferCheck($count->enqueue(1, 'telemetry', 1, (object) ['value' => 3]) === null && $count->statistics()['capacity_reason'] === 'records', '条数先满未拒绝');
    $count->close();
    $exceptionBytes = bufferOpen('exception-bytes', 10, 100000, 10, 16384);
    for ($ordinal = 1; $ordinal <= 2; $ordinal++) {
        $item = $exceptionBytes->enqueue(1, 'telemetry', 1, bufferValues((string) $ordinal, 12000));
        $exceptionBytes->applyReceipt(bufferReceipt($item, 'sample_expired'));
    }
    bufferCheck($exceptionBytes->statistics()['exception_count'] === 1 && $exceptionBytes->statistics()['exception_payload_bytes'] === 12000
        && $exceptionBytes->statistics()['exceptions_dropped'] === 1, '异常字节额度无效');
    $tooLarge = false;
    try { $exceptionBytes->enqueue(1, 'telemetry', 1, (object) ['note' => str_repeat('x', 16384)]); } catch (InvalidArgumentException) { $tooLarge = true; }
    bufferCheck($tooLarge && $exceptionBytes->statistics()['last_sequence'] === '2', '超大载荷改变持久序号');
    $exceptionBytes->close();
    return ['original-bytes-and-model', 'restart-and-old-message-preservation', 'strict-receipt-identity-and-hash', 'accepted-idempotency',
        'terminal-rejection-not-success', 'bounded-exceptions-and-overflow', 'identity-and-capacity-reopen-guard', 'byte-limit-and-release', 'record-limit', '16k-json-limit'];
}

function bufferCapacity(): array
{
    $buffer = bufferOpen('baseline');
    for ($sequence = 1; $sequence <= 8640; $sequence++) {
        $record = $buffer->enqueue(1, 'telemetry', 1, bufferValues((string) $sequence, 1000));
        bufferCheck($record !== null && $record['sequence'] === (string) $sequence && $record['payload_bytes'] === 1000, '24小时基线缓存不足');
    }
    bufferCheck($buffer->enqueue(1, 'telemetry', 1, bufferValues('8641', 1000)) === null, '基线满额未拒绝');
    $stats = $buffer->statistics();
    bufferCheck($stats['pending_count'] === 8640 && $stats['pending_bytes'] === 8640000 && $stats['not_admitted'] === 1, '纯载荷基线计量不符');
    $buffer->close();
    $buffer = bufferOpen('maximum');
    $accepted = 0;
    for ($index = 1; $index <= 528; $index++) {
        $message = $buffer->enqueue(1, 'telemetry', 1, bufferValues((string) $index, 16384));
        if ($message !== null) { $accepted++; }
    }
    bufferCheck($accepted === 527 && $buffer->statistics()['pending_count'] === 527 && $buffer->statistics()['full'], '16KiB不应声称同空间容纳8640条');
    $buffer->close();
    return ['baseline' => $stats, 'maximum_payload_records' => $accepted];
}

function main(int $argc, array $argv): void
{
    $role = $argv[1] ?? 'cases';
    if ($role === 'cases') { echo json_encode(bufferCases(), JSON_THROW_ON_ERROR), "\n"; return; }
    if ($role === 'capacity') { echo json_encode(bufferCapacity(), JSON_THROW_ON_ERROR), "\n"; return; }
    if ($role === 'crash' || $role === 'resume') {
        $buffer = bufferOpen('crash');
        if ($role === 'crash') {
            $message = $buffer->enqueue(7, 'telemetry', 1, (object) ['value' => 'kill-after-local-commit']);
            echo json_encode($message, JSON_THROW_ON_ERROR), "\n";
            while (true) { usleep(50000); }
        }
        $restored = $buffer->pending();
        bufferCheck(count($restored) === 1 && $restored[0]['sequence'] === '1' && $restored[0]['model_version'] === 7, '硬退出丢失原身份');
        bufferCheck($buffer->enqueue(8, 'telemetry', 1, (object) ['value' => 'after-restart'])['sequence'] === '2', '硬退出重启后序号重用');
        $buffer->close();
        echo json_encode($restored[0], JSON_THROW_ON_ERROR), "\n"; return;
    }
    if ($role === 'sequence') {
        $buffer = bufferOpen('sequence');
        $message = $buffer->enqueue(1, 'telemetry', 1, (object) ['value' => 'boundary']);
        echo json_encode($message, JSON_THROW_ON_ERROR), "\n";
        $buffer->close(); return;
    }
    if ($role === 'exhausted') {
        $buffer = bufferOpen('sequence');
        $before = $buffer->statistics();
        $refused = false;
        try { $buffer->enqueue(1, 'telemetry', 1, (object) ['value' => 'overflow']); } catch (LogicException) { $refused = true; }
        bufferCheck($refused && $buffer->statistics() === $before, '序号溢出回绕或改变缓存');
        $buffer->close(); echo "sequence-exhausted\n"; return;
    }
    if ($role === 'write-failure') {
        $buffer = bufferOpen('sequence');
        $before = $buffer->statistics();
        $refused = false;
        try { $buffer->enqueue(1, 'telemetry', 1, (object) ['value' => 'write-failure']); } catch (Throwable) { $refused = true; }
        bufferCheck($refused && $buffer->statistics() === $before, 'SQLite写失败分离了原件与序号事务');
        $buffer->close(); echo "write-rolled-back\n"; return;
    }
    throw new InvalidArgumentException('未知缓存验证角色');
}
PHP;
}

$root = dirname(__DIR__);
$native = in_array('--native', $argv, true);
$base = $root . '/build/iot-device-buffer-' . bin2hex(random_bytes(6));
expect(mkdir($base . '/app/iot', 0700, true), '无法创建独立设备缓存验收目录');
$relative = substr($base, strlen($root) + 1);
$sourceCount = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/iot', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $source) {
    $target = $base . '/app/iot' . substr($source->getPathname(), strlen($root . '/app/iot'));
    if ($source->isDir()) {
        expect(mkdir($target, 0700), '无法复制独立IoT应用目录');
    } else {
        expect(copy($source->getPathname(), $target), '无法复制独立IoT应用源码');
        $sourceCount++;
    }
}
file_put_contents($base . '/app/main.php', deviceBufferApplication());
$rootComposer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$toolchain = json_decode(file_get_contents($root . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$repositories = $rootComposer['repositories'];
$repositories[0]['url'] = '../../plugin/*';
$repositories[0]['options']['symlink'] = false;
$composer = ['name' => 'type-tests/device-buffer', 'type' => 'project', 'license' => 'Apache-2.0', 'require' => $rootComposer['require'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']],
    'autoload' => ['psr-4' => ['app\\iot\\' => 'app/iot/'], 'classmap' => ['app/main.php']], 'repositories' => $repositories,
    'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($base . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/toolchain.lock.json', $base . '/toolchain.lock.json');
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts', '--no-progress'], $base);
$command = [PHP_BINARY, '-r', 'require "vendor/autoload.php"; require "app/main.php"; main($argc, $argv);', '--'];
$report = null;
$noSource = 'not-verified';
if ($native) {
    // 独立应用完整复制IoT域并实际安装全部生产包，不遗漏其声明入口或排除难编译文件。
    $configuration = ['name' => 'iot-device-buffer-verification', 'entry' => 'app/main.php', 'sources' => ['app'],
        'output' => 'build/native/type-app', 'build-directory' => 'build/native/compiler'];
    file_put_contents($base . '/type-app.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $built = successful([PHP_BINARY, $base . '/vendor/bin/type', $base . '/type-app.json'], $base);
    file_put_contents($base . '/build.log', $built);
    $report = json_decode(file_get_contents($base . '/build/native/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $command = nativeCommand($base . '/build/native/type-app');
    if (PHP_OS_FAMILY === 'Darwin') {
        expect(mkdir($base . '/runtime', 0700), '无法创建搬迁运行目录');
        copy($base . '/build/native/type-app', $base . '/runtime/type-app');
        chmod($base . '/runtime/type-app', 0700);
        copy($report['runtime-profile']['ini'], $base . '/runtime/php.ini');
        $sourcePolicy = file_get_contents($root . '/tests/fixtures/iot-no-source.sb')
            . "\n(deny file-read-data (subpath (param \"ROOT_APP\")) (subpath (param \"ROOT_VENDOR\")))\n";
        file_put_contents($base . '/no-source.sb', $sourcePolicy);
        $policy = ['sandbox-exec', '-f', $base . '/no-source.sb'];
        foreach (['APP' => $base . '/app', 'PLUGIN' => $root . '/plugin', 'VENDOR' => $base . '/vendor', 'CONFIG' => $root . '/config',
            'ROOT_APP' => $root . '/app', 'ROOT_VENDOR' => $root . '/vendor',
            'COMPILER' => $base . '/build/native/compiler', 'COMPOSER' => $root . '/composer.json'] as $name => $path) {
            array_push($policy, '-D', $name . '=' . $path);
        }
        $probe = 'foreach (array_slice($argv, 1) as $path) { if (@file_get_contents($path) !== false) { throw new RuntimeException("source-readable"); } } echo "denied\n";';
        expect(successful([...$policy, PHP_BINARY, '-n', '-r', $probe, $root . '/app/iot/service/DeviceBuffer.php', $root . '/vendor/autoload.php', $base . '/app/main.php', $base . '/vendor/autoload.php'], $base) === "denied\n", '禁读源码装置未生效');
        $command = [...$policy, 'env', 'PHPRC=' . $base . '/runtime/php.ini', 'PHP_INI_SCAN_DIR=', $base . '/runtime/type-app'];
        $noSource = 'kernel-denied-production-and-generated-source';
    }
}
$environment = getenv();
$environment['BUFFER_TEST_DIRECTORY'] = $base;
$run = static function (string $role) use ($command, $base, $environment): string {
    $result = (new Process([...$command, $role], $base, $environment))->wait(60);
    expect($result->successful() && $result->stderr === '', '设备缓存公开入口失败：' . $role . ' ' . $result->stdout . $result->stderr);
    return trim($result->stdout);
};
$checks = json_decode($run('cases'), true, 32, JSON_THROW_ON_ERROR);
$capacity = json_decode($run('capacity'), true, 32, JSON_THROW_ON_ERROR);
$crash = new Process([...$command, 'crash'], $base, $environment);
try {
    $until = microtime(true) + 10;
    do {
        $original = trim($crash->stdout());
        expect($crash->running(), '硬退出装置提前失败：' . $crash->stderr());
        if ($original !== '') {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $until);
    expect($original !== '' && is_int($crash->pid()), '硬退出装置没有完成本地同步提交');
    expect(posix_kill($crash->pid(), SIGKILL), '无法结束本次创建的设备进程');
    $crash->wait(5);
    expect($run('resume') === $original, '跨进程硬退出恢复改变原JSON、模型或摘要');
    $checks[] = 'sigkill-and-cross-process-recovery';
} finally {
    $crash->stop();
}
$run('sequence');
// 使用隔离真实数据库准备耗时不可穷举的计数边界与实际写故障；行为仍从公开编译入口观察。
$database = new PDO('sqlite:' . $base . '/sequence.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $database->prepare('UPDATE device_buffer_state SET last_sequence = ?');
$statement->execute([str_repeat('9', 37)]);
$boundary = json_decode($run('sequence'), true, 32, JSON_THROW_ON_ERROR);
expect($boundary['sequence'] === '1' . str_repeat('0', 37), '38位序号经过机器整数损坏');
$database->exec("CREATE TRIGGER reject_buffer_insert BEFORE INSERT ON device_buffer_messages BEGIN SELECT RAISE(ABORT, 'controlled-write-failure'); END");
expect($run('write-failure') === 'write-rolled-back', '实际SQLite写失败未回滚');
$database->exec('DROP TRIGGER reject_buffer_insert');
$statement->execute([str_repeat('9', 38)]);
expect($run('exhausted') === 'sequence-exhausted', '序号耗尽必须明确拒绝');
$statement = null;
$database = null;
$checks[] = '38-digit-persistent-sequence-boundary';
$checks[] = 'real-sqlite-write-rollback';
$checks[] = '24-hour-baseline-and-16k-capacity-distinction';
$evidence = ['mode' => $native ? 'aot' : 'php', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no-source-runtime' => $noSource, 'iot-sources' => $sourceCount,
    'checks' => $checks, 'capacity' => $capacity, 'build' => $report === null ? null : array_intersect_key($report, array_flip(['build-id', 'sha256', 'typephp', 'phpx', 'production-packages']))];
file_put_contents($base . '/verification.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo ($native ? '原生' : 'PHP') . '设备持久缓存公开入口验证通过：' . $relative . "\n";
