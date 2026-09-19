<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Orm\Migration\Migration;
use Type\Orm\Migration\MigrationException;
use Type\Orm\Migration\Migrator;
use Type\Orm\Driver;
use Type\Orm\Sqlite\SqliteDriver;

/** 在真实 SQLite 回调中暂停迁移，方便第二个独立进程竞争同一公开入口。 */
final class SqliteMigrationLockDriver implements Driver
{
    public function __construct(private SqliteDriver $driver, private string $ready, private string $release)
    {
    }

    public function name(): string
    {
        return $this->driver->name();
    }

    public function identity(): array
    {
        return $this->driver->identity();
    }

    public function connect(): PDO
    {
        // PDO 的旧 sqliteCreateFunction 在 PHP 8.5 弃用；测试需要真实驱动专属接口。
        $identity = $this->driver->identity();
        $pdo = new \Pdo\Sqlite('sqlite:' . $identity['database'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 100');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $mode = $pdo->query('PRAGMA journal_mode = WAL');
        expect($mode->fetchColumn() === 'wal', '并发测试数据库未进入 WAL');
        $mode->closeCursor();
        $pdo->exec('PRAGMA synchronous = FULL');
        $pdo->createFunction('type_migration_test_hold', function (): int {
            file_put_contents($this->ready, 'ready');
            $deadline = microtime(true) + 10.0;
            while (!is_file($this->release)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('迁移测试等待释放超时');
                }
                usleep(10000);
            }

            return 1;
        }, 0);

        return $pdo;
    }
}

/** @return list<Migration> 固定迁移内容，两次执行必须使用相同校验和。 */
function sqliteLockConcurrentPlan(): array
{
    return [
        new Migration('1', '建立并发迁移测试表', ['CREATE TABLE concurrent_probe (id INTEGER PRIMARY KEY)']),
        new Migration('2', '暂停中的真实迁移', ['INSERT INTO concurrent_probe (id) VALUES (type_migration_test_hold())']),
    ];
}

/** @param Closure(): mixed $operation 只从公开迁移入口观察竞争与失败码。 */
function sqliteLockRejects(Closure $operation, string $expected): void
{
    try {
        $operation();
    } catch (MigrationException $error) {
        expect(str_contains($error->getMessage(), $expected), '迁移锁没有提供约定失败码：' . $error->getMessage());

        return;
    }
    throw new RuntimeException('迁移锁没有拒绝操作：' . $expected);
}

/** @return array{process: resource, stdout: resource, stderr: resource} */
function sqliteLockProcess(array $arguments): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    expect(is_resource($stdout) && is_resource($stderr), '无法创建子进程输出缓冲');
    $process = proc_open([PHP_BINARY, __FILE__, '--child', ...$arguments], [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, null, null, ['bypass_shell' => true]);
    expect(is_resource($process), '无法启动独立迁移进程');
    fclose($pipes[0]);

    return ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];
}

/** 等待明确就绪标记；失败时只读取本轮测试进程的输出。 */
function sqliteLockReady(array $running, string $ready): void
{
    $deadline = microtime(true) + 5.0;
    while (!is_file($ready)) {
        $state = proc_get_status($running['process']);
        if (!$state['running'] || microtime(true) >= $deadline) {
            rewind($running['stdout']);
            rewind($running['stderr']);
            throw new RuntimeException('迁移进程未就绪：' . stream_get_contents($running['stdout']) . stream_get_contents($running['stderr']));
        }
        usleep(10000);
    }
}

