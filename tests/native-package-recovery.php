<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-package-sandbox.php';
require __DIR__ . '/recovery-files.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use Type\Testing\HttpClient;
use Type\Testing\Process;

/**
 * @param list<string> $command 参数数组，不经shell插值；文件输入保持原字节。
 * @param array<string,string> $environment 仅在进程环境中传递测试凭据。
 * @throws RuntimeException 子进程未成功结束；不打印可能含备份内容的stdout。
 */
function nativeRecoveryRun(array $command, array $environment, ?string $stdin = null, int $seconds = 90): string
{
    $process = new Process($command, null, $environment, 16777216, $stdin);
    try {
        $result = $process->wait($seconds);
        $secrets = array_values(array_filter([$environment['DB_PASSWORD'] ?? '', $environment['MYSQL_PWD'] ?? '', $environment['PGPASSWORD'] ?? '', $environment['APP_API_TOKEN'] ?? ''], static fn (string $secret): bool => $secret !== ''));
        // 失败诊断可含 stderr；stdout 仅在 stderr 为空时附带，避免默认打印备份正文。
        $detail = str_replace($secrets, '<REDACTED>', $result->stderr);
        if ($detail === '' && $result->stdout !== '') {
            $detail = str_replace($secrets, '<REDACTED>', $result->stdout);
        }
        if ($result->signal !== null) {
            $detail = 'signal=' . $result->signal . ($detail !== '' ? ' ' . $detail : '');
        }
        expect($result->successful(), '恢复验收命令失败（exit=' . $result->exitCode . ', timeout=' . (int) $result->timedOut . '）：' . $detail);
        return $result->stdout;
    } finally {
        $process->stop();
    }
}

/**
 * @param string $root 测试项目根。
 * @param string $package 已验证的原生发布目录。
 * @param array<string,string> $environment 专用数据根和测试连接配置。
 * @return array{0:Process,1:HttpClient,2:?string} 调用者使用明确进程身份停止本轮原生应用。
 * @throws RuntimeException 进程提前退出或HTTP未就绪；失败时停止已启动进程。
 */
