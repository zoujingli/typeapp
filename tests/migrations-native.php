<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$mode = ($argv[1] ?? '') === '--php' ? 'PHP' : '原生';
if ($mode === 'PHP') {
    expect(($argv[3] ?? '') !== 'core', 'PHP 诊断模式使用独立 ORM 入口');
    $root = dirname(__DIR__);
    $consumerRoot = getenv('TYPE_MIGRATION_CONSUMER_ROOT');
    if ($consumerRoot !== false) {
        $consumerRoot = realpath($consumerRoot);
        expect($consumerRoot !== false && is_file($consumerRoot . '/app/DriverFactory.php'), '独立迁移消费根目录无效');
    }
    $launcher = 'require ' . var_export(($consumerRoot ?: $root) . '/vendor/autoload.php', true)
        . '; require ' . var_export($consumerRoot ? $consumerRoot . '/app/DriverFactory.php' : $root . '/examples/migrations/DriverFactory.php', true)
        . '; require ' . var_export($consumerRoot ? $consumerRoot . '/app/Plan.php' : $root . '/examples/migrations/Plan.php', true)
        . '; require ' . var_export($consumerRoot ? $consumerRoot . '/app/main.php' : $root . '/examples/migration-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-r', $launcher];
} else {
    $command = nativeCommand($argv[1] ?? 'build/migrations/type-app');
}
$driver = $argv[2] ?? 'sqlite';
if (($argv[3] ?? '') === 'core') {
    $command[] = 'migrate';
}
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知迁移驱动');
putenv('TYPE_MIGRATION_DRIVER=' . $driver);
$prefix = 'm' . bin2hex(random_bytes(8));
putenv('TYPE_MIGRATION_PREFIX=' . $prefix);
$file = sys_get_temp_dir() . '/' . $prefix . '.sqlite';
putenv('TYPE_SQLITE_FILE=' . $file);
putenv('TYPE_REDIS_HOST=127.0.0.1');
putenv('TYPE_REDIS_PORT=1');
putenv('TYPE_HTTP_PORT=1');
putenv('TYPE_MIGRATION_SCENARIO=normal');

/**
 * 要求迁移命令成功并解析 JSON 结果，保持命令与参数的独立数组边界。
 *
 * @param list<string> $command
 * @param list<string> $arguments
 * @return list<array<string, mixed>>
 */
