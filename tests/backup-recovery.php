<?php

declare(strict_types=1);

use Type\Testing\HttpClient;
use Type\Testing\Process;

require_once __DIR__ . '/recovery-files.php';

/** 相同编译应用在指定新/旧数据目标上运行；只有测试控制器含PHP代码。 */
function recoveryStart(array $context, string $data, string $network, string $name): array
{
    $command = array_values(array_filter($context['runtime'], static fn (string $value): bool => $value !== '--rm'));
    $command[1] = 'create';
    foreach ($command as &$argument) {
        if ($argument === 'type=bind,source=' . $context['data'] . ',target=/data') {
            $argument = 'type=bind,source=' . $data . ',target=/data';
        }
    }
    unset($argument);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($socket), '无法选择恢复验收端口');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = substr(strrchr($address, ':'), 1);
    cleanPackageCommand([...$command, '--name', $name, '--label', $context['label'], ...($context['driver'] === 'sqlite' ? [] : ['--network', $context['ingress']]),
        '--publish', '127.0.0.1:' . $port . ':9501', '--env', 'APP_LISTEN=0.0.0.0', '--env', 'APP_PORT=9501', '--env', 'APP_ALLOWED_HOSTS=' . $address,
        '--env', 'APP_API_TOKEN', $context['image'], 'serve'], 30, $context['environment']);
    if ($context['driver'] !== 'sqlite') {
        cleanPackageCommand(['docker', 'network', 'connect', $network, $name]);
    }
    cleanPackageCommand(['docker', 'start', $name]);
    $client = new HttpClient('http://' . $address, 1);
    $deadline = microtime(true) + 25;
    do {
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                return [$client, $address];
            }
        } catch (RuntimeException) {
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('隔离恢复应用未就绪');
}

function recoveryStop(string $name): void
{
    cleanPackageCommand(['docker', 'stop', '--timeout', '10', $name]);
    $state = json_decode(cleanPackageCommand(['docker', 'inspect', $name]), true, 512, JSON_THROW_ON_ERROR)[0]['State'];
    expect(!$state['Running'] && $state['ExitCode'] === 0, '恢复演练应用未正常停止');
    cleanPackageCommand(['docker', 'rm', $name]);
}

/** 原生客户端只向空数据库恢复；原目标有表时先拒绝，不尝试DROP/CLEAN。 */
function recoveryRestoreServer(array $context, string $backend, array $query, string $backup, string $trusted): void
{
    recoveryVerify($backup, $trusted, ['driver' => $context['driver'], 'release-sha256' => $context['release-sha256'], 'build-id' => $context['build-id']]);
    $sql = recoveryEmptyQuery($context['driver']);
    expect(trim(cleanPackageCommand([...$query, $sql], 10, $context['environment'])) === '0', '恢复拒绝写入非空数据库');
    cleanPackageCommand(['docker', 'cp', $backup . '/database.dump', $backend . ':/tmp/type-recovery.dump']);
    if ($context['driver'] === 'mysql') {
        $admin = $context['environment'];
        $admin['MYSQL_PWD'] = $admin['MYSQL_ROOT_PASSWORD'];
        expect(preg_match('/^type_clean_[a-f0-9]{12}$/D', $context['database']) === 1, '恢复数据库名称不属于本轮');
        cleanPackageCommand(['docker', 'exec', '--env', 'MYSQL_PWD', $backend, 'sh', '-c',
            'exec mysql --host=127.0.0.1 --user=root --database="$1" < /tmp/type-recovery.dump', 'restore', $context['database']], 60, $admin);
    } else {
        cleanPackageCommand(['docker', 'exec', '--env', 'PGPASSWORD', $backend, 'pg_restore', '--host=127.0.0.1', '--username=type_app',
            '--dbname=' . $context['database'], '--exit-on-error', '--single-transaction', '--no-password', '/tmp/type-recovery.dump'], 60, $context['environment']);
    }
}