/** 验证子进程真实成功退出；不用 Unix 信号字段证明 Windows 行为。 */
function sqliteLockFinished(array $running): void
{
    $deadline = microtime(true) + 5.0;
    do {
        $state = proc_get_status($running['process']);
        if (!$state['running']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect(!$state['running'], '迁移释放后子进程没有结束');
    $status = proc_close($running['process']);
    rewind($running['stdout']);
    rewind($running['stderr']);
    $output = stream_get_contents($running['stdout']);
    $error = stream_get_contents($running['stderr']);
    expect(($status === 0 || ($status === -1 && $state['exitcode'] === 0)) && $output === "completed\n" && $error === '', '迁移子进程失败：' . $output . $error);
}

/** 无论断言是否成功，都停止当前测试拥有的子进程并回收临时缓冲。 */
function sqliteLockCleanup(array $running): void
{
    if (is_resource($running['process'])) {
        if (proc_get_status($running['process'])['running']) {
            proc_terminate($running['process']);
        }
        proc_close($running['process']);
    }
    foreach (['stdout', 'stderr'] as $stream) {
        if (is_resource($running[$stream])) {
            fclose($running[$stream]);
        }
    }
}

if (($argv[1] ?? '') === '--child') {
    $role = $argv[2];
    $database = $argv[3];
    $readyFile = $argv[4];
    $releaseFile = $argv[5];
    if ($role === 'migration') {
        $driver = new SqliteMigrationLockDriver(new SqliteDriver($database, 100), $readyFile, $releaseFile);
        (new Migrator($driver))->run(sqliteLockConcurrentPlan());
    } elseif ($role === 'legacy' && PHP_OS_FAMILY === 'Linux') {
        $legacyHandle = fopen($database, 'r+b');
        expect(is_resource($legacyHandle) && flock($legacyHandle, LOCK_EX | LOCK_NB), '无法准备旧执行者数据库 flock');
        try {
            file_put_contents($readyFile, 'ready');
            $deadline = microtime(true) + 10.0;
            while (!is_file($releaseFile)) {
                expect(microtime(true) < $deadline, '旧协议测试等待释放超时');
                usleep(10000);
            }
        } finally {
            flock($legacyHandle, LOCK_UN);
            fclose($legacyHandle);
        }
    } else {
        throw new RuntimeException('迁移测试子角色无效');
    }
    echo "completed\n";
    exit(0);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'type-migration-lock-' . bin2hex(random_bytes(6));
expect(mkdir($directory, 0700), '无法创建专属迁移锁测试目录');
$directory = realpath($directory);
$file = $directory . DIRECTORY_SEPARATOR . 'normal.sqlite';
$lockFile = $file . '.type-migration.lock';
$files = [$file];
$standaloneFiles = [];
$checks = ['normal', 'reuse'];
$plan = [new Migration('1', '本地迁移锁验证', ['CREATE TABLE lock_probe (id INTEGER PRIMARY KEY)'])];
try {
    $migrator = new Migrator(new SqliteDriver($file, 100));
    $first = $migrator->run($plan);
    expect($first[0]['state'] === 'applied' && $first[0]['attempts'] === 1, '独立锁下 SQLite 迁移不能实际提交');
    expect(is_file($lockFile) && !is_link($lockFile), '迁移没有留下稳定独立锁文件');
    $before = lstat($lockFile);
    $second = $migrator->run($plan);
    clearstatcache(true, $lockFile);
    $after = lstat($lockFile);
    expect($second[0]['attempts'] === 1 && is_file($lockFile), '正常结束后不能再次取得迁移锁');
    if (PHP_OS_FAMILY !== 'Windows') {
        expect($before['dev'] === $after['dev'] && $before['ino'] === $after['ino'], '锁文件被替换，破坏了跨进程互斥身份');
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    expect($pdo->query("SELECT name FROM sqlite_master WHERE name = 'lock_probe'")->fetchColumn() === 'lock_probe', '成功迁移没有真实创建业务表');
    $pdo = null;

    $concurrentFile = $directory . DIRECTORY_SEPARATOR . 'concurrent.sqlite';
    $files[] = $concurrentFile;
    $readyFile = $directory . DIRECTORY_SEPARATOR . 'concurrent.ready';
    $releaseFile = $directory . DIRECTORY_SEPARATOR . 'concurrent.release';
    array_push($standaloneFiles, $readyFile, $releaseFile);
    $running = sqliteLockProcess(['migration', $concurrentFile, $readyFile, $releaseFile]);
    try {
        sqliteLockReady($running, $readyFile);
        $contender = new Migrator(new SqliteDriver($concurrentFile, 100));
        $started = microtime(true);
        sqliteLockRejects(static fn () => $contender->run(sqliteLockConcurrentPlan()), 'TYPE_MIGRATION_LOCKED');
        expect(microtime(true) - $started < 2.0, '第二进程未按非阻塞规则及时拒绝');
        if (PHP_OS_FAMILY === 'Linux') {
            $oldContender = fopen($concurrentFile, 'r+b');
            expect(is_resource($oldContender), '无法打开旧协议兼容检查句柄');
            try {
                expect(!flock($oldContender, LOCK_EX | LOCK_NB), '新执行者没有阻止旧 Linux 执行者');
            } finally {
                fclose($oldContender);
            }
        }
        file_put_contents($releaseFile, 'release');
        sqliteLockFinished($running);
        expect($contender->run(sqliteLockConcurrentPlan())[1]['attempts'] === 1, '并发迁移结束后无法再次取得稳定锁');
        $checks[] = 'second-process-rejected';
    } finally {
        file_put_contents($releaseFile, 'release');
        sqliteLockCleanup($running);
    }

    $failedFile = $directory . DIRECTORY_SEPARATOR . 'failed.sqlite';
    $files[] = $failedFile;
    $failedPlan = [new Migration('1', '实际 SQL 失败后释放锁', ['CREATE TABLE failed_probe (id INTEGER PRIMARY KEY)', 'INSERT INTO missing_gate (id) VALUES (1)'])];
    $failedMigrator = new Migrator(new SqliteDriver($failedFile, 100));
    sqliteLockRejects(static fn () => $failedMigrator->run($failedPlan), 'TYPE_MIGRATION_FAILED');
    expect($failedMigrator->recover($failedPlan, '1', 'retry', '测试确认事务已回滚')[0]['state'] === 'pending', 'SQL 失败后的文件锁没有释放');
    $gate = new PDO('sqlite:' . $failedFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $gate->exec('CREATE TABLE missing_gate (id INTEGER PRIMARY KEY)');
    $gate = null;
    expect($failedMigrator->run($failedPlan)[0]['attempts'] === 2, '恢复后无法完成同一迁移');
    $checks[] = 'failure-release-and-recovery';

    $linkedFile = $directory . DIRECTORY_SEPARATOR . 'linked.sqlite';
    $files[] = $linkedFile;
    $protectedFile = $directory . DIRECTORY_SEPARATOR . 'must-remain.txt';
    $standaloneFiles[] = $protectedFile;
    file_put_contents($protectedFile, '锁检查不得改写此文件');
    $symlinkCreated = @symlink($protectedFile, $linkedFile . '.type-migration.lock');
    if ($symlinkCreated) {
        sqliteLockRejects(static fn () => (new Migrator(new SqliteDriver($linkedFile, 100)))->run($plan), 'TYPE_MIGRATION_LOCK_INVALID');
        expect(file_get_contents($protectedFile) === '锁检查不得改写此文件' && is_link($linkedFile . '.type-migration.lock'), '锁检查跟随或替换了符号链接');
        $checks[] = 'symlink-rejected';
    } else {
        expect(PHP_OS_FAMILY === 'Windows', '当前系统无法建立符号链接拒绝用例');
        $checks[] = 'symlink-unverified-windows-privilege';
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        $hardlinkFile = $directory . DIRECTORY_SEPARATOR . 'hardlink.sqlite';
        $hardlinkAlias = $directory . DIRECTORY_SEPARATOR . 'hardlink-alias.sqlite';
        array_push($files, $hardlinkFile, $hardlinkAlias);
        $seed = new PDO('sqlite:' . $hardlinkFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $seed->exec('CREATE TABLE original_data (id INTEGER PRIMARY KEY)');
        $seed = null;
        expect(link($hardlinkFile, $hardlinkAlias), '无法准备数据库真实硬链接别名');
        foreach ([$hardlinkFile, $hardlinkAlias] as $hardlinkDatabase) {
            sqliteLockRejects(static fn () => (new Migrator(new SqliteDriver($hardlinkDatabase, 100)))->run($plan), '硬链接');
            expect(!is_file($hardlinkDatabase . '.type-migration.lock'), '硬链接数据库被拒绝前已经创建了不同的迁移互斥域');
        }
        $hardlinkLockDatabase = $directory . DIRECTORY_SEPARATOR . 'hardlink-lock.sqlite';
        $files[] = $hardlinkLockDatabase;
        expect(link($protectedFile, $hardlinkLockDatabase . '.type-migration.lock'), '无法准备迁移锁真实硬链接');
        sqliteLockRejects(static fn () => (new Migrator(new SqliteDriver($hardlinkLockDatabase, 100)))->run($plan), '硬链接');
        expect(file_get_contents($protectedFile) === '锁检查不得改写此文件', '硬链接锁检查修改了无关文件');
        $checks[] = 'unix-database-and-lock-hardlinks-rejected';
    }

    if (PHP_OS_FAMILY === 'Linux') {
        $legacyFile = $directory . DIRECTORY_SEPARATOR . 'legacy.sqlite';
        $files[] = $legacyFile;
        $prepare = (new SqliteDriver($legacyFile, 100))->connect();
        $prepare = null;
        $legacyReady = $directory . DIRECTORY_SEPARATOR . 'legacy.ready';
        $legacyRelease = $directory . DIRECTORY_SEPARATOR . 'legacy.release';
        array_push($standaloneFiles, $legacyReady, $legacyRelease);
        $legacyProcess = sqliteLockProcess(['legacy', $legacyFile, $legacyReady, $legacyRelease]);
        try {
            sqliteLockReady($legacyProcess, $legacyReady);
            $newMigrator = new Migrator(new SqliteDriver($legacyFile, 100));
            sqliteLockRejects(static fn () => $newMigrator->run($plan), 'TYPE_MIGRATION_LOCKED');
            $newLock = fopen($legacyFile . '.type-migration.lock', 'r+b');
            expect(is_resource($newLock), '取得旧锁失败后没有保留独立锁文件');
            try {
                expect(flock($newLock, LOCK_EX | LOCK_NB), '取得旧锁失败时泄漏了新协议锁');
                flock($newLock, LOCK_UN);
            } finally {
                fclose($newLock);
            }
            file_put_contents($legacyRelease, 'release');
            sqliteLockFinished($legacyProcess);
            expect($newMigrator->run($plan)[0]['state'] === 'applied', '旧执行者释放后新执行者仍无法迁移');
            $checks[] = 'linux-legacy-two-way-compatible';
        } finally {
            file_put_contents($legacyRelease, 'release');
            sqliteLockCleanup($legacyProcess);
        }
    }
    echo json_encode(['platform' => PHP_OS_FAMILY, 'checks' => $checks, 'file-identity' => PHP_OS_FAMILY === 'Windows' ? 'trusted-directory-no-native-file-id' : 'dev-and-inode'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    foreach ($files as $databaseFile) {
        foreach ([$databaseFile, $databaseFile . '-wal', $databaseFile . '-shm', $databaseFile . '-journal', $databaseFile . '.type-migration.lock'] as $temporaryFile) {
            if (is_link($temporaryFile) || is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }
    foreach ($standaloneFiles as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
    rmdir($directory);
}