function migrationResult(array $command, array $arguments): array
{
    return json_decode(successful([...$command, ...$arguments]), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * 验证迁移失败同时满足退出码、稳定错误码和空标准输出的命令契约。
 *
 * @param list<string> $command
 * @param list<string> $arguments
 */
function migrationFailure(array $command, array $arguments, string $code, int $exitCode = 70): void
{
    [$status, $stdout, $stderr] = execute([...$command, ...$arguments]);
    expect($status === $exitCode && str_contains($stderr, $code) && $stdout === '', '迁移没有按约定失败：' . $stdout . $stderr);
}

/** 连接本轮迁移测试选定的真实数据库，SQLite 使用指定临时文件；连接由调用者释放。 */
function migrationPdo(string $driver, string $file): PDO
{
    if ($driver === 'mysql') {
        return new PDO(
            'mysql:host=' . (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1') . ';port=' . (getenv('TYPE_MYSQL_PORT') ?: '3306') . ';dbname=' . (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
            getenv('TYPE_MYSQL_USER') ?: 'root',
            getenv('TYPE_MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    if ($driver === 'pgsql') {
        return new PDO(
            'pgsql:host=' . (getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1') . ';port=' . (getenv('TYPE_PGSQL_PORT') ?: '5432') . ';dbname=' . (getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test'),
            getenv('TYPE_PGSQL_USER') ?: 'type_app',
            getenv('TYPE_PGSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** 按实际数据库方言检查当前库或 schema 中的表，表名通过绑定参数传入。 */
function migrationTableExists(PDO $pdo, string $driver, string $table): bool
{
    $sql = $driver === 'mysql' ? 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        : ($driver === 'pgsql' ? 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?'
            : "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
    $statement = $pdo->prepare($sql);
    $statement->execute([$table]);

    return $statement->fetchColumn() !== false;
}

$connectionEnvironment = ['TYPE_MYSQL_HOST', 'TYPE_MYSQL_PORT', 'TYPE_PGSQL_HOST', 'TYPE_PGSQL_PORT'];
$savedEnvironment = [];
foreach ($connectionEnvironment as $key) {
    $savedEnvironment[$key] = getenv($key);
    putenv($key . '=' . (str_ends_with($key, 'PORT') ? '1' : '127.0.0.1'));
}
expect(str_contains(successful([...$command, 'help']), '迁移命令：status'), '数据库不可用时帮助命令失败');
foreach ($savedEnvironment as $key => $value) {
    putenv($value === false ? $key : $key . '=' . $value);
}
expect(!is_file($file), '帮助命令提前打开了 SQLite 数据库');

$before = migrationResult($command, ['status']);
expect($before[0]['state'] === 'pending', '新迁移没有显示待执行');
$applied = migrationResult($command, ['run']);
expect($applied[0]['state'] === 'applied' && $applied[0]['attempts'] === 1, '迁移没有记录成功');
$again = migrationResult($command, ['run']);
expect($again[0]['attempts'] === 1, '迁移被重复执行');
$pdo = migrationPdo($driver, $file);
expect($pdo->query('SELECT label FROM ' . $prefix . '_items WHERE id = 1')->fetchColumn() === '中文迁移', 'DDL 与数据迁移没有实际生效');
putenv('TYPE_MIGRATION_SCENARIO=index');
$indexed = migrationResult($command, ['run']);
expect(count($indexed) === 2 && $indexed[0]['attempts'] === 1 && $indexed[1]['state'] === 'applied', '追加版本或非事务索引迁移失败');
$indexSql = $driver === 'mysql' ? 'SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = ?'
    : ($driver === 'pgsql' ? 'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?'
        : "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?");
$indexStatement = $pdo->prepare($indexSql);
$indexStatement->execute([$prefix . '_label_idx']);
expect($indexStatement->fetchColumn() !== false, '索引 DDL 没有在真实数据库生效');
$indexStatement->closeCursor();
foreach (['changed' => 'TYPE_MIGRATION_CHANGED', 'missing' => 'TYPE_MIGRATION_MISSING', 'duplicate' => 'TYPE_MIGRATION_DUPLICATE',
    'compound' => 'TYPE_MIGRATION_UNSUPPORTED', 'ambiguous-escape' => 'TYPE_MIGRATION_UNSUPPORTED'] as $scenario => $errorCode) {
    putenv('TYPE_MIGRATION_SCENARIO=' . $scenario);
    migrationFailure($command, ['run'], $errorCode);
}
if ($driver === 'mysql') {
    putenv('TYPE_MIGRATION_SCENARIO=unsupported-transaction');
    migrationFailure($command, ['run'], 'TYPE_MIGRATION_UNSUPPORTED');
} else {
    putenv('TYPE_MIGRATION_SCENARIO=unsupported-ddl');
    migrationFailure($command, ['run'], 'TYPE_MIGRATION_UNSUPPORTED');
}
putenv('TYPE_MIGRATION_SCENARIO=failure');
$failurePrefix = $prefix . 'f';
putenv('TYPE_MIGRATION_PREFIX=' . $failurePrefix);
$pdo->exec('CREATE TABLE ' . $failurePrefix . '_gate (id INTEGER PRIMARY KEY, delay INTEGER NOT NULL)');
$pdo->exec('INSERT INTO ' . $failurePrefix . '_gate VALUES (1, 0)');
migrationFailure($command, ['run'], 'TYPE_MIGRATION_FAILED');
$failed = migrationResult($command, ['status']);
expect($failed[0]['state'] === 'failed' && $failed[0]['attempts'] === 1 && $failed[0]['error'] !== '', '失败记录缺失');
expect(migrationTableExists($pdo, $driver, $failurePrefix . '_items') === ($driver === 'mysql'), 'DDL 回滚结果不符合数据库能力');
migrationFailure($command, ['run'], 'TYPE_MIGRATION_RECOVERY_REQUIRED');
migrationFailure($command, ['recover', '202609080001', 'retry', ''], 'TYPE_MIGRATION_INVALID');
if ($driver === 'mysql') {
    $pdo->exec('DROP TABLE ' . $failurePrefix . '_items');
}
$pdo->exec('UPDATE ' . $failurePrefix . '_gate SET id = 2');
$recovered = migrationResult($command, ['recover', '202609080001', 'retry', '已检查并清理部分 DDL，修复前置数据']);
expect($recovered[0]['state'] === 'pending', '显式恢复没有重新开放待执行状态');
$retried = migrationResult($command, ['run']);
expect($retried[0]['state'] === 'applied' && $retried[0]['attempts'] === 2, '恢复后的执行次数或结果错误');
$history = migrationResult($command, ['history']);
expect(array_column($history, 'state') === ['running', 'failed', 'recovered_pending', 'running', 'applied'], '迁移执行与恢复审计记录不完整');

$manualPrefix = $prefix . 'a';
putenv('TYPE_MIGRATION_PREFIX=' . $manualPrefix);
$pdo->exec('CREATE TABLE ' . $manualPrefix . '_gate (id INTEGER PRIMARY KEY, delay INTEGER NOT NULL)');
$pdo->exec('INSERT INTO ' . $manualPrefix . '_gate VALUES (1, 0)');
migrationFailure($command, ['run'], 'TYPE_MIGRATION_FAILED');
$pdo->exec('UPDATE ' . $manualPrefix . '_gate SET id = 2');
if ($driver !== 'mysql') {
    $pdo->exec('CREATE TABLE ' . $manualPrefix . '_items (id INTEGER PRIMARY KEY, label VARCHAR(100) NOT NULL)');
    $pdo->exec('INSERT INTO ' . $manualPrefix . "_items VALUES (1, '中文迁移')");
}
$pdo->exec('INSERT INTO ' . $manualPrefix . "_items VALUES (2, '故障恢复')");
expect(migrationResult($command, ['recover', '202609080001', 'applied', '已人工补齐并核对迁移的所有结果'])[0]['state'] === 'applied', '人工完成后无法登记恢复');
expect(migrationResult($command, ['run'])[0]['attempts'] === 1, '人工确认成功后再次执行了历史迁移');
expect(array_column(migrationResult($command, ['history']), 'state') === ['running', 'failed', 'recovered_applied'], '人工完成的审计历史缺失');

putenv('TYPE_MIGRATION_SCENARIO=crash');
$crashPrefix = $prefix . 'c';
putenv('TYPE_MIGRATION_PREFIX=' . $crashPrefix);
$pdo->exec('CREATE TABLE ' . $crashPrefix . '_gate (id INTEGER PRIMARY KEY, delay INTEGER NOT NULL)');
$pdo->exec('INSERT INTO ' . $crashPrefix . '_gate VALUES (1, 8)');
$capture = tmpfile();
expect($capture !== false, '无法创建迁移中断输出缓冲');
$process = proc_open([...$command, 'run'], [0 => ['file', '/dev/null', 'r'], 1 => $capture, 2 => $capture], $pipes);
expect(is_resource($process), '无法启动迁移竞争进程');
try {
    $deadline = microtime(true) + 5.0;
    do {
        usleep(10000);
        $state = proc_get_status($process);
        expect($state['running'], '被测迁移在中断前已经退出');
        $snapshot = migrationResult($command, ['status']);
    } while ($snapshot[0]['state'] !== 'running' && microtime(true) < $deadline);
    expect($snapshot[0]['state'] === 'running', '无法从公开状态命令观察正在运行的迁移');
    $started = microtime(true);
    migrationFailure($command, ['run'], 'TYPE_MIGRATION_LOCKED', 75);
    expect(microtime(true) - $started < 3.0, '竞争迁移没有及时拒绝');
    proc_terminate($process, 9);
    $deadline = microtime(true) + 5.0;
    do {
        $state = proc_get_status($process);
        if ($state['running']) {
            usleep(10000);
        }
    } while ($state['running'] && microtime(true) < $deadline);
    expect(!$state['running'] && $state['signaled'] && $state['termsig'] === 9, '没有证明确实发生迁移 SIGKILL');
} finally {
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    fclose($capture);
}

// 服务端会话可能先结束当前 SQL 才释放锁，恢复只等待真实锁释放，不窃取租约。
$deadline = microtime(true) + 15.0;
do {
    [$status, $stdout, $stderr] = execute([...$command, 'run']);
    if ($status === 75) {
        usleep(100000);
    }
} while ($status === 75 && microtime(true) < $deadline);
expect($status === 70 && str_contains($stderr, 'TYPE_MIGRATION_RECOVERY_REQUIRED'), '中断后的锁或恢复状态错误：' . $stdout . $stderr);
expect(migrationResult($command, ['status'])[0]['state'] === 'running', '中断执行被误标记为成功');
expect(migrationTableExists($pdo, $driver, $crashPrefix . '_items') === ($driver === 'mysql'), '中断后的 DDL 回滚语义错误');
if ($driver === 'mysql') {
    $pdo->exec('DROP TABLE ' . $crashPrefix . '_items');
}
$pdo->exec('UPDATE ' . $crashPrefix . '_gate SET delay = 0');
migrationResult($command, ['recover', '202609080001', 'retry', '确认中断并检查数据库，已清理非事务操作']);
expect(migrationResult($command, ['run'])[0]['state'] === 'applied', '迁移中断后不能恢复执行');

foreach ([$prefix, $failurePrefix, $manualPrefix, $crashPrefix] as $generatedPrefix) {
    foreach (['_items', '_gate', '_migrations_events', '_migrations'] as $suffix) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $generatedPrefix . $suffix);
    }
}
$pdo = null;
if ($driver === 'sqlite') {
    foreach ([$file, $file . '-wal', $file . '-shm', $file . '.type-migration.lock'] as $generatedFile) {
        if (is_file($generatedFile)) {
            unlink($generatedFile);
        }
    }
}
echo $driver . ' ' . $mode . "迁移校验、DDL 恢复、竞争互斥与 SIGKILL 恢复验证通过。\n";
