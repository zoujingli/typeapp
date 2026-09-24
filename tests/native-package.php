<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-package-sandbox.php';

use Type\Build\NativePackage;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Testing\HttpClient;
use Type\Testing\Process;

$root = dirname(__DIR__);
$artifact = realpath($argv[1] ?? $root . '/build/app/type-app');
expect($artifact !== false, '需要真实标准物联项目原生产物');
$project = realpath(getenv('TYPE_PACKAGE_PROJECT') ?: $root);
expect($project !== false && is_file($project . '/.env.example'), '需要被验收应用的配置示例');
$helpMarker = $project === $root ? 'TypeApp 物联中心' : 'Type 业务应用';
$driver = getenv('TYPE_PACKAGE_DRIVER') ?: 'sqlite';
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '发布验收需要明确的受支持数据库驱动');
$base = $root . '/build/package-test-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮发布测试目录');
$publisher = new NativePackage();
$created = json_decode(successful([PHP_BINARY, $project . '/vendor/bin/type',
    'package', $artifact, $base . '/release', $project . '/.env.example'], $project), true, 512, JSON_THROW_ON_ERROR);
$package = $base . '/moved release';
expect(rename($created['directory'], $package), '无法搬迁实际发布目录');
$release = $publisher->verify($package, $created['manifest-sha256']);
expect(isset($release['files']['OPERATIONS.md']) && file_get_contents($package . '/OPERATIONS.md') === file_get_contents($root . '/plugin/type-build/docs/operations.md'), '发布未包含同一份完整操作手册');
expect(isset($release['files']['LICENSE'], $release['files']['NOTICE'])
    && file_get_contents($package . '/LICENSE') === file_get_contents($root . '/LICENSE')
    && file_get_contents($package . '/NOTICE') === file_get_contents($root . '/NOTICE'), '发布未包含完整第一方许可证材料');
expect(!is_dir($package . '/vendor') && !is_dir($package . '/app'), '发布包含源码或Composer目录');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS)) as $entry) {
    expect(!preg_match('/\.(?:php[0-9]?|phtml|phar|inc|cc|cpp|h)$/iD', $entry->getFilename()), '发布包含源码或编译头文件');
}
$overwritten = false;
try {
    $publisher->create($artifact, $package);
} catch (RuntimeException $error) {
    $overwritten = str_contains($error->getMessage(), '已存在');
}
expect($overwritten, '已有发布目录没有拒绝覆盖');
file_put_contents($base . '/.env.example', "APP_API_TOKEN=do-not-publish\n");
$secretRejected = false;
try {
    $publisher->create($artifact, $base . '/secret-rejected', $base . '/.env.example');
} catch (RuntimeException $error) {
    $secretRejected = str_contains($error->getMessage(), '秘密');
}
expect($secretRejected && !is_dir($base . '/secret-rejected'), '带秘密配置被发布');
unlink($base . '/.env.example');

$runtime = $base . '/runtime-data';
expect(mkdir($runtime, 0700), '无法创建发布之外的独立运行数据根');
$environment = ['PATH' => '/usr/bin:/bin', 'APP_BASE_PATH' => $runtime, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_CACHE_ENABLED' => 'false', 'DB_DRIVER' => $driver, 'DB_SQLITE_FILE' => 'var/app.sqlite',
    'APP_API_TOKEN' => 'package-test-' . bin2hex(random_bytes(20)), 'TYPE_APP_RELEASE_SHA256' => $created['manifest-sha256'], 'TYPE_APP_TRACE' => '1'];
