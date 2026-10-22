<?php

declare(strict_types=1);

require __DIR__ . '/application-runtime.php';
require dirname(__DIR__) . '/tools/release/UpgradeBaseline.php';

use Type\Build\ArtifactManifest;
use Type\Testing\HttpClient;
use Type\Testing\Process;
use TypeApp\Release\UpgradeBaseline;

// 当前 profile 使用真实公开旧程序建立非空安装；测试控制器不替代应用执行迁移。
expect(in_array($argc, [4, 5], true) && in_array($argv[2], ['sqlite', 'mysql', 'pgsql'], true), '用法：php tests/candidate-upgrade.php <当前程序> <数据库profile> <已核验旧版目录> [回执路径]');
$root = dirname(__DIR__);
$receiptPath = $argv[4] ?? null;
if ($receiptPath !== null) {
    $receiptParent = realpath(dirname($receiptPath));
    expect(is_string($receiptParent) && testPathIsWithin($receiptParent, $root . '/build') && !file_exists($receiptPath), '升级回执须为build内的新文件');
}
$target = realpath($argv[1]);
expect(is_string($target) && is_file($target . '.build.json'), '升级验收需要当前程序及构建身份');
$driver = $argv[2];
$platform = match (PHP_OS_FAMILY) {
    'Darwin' => 'macos-arm64', 'Windows' => 'windows-x64',
    'Linux' => in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'linux-arm64' : 'linux-x64',
    default => throw new RuntimeException('升级验收平台无效'),
};
$baseline = UpgradeBaseline::verify($argv[3], $platform, $driver);
$old = realpath($argv[3] . '/' . $baseline['file']);
expect(is_string($old), '已核验旧程序不存在');
$built = json_decode(file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
$identity = (new ArtifactManifest())->read($target, null, $built['sha256']);
if (($identity['runtime-linkage'] ?? null) === 'static') {
    $command = [$target];
} else {
    putenv('TYPE_NATIVE_PHP_INI=' . $built['runtime-profile']['ini']);
    $command = nativeCommand($target);
}
$base = $root . '/build/candidate-upgrade-' . bin2hex(random_bytes(6));
$directory = $base . '/application with spaces';
expect(mkdir($directory, 0700, true), '无法创建升级测试目录');
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (preg_match('/^(APP_|DB_|IOT_|BROKER_|REDIS_)/', $key)) {
        unset($environment[$key]);
    }
}
$environment = array_replace($environment, ['APP_BASE_PATH' => $directory, 'APP_ENV' => 'prod', 'APP_DEBUG' => 'false',
    'APP_CACHE_ENABLED' => 'false', 'APP_HTTP_THREADS' => '1', 'DB_DRIVER' => $driver, 'DB_SQLITE_FILE' => 'application.sqlite']);
$ownedDatabase = null;
$controlDsn = null;
$database = null;
$server = null;
$commandId = 0;
$report = ['status' => 'running', 'driver' => $driver, 'platform' => $platform, 'artifact-sha256' => hash_file('sha256', $target),
    'baseline' => $baseline, 'checks' => []];
