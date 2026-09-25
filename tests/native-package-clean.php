<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\NativePackage;
use Type\Testing\HttpClient;
use Type\Testing\Process;

require __DIR__ . '/native-package-image.php';

$root = dirname(__DIR__);
$package = realpath($argv[1] ?? '') ?: throw new RuntimeException('需要Linux原生发布目录');
$digest = $argv[2] ?? '';
$driver = $argv[3] ?? 'sqlite';
$recover = in_array('--recover', $argv, true);
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '干净部署只接受已支持的三种数据库驱动');
$manifest = (new NativePackage())->verify($package, $digest);
expect($manifest['runtime']['os'] === 'Linux', '此空白镜像验收仅适用于Linux，不替代其他原生平台');
expect(trim(cleanPackageCommand(['docker', 'info', '--format', '{{.OSType}}'])) === 'linux', '需要已存在的Linux测试引擎，不自动安装或拉取');
$identity = bin2hex(random_bytes(6));
$base = $root . '/build/clean-package-' . $identity;
$data = $base . '/data';
expect(mkdir($data, 0700, true), '无法创建本轮干净部署目录');
$uid = function_exists('posix_geteuid') && posix_geteuid() > 0 ? posix_geteuid() : 10001;
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    expect(chown($data, $uid), '无法为独立非root测试账号准备数据目录');
}
$tag = 'type-native-clean-test:' . $identity;
$container = 'type-native-clean-http-' . $identity;
$backend = 'type-native-clean-db-' . $identity;
$network = 'type-native-clean-net-' . $identity;
$ingress = 'type-native-clean-ingress-' . $identity;
$resourceLabel = 'type-app-validation=clean-' . $identity;
$secretEnvironment = getenv();
$secretEnvironment['DB_PASSWORD'] = 'clean-db-' . bin2hex(random_bytes(20));
$secretEnvironment['MYSQL_PASSWORD'] = $secretEnvironment['DB_PASSWORD'];
$secretEnvironment['MYSQL_ROOT_PASSWORD'] = 'clean-root-' . bin2hex(random_bytes(20));
$secretEnvironment['MYSQL_PWD'] = $secretEnvironment['DB_PASSWORD'];
$secretEnvironment['POSTGRES_PASSWORD'] = $secretEnvironment['DB_PASSWORD'];
$secretEnvironment['PGPASSWORD'] = $secretEnvironment['DB_PASSWORD'];
$secretEnvironment['APP_API_TOKEN'] = 'clean-runtime-test-' . bin2hex(random_bytes(20));
$secretEnvironment['APP_ADMIN_PASSWORD'] = bin2hex(random_bytes(16));
$secretEnvironment['APP_CUSTOMER_PASSWORD'] = bin2hex(random_bytes(16));
$backendImage = null;
$backendQuery = null;
$networkCreated = false;
$ingressCreated = false;
$backendCreated = false;
$imageCreated = false;
$containerCreated = false;
try {
    $imageResult = cleanPackageImage($package, $digest, $base, $uid, $identity);
    $imageCreated = true;
    $image = $imageResult['image'];
    $entries = $imageResult['entries'];
    $databaseArguments = ['--env', 'DB_DRIVER=' . $driver, '--env', 'DB_SQLITE_FILE=var/app.sqlite'];
    if ($driver !== 'sqlite') {
        $sourceImage = $driver === 'mysql' ? (getenv('TYPE_TEST_MYSQL_IMAGE') ?: 'mysql:8.4.11') : (getenv('TYPE_TEST_PGSQL_IMAGE') ?: 'postgres:17-bookworm');
        $backendImage = trim(cleanPackageCommand(['docker', 'image', 'inspect', '--format', '{{.Id}}', $sourceImage]));
        expect(preg_match('/^sha256:[a-f0-9]{64}$/D', $backendImage) === 1, '数据库测试必须使用已经存在的固定镜像');
        cleanPackageCommand(['docker', 'network', 'create', '--internal', '--label', $resourceLabel, $network]);
        $networkCreated = true;
        cleanPackageCommand(['docker', 'network', 'create', '--label', $resourceLabel, $ingress]);
        $ingressCreated = true;
        $database = 'type_clean_' . $identity;
        $options = $driver === 'mysql'
            ? ['--env', 'MYSQL_DATABASE=' . $database, '--env', 'MYSQL_USER=type_app', '--env', 'MYSQL_PASSWORD', '--env', 'MYSQL_ROOT_PASSWORD', '--tmpfs', '/var/lib/mysql:rw,size=768m']
            : ['--env', 'POSTGRES_DB=' . $database, '--env', 'POSTGRES_USER=type_app', '--env', 'POSTGRES_PASSWORD', '--tmpfs', '/var/lib/postgresql/data:rw,size=256m'];
        cleanPackageCommand(['docker', 'run', '--detach', '--pull=never', '--name', $backend, '--label', $resourceLabel,
            '--network', $network, '--network-alias', 'database', ...$options, $backendImage], 30, $secretEnvironment);
        $backendCreated = true;
        $backendQuery = $driver === 'mysql'
            ? ['docker', 'exec', '--env', 'MYSQL_PWD', $backend, 'mysql', '--host=127.0.0.1', '--user=type_app', '--database=' . $database, '--batch', '--skip-column-names', '--execute']
            : ['docker', 'exec', '--env', 'PGPASSWORD', $backend, 'psql', '--host=127.0.0.1', '--username=type_app', '--dbname=' . $database, '-Atc'];
        $ready = false;
        $deadline = microtime(true) + 60;
        do {
            try {
                $ready = trim(cleanPackageCommand([...$backendQuery, 'SELECT 1'], 5, $secretEnvironment)) === '1';
            } catch (RuntimeException) {
            }
            if (!$ready) {
                usleep(100000);
            }
        } while (!$ready && microtime(true) < $deadline);
        expect($ready, '本轮独立数据库没有就绪');
        // 连接本轮网络的明确别名，末尾点阻止解析器追加宿主机搜索域。
        array_push(
            $databaseArguments,
            '--env',
            'DB_HOST=database.',
            '--env',
            'DB_PORT=' . ($driver === 'mysql' ? '3306' : '5432'),
            '--env',
            'DB_DATABASE=' . $database,
            '--env',
            'DB_USERNAME=type_app',
            '--env',
            'DB_PASSWORD'
        );
    }
    // 主动覆盖带搜索域的运行环境，避免只在无搜索域的开发机上通过。
    $runtime = ['docker', 'run', '--rm', '--pull=never', '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges',
        '--dns-search', 'typeapp-validation.invalid',
        '--tmpfs', '/tmp:rw,nosuid,nodev,size=16m', '--mount', 'type=bind,source=' . $data . ',target=/data',
        '--env', 'APP_BASE_PATH=/data', '--env', 'APP_ENV=production', '--env', 'APP_DEBUG=false', '--env', 'APP_CACHE_ENABLED=false',
        '--env', 'APP_ADMIN_PASSWORD', '--env', 'APP_CUSTOMER_PASSWORD', '--env', 'TYPE_APP_TRACE=1',
        ...$databaseArguments, '--env', 'TYPE_APP_RELEASE_SHA256=' . $digest];
    $help = cleanPackageCommand([...$runtime, '--network=none', $image, 'help'], 30, $secretEnvironment);
    expect(str_contains($help, 'TypeApp 物联中心') || str_contains($help, 'Type 业务应用'), '空白环境没有运行应用帮助');
    cleanPackageCommand([...$runtime, '--network=none', $image, 'verify-runtime'], 90, $secretEnvironment);
    $databaseNetwork = $driver === 'sqlite' ? 'none' : $network;
    if ($driver !== 'sqlite') {
        $wrongCredentials = $secretEnvironment;
        $wrongCredentials['DB_PASSWORD'] = 'deliberately-invalid-test-password';
        $invalid = new Process([...$runtime, '--network', $databaseNetwork, $image, 'app:install', 'clean-admin', '干净管理员', 'clean-customer', '干净客户', '干净租户'], null, $wrongCredentials);
        try {
            $result = $invalid->wait(20);
            expect(!$result->successful() && !$result->timedOut && !str_contains($result->stdout . $result->stderr, $wrongCredentials['DB_PASSWORD']), '数据库认证失败未拒绝或泄漏了密码');
            if ($driver === 'mysql') {
                expect(str_contains($result->stdout . $result->stderr, '"driver_code":1045'), 'MySQL错误口令必须到达认证阶段，不能由DNS或连接失败替代');
            }
        } finally {
            $invalid->stop();
        }
    }
    $installed = cleanPackageCommand([...$runtime, '--network', $databaseNetwork, $image, 'app:install', 'clean-admin', '干净管理员', 'clean-customer', '干净客户', '干净租户'], 60, $secretEnvironment);
    expect(is_array(json_decode($installed, true, 512, JSON_THROW_ON_ERROR)), '干净环境安装没有返回有效状态：' . bin2hex($installed));
    foreach (['status', 'history'] as $operation) {
        $migrated = cleanPackageCommand([...$runtime, '--network', $databaseNetwork, $image, 'migrate', $operation], 30, $secretEnvironment);
        expect(is_array(json_decode($migrated, true, 512, JSON_THROW_ON_ERROR)), '干净环境迁移没有返回有效状态');
    }
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法选择本轮HTTP映射端口');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $port = substr(strrchr($address, ':'), 1);
    $token = $secretEnvironment['APP_API_TOKEN'];
    $server = array_values(array_filter($runtime, static fn (string $value): bool => $value !== '--rm'));
    $server[1] = 'create';
    cleanPackageCommand([...$server, '--name', $container, '--label', $resourceLabel, ...($driver === 'sqlite' ? [] : ['--network', $ingress]), '--publish', '127.0.0.1:' . $port . ':9501',
        '--env', 'APP_LISTEN=0.0.0.0', '--env', 'APP_PORT=9501', '--env', 'APP_ALLOWED_HOSTS=' . $address,
        '--env', 'APP_API_TOKEN', $image, 'serve'], 30, $secretEnvironment);
    $containerCreated = true;
    if ($driver !== 'sqlite') {
        cleanPackageCommand(['docker', 'network', 'connect', $network, $container]);
    }
    cleanPackageCommand(['docker', 'start', $container]);
    $client = new HttpClient('http://' . $address, 1);
    $ready = false;
    $deadline = microtime(true) + 25;
    do {
        try {
            $ready = $client->request('GET', '/readyz')->status === 200;
        } catch (RuntimeException) {
        }
        if (!$ready) {
            usleep(100000);
        }
    } while (!$ready && microtime(true) < $deadline);
    if (!$ready) {
        $failedState = json_decode(cleanPackageCommand(['docker', 'inspect', $container]), true, 512, JSON_THROW_ON_ERROR)[0];
        $logs = new Process(['docker', 'logs', '--tail', '40', $container]);
        try {
            $logResult = $logs->wait(5);
            $redacted = str_replace([$token, $secretEnvironment['DB_PASSWORD'], $secretEnvironment['MYSQL_ROOT_PASSWORD']], '<REDACTED>', $logResult->stdout . $logResult->stderr);
        } finally {
            $logs->stop();
        }
        file_put_contents($base . '/failure.json', json_encode(['status' => 'failed', 'driver' => $driver,
            'state' => $failedState['State'], 'ports' => $failedState['NetworkSettings']['Ports'],
            'port-bindings' => $failedState['HostConfig']['PortBindings'], 'logs' => $redacted], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        throw new RuntimeException('空白运行镜像HTTP未就绪，已脱敏现场：' . $base . '/failure.json');
    }
    $state = json_decode(cleanPackageCommand(['docker', 'inspect', $container]), true, 512, JSON_THROW_ON_ERROR)[0];
    expect($state['Image'] === $image && $state['HostConfig']['ReadonlyRootfs'] && !$state['HostConfig']['Privileged']
        && $state['HostConfig']['PidMode'] === '' && $state['HostConfig']['CapDrop'] === ['ALL'], '实际容器隔离条件不符');
    expect(count($state['Mounts']) === 1 && $state['Mounts'][0]['Type'] === 'bind' && $state['Mounts'][0]['Destination'] === '/data'
        && $state['Mounts'][0]['Source'] === realpath($data), '运行时出现非本轮数据目录的挂载');
    if ($driver !== 'sqlite') {
        $networkState = json_decode(cleanPackageCommand(['docker', 'network', 'inspect', $network]), true, 512, JSON_THROW_ON_ERROR)[0];
        expect($networkState['Internal'] === true && count($networkState['Containers']) === 2, '数据库网络不是仅含本轮两个实例的私有网络');
        $ingressState = json_decode(cleanPackageCommand(['docker', 'network', 'inspect', $ingress]), true, 512, JSON_THROW_ON_ERROR)[0];
        expect($ingressState['Internal'] === false && count($ingressState['Containers']) === 1, 'HTTP入口网络混入了数据库');
        $backendState = json_decode(cleanPackageCommand(['docker', 'inspect', $backend]), true, 512, JSON_THROW_ON_ERROR)[0];
        expect(count($backendState['NetworkSettings']['Networks']) === 1 && empty($backendState['HostConfig']['PortBindings']), '数据库暴露了宿主端口或加入了入口网络');
    }
    $adminPassword = $secretEnvironment['APP_ADMIN_PASSWORD'];
    expect($client->request('GET', '/admin/users')->status === 401, '干净部署丢失授权');
    $login = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
        'login' => 'clean-admin', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($login->status === 200, '干净部署管理员登录失败');
    $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
    $created = $client->request('POST', '/admin/users', $headers, json_encode([
        'login' => 'clean-user', 'name' => '干净环境', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($created->status === 200, '干净部署业务写入失败');
    $id = $created->json()['data']['id'];
    $listed = $client->request('GET', '/admin/users?search=clean-user', $headers);
    expect($listed->status === 200 && $listed->json()['data']['total'] === 1, '干净部署查询失败');
    $invalid = $client->request('PATCH', '/admin/users/' . $id, $headers, json_encode([
        'version' => 1, 'login' => 'clean-user', 'name' => '资料', 'password' => $adminPassword,
    ], JSON_THROW_ON_ERROR));
    expect($invalid->status === 422, '干净部署丢失白名单校验');
    $patched = $client->request('PATCH', '/admin/users/' . $id, $headers, json_encode([
        'version' => 1, 'login' => 'clean-user', 'name' => '干净环境已改',
    ], JSON_THROW_ON_ERROR));
    expect($patched->status === 200 && $patched->json()['data']['name'] === '干净环境已改', 'PATCH资料或事务回读错误');
    expect($client->request('PATCH', '/admin/users/' . $id, $headers, json_encode([
        'version' => 1, 'login' => 'clean-user', 'name' => '过期更新',
    ], JSON_THROW_ON_ERROR))->status === 409, '干净部署没有拒绝旧版本更新');
    expect($client->request('POST', '/admin/users', $headers, json_encode([
        'login' => 'x', 'name' => '', 'password' => 'short',
    ], JSON_THROW_ON_ERROR))->status === 422, '干净部署没有拒绝非法输入');
    $disabled = $client->request('POST', '/admin/users/' . $id . '/status', $headers, json_encode([
        'version' => $patched->json()['data']['version'], 'enabled' => false,
    ], JSON_THROW_ON_ERROR));
    expect($disabled->status === 200 && empty($disabled->json()['data']['enabled']), '干净部署停用失败');
    expect($client->request('GET', '/admin/users?search=clean-user&enabled=0', $headers)->json()['data']['total'] === 1, '干净部署停用后查询失败');
    if ($backendQuery !== null) {
        expect(trim(cleanPackageCommand([...$backendQuery, 'SELECT COUNT(*) FROM admin_users'], 10, $secretEnvironment)) === '2', 'HTTP业务未写入所声明的真实数据库');
    } else {
        $databaseFile = new PDO('sqlite:' . $data . '/var/app.sqlite');
        expect((int) $databaseFile->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 2, 'SQLite数据没有持久化到本轮目录');
        unset($databaseFile);
    }
    cleanPackageCommand(['docker', 'stop', '--timeout', '10', $container]);
    $exit = json_decode(cleanPackageCommand(['docker', 'inspect', $container]), true, 512, JSON_THROW_ON_ERROR)[0]['State'];
    expect(!$exit['Running'] && $exit['ExitCode'] === 0, '干净部署没有正常停止');
    $evidence = ['platform' => 'Linux', 'driver' => $driver, 'backend-image' => $backendImage, 'artifact-sha256' => $manifest['artifact']['sha256'], 'build-id' => $manifest['artifact']['build-id'],
        'release-sha256' => $digest, 'image' => $image, 'files-inspected' => $entries, 'uid' => $uid,
        'checks' => ['scratch-rootfs', 'payload-only', 'no-php-source-cli-composer-sdk-compiler', 'artifact-bytes', 'readonly-root', 'private-pid-namespace',
            'nonroot', 'capabilities-dropped', 'data-only-mount', 'offline-help-audit', 'explicit-repeatable-migration', 'http-auth-crud-query-validation',
            'admin-field-whitelist', 'stale-version-conflict', 'invalid-input-rejected', 'user-disable', 'actual-backend-rows', 'sigterm-exit-zero']];
    if ($driver !== 'sqlite') {
        $evidence['checks'][] = 'private-database-network';
        $evidence['checks'][] = 'absolute-container-dns-with-search-domain';
        $evidence['checks'][] = 'separate-http-ingress';
        $evidence['checks'][] = 'bad-credentials-rejected-without-disclosure';
    }
    if ($recover) {
        // 旧应用已经正常停止；恢复演练保留原数据库，只建立新的恢复目标。
        cleanPackageCommand(['docker', 'rm', $container]);
        $containerCreated = false;
        require __DIR__ . '/backup-recovery.php';
        $evidence['recovery'] = cleanPackageRecovery(['base' => $base, 'data' => $data, 'driver' => $driver, 'image' => $image,
            'backend' => $backend, 'backend-image' => $backendImage, 'backend-query' => $backendQuery,
            'backend-options' => $options ?? [], 'database' => $database ?? null, 'network' => $network, 'ingress' => $ingress,
            'runtime' => $runtime, 'environment' => $secretEnvironment, 'uid' => $uid, 'label' => $resourceLabel,
            'release-sha256' => $digest, 'build-id' => $manifest['artifact']['build-id']]);
        $evidence['checks'][] = 'native-database-backup-and-isolated-restore';
    }
} catch (Throwable $failure) {
    // 在回收前保留数据库存活与网络证据；不保存含环境变量的完整 inspect。
    $diagnostic = ['driver' => $driver, 'release-sha256' => $digest];
    if (is_file($base . '/failure.json')) {
        $diagnostic['application'] = json_decode(file_get_contents($base . '/failure.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    if ($backendCreated) {
        foreach ([
            'backend-state' => ['docker', 'inspect', '--format', '{{json .State}}', $backend],
            'backend-network' => ['docker', 'inspect', '--format', '{{json .NetworkSettings.Networks}}', $backend],
            'backend-resolver' => ['docker', 'exec', $backend, 'cat', '/etc/resolv.conf'],
            'backend-query' => [...$backendQuery, 'SELECT 1'],
            'backend-query-by-name' => [...array_map(static fn (string $argument): string => $argument === '--host=127.0.0.1' ? '--host=database' : $argument, $backendQuery), 'SELECT 1'],
            'backend-log' => ['docker', 'logs', '--tail', '40', $backend],
        ] as $label => $command) {
            $inspection = new Process($command, null, $secretEnvironment);
            try {
                $observed = $inspection->wait(5);
                $diagnostic[$label] = ['exit' => $observed->exitCode, 'timeout' => $observed->timedOut,
                    'output' => $observed->stdout . $observed->stderr];
            } catch (Throwable $inspectionFailure) {
                $diagnostic[$label] = ['error' => $inspectionFailure->getMessage()];
            } finally {
                $inspection->stop();
            }
        }
    }
    $encoded = json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    foreach ($secretEnvironment as $key => $value) {
        if ($value !== '' && preg_match('/password|secret|token|pwd/i', $key)) {
            $encoded = str_replace($value, '<REDACTED>', $encoded);
        }
    }
    file_put_contents($base . '/failure.json', $encoded . "\n");
    fwrite(STDERR, "干净部署失败现场：\n" . $encoded . "\n");
    throw $failure;
} finally {
    if ($containerCreated) {
        cleanPackageCommand(['docker', 'rm', '--force', $container]);
    }
    if ($backendCreated) {
        cleanPackageCommand(['docker', 'rm', '--force', $backend]);
    }
    if ($networkCreated) {
        cleanPackageCommand(['docker', 'network', 'rm', $network]);
    }
    if ($ingressCreated) {
        cleanPackageCommand(['docker', 'network', 'rm', $ingress]);
    }
    if ($imageCreated) {
        cleanPackageCommand(['docker', 'image', 'rm', $tag]);
    }
}
file_put_contents($base . '/verification.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Linux无源码/CLI/Composer/编译器的完整运行镜像通过，测试容器与镜像已清理：' . $base . "/verification.json\n";
