<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

$root = dirname(__DIR__);
$kind = $argv[2] ?? 'mysql';
expect(in_array($kind, ['mysql', 'pgsql', 'sqlite'], true), '未知租户测试驱动');
$admin = $kind === 'sqlite' ? null : TypeApp\ModelExample\Drivers::create($kind)->connect();
$createdDatabases = [];
$createdUsers = [];
$createdSchemas = [];
$sqliteFiles = [];
$targets = [];
$sensitive = [];
$record = $root . '/build/tenant-http-' . $kind . '-' . bin2hex(random_bytes(6));
expect(mkdir($record, 0700), '无法创建租户验收记录目录');
$environment = getenv();
$environment['TYPE_TENANT_APPLICATION'] = 'type_tenant_test_' . bin2hex(random_bytes(8));
$environment['TYPE_TENANT_DRIVER'] = $kind;
$environment['TYPE_TENANT_LOG'] = $record . '/requests.jsonl';
$process = null;
$log = tmpfile();
expect($log !== false, '无法准备租户日志');
function tenantRequest(int $port, string $token, string $tenant, string $path = '/tenant')
{
    static $sequence = 0;
    $marker = 'request-' . ++$sequence;
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 3);
    expect(is_resource($socket), '无法连接租户入口');
    stream_set_timeout($socket, 5);
    $wire = 'GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nAuthorization: Bearer " . $token . "\r\nX-Test-Marker: " . $marker . "\r\nX-Tenant: " . $tenant . "\r\n\r\n";
    expect(fwrite($socket, $wire) === strlen($wire), '租户请求写入不完整');
    return $socket;
}
try {
    foreach (['alpha', 'beta'] as $tenant) {
        $id = 'type_tenant_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(24));
        $databaseName = $id;
        $qualifier = '';
        $seed = $admin;
        if ($kind === 'mysql') {
            $admin->exec('CREATE DATABASE `' . $id . '`');
            $createdDatabases[] = $id;
            $admin->exec('CREATE USER ' . $admin->quote($id) . "@'%' IDENTIFIED BY " . $admin->quote($password));
            $createdUsers[] = $id;
            $admin->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON `' . $id . '`.* TO ' . $admin->quote($id) . "@'%'");
            $qualifier = '`' . $id . '`.';
        } elseif ($kind === 'pgsql') {
            $databaseName = getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test';
            $admin->exec('CREATE ROLE "' . $id . '" LOGIN PASSWORD ' . $admin->quote($password));
            $createdUsers[] = $id;
            $admin->exec('CREATE SCHEMA "' . $id . '" AUTHORIZATION "' . $id . '"');
            $createdSchemas[] = $id;
            $qualifier = '"' . $id . '".';
        } else {
            $databaseName = $record . '/' . $tenant . '.sqlite';
            $seed = TypeApp\ModelExample\Drivers::create('sqlite', $databaseName)->connect();
            $sqliteFiles[] = $databaseName;
        }
        $seed->exec('CREATE TABLE ' . $qualifier . 'tenant_values (id INTEGER PRIMARY KEY, owner VARCHAR(255), visits INTEGER)');
        $seed->exec('CREATE TABLE ' . $qualifier . 'tenant_labels (id INTEGER PRIMARY KEY, label VARCHAR(255))');
        $seed->exec('INSERT INTO ' . $qualifier . 'tenant_values VALUES (1,' . $seed->quote($tenant) . ',0)');
        $seed->exec('INSERT INTO ' . $qualifier . 'tenant_labels VALUES (1,' . $seed->quote($tenant . '-label') . ')');
        if ($kind === 'pgsql') {
            $admin->exec('GRANT USAGE ON SCHEMA "' . $id . '" TO "' . $id . '"');
            $admin->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA "' . $id . '" TO "' . $id . '"');
        }
        $targets[$tenant] = ['database' => $databaseName, 'qualifier' => $qualifier];
        $sensitive = [...$sensitive, $id, $password, $databaseName];
        $seed = null;
        $prefix = 'TYPE_TENANT_' . strtoupper($tenant) . '_';
        $environment[$prefix . 'DATABASE'] = $databaseName;
        $environment[$prefix . 'USER'] = $id;
        $environment[$prefix . 'PASSWORD'] = $password;
        $environment[$prefix . 'SCHEMA'] = $kind === 'pgsql' ? $id : '';
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($socket), '无法分配租户端口');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $environment['TYPE_HTTP_PORT'] = (string) $port;
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($root . '/examples/tenant/Endpoint.php', true)
            . '; require ' . var_export($root . '/examples/tenant-http-command.php', true) . '; main($argc,$argv);';
        $command = [PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $launcher];
    }
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
    expect(is_resource($process), '无法启动租户服务');
    $deadline = microtime(true) + 10;
    $ready = false;
    while (microtime(true) < $deadline) {
        expect(proc_get_status($process)['running'], '租户服务提前退出');
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        } usleep(10000);
    }
    expect($ready, '租户服务没有就绪');
    for ($pass = 0; $pass < 2; $pass++) {
        $requests = [];
        for ($index = 0; $index < 8; $index++) {
            $tenant = $index % 2 === 0 ? 'alpha' : 'beta';
            $requests[] = [$tenant, tenantRequest($port, $tenant . '-token', $tenant)];
        }
        foreach ($requests as [$tenant, $request]) {
            [$status, $body] = receiveHttp($request);
            expect($status === 200 && json_decode($body, true) === ['tenant' => $tenant, 'owner' => $tenant, 'label' => $tenant . '-label', 'raw' => $tenant], '租户 SQL/Join/缓存状态混用：' . $body);
        }
    }
    foreach (['beta', 'unknown', '../alpha', 'alpha;other', 'alpha,beta'] as $tenant) {
        [$status, $body] = receiveHttp(tenantRequest($port, 'alpha-token', $tenant));
        expect($status === 403 && json_decode($body, true)['error'] === 'tenant_forbidden', '未授权输入改变了租户资源');
    }
    foreach (['alpha', 'beta'] as $tenant) {
        foreach (['poison=true', 'fail=true', 'rotate=true'] as $action) {
            [$status, $body] = receiveHttp(tenantRequest($port, $tenant . '-token', $tenant, '/tenant?' . $action));
            expect($status === ($action === 'fail=true' ? 500 : 200), '租户会话/事务/代次操作失败：' . $body);
            if ($action === 'fail=true') {
                expect($body === '{"error":"internal_error"}', '租户失败暴露了内部异常');
            }
            [$status, $body] = receiveHttp(tenantRequest($port, $tenant . '-token', $tenant));
            expect($status === 200 && json_decode($body, true)['owner'] === $tenant, '失败或轮换后的下一请求取得错误身份');
        }
        [$status, $body] = receiveHttp(tenantRequest($port, $tenant . '-token', $tenant, '/tenant?probe=true'));
        $boundary = json_decode($body, true);
        expect($status === 200 && $boundary['cross_database_denied'] === ($kind === 'sqlite' ? null : true), '数据库权限探针没有维持声明边界');
        expect($boundary['isolation'] === ($kind === 'sqlite' ? 'trusted-file-mapping' : 'database-permissions'), 'SQLite文件映射被冒称数据库账号权限');
        for ($repeat = 0; $repeat < 3; $repeat++) {
            [$status, $body] = receiveHttp(tenantRequest($port, $tenant . '-token', $tenant));
            expect($status === 200 && json_decode($body, true)['owner'] === $tenant, '租约复用后租户错误');
        }
        $inspection = $admin ?? TypeApp\ModelExample\Drivers::create('sqlite', $targets[$tenant]['database'])->connect();
        expect((int) $inspection->query('SELECT visits FROM ' . $targets[$tenant]['qualifier'] . 'tenant_values WHERE id = 1')->fetchColumn() === 1, '失败租户事务仍有持久写入');
        $inspection = null;
    }
    echo "两个授权租户的模型、会话复用、回滚、代次和缓存隔离通过。\n";
} finally {
    if (is_resource($process)) {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 15);
        }
        $deadline = microtime(true) + 10;
        do {
            $state = proc_get_status($process);
            if (!$state['running']) {
                break;
            } usleep(10000);
        } while (microtime(true) < $deadline);
        if ($state['running']) {
            proc_terminate($process, 9);
        } proc_close($process);
    }
    foreach ($createdSchemas as $schema) {
        $admin->exec('DROP SCHEMA "' . $schema . '" CASCADE');
    }
    foreach ($createdUsers as $user) {
        $admin->exec($kind === 'mysql' ? 'DROP USER ' . $admin->quote($user) . "@'%'" : 'DROP ROLE "' . $user . '"');
    }
    foreach ($createdDatabases as $database) {
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
    foreach ($sqliteFiles as $file) {
        foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
    rewind($log);
    $output = stream_get_contents($log);
    fclose($log);
    if ($output !== '') {
        fwrite(STDERR, $output);
    }
}
$wire = file_get_contents($record . '/requests.jsonl');
expect(is_string($wire) && $wire !== '' && str_ends_with($wire, "\n"), '租户日志没有完整写出');
foreach (array_unique([...$sensitive, 'alpha-token', 'beta-token', 'tenant failure sentinel']) as $secret) {
    expect($secret === '' || !str_contains($wire, $secret), '租户日志泄漏敏感身份或内部异常');
}
$rows = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($wire)));
$namespaces = [];
$reused = [];
$failures = 0;
$shutdown = null;
foreach ($rows as $row) {
    if ($row['message'] === 'tenant-stopped') {
        if ($row['context']['before']['active'] !== []) {
            expect($shutdown === null, '单worker测试出现多份活动池停止记录');
            $shutdown = $row['context'];
        } else {
            expect($row['context']['after']['active'] === [], '未服务请求的主进程意外持有连接');
        }
        continue;
    }
    expect($row['message'] === 'tenant-request' && preg_match('/^request-[0-9]+$/D', $row['correlation']['request_id']) === 1, '租户日志缺少请求关联');
    $tenant = $row['correlation']['tenant_id'];
    $generation = $row['context']['generation'];
    expect(in_array($tenant, ['alpha', 'beta'], true) && in_array($generation, [1, 2], true), '日志中的租户或凭据代次错误');
    $namespaces[$tenant][$generation] ??= $row['context']['namespace'];
    expect($namespaces[$tenant][$generation] === $row['context']['namespace'], '同一已声明身份的缓存键空间不稳定');
    $reused[$tenant] = ($reused[$tenant] ?? false) || $row['context']['reused_idle'];
    if ($row['context']['failed']) {
        expect($row['context']['outcome'] === 'ROLLED_BACK', '失败租户事务没有回滚');
        $failures++;
    }
}
expect($failures === 2 && is_array($shutdown), '失败与停止日志不完整');
foreach (['alpha', 'beta'] as $tenant) {
    expect(($reused[$tenant] ?? false) && isset($namespaces[$tenant][1], $namespaces[$tenant][2])
        && $namespaces[$tenant][1] !== $namespaces[$tenant][2], '没有真实复用空闲租约或轮换缓存身份');
    expect(isset($shutdown['before']['active'][$tenant . '-db'], $shutdown['after']['active'][$tenant . '-db'])
        && $shutdown['before']['active'][$tenant . '-db']['leased'] === 0
        && $shutdown['before']['active'][$tenant . '-db']['created'] <= 8
        && $shutdown['after']['active'][$tenant . '-db']['created'] === 0, '租户池超过预算或没有完整回收');
}
expect($namespaces['alpha'][1] !== $namespaces['beta'][1] && $namespaces['alpha'][2] !== $namespaces['beta'][2], '不同租户共享缓存身份');
file_put_contents($record . '/verification.json', json_encode(['status' => 'passed', 'driver' => $kind,
    'transport' => 'swoole', 'mode' => ($argv[1] ?? '--php') === '--php' ? 'php' : 'native',
    'namespaces' => $namespaces, 'reused' => $reused, 'rolled_back_failures' => $failures, 'shutdown' => $shutdown,
    'log_sha256' => hash('sha256', $wire), 'database_permissions' => $kind === 'sqlite' ? 'not-applicable-file-mapping' : 'verified'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '租户身份与日志证据：' . substr($record, strlen($root) + 1) . "/verification.json\n";