/** 已停止的单应用夹具：一致备份、损坏拒绝、全新目标恢复及原目标保留。 */
function cleanPackageRecovery(array $context): array
{
    $base = $context['base'];
    $driver = $context['driver'];
    $expected = ['driver' => $driver, 'release-sha256' => $context['release-sha256'], 'build-id' => $context['build-id']];
    $data = $context['data'];
    $backup = $base . '/backup';
    expect(mkdir($backup, 0700), '不能覆盖既有恢复点');
    expect(!is_file($data . '/.env') && mkdir($data . '/uploads', 0700), '不能覆盖已有外部配置或资源');
    $configuration = "APP_NAME=restore-point\nAPP_API_TOKEN=" . $context['environment']['APP_API_TOKEN'] . "\n";
    file_put_contents($data . '/.env', $configuration);
    file_put_contents($data . '/uploads/example.bin', "data-at-backup\0" . random_bytes(32));
    chmod($data . '/.env', 0600);
    chmod($data . '/uploads/example.bin', 0600);
    $beforeHistory = cleanPackageCommand([...$context['runtime'], '--network', $driver === 'sqlite' ? 'none' : $context['network'], $context['image'], 'migrate', 'history'], 30, $context['environment']);
    if ($driver === 'sqlite') {
        $sqlite = getenv('TYPE_SQLITE_BACKUP_TOOL') ?: 'sqlite3';
        expect(!str_contains($backup, "'"), 'SQLite维护工具测试路径不能含单引号');
        $version = trim(cleanPackageCommand([$sqlite, '--version']));
        cleanPackageCommand([$sqlite, $data . '/var/app.sqlite', '.timeout 5000', ".backup '" . $backup . "/database.dump'"]);
        expect(trim(cleanPackageCommand([$sqlite, $backup . '/database.dump', 'PRAGMA integrity_check'])) === 'ok', 'SQLite备份完整性检查失败');
    } elseif ($driver === 'mysql') {
        $admin = $context['environment'];
        $admin['MYSQL_PWD'] = $admin['MYSQL_ROOT_PASSWORD'];
        $version = trim(cleanPackageCommand(['docker', 'exec', $context['backend'], 'mysqldump', '--version']));
        $dump = cleanPackageCommand(['docker', 'exec', '--env', 'MYSQL_PWD', $context['backend'], 'mysqldump', '--host=127.0.0.1', '--user=root',
            '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', $context['database']], 60, $admin);
        expect(file_put_contents($backup . '/database.dump', $dump) === strlen($dump), 'MySQL备份写入不完整');
    } else {
        $version = trim(cleanPackageCommand(['docker', 'exec', $context['backend'], 'pg_dump', '--version']));
        $dump = cleanPackageCommand(['docker', 'exec', '--env', 'PGPASSWORD', $context['backend'], 'pg_dump', '--host=127.0.0.1', '--username=type_app',
            '--dbname=' . $context['database'], '--format=custom', '--no-password'], 60, $context['environment']);
        expect(file_put_contents($backup . '/database.dump', $dump) === strlen($dump), 'PostgreSQL备份写入不完整');
    }
    copy($data . '/.env', $backup . '/application.env');
    copy($data . '/uploads/example.bin', $backup . '/asset.bin');
    $files = [];
    foreach (['database.dump', 'application.env', 'asset.bin'] as $file) {
        chmod($backup . '/' . $file, 0600);
        $files[$file] = ['sha256' => hash_file('sha256', $backup . '/' . $file), 'bytes' => filesize($backup . '/' . $file)];
    }
    $record = ['protocol' => 1, 'created-at' => gmdate('c'), 'driver' => $driver, 'tool-version' => $version, 'release-sha256' => $context['release-sha256'],
        'build-id' => $context['build-id'], 'migration-history-sha256' => hash('sha256', $beforeHistory), 'consistency' => 'isolated-application-stopped', 'files' => $files];
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($backup . '/backup.json', $json);
    chmod($backup . '/backup.json', 0600);
    $trusted = hash('sha256', $json);
    recoveryVerify($backup, $trusted, $expected);
    $corrupt = $base . '/corrupt-backup';
    mkdir($corrupt, 0700);
    foreach (['backup.json', ...array_keys($files)] as $file) {
        copy($backup . '/' . $file, $corrupt . '/' . $file);
        chmod($corrupt . '/' . $file, 0600);
    }
    file_put_contents($corrupt . '/database.dump', 'corrupted', FILE_APPEND);
    $rejected = false;
    try {
        recoveryVerify($corrupt, $trusted);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '损坏备份未在恢复前拒绝');
    $rejected = false;
    try {
        recoveryData($backup, $trusted, $data, $context['uid'], $expected);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '恢复覆盖了已有运行数据根');
    $restoreData = $base . '/restored-data';
    $suffix = basename($base);
    $originalApp = 'type-recovery-original-' . $suffix;
    $restoredApp = 'type-recovery-app-' . $suffix;
    $restoredBackend = 'type-recovery-db-' . $suffix;
    $restoredNetwork = 'type-recovery-net-' . $suffix;
    $startedOriginal = false;
    $createdRestored = false;
    $createdBackend = false;
    $createdNetwork = false;
    try {
        $startedOriginal = true;
        [$originalClient] = recoveryStart($context, $data, $context['network'], $originalApp);
        $adminPassword = $context['environment']['APP_ADMIN_PASSWORD'];
        $login = $originalClient->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
            'login' => 'clean-admin', 'password' => $adminPassword,
        ], JSON_THROW_ON_ERROR));
        expect($login->status === 200, '恢复演练源库管理员登录失败');
        $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
        expect($originalClient->request('POST', '/admin/users', $headers, json_encode([
            'login' => 'after-backup', 'name' => '备份之后写入', 'password' => $adminPassword,
        ], JSON_THROW_ON_ERROR))->status === 200, '不能验证恢复点之后的源数据变化');
        recoveryStop($originalApp);
        $startedOriginal = false;
        file_put_contents($data . '/uploads/example.bin', 'asset-after-backup');
        file_put_contents($data . '/.env', "APP_NAME=after-backup\nAPP_API_TOKEN=" . $context['environment']['APP_API_TOKEN'] . "\n");
        recoveryData($backup, $trusted, $restoreData, $context['uid'], $expected);
        if ($driver === 'sqlite') {
            mkdir($restoreData . '/var', 0700);
            expect(!file_exists($restoreData . '/var/app.sqlite'), 'SQLite恢复必须是新目标');
            cleanPackageCommand([$sqlite, $restoreData . '/var/app.sqlite', ".restore '" . $backup . "/database.dump'"]);
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                chown($restoreData . '/var', $context['uid']);
                chown($restoreData . '/var/app.sqlite', $context['uid']);
            }
            $restoreRuntime = $context['runtime'];
            $databaseNetwork = 'none';
        } else {
            $rejected = false;
            try {
                recoveryRestoreServer($context, $context['backend'], $context['backend-query'], $backup, $trusted);
            } catch (RuntimeException) {
                $rejected = true;
            }
            expect($rejected, '数据库恢复没有拒绝非空源库');
            cleanPackageCommand(['docker', 'network', 'create', '--internal', '--label', $context['label'], $restoredNetwork]);
            $createdNetwork = true;
            cleanPackageCommand(['docker', 'run', '--detach', '--pull=never', '--name', $restoredBackend, '--label', $context['label'],
                '--network', $restoredNetwork, '--network-alias', 'database', ...$context['backend-options'], $context['backend-image']], 30, $context['environment']);
            $createdBackend = true;
            $query = $context['backend-query'];
            foreach ($query as &$part) {
                if ($part === $context['backend']) {
                    $part = $restoredBackend;
                }
            } unset($part);
            $ready = false;
            $deadline = microtime(true) + 60;
            do {
                try {
                    $ready = trim(cleanPackageCommand([...$query, 'SELECT 1'], 5, $context['environment'])) === '1';
                } catch (RuntimeException) {
                }
                if (!$ready) {
                    usleep(100000);
                }
            } while (!$ready && microtime(true) < $deadline);
            expect($ready, '新恢复数据库未就绪');
            recoveryRestoreServer($context, $restoredBackend, $query, $backup, $trusted);
            expect(trim(cleanPackageCommand([...$query, 'SELECT COUNT(*) FROM admin_users'], 10, $context['environment'])) === '2', '恢复没有回到指定备份点');
            $restoreRuntime = $context['runtime'];
            $databaseNetwork = $restoredNetwork;
        }
        foreach ($restoreRuntime as &$argument) {
            if ($argument === 'type=bind,source=' . $data . ',target=/data') {
                $argument = 'type=bind,source=' . $restoreData . ',target=/data';
            }
        } unset($argument);
        $history = cleanPackageCommand([...$restoreRuntime, '--network', $databaseNetwork, $context['image'], 'migrate', 'history'], 30, $context['environment']);
        expect(hash('sha256', $history) === $record['migration-history-sha256'], '恢复遗漏或改动迁移历史');
        cleanPackageCommand([...$restoreRuntime, '--network', $databaseNetwork, $context['image'], 'migrate', 'status'], 30, $context['environment']);
        expect(file_get_contents($restoreData . '/.env') === $configuration && hash_file('sha256', $restoreData . '/uploads/example.bin') === $files['asset.bin']['sha256'], '外部配置或资源恢复不一致');
        $createdRestored = true;
        [$restoredClient] = recoveryStart($context, $restoreData, $restoredNetwork, $restoredApp);
        $login = $restoredClient->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
            'login' => 'clean-admin', 'password' => $adminPassword,
        ], JSON_THROW_ON_ERROR));
        expect($login->status === 200, '恢复后管理员登录失败');
        $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
        expect($restoredClient->request('GET', '/admin/users?search=after-backup', $headers)->json()['data']['total'] === 0, '恢复错误地保留了备份点之后的写入');
        expect($restoredClient->request('POST', '/admin/users', $headers, json_encode([
            'login' => 'restored-write', 'name' => '恢复后写入', 'password' => $adminPassword,
        ], JSON_THROW_ON_ERROR))->status === 200, '恢复后原生业务不可写');
        recoveryStop($restoredApp);
        $createdRestored = false;
        if ($driver !== 'sqlite') {
            expect(trim(cleanPackageCommand([...$context['backend-query'], 'SELECT COUNT(*) FROM admin_users'], 10, $context['environment'])) === '3', '恢复修改了原数据库');
        } else {
            $source = new PDO('sqlite:' . $data . '/var/app.sqlite');
            expect((int) $source->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 3, '恢复覆盖了原SQLite数据');
            unset($source);
        }
        recoveryVerify($backup, $trusted, $expected);
        expect(file_get_contents($data . '/uploads/example.bin') === 'asset-after-backup', '恢复覆盖了原资源数据');
        expect((fileperms($backup) & 0077) === 0, '备份目录不私有');
        foreach (['backup.json', ...array_keys($files)] as $file) {
            expect((fileperms($backup . '/' . $file) & 0077) === 0, '备份文件权限不私有');
        }
    } finally {
        if ($createdRestored) {
            cleanPackageCommand(['docker', 'rm', '--force', $restoredApp]);
        }
        if ($startedOriginal) {
            cleanPackageCommand(['docker', 'rm', '--force', $originalApp]);
        }
        if ($createdBackend) {
            cleanPackageCommand(['docker', 'rm', '--force', $restoredBackend]);
        }
        if ($createdNetwork) {
            cleanPackageCommand(['docker', 'network', 'rm', $restoredNetwork]);
        }
    }
    return ['backup-manifest-sha256' => $trusted, 'database-backup-sha256' => $files['database.dump']['sha256'], 'tool-version' => $version,
        'scope' => '测试应用数据库和显式配置/资源；不是整个数据库集群、所有账号、WAL/binlog或任意应用文件的备份',
        'checks' => ['writers-stopped', 'native-database-backup', 'private-backup-files', 'manifest-and-file-digests', 'corrupt-rejected',
            'existing-target-rejected', 'new-target-restored', 'migration-history-restored', 'external-config-and-data', 'snapshot-point-loss-explicit',
            'restored-native-read-write', 'original-data-preserved', 'normal-stop']];
}
