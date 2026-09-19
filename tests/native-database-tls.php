<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;

$root = dirname(__DIR__);
$driver = $argv[1] ?? '';
expect(
    in_array($driver, ['mysql', 'pgsql'], true) && $argc === 4,
    '用法：php tests/native-database-tls.php <mysql|pgsql> <数据库工具根> <TLS原生产物>'
);
$tools = NativeDatabase::tools($driver, $argv[2]);
$binary = realpath($argv[3]);
expect($binary !== false, 'TLS原生产物不存在');
(new BuildPlatform())->assertArtifact($binary);
$base = $root . '/build/native-' . $driver . '-tls-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮TLS测试目录');
$environment = (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: '');
$environment['PATH'] = getenv('PATH') ?: '';
$environment['PHPRC'] = getenv('PHPRC') ?: '';
$environment['PHP_INI_SCAN_DIR'] = getenv('PHP_INI_SCAN_DIR') ?: '';
$openssl = getenv('TYPE_OPENSSL_BINARY') ?: 'openssl';
$commands = [
    [$openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $base . '/ca.key', '-out', $base . '/ca.pem', '-subj', '/CN=Type-Native-Test-CA', '-days', '2'],
    [$openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $base . '/wrong-ca.key', '-out', $base . '/wrong-ca.pem', '-subj', '/CN=Type-Native-Wrong-CA', '-days', '2'],
    [$openssl, 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', $base . '/server.key', '-out', $base . '/server.csr', '-subj', '/CN=type-native-db', '-addext', 'subjectAltName=IP:127.0.0.1'],
    [$openssl, 'x509', '-req', '-in', $base . '/server.csr', '-CA', $base . '/ca.pem', '-CAkey', $base . '/ca.key', '-CAcreateserial', '-days', '2', '-copy_extensions', 'copy', '-out', $base . '/server.pem'],
];
$database = null;
$report = ['status' => 'running', 'driver' => $driver, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'php' => PHP_VERSION, 'pdo-extension' => phpversion('pdo_' . $driver), 'artifact-sha256' => hash_file('sha256', $binary),
    'artifact-report-sha256' => hash_file('sha256', $binary . '.build.json'), 'steps' => []];
try {
    foreach ($commands as $index => $command) {
        nativeDatabaseCommand($command, $environment, [], $base . '/certificate-' . $index . '.log', 30);
    }
    expect(chmod($base . '/server.key', 0600), '无法保护服务器私钥');
    $database = new NativeDatabase(
        $base . '/database',
        $driver,
        $tools,
        ['ca' => $base . '/ca.pem', 'certificate' => $base . '/server.pem', 'key' => $base . '/server.key']
    );
    $connectionEnvironment = $database->environment();
    $port = $connectionEnvironment['TYPE_' . strtoupper($driver) . '_PORT'];
    $password = $connectionEnvironment['TYPE_' . strtoupper($driver) . '_PASSWORD'];
    // localhost.使用TCP且不匹配仅含IP SAN的证书；先绕过身份验证核对其实际服务器身份。
    $wrongHost = 'localhost.';
    $dsn = $driver . ':host=' . $wrongHost . ';port=' . $port . ';dbname=type_app_test';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if ($driver === 'mysql') {
        $options[\Pdo\Mysql::ATTR_SSL_CA] = $base . '/ca.pem';
        $options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
    } else {
        $dsn .= ';sslmode=require';
    }
    $probe = new PDO($dsn, $driver === 'mysql' ? 'root' : 'type_app', $password, $options);
    $data = $probe->query($driver === 'mysql' ? 'SELECT @@datadir' : 'SHOW data_directory')->fetchColumn();
    expect(is_string($data) && realpath($data) === realpath($base . '/database/data'), '错误主机名没有到达本轮同一服务器');
    $report['wrong-host-connected-to-owned-server'] = true;
    $report['pdo-client'] = $probe->getAttribute(PDO::ATTR_CLIENT_VERSION);
    $probe = null;
    $environment = array_replace($connectionEnvironment, $environment, ['TYPE_TLS_HOST' => '127.0.0.1', 'TYPE_TLS_WRONG_HOST' => $wrongHost,
        'TYPE_TLS_PORT' => $port, 'TYPE_TLS_CA' => $base . '/ca.pem', 'TYPE_TLS_WRONG_CA' => $base . '/wrong-ca.pem', 'TYPE_TLS_PASSWORD' => $password]);
    foreach (['php' => '--php', 'native' => $binary] as $mode => $target) {
        $log = $base . '/' . $mode . '.log';
        $runEnvironment = $environment;
        if ($mode === 'native') {
            $built = json_decode(file_get_contents($binary . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
            $runEnvironment['TYPE_NATIVE_PHP_INI'] = $built['runtime-profile']['ini'];
        }
        echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/tls.php', $target, $driver], $runEnvironment, [$password], $log, 60);
        $report['steps'][] = ['mode' => $mode, 'log' => basename($log), 'sha256' => hash_file('sha256', $log)];
    }
    $report['status'] = 'passed';
} finally {
    try {
        if ($database !== null) {
            $database->close();
            $report['database'] = $database->evidence();
        }
    } catch (Throwable $cleanup) {
        $report['status'] = 'failed';
        throw $cleanup;
    } finally {
        foreach (['ca.key', 'wrong-ca.key', 'server.key'] as $private) {
            if (is_file($base . '/' . $private) && !unlink($base . '/' . $private)) {
                $report['status'] = 'failed';
            }
        }
        if ($report['status'] !== 'passed') {
            $report['status'] = 'failed';
        }
        file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
expect($report['status'] === 'passed', 'TLS测试或私钥清理失败');
echo '原生数据库PHP/AOT TLS验收通过：' . substr($base, strlen($root) + 1) . "/verification.json\n";