try {
    if ($driver !== 'sqlite') {
        $prefix = 'TYPE_' . strtoupper($driver) . '_';
        foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $to => $from) {
            $value = getenv($prefix . $from);
            expect(is_string($value) && $value !== '', '升级验收缺少专用数据库参数：' . $prefix . $from);
            $environment['DB_' . $to] = $value;
        }
        expect($environment['DB_HOST'] === '127.0.0.1', '升级夹具只接受本轮回环数据库');
        $controlDsn = $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
        $owner = new PDO($controlDsn, $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $createdDatabase = 'type_upgrade_' . bin2hex(random_bytes(8));
        $owner->exec('CREATE DATABASE ' . $createdDatabase);
        $ownedDatabase = $createdDatabase;
        $owner = null;
        $environment['DB_DATABASE'] = $ownedDatabase;
    }
    $run = static function (array $arguments) use ($command, $environment, $directory, $base, &$commandId): \Type\Testing\ProcessResult {
        return runtimeCommand($command, $arguments, $environment, dirname($directory), $base . '/command-' . (++$commandId) . '.log');
    };
    $password = bin2hex(random_bytes(24));
    $legacyEnvironment = array_replace($environment, ['APP_ENV' => UpgradeBaseline::definition()['environment']]);
    $installed = runtimeCommand(
        [$old],
        ['app:install', 'operator', '平台管理员', 'customer', '客户管理员', '升级保留租户'],
        array_replace($legacyEnvironment, ['APP_ADMIN_PASSWORD' => $password, 'APP_CUSTOMER_PASSWORD' => $password . '-customer']),
        dirname($directory),
        $base . '/legacy-install.log'
    );
    expect($installed->successful(), '真实旧版本初始化失败：' . $installed->stderr);
    $receipt = json_decode($installed->stdout, true, 64, JSON_THROW_ON_ERROR);
    $fixture = runtimeBusinessFixture([$old], $legacyEnvironment, $directory, $password . '-customer', $receipt['data']['tenant_id'], $password, true);
    $dsn = $driver === 'sqlite' ? 'sqlite:' . $directory . '/application.sqlite'
        : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
    $database = new PDO($dsn, $environment['DB_USERNAME'] ?? '', $environment['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sourceDevices = $database->query('SELECT * FROM iot_devices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $sourceCompatibility = $database->query('SELECT * FROM broker_compatibility')->fetchAll(PDO::FETCH_ASSOC);
    $before = runtimeDataIdentity($database, $driver);
    $roles = [];
    $permissions = [];
    foreach (['admin', 'customer'] as $realm) {
        $roles[$realm] = $database->query('SELECT * FROM ' . $realm . '_roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $permissions[$realm] = [];
        foreach ($database->query('SELECT role_id, permission FROM ' . $realm . '_role_permissions')->fetchAll(PDO::FETCH_ASSOC) as $binding) {
            $permissions[$realm][] = $binding['role_id'] . ':' . $binding['permission'];
        }
    }
    $backup = $directory . '/backup';
    if ($driver === 'sqlite') {
        $database->exec('VACUUM INTO ' . $database->quote($backup));
    } else {
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $toolRoot = realpath((string) getenv('TYPE_' . strtoupper($driver) . '_TOOLS'));
        expect(is_string($toolRoot), '升级备份缺少对应数据库工具目录');
        $tool = $toolRoot . '/bin/' . ($driver === 'mysql' ? 'mysqldump' : 'pg_dump') . $suffix;
        expect(is_file($tool), '升级备份工具不存在');
        $arguments = $driver === 'mysql'
            ? ['--no-defaults', '--host=' . $environment['DB_HOST'], '--port=' . $environment['DB_PORT'], '--user=' . $environment['DB_USERNAME'], '--single-transaction', '--set-gtid-purged=OFF', $environment['DB_DATABASE']]
            : ['--host=' . $environment['DB_HOST'], '--port=' . $environment['DB_PORT'], '--username=' . $environment['DB_USERNAME'], $environment['DB_DATABASE']];
        $dump = new Process([$tool, ...$arguments], $directory, array_replace($environment, ['MYSQL_PWD' => $environment['DB_PASSWORD'], 'PGPASSWORD' => $environment['DB_PASSWORD']]), 16777216);
        try {
            $dumped = $dump->wait(45);
            expect($dumped->successful(), '真实旧库备份失败');
            expect(file_put_contents($backup, $dumped->stdout) === strlen($dumped->stdout), '无法保存本轮备份');
        } finally {
            $dump->stop(5);
        }
    }
    chmod($backup, 0600);
    $preflight = $run(['app:upgrade', '--check']);
    expect($preflight->successful(), '真实旧版本升级预检失败：' . $preflight->stderr);
    $inventory = json_decode($preflight->stdout, true, 64, JSON_THROW_ON_ERROR);
    expect($inventory['data_upgrade']['legacy_devices'] === 103 && $inventory['data_upgrade']['pending']['exports'] === 1
        && $inventory['data_upgrade']['installation_id'] === $receipt['data']['installation_id'], '升级预检未识别真实旧数据');
    expect(runtimeDataIdentity($database, $driver) === $before, '只读预检改写了旧数据');
    $upgrade = ['app:upgrade', '--offline', '--backup', 'backup', '--sha256', hash_file('sha256', $backup)];
    $first = $run($upgrade);
    expect($first->successful() && json_decode($first->stdout, true, 64, JSON_THROW_ON_ERROR)['status'] === 'upgrade_in_progress', '首批升级未保留可续跑状态：' . $first->stderr);
    expect((int) $database->query('SELECT COUNT(*) FROM iot_device_upgrade_records')->fetchColumn() === 100, '首批未按既定100台边界执行');
    $blocked = $run(['serve']);
    expect(!$blocked->successful() && str_contains($blocked->stderr, 'upgrade_data_incomplete'), '未完成转换时开放了当前业务');
    $stale = runtimeCommand([$old], ['serve'], $legacyEnvironment, dirname($directory), $base . '/stale-runtime.log');
    expect(!$stale->successful() && str_contains($stale->stdout . $stale->stderr, 'broker_compat_runtime_stale'), '旧公开程序未被兼容门拒绝');
    $resumed = $run($upgrade);
    expect($resumed->successful() && json_decode($resumed->stdout, true, 64, JSON_THROW_ON_ERROR)['status'] === 'upgrade_complete', '续跑不能完成升级：' . $resumed->stderr);
    runtimeDeviceUpgrade($database, $sourceDevices, $sourceCompatibility);
    if (!isset($before['iot_registration_attempts'])) {
        $attempts = runtimeDataIdentity($database, $driver)['iot_registration_attempts'];
        expect($attempts === ['rows' => 0, 'sha256' => hash('sha256', '')], '升级隐式产生注册尝试');
        $before['iot_registration_attempts'] = $attempts;
        ksort($before);
    }
    $after = runtimeIdentityUpgrade($database, $driver, $before, $roles, $permissions);
    expect($run($upgrade)->successful() && runtimeDataIdentity($database, $driver) === $after, '重复升级改写了已完成数据');
    expect($run(['web:install', '--force'])->successful(), '升级后无法从同一候选安装页面');
    foreach ($identity['embedded-resources'] as $path => $resource) {
        if (str_starts_with($path, 'web/')) {
            expect(hash_file('sha256', $directory . '/public/' . substr($path, 4)) === $resource['sha256'], '升级页面与候选内嵌字节不一致');
        }
    }
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    expect(is_resource($listener), '无法分配升级后HTTP端口');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $server = new Process([...$command, 'serve'], dirname($directory), array_replace($environment, ['APP_PORT' => substr(strrchr($address, ':'), 1), 'APP_ALLOWED_HOSTS' => $address]));
    $client = new HttpClient('http://' . $address, PHP_OS_FAMILY === 'Windows' ? 15 : 5);
    runtimeProbe($client, $server, '/readyz', 200);
    foreach (['admin' => $fixture['admin_token'], 'customer' => $fixture['token']] as $realm => $token) {
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
        if ($realm === 'admin') {
            $wrongScope = $client->request('GET', '/admin/auth/me', $headers + ['X-Tenant-Id' => $fixture['tenant']]);
            expect($wrongScope->status === 403, '升级后控制端接受了客户身份上下文');
        } else {
            $headers['X-Tenant-Id'] = $fixture['tenant'];
        }
        $session = $client->request('GET', '/' . $realm . '/auth/me', $headers);
        $report['identity_checks'][$realm] = ['status' => $session->status];
        expect($session->status === 200, '升级后原身份会话失败：' . $realm . ' status=' . $session->status);
        $applications = $realm === 'admin' ? '/admin/applications' : $fixture['path'] . '/applications';
        $input = ['id' => bin2hex(random_bytes(16)), 'name' => '升级后接入应用'];
        if ($realm === 'admin') {
            $input['tenant_id'] = $fixture['tenant'];
        }
        $created = $client->request('POST', $applications, $headers, json_encode($input, JSON_THROW_ON_ERROR));
        expect($created->status === 201, '升级后最高角色不能使用新应用权限：' . $realm);
    }
    $headers = ['Authorization' => 'Bearer ' . $fixture['token'], 'X-Tenant-Id' => $fixture['tenant']];
    $device = $client->request('GET', $fixture['path'] . '/devices/' . $fixture['device'], $headers);
    expect($device->status === 200 && $device->json()['data']['name'] === '升级保留设备'
        && !array_key_exists('model_version', $device->json()['data']), '升级后设备缺失或仍开放物模型');
    foreach (['current', 'history', 'exports', 'commands'] as $retired) {
        expect($client->request('GET', $fixture['path'] . '/devices/' . $fixture['device'] . '/' . $retired, $headers)->status === 404, '退役入口仍可访问');
    }
    expect($server->stop(5)->successful(), '升级后服务未正常停止');
    expect(hash_file('sha256', $target) === $report['artifact-sha256'], '升级验收期间候选程序字节改变');
    $report['checks'] = ['published-baseline-installation', 'nonempty-legacy-data-and-pending-export', 'readonly-inventory',
        'real-database-backup', 'batch-100-and-resume', 'old-runtime-rejected', 'source-records-preserved',
        'ordinary-roles-not-escalated', 'idempotent-upgrade', 'embedded-frontend-installed', 'dual-realm-new-permissions', 'retired-api-rejected'];
    $report['status'] = 'passed';
} finally {
    $server?->stop(5);
    unset($run, $owner);
    $database = null;
    if ($ownedDatabase !== null) {
        $cleanup = new PDO($controlDsn, $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cleanup->exec('DROP DATABASE ' . $ownedDatabase);
        $cleanup = null;
    }
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if ($receiptPath !== null) {
        expect(copy($base . '/verification.json', $receiptPath), '无法保存候选升级回执');
    }
    if ($report['status'] === 'passed') {
        removeTestDirectory($directory);
    }
}
echo '真实公开旧版本升级通过：' . $base . "/verification.json\n";