function nativeRecoveryServer(string $root, string $package, array $environment): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
    expect(is_resource($socket), '无法选择恢复验收回环端口');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $environment['APP_LISTEN'] = '127.0.0.1';
    $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $address;
    $processInfo = PHP_OS_FAMILY === 'Linux' ? $environment['APP_BASE_PATH'] . '/server-' . bin2hex(random_bytes(6)) . '.json' : null;
    $command = sandboxPackageCommand($root, $package, [$environment['APP_BASE_PATH']], $processInfo);
    $process = new Process([...$command, 'serve'], null, $environment);
    $client = new HttpClient('http://' . $address, 1);
    try {
        $deadline = microtime(true) + 20;
        do {
            expect($process->running(), '本轮恢复应用提前退出');
            try {
                if ($client->request('GET', '/readyz')->status === 200) {
                    return [$process, $client, $processInfo];
                }
            } catch (RuntimeException) {
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('本轮恢复应用未就绪');
    } catch (Throwable $failure) {
        stopPackageProcess($process, $package, $processInfo, 10);
        throw $failure;
    }
}

/**
 * @param array{HOST:string,PORT:string,DATABASE:string,USER:string,PASSWORD:string} $connection 专用回环测试连接。
 * @throws RuntimeException 标识非法或实际连接失败。
 */
function nativeRecoveryConnection(string $driver, array $connection, string $database): PDO
{
    expect(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/D', $database) === 1, '测试数据库标识无效');
    $extra = $driver === 'pgsql' ? ';hostaddr=' . $connection['HOST'] . ';connect_timeout=5' : '';
    return new PDO(
        ($driver === 'mysql' ? 'mysql:' : 'pgsql:') . 'host=' . $connection['HOST'] . ';port=' . $connection['PORT'] . ';dbname=' . $database . $extra,
        $connection['USER'],
        $connection['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/**
 * 在调用客户端前验证备份与空目标；不通过DROP/CLEAN准备恢复位置。
 *
 * @param array<string,string> $connection 服务端数据库连接；SQLite使用空数组。
 * @param list<string> $command 维护工具前缀；目标参数由本函数统一构造。
 * @param array<string,string> $environment 维护凭据不放进命令参数。
 * @param array{driver:string,'release-sha256':string,'build-id':string} $expected 当前受信恢复上下文，不能从备份自我推导。
 * @throws RuntimeException 备份无效、目标已占用或恢复失败。
 */
function nativeRecoveryRestore(string $driver, string $backup, string $trusted, string $target, array $connection, array $command, array $environment, array $expected): void
{
    recoveryVerify($backup, $trusted, $expected);
    if ($driver === 'sqlite') {
        expect((new BuildPlatform())->absolute($target) && is_dir(dirname($target)), 'SQLite恢复仅接受已解析的新文件目标，不能使用内存或URI');
        expect(!file_exists($target) && !is_link($target), 'SQLite恢复拒绝覆盖已有目标');
        expect(!str_contains($backup, "'"), 'SQLite维护工具测试路径不能含单引号');
        nativeRecoveryRun([...$command, $target, ".restore '" . $backup . "/database.dump'"], $environment);
    } else {
        // 检查和恢复由同一目标构造，不能分别传入不相干的PDO与客户端参数。
        $database = nativeRecoveryConnection($driver, $connection, $target);
        expect((int) $database->query(recoveryEmptyQuery($driver))->fetchColumn() === 0, '恢复拒绝写入非空数据库');
        $database = null;
        $options = $driver === 'mysql'
            ? ['--no-defaults', '--protocol=TCP', '--host=' . $connection['HOST'], '--port=' . $connection['PORT'], '--user=' . $connection['USER'], '--database=' . $target, '--binary-mode', '--batch']
            : ['--host=' . $connection['HOST'], '--port=' . $connection['PORT'], '--username=' . $connection['USER'], '--dbname=' . $target, '--exit-on-error', '--single-transaction', '--no-password'];
        nativeRecoveryRun([...$command, ...$options], $environment, $backup . '/database.dump');
    }
}

$root = dirname(__DIR__);
$package = realpath($argv[1] ?? '');
$digest = $argv[2] ?? '';
$driver = $argv[3] ?? 'sqlite';
expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) && posix_geteuid() > 0 && $argc <= 4 && $package !== false && in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '需要非root Linux/macOS环境、原生发布目录、受信摘要和数据库驱动');
$manifest = (new NativePackage())->verify($package, $digest);
expect($manifest['runtime']['os'] === PHP_OS_FAMILY, '恢复不能使用其他平台产物');
$expected = ['driver' => $driver, 'release-sha256' => $digest, 'build-id' => $manifest['artifact']['build-id']];
$base = $root . '/build/native-recovery-' . $driver . '-' . bin2hex(random_bytes(6));
expect(mkdir($base . '/data/uploads', 0700, true), '无法创建本轮恢复数据根');
$data = $base . '/data';
$restored = $base . '/restored-data';
$backup = $base . '/backup';
$token = 'native-recovery-' . bin2hex(random_bytes(20));
$configuration = "APP_NAME=restore-point\nAPP_API_TOKEN=" . $token . "\n";
file_put_contents($data . '/.env', $configuration);
file_put_contents($data . '/uploads/example.bin', "backup-resource\0" . random_bytes(32));
chmod($data . '/.env', 0600);
chmod($data . '/uploads/example.bin', 0600);
$environment = ['PATH' => '/usr/bin:/bin', 'APP_BASE_PATH' => $data, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
    'APP_CACHE_ENABLED' => 'false', 'APP_ADMIN_PASSWORD' => bin2hex(random_bytes(16)), 'APP_CUSTOMER_PASSWORD' => bin2hex(random_bytes(16)),
    'DB_DRIVER' => $driver, 'DB_SQLITE_FILE' => 'var/app.sqlite', 'TYPE_APP_RELEASE_SHA256' => $digest, 'TYPE_APP_TRACE' => '1'];
$tools = match ($driver) {
    'mysql' => ['dump' => ['mysqldump'], 'restore' => ['mysql']],
    'pgsql' => ['dump' => ['pg_dump'], 'restore' => ['pg_restore']],
    default => ['dump' => [getenv('TYPE_SQLITE_BACKUP_TOOL') ?: '/usr/bin/sqlite3'], 'restore' => [getenv('TYPE_SQLITE_BACKUP_TOOL') ?: '/usr/bin/sqlite3']],
};
// 维护端可以显式提供外部客户端命令前缀；记录载体，不冒称其为本机原生工具。
$customTools = getenv('TYPE_RECOVERY_COMMANDS');
if ($customTools !== false) {
    $tools = json_decode($customTools, true, 32, JSON_THROW_ON_ERROR);
}
expect(is_array($tools) && count($tools) === 2 && isset($tools['dump'], $tools['restore']), '维护工具需要dump/restore两个明确命令');
foreach ($tools as &$command) {
    expect(is_array($command) && array_is_list($command) && $command !== [], '维护命令必须为非空参数数组');
    foreach ($command as $argument) {
        expect(is_string($argument) && $argument !== '' && !str_contains($argument, "\0"), '维护命令参数无效');
    }
    if ($customTools === false) {
        $binary = realpath($command[0]);
        if ($binary === false) {
            foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
                if (is_executable($directory . '/' . $command[0])) {
                    $binary = realpath($directory . '/' . $command[0]);
                    break;
                }
            }
        }
        expect($binary !== false && is_executable($binary) && BuildPlatform::format($binary) === (PHP_OS_FAMILY === 'Darwin' ? 'Mach-O' : 'ELF'), '需要本机原生数据库维护工具');
        $command[0] = $binary;
    }
}
unset($command);
$toolEnvironment = getenv();
foreach (['PGHOSTADDR', 'PGSERVICE', 'PGSERVICEFILE', 'PGOPTIONS', 'PGPASSFILE'] as $key) {
    unset($toolEnvironment[$key]);
}
$connection = [];
if ($driver !== 'sqlite') {
    foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD'] as $key) {
        $value = getenv('TYPE_' . strtoupper($driver) . '_' . $key);
        expect(is_string($value) && $value !== '', '需要显式专用测试数据库连接：' . $key);
        $connection[$key] = $value;
    }
    expect(in_array($connection['HOST'], ['127.0.0.1', '::1'], true) && ctype_digit($connection['PORT'])
        && (int) $connection['PORT'] > 0 && (int) $connection['PORT'] < 65536, '恢复验收只接受专用回环测试连接');
    $environment['DB_HOST'] = $connection['HOST'];
    $environment['DB_PORT'] = $connection['PORT'];
    $environment['DB_USERNAME'] = $connection['USER'];
    $environment['DB_PASSWORD'] = $connection['PASSWORD'];
    $toolEnvironment[$driver === 'mysql' ? 'MYSQL_PWD' : 'PGPASSWORD'] = $connection['PASSWORD'];
}
$versions = [];
$toolIdentities = [];
foreach ($tools as $name => $command) {
    $versions[$name] = trim(nativeRecoveryRun([...$command, '--version'], $toolEnvironment));
    if ($customTools === false) {
        $toolIdentities[$name] = ['name' => basename($command[0]), 'format' => BuildPlatform::format($command[0]), 'sha256' => hash_file('sha256', $command[0])];
    }
}
$sourceName = 'type_recovery_src_' . bin2hex(random_bytes(6));
$restoreName = 'type_recovery_dst_' . bin2hex(random_bytes(6));
$created = [];
$children = [];
$admin = null;
$sourceDatabase = null;
$verified = null;
try {
    if ($driver !== 'sqlite') {
        $admin = nativeRecoveryConnection($driver, $connection, $connection['DATABASE']);
        $admin->exec('CREATE DATABASE ' . $sourceName);
        $created[] = $sourceName;
        $environment['DB_DATABASE'] = $sourceName;
        $sourceDatabase = nativeRecoveryConnection($driver, $connection, $sourceName);
    }
    $sourceCommand = sandboxPackageCommand($root, $package, [$data]);
    nativeRecoveryRun([...$sourceCommand, 'help'], $environment);
    nativeRecoveryRun([...$sourceCommand, 'verify-runtime'], $environment);
    nativeRecoveryRun([...$sourceCommand, 'app:install', 'recovery-admin', '恢复管理员', 'recovery-customer', '恢复客户', '恢复租户'], $environment);
    if ($driver === 'sqlite') {
        // 保留维护连接，使应用退出后仍有真实WAL，而不是仅备份已自动检查点的主文件。
        $sourceDatabase = new PDO('sqlite:' . $data . '/var/app.sqlite');
        expect(strtolower((string) $sourceDatabase->query('PRAGMA journal_mode')->fetchColumn()) === 'wal', '源SQLite未使用WAL');
    } elseif ($driver === 'pgsql') {
        $sourceDatabase->exec('CREATE SCHEMA recovery_meta');
        $sourceDatabase->exec('CREATE TABLE recovery_meta.owner_probe (role_name TEXT NOT NULL)');
        $sourceDatabase->exec('INSERT INTO recovery_meta.owner_probe SELECT current_user');
    }
    $history = nativeRecoveryRun([...$sourceCommand, 'migrate', 'history'], $environment);
    $adminPassword = $environment['APP_ADMIN_PASSWORD'];
    [$original, $client, $processInfo] = nativeRecoveryServer($root, $package, $environment);
    $children[] = [$original, $processInfo];
    expect($client->request('GET', '/admin/users')->status === 401, '恢复夹具丢失授权');
    $login = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
        'login' => 'recovery-admin', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($login->status === 200, '恢复管理员登录失败');
    $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
    $user = $client->request('POST', '/admin/users', $headers, json_encode([
        'login' => 'backup-user', 'name' => '备份用户', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($user->status === 200, '无法创建恢复夹具人员');
    $saved = $user->json()['data'];
    $disabled = $client->request('POST', '/admin/users/' . $saved['id'] . '/status', $headers, json_encode([
        'version' => $saved['version'], 'enabled' => false,
    ], JSON_THROW_ON_ERROR));
    expect($disabled->status === 200 && empty($disabled->json()['data']['enabled']), '无法准备真实停用状态');
    expect(stopPackageProcess($original, $package, $processInfo, 10)->successful(), '备份前应用没有正常停止');
    $snapshotRows = $sourceDatabase->query('SELECT id, login, name, enabled, version FROM admin_users ORDER BY login')->fetchAll(PDO::FETCH_ASSOC);
    expect(count($snapshotRows) === 2, '备份点人员数量不符');
    expect(mkdir($backup, 0700), '不能覆盖既有恢复点');
    if ($driver === 'sqlite') {
        clearstatcache();
        $walBytes = filesize($data . '/var/app.sqlite-wal');
        expect($walBytes > 32, '没有未检查点的真实WAL数据');
        expect(!str_contains($backup, "'"), 'SQLite维护工具测试路径不能含单引号');
        nativeRecoveryRun([...$tools['dump'], $data . '/var/app.sqlite', '.timeout 5000', ".backup '" . $backup . "/database.dump'"], $toolEnvironment);
        expect(trim(nativeRecoveryRun([...$tools['dump'], $backup . '/database.dump', 'PRAGMA integrity_check'], $toolEnvironment)) === 'ok', 'SQLite备份完整性检查失败');
        expect(trim(nativeRecoveryRun([...$tools['dump'], $backup . '/database.dump', 'SELECT COUNT(*) FROM admin_users'], $toolEnvironment)) === '2', '备份没有包含WAL中的已提交数据');
        $checkpoint = trim(nativeRecoveryRun([...$tools['dump'], $data . '/var/app.sqlite', 'PRAGMA wal_checkpoint(TRUNCATE)'], $toolEnvironment));
        expect($checkpoint === '0|0|0', '源SQLite检查点没有完整完成');
    } else {
        $options = $driver === 'mysql'
            ? ['--no-defaults', '--protocol=TCP', '--host=' . $connection['HOST'], '--port=' . $connection['PORT'], '--user=' . $connection['USER'], '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', $sourceName]
            : ['--host=' . $connection['HOST'], '--port=' . $connection['PORT'], '--username=' . $connection['USER'], '--dbname=' . $sourceName, '--format=custom', '--no-password'];
        $dump = nativeRecoveryRun([...$tools['dump'], ...$options], $toolEnvironment);
        expect(file_put_contents($backup . '/database.dump', $dump) === strlen($dump), '数据库备份写入不完整');
    }
    copy($data . '/.env', $backup . '/application.env');
    copy($data . '/uploads/example.bin', $backup . '/asset.bin');
    $files = [];
    foreach (['database.dump', 'application.env', 'asset.bin'] as $file) {
        chmod($backup . '/' . $file, 0600);
        $files[$file] = ['sha256' => hash_file('sha256', $backup . '/' . $file), 'bytes' => filesize($backup . '/' . $file)];
    }
    $snapshot = ['protocol' => 1, 'created-at' => gmdate('c'), 'driver' => $driver, 'tool-version' => $versions['dump'], 'release-sha256' => $digest,
        'build-id' => $manifest['artifact']['build-id'], 'migration-history-sha256' => hash('sha256', $history), 'consistency' => 'isolated-application-stopped', 'files' => $files];
    file_put_contents($backup . '/backup.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    chmod($backup . '/backup.json', 0600);
    $trusted = hash_file('sha256', $backup . '/backup.json');
    recoveryVerify($backup, $trusted, $expected);
    foreach (['driver' => $driver === 'sqlite' ? 'mysql' : 'sqlite', 'release-sha256' => str_repeat('0', 64), 'build-id' => str_repeat('0', 64), 'protocol' => 2] as $field => $wrong) {
        $invalid = $base . '/identity-' . $field;
        expect(mkdir($invalid, 0700), '无法创建身份拒绝用例');
        foreach (array_keys($files) as $file) {
            copy($backup . '/' . $file, $invalid . '/' . $file);
            chmod($invalid . '/' . $file, 0600);
        }
        $changed = $snapshot;
        $changed[$field] = $wrong;
        file_put_contents($invalid . '/backup.json', json_encode($changed, JSON_THROW_ON_ERROR));
        $wrongHash = hash_file('sha256', $invalid . '/backup.json');
        $rejectedIdentity = false;
        try {
            nativeRecoveryRestore($driver, $invalid, $wrongHash, $base . '/must-not-create-' . $field, $connection, $tools['restore'], $toolEnvironment, $expected);
        } catch (RuntimeException $failure) {
            $rejectedIdentity = str_contains($failure->getMessage(), $field === 'protocol' ? '备份清单结构无效' : '备份身份与恢复上下文不一致');
        }
        expect($rejectedIdentity && !file_exists($base . '/must-not-create-' . $field), '错误身份或版本没有在维护命令前拒绝');
    }
    if ($driver === 'sqlite') {
        foreach ([':memory:', 'file:recovery?mode=memory&cache=shared'] as $volatileTarget) {
            $memoryRejected = false;
            try {
                nativeRecoveryRestore($driver, $backup, $trusted, $volatileTarget, [], $tools['restore'], $toolEnvironment, $expected);
            } catch (RuntimeException $failure) {
                $memoryRejected = str_contains($failure->getMessage(), '新文件目标');
            }
            expect($memoryRejected, '恢复被误导到易失内存或URI数据库');
        }
    }
    mkdir($base . '/corrupt', 0700);
    foreach (['backup.json', ...array_keys($files)] as $file) {
        copy($backup . '/' . $file, $base . '/corrupt/' . $file);
        chmod($base . '/corrupt/' . $file, 0600);
    }
    file_put_contents($base . '/corrupt/database.dump', 'corrupted', FILE_APPEND);
    $rejected = false;
    try {
        nativeRecoveryRestore($driver, $base . '/corrupt', $trusted, $base . '/must-not-create-corrupt', $connection, $tools['restore'], $toolEnvironment, $expected);
    } catch (RuntimeException $failure) {
        $rejected = str_contains($failure->getMessage(), '备份文件摘要不一致');
    }
    expect($rejected && !file_exists($base . '/must-not-create-corrupt'), '损坏备份没有在维护命令前拒绝');
    $rejected = false;
    try {
        recoveryData($backup, $trusted, $data, posix_geteuid(), $expected);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '恢复覆盖了原数据根');
    [$original, $client, $processInfo] = nativeRecoveryServer($root, $package, $environment);
    $children[] = [$original, $processInfo];
    $login = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
        'login' => 'recovery-admin', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($login->status === 200, '备份后源库管理员登录失败');
    $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
    $afterBackup = $client->request('POST', '/admin/users', $headers, json_encode([
        'login' => 'after-backup', 'name' => '备份之后写入', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($afterBackup->status === 200, '无法验证恢复点之后的数据变化');
    expect(stopPackageProcess($original, $package, $processInfo, 10)->successful(), '源应用再次停止失败');
    file_put_contents($data . '/uploads/example.bin', 'asset-after-backup');
    file_put_contents($data . '/.env', "APP_NAME=after-backup\nAPP_API_TOKEN=" . $token . "\n");
    recoveryData($backup, $trusted, $restored, posix_geteuid(), $expected);
    $restoreEnvironment = $environment;
    $restoreEnvironment['APP_BASE_PATH'] = $restored;
    if ($driver === 'sqlite') {
        mkdir($restored . '/var', 0700);
        $restoreCommand = $tools['restore'];
        $target = $restored . '/var/app.sqlite';
        $occupied = $data . '/var/app.sqlite';
    } else {
        $admin->exec('CREATE DATABASE ' . $restoreName);
        $created[] = $restoreName;
        $restoreEnvironment['DB_DATABASE'] = $restoreName;
        $restoreCommand = $tools['restore'];
        $target = $restoreName;
        $occupied = $sourceName;
    }
    $rejected = false;
    try {
        nativeRecoveryRestore($driver, $backup, $trusted, $occupied, $connection, $restoreCommand, $toolEnvironment, $expected);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '数据库恢复没有拒绝既有目标');
    nativeRecoveryRestore($driver, $backup, $trusted, $target, $connection, $restoreCommand, $toolEnvironment, $expected);
    $verified = $driver === 'sqlite' ? new PDO('sqlite:' . $target) : nativeRecoveryConnection($driver, $connection, $target);
    expect($verified->query('SELECT id, login, name, enabled, version FROM admin_users ORDER BY login')->fetchAll(PDO::FETCH_ASSOC) === $snapshotRows, '恢复后的实际业务字段与备份点不一致');
    if ($driver === 'sqlite') {
        expect($verified->query('PRAGMA integrity_check')->fetchColumn() === 'ok', '恢复后的SQLite文件不完整');
    } else {
        expect($verified->query($driver === 'mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn() === $restoreName, '恢复验证连接并非本轮新目标数据库');
    }
    if ($driver === 'pgsql') {
        expect($verified->query('SELECT role_name FROM recovery_meta.owner_probe')->fetchColumn() === $connection['USER']
            && $verified->query("SELECT tableowner FROM pg_tables WHERE schemaname='recovery_meta' AND tablename='owner_probe'")->fetchColumn() === $connection['USER'], '恢复丢失schema或已声明角色归属');
    }
    $verified = null;
    $restoredCommand = sandboxPackageCommand($root, $package, [$restored]);
    // 页面可由同一原生产物重建，恢复数据后不能再次初始化数据库。
    nativeRecoveryRun([...$restoredCommand, 'web:install'], $restoreEnvironment);
    $restoredHistory = nativeRecoveryRun([...$restoredCommand, 'migrate', 'history'], $restoreEnvironment);
    expect(hash('sha256', $restoredHistory) === $snapshot['migration-history-sha256'], '恢复遗漏或改动迁移历史');
    nativeRecoveryRun([...$restoredCommand, 'migrate', 'status'], $restoreEnvironment);
    expect(file_get_contents($restored . '/.env') === $configuration && hash_file('sha256', $restored . '/uploads/example.bin') === $files['asset.bin']['sha256'], '恢复的配置或资源不一致');
    [$application, $client, $processInfo] = nativeRecoveryServer($root, $package, $restoreEnvironment);
    $children[] = [$application, $processInfo];
    $login = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
        'login' => 'recovery-admin', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($login->status === 200, '恢复后管理员登录失败');
    $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
    $listed = $client->request('GET', '/admin/users?search=after-backup', $headers);
    expect($listed->status === 200 && $listed->json()['data']['total'] === 0, '恢复保留了备份点之后的写入');
    $disabledListed = $client->request('GET', '/admin/users?search=backup-user&enabled=0', $headers);
    expect($disabledListed->status === 200 && $disabledListed->json()['data']['total'] === 1, '恢复丢失了备份点停用状态');
    $createdUser = $client->request('POST', '/admin/users', $headers, json_encode([
        'login' => 'restored-write', 'name' => '恢复后写入', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($createdUser->status === 200, '恢复后原生应用无法写入');
    $readUser = $client->request('GET', '/admin/users/' . $createdUser->json()['data']['id'], $headers);
    expect($readUser->status === 200 && $readUser->json()['data']['items'][0]['name'] === '恢复后写入', '恢复后的新写入无法从原生入口读回');
    expect(stopPackageProcess($application, $package, $processInfo, 10)->successful(), '恢复后的应用没有正常停止');
    nativeRecoveryRun([...$restoredCommand, 'migrate', 'status'], $restoreEnvironment);
    expect(nativeRecoveryRun([...$restoredCommand, 'migrate', 'history'], $restoreEnvironment) === $restoredHistory, '恢复后重复迁移改动了既有历史');
    $sourceDatabase ??= new PDO('sqlite:' . $data . '/var/app.sqlite');
    expect((int) $sourceDatabase->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 3, '恢复改动了原数据库');
    expect(file_get_contents($data . '/uploads/example.bin') === 'asset-after-backup'
        && file_get_contents($data . '/.env') === "APP_NAME=after-backup\nAPP_API_TOKEN=" . $token . "\n", '恢复覆盖了原配置或资源');
    recoveryVerify($backup, $trusted, $expected);
    expect((fileperms($backup) & 0077) === 0, '备份目录不私有');
    foreach (['backup.json', ...array_keys($files)] as $file) {
        expect((fileperms($backup . '/' . $file) & 0077) === 0, '备份文件权限不私有');
    }
    $evidence = ['platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'driver' => $driver, 'artifact-sha256' => $manifest['artifact']['sha256'],
        'build-id' => $manifest['artifact']['build-id'], 'release-sha256' => $digest, 'operations-sha256' => hash_file('sha256', $package . '/OPERATIONS.md'),
        'backup-manifest-sha256' => $trusted, 'maintenance-execution' => $customTools === false ? 'native' : 'caller-command-prefix', 'tool-versions' => $versions, 'tool-identities' => $toolIdentities,
        'scope' => '测试应用数据库与显式配置/资源；不是整个集群、所有账号、WAL/binlog或任意应用文件的备份',
        'checks' => ['source-tool-access-denied', 'writers-stopped', 'native-database-backup', 'private-backup-files', 'manifest-and-file-digests', 'corrupt-rejected',
            'existing-target-rejected', 'wrong-driver-release-build-protocol-rejected', 'new-target-restored', 'snapshot-rows-identical', 'migration-history-restored', 'repeated-migration-history-unchanged',
            'external-config-and-data', 'snapshot-point-loss-explicit', 'restored-native-read-write', 'original-data-preserved', 'normal-stop']];
    if ($driver === 'sqlite') {
        $evidence['sqlite'] = ['wal_bytes_before_backup' => $walBytes, 'checkpoint' => $checkpoint, 'memory_target_rejected' => true, 'uri_target_rejected' => true, 'restored_integrity' => 'ok'];
    } elseif ($driver === 'pgsql') {
        $evidence['schema-role-restored'] = true;
    }
} finally {
    $cleanupFailures = [];
    foreach ($children as [$child, $childInfo]) {
        try {
            stopPackageProcess($child, $package, $childInfo, 10);
        } catch (Throwable $failure) {
            $cleanupFailures[] = $failure->getMessage();
        }
    }
    $verified = null;
    $sourceDatabase = null;
    foreach (array_reverse($created) as $database) {
        try {
            $admin->exec('DROP DATABASE ' . $database);
        } catch (Throwable $failure) {
            $cleanupFailures[] = '本轮数据库清理失败';
        }
    }
    $admin = null;
    expect($cleanupFailures === [], implode('；', $cleanupFailures));
}
$evidence['checks'][] = 'owned-processes-and-databases-cleaned';
file_put_contents($base . '/verification.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo $driver . ' ' . PHP_OS_FAMILY . '原生发布恢复通过：' . $base . "/verification.json\n";