$command = [$package . (PHP_OS_FAMILY === 'Windows' ? '/run.cmd' : '/run')];
$isolated = false;
if (PHP_OS_FAMILY === 'Darwin' || (PHP_OS_FAMILY === 'Linux' && getenv('TYPE_BWRAP_BINARY') !== false)) {
    $command = sandboxPackageCommand($root, $package, [$runtime]);
    $isolated = true;
} elseif (PHP_OS_FAMILY === 'Windows') {
    $environment['SystemRoot'] = (string) getenv('SystemRoot');
    $environment['TEMP'] = sys_get_temp_dir();
    $command = [$environment['SystemRoot'] . '/System32/cmd.exe', '/d', '/c', $package . '/run.cmd'];
}
$help = (new Process([...$command, 'help'], $package, $environment))->wait(10);
expect($help->successful() && str_contains($help->stdout, $helpMarker) && $help->stderr === '', '搬迁后原生帮助失败：' . $help->stderr);
$untrustedEnvironment = $environment;
$untrustedEnvironment['TYPE_APP_RELEASE_SHA256'] = str_repeat('0', 64);
$untrusted = (new Process([...$command, 'help'], $package, $untrustedEnvironment))->wait(10);
expect($untrusted->exitCode === 1 && !$untrusted->timedOut && !str_contains($untrusted->stdout, $helpMarker), '错误的外部受信摘要没有在原生启动时拒绝');
$audit = (new Process([...$command, 'verify-runtime'], $package, $environment))->wait(90);
expect($audit->successful() && $audit->stdout === "运行环境完整性校验通过。\n", '独立运行库完整审计失败：' . $audit->stderr);
$admin = null;
$databaseCreated = false;
$database = 'type_package_' . bin2hex(random_bytes(6));
try {
    if ($driver !== 'sqlite') {
        $prefix = 'TYPE_' . strtoupper($driver) . '_';
        $settings = [];
        foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD'] as $key) {
            $value = getenv($prefix . $key);
            expect(is_string($value) && $value !== '', '发布验收需要专用数据库参数：' . $prefix . $key);
            $settings[$key] = $value;
        }
        expect(ctype_digit($settings['PORT']) && (int) $settings['PORT'] > 0 && (int) $settings['PORT'] < 65536, '发布验收数据库端口无效');
        $databaseDriver = $driver === 'mysql'
            ? new MysqlDriver($settings['HOST'], (int) $settings['PORT'], $settings['DATABASE'], $settings['USER'], $settings['PASSWORD'])
            : new PgsqlDriver($settings['HOST'], (int) $settings['PORT'], $settings['DATABASE'], $settings['USER'], $settings['PASSWORD']);
        $admin = $databaseDriver->connect();
        $admin->exec('CREATE DATABASE ' . $database);
        $databaseCreated = true;
        $environment['DB_HOST'] = $settings['HOST'];
        $environment['DB_PORT'] = $settings['PORT'];
        $environment['DB_DATABASE'] = $database;
        $environment['DB_USERNAME'] = $settings['USER'];
        $environment['DB_PASSWORD'] = $settings['PASSWORD'];
    }
    $password = bin2hex(random_bytes(16));
    $initialization = $project === $root
        ? [...$command, 'app:install', 'package-admin', '发布管理员', 'package-customer', '发布客户', '发布租户']
        : [...$command, 'migrate', 'run'];
    $installed = (new Process($initialization, $package, $environment + [
        'APP_ADMIN_PASSWORD' => $password, 'APP_CUSTOMER_PASSWORD' => $password . '-customer',
    ]))->wait(20);
    expect($installed->successful(), '源码不可访问时应用初始化失败：' . $installed->stderr);

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法选择部署验收端口');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $address;
    $processInfo = PHP_OS_FAMILY === 'Linux' && $isolated ? $base . '/server-process.json' : null;
    $serverCommand = $processInfo === null ? $command : sandboxPackageCommand($root, $package, [$runtime], $processInfo);
    $process = new Process([...$serverCommand, 'serve'], $package, $environment);
    $client = new HttpClient('http://' . $address);
    try {
        $ready = false;
        $until = microtime(true) + 10;
        do {
            expect($process->running(), '部署服务提前退出：' . $process->stderr());
            try {
                $ready = $client->request('GET', '/readyz')->status === 200;
            } catch (RuntimeException) {
            }
            if (!$ready) {
                usleep(10000);
            }
        } while (!$ready && microtime(true) < $until);
        expect($ready, '部署服务未就绪');
        $headers = ['Authorization' => 'Bearer ' . $environment['APP_API_TOKEN'], 'Content-Type' => 'application/json'];
        if (getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE') !== false) {
            expect($client->request('GET', '/', $headers)->json()['message'] === getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE'), '无源码部署没有运行创建后修改的业务');
        }
        if ($project === $root) {
            expect($client->request('GET', '/admin/users')->status === 401, '部署丢失授权');
            $login = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
                'login' => 'package-admin', 'password' => $password,
            ], JSON_THROW_ON_ERROR));
            expect($login->status === 200, '部署管理员登录失败');
            $headers['Authorization'] = 'Bearer ' . $login->json()['data']['accessToken'];
            $user = $client->request('POST', '/admin/users', $headers, json_encode([
                'login' => 'package-user', 'name' => '包验收用户', 'password' => $password,
            ], JSON_THROW_ON_ERROR));
            expect($user->status === 200, '部署人员创建失败');
            $saved = $user->json()['data'];
            $listed = $client->request('GET', '/admin/users?search=package-user', $headers);
            expect($listed->status === 200 && $listed->json()['data']['total'] === 1, '部署人员查询失败');
            $invalid = $client->request('PATCH', '/admin/users/' . $saved['id'], $headers, json_encode([
                'version' => 1, 'login' => 'package-user', 'name' => '资料', 'password' => $password,
            ], JSON_THROW_ON_ERROR));
            expect($invalid->status === 422, '部署绕过了人员资料字段白名单');
        } else {
            expect($client->request('GET', '/users')->status === 401, '部署丢失授权');
            $user = $client->request('POST', '/users', $headers, '{"name":"包验收用户","age":21}');
            expect($user->status === 201 && $client->request('GET', '/users?sort=age&direction=DESC', $headers)->json()['total'] === 1, '部署业务、排序或数据库写入失败');
            expect($client->request('GET', '/users?sort=email', $headers)->status === 422, '部署绕过了校验白名单');
        }
        $stopped = stopPackageProcess($process, $package, $processInfo, 5);
        expect($stopped->successful(), '部署进程未正常停止：' . json_encode(['exit' => $stopped->exitCode, 'signal' => $stopped->signal, 'timeout' => $stopped->timedOut]));
    } finally {
        try {
            stopPackageProcess($process, $package, $processInfo, 5);
        } finally {
            $process->stop();
        }
    }
} finally {
    if ($admin !== null && $databaseCreated) {
        $admin->exec('DROP DATABASE ' . $database);
    }
    $admin = null;
}

$example = $package . '/config/env.example';
$original = (string) file_get_contents($example);
expect($original !== '', '等长篡改验收需要非空配置示例');
file_put_contents($example, chr(ord($original[0]) ^ 1) . substr($original, 1));
$tamperRejected = false;
try {
    $publisher->verify($package, $created['manifest-sha256']);
} catch (RuntimeException) {
    $tamperRejected = true;
}
$tamperedRun = (new Process([...$command, 'check'], $package, $environment))->wait(10);
expect($tamperRejected && $tamperedRun->exitCode === 1 && !$tamperedRun->timedOut, '发布文件等长篡改未在校验和运行入口拒绝');
file_put_contents($example, $original);
$publisher->verify($package, $created['manifest-sha256']);
if (PHP_OS_FAMILY !== 'Windows') {
    chmod($package . '/run', 0644);
    clearstatcache();
    $permissionRejected = false;
    try {
        $publisher->verify($package, $created['manifest-sha256']);
    } catch (RuntimeException) {
        $permissionRejected = true;
    } finally {
        chmod($package . '/run', 0755);
        clearstatcache();
    }
    expect($permissionRejected, '摘要不变的不可执行启动器被误报为有效发布');
}

$record = ['os' => PHP_OS_FAMILY, 'driver' => $driver, 'artifact-sha256' => $release['artifact']['sha256'], 'release-sha256' => $created['manifest-sha256'],
    'package' => $package, 'project' => $project, 'relocated' => true, 'source-and-sdk-read-denied' => $isolated, 'scope' => '当前应用' . $driver . '原生发布闭环，不代表其他平台已验收',
    'business-message' => getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE') ?: null,
    'composer-read-and-php-compiler-exec-denied' => $isolated,
    'checks' => ['no-source-payload', 'no-overwrite', 'secret-rejection', 'help', 'external-trusted-digest-rejection', 'deployment-audit', 'application-initialization', 'http-auth-crud-query-validation', 'graceful-stop', 'same-size-tamper-rejection', 'executable-permissions']];
if ($driver !== 'sqlite') {
    $record['checks'][] = 'dedicated-database-created-and-removed';
}
file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
if (in_array('--archive', $argv, true)) {
    $archiveEnvironment = getenv();
    $archived = (new Process([PHP_BINARY, $root . '/tests/package-archive.php', $package, $created['manifest-sha256']], $root, $archiveEnvironment))->wait(90);
    // PHP致命错误可能写入stdout；不能只转发stderr而丢失内存耗尽等真实原因。
    $archiveStatus = json_encode(['exit-code' => $archived->exitCode, 'timed-out' => $archived->timedOut,
        'output-exceeded' => $archived->outputExceeded, 'signal' => $archived->signal], JSON_THROW_ON_ERROR);
    expect($archived->successful(), '发布归档后验收失败 ' . $archiveStatus . "：\n" . $archived->stdout . $archived->stderr);
    echo $archived->stdout;
}
echo '原生发布、搬迁、' . $driver . '业务与篡改拒绝通过：' . $base . "/verification.json\n";
