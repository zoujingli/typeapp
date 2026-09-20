<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-package-image.php';
require __DIR__ . '/native-package-sandbox.php';
require __DIR__ . '/native-rollout-redis.php';

use Type\Build\NativePackage;
use Type\Testing\Process;

$root = dirname(__DIR__);
$preparationFile = $argv[1] ?? '';
expect($preparationFile !== '' && is_file($preparationFile), '用法：php tests/packaged-rollout.php <preparation.json> [sqlite|mysql|pgsql] [--native-services]');
$prepared = json_decode(file_get_contents($preparationFile), true, 512, JSON_THROW_ON_ERROR);
$platform = $prepared['platform'] ?? '';
$parameters = array_slice($argv, 2);
$nativeServices = end($parameters) === '--native-services';
if ($nativeServices) {
    array_pop($parameters);
}
expect(count($parameters) <= 1, '发布演练服务模式参数无效');
$driver = $parameters[0] ?? 'sqlite';
expect(in_array($platform, ['Linux', 'Darwin'], true) && in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '需要Linux/macOS双版本产物及支持的驱动');
expect($platform !== 'Darwin' || PHP_OS_FAMILY === 'Darwin', 'macOS产物必须在macOS原生执行');
expect(!$nativeServices || $platform === PHP_OS_FAMILY, '原生服务模式需要本平台发布包');
$identity = bin2hex(random_bytes(6));
$label = 'type-app.packaged-rollout=' . $identity;
$base = $root . '/build/packaged-rollout-' . $driver . '-' . $identity;
expect(mkdir($base, 0700), '无法创建本轮发布演练目录');
$uid = posix_geteuid();
expect($uid > 0, '控制器须为能写入本轮数据目录的非root用户');
$images = [];
$packages = [];
$network = 'type-packaged-rollout-' . $identity;
$networkCreated = false;
$nativeRedis = null;
$environment = getenv();
if ($nativeServices && $driver !== 'sqlite') {
    $prefix = 'TYPE_' . strtoupper($driver) . '_';
    foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD'] as $key) {
        expect(isset($environment[$prefix . $key]) && $environment[$prefix . $key] !== '', '原生服务模式需要显式专用测试数据库参数：' . $prefix . $key);
    }
    expect(ctype_digit($environment[$prefix . 'PORT']) && (int) $environment[$prefix . 'PORT'] > 0
        && (int) $environment[$prefix . 'PORT'] <= 65535, '测试数据库端口无效');
}
if (!$nativeServices) {
    $environment['TYPE_MYSQL_PASSWORD'] = 'rollout-mysql-' . bin2hex(random_bytes(20));
    $environment['MYSQL_ROOT_PASSWORD'] = $environment['TYPE_MYSQL_PASSWORD'];
    $environment['MYSQL_PWD'] = $environment['TYPE_MYSQL_PASSWORD'];
    $environment['TYPE_PGSQL_PASSWORD'] = 'rollout-pgsql-' . bin2hex(random_bytes(20));
    $environment['POSTGRES_PASSWORD'] = $environment['TYPE_PGSQL_PASSWORD'];
    $environment['PGPASSWORD'] = $environment['TYPE_PGSQL_PASSWORD'];
    $ports = [];
    $listeners = [];
    foreach (['queue', 'cache', 'database'] as $name) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        expect(is_resource($socket), '无法保留本轮维护端口');
        $ports[$name] = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        $listeners[] = $socket;
    }
    foreach ($listeners as $socket) {
        fclose($socket);
    }
}
try {
    if ($platform === 'Darwin' || $nativeServices) {
        expect(mkdir($base . '/data', 0700), '无法创建本轮原生进程数据根');
        $environment['TYPE_ROLLOUT_DATA_PARENT'] = $base . '/data';
    }
    foreach (['old' => '1.0.0', 'new' => '1.1.0'] as $variant => $version) {
        $record = $prepared['variants'][$variant] ?? [];
        $relative = $record['release'] ?? '';
        expect(is_string($relative) && preg_match('~^build/packaged-rollout-build-[a-f0-9]{12}/(?:old|new)/release$~D', $relative) === 1, '发布目录不属于本轮已准备产物');
        $package = $root . '/' . $relative;
        $release = (new NativePackage())->verify($package, $record['release-sha256']);
        expect($release['version'] === $version && $release['runtime']['os'] === $platform
            && $release['artifact']['sha256'] === $record['artifact-sha256'], '真实二进制版本、平台或摘要不符');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS)) as $file) {
            expect(!preg_match('/\.(?:php[0-9]?|phtml|phar|inc|h|hpp|c|cc|cpp)$/iD', $file->getFilename()), '发布包含源码或编译头文件');
        }
        $packages[$variant] = $package;
        if ($platform === 'Linux' && !$nativeServices) {
            $imageRoot = $base . '/' . $variant;
            mkdir($imageRoot, 0700);
            $images[$variant] = cleanPackageImage($package, $record['release-sha256'], $imageRoot, $uid, bin2hex(random_bytes(6)));
        }
    }
    if ($nativeServices) {
        $nativeRedis = new NativeRolloutRedis($base . '/native-services', getenv('TYPE_REDIS_SERVER') ?: '');
        $environment = array_replace($environment, $nativeRedis->environment());
    } else {
        cleanPackageCommand(['docker', 'network', 'create', '--label', $label, $network]);
        $networkCreated = true;
        $redis = trim(cleanPackageCommand(['docker', 'image', 'inspect', 'redis:8.10.1', '--format', '{{.Id}}']));
        expect(preg_match('/^sha256:[a-f0-9]{64}$/D', $redis) === 1, '需要已有固定Redis镜像');
        foreach (['queue', 'cache'] as $kind) {
            $name = 'type-rollout-' . $kind . '-' . $identity;
            $arguments = $kind === 'queue'
                ? ['--appendonly', 'yes', '--appendfsync', 'always', '--save', '', '--maxmemory', '16mb', '--maxmemory-policy', 'noeviction']
                : ['--appendonly', 'no', '--save', '', '--maxmemory', '4mb', '--maxmemory-policy', 'allkeys-lru'];
            cleanPackageCommand(['docker', 'run', '--detach', '--pull=never', '--name', $name, '--label', $label, '--network', $network,
                '--network-alias', $kind, '--publish', '127.0.0.1:' . $ports[$kind] . ':6379', '--tmpfs', '/data:rw,size=32m', $redis, 'redis-server', ...$arguments]);
            $ready = false;
            $deadline = microtime(true) + 15;
            do {
                try {
                    $ready = trim(cleanPackageCommand(['docker', 'exec', $name, 'redis-cli', 'ping'], 5)) === 'PONG';
                } catch (RuntimeException) {
                }
                if (!$ready) {
                    usleep(100000);
                }
            } while (!$ready && microtime(true) < $deadline);
            expect($ready, '本轮Redis没有就绪');
        }
        $environment['TYPE_REDIS_HOST'] = '127.0.0.1';
        $environment['TYPE_REDIS_PORT'] = $ports['queue'];
        $environment['TYPE_ROLLOUT_CACHE_HOST'] = '127.0.0.1';
        $environment['TYPE_ROLLOUT_CACHE_PORT'] = $ports['cache'];
        if ($driver !== 'sqlite') {
            $database = 'type-rollout-database-' . $identity;
            $imageName = $driver === 'mysql' ? (getenv('TYPE_TEST_MYSQL_IMAGE') ?: 'mysql:8.4.11') : (getenv('TYPE_TEST_PGSQL_IMAGE') ?: 'postgres:17-bookworm');
            $databaseImage = trim(cleanPackageCommand(['docker', 'image', 'inspect', $imageName, '--format', '{{.Id}}']));
            expect(preg_match('/^sha256:[a-f0-9]{64}$/D', $databaseImage) === 1, '需要已有固定数据库镜像');
            $arguments = $driver === 'mysql'
                ? ['--env', 'MYSQL_ROOT_PASSWORD', '--env', 'MYSQL_DATABASE=type_app_test', '--tmpfs', '/var/lib/mysql:rw,size=768m']
                : ['--env', 'POSTGRES_PASSWORD', '--env', 'POSTGRES_USER=type_app', '--env', 'POSTGRES_DB=type_app_test', '--tmpfs', '/var/lib/postgresql/data:rw,size=256m'];
            cleanPackageCommand(['docker', 'run', '--detach', '--pull=never', '--name', $database, '--label', $label, '--network', $network,
                '--network-alias', 'database', '--publish', '127.0.0.1:' . $ports['database'] . ':' . ($driver === 'mysql' ? '3306' : '5432'), ...$arguments, $databaseImage], 30, $environment);
            $query = $driver === 'mysql'
                ? ['docker', 'exec', '--env', 'MYSQL_PWD', $database, 'mysql', '--host=127.0.0.1', '--user=root', '--database=type_app_test', '--batch', '--skip-column-names', '--execute', 'SELECT 1']
                : ['docker', 'exec', '--env', 'PGPASSWORD', $database, 'psql', '--host=127.0.0.1', '--username=type_app', '--dbname=type_app_test', '-Atc', 'SELECT 1'];
            $ready = false;
            $deadline = microtime(true) + 60;
            do {
                try {
                    $ready = trim(cleanPackageCommand($query, 5, $environment)) === '1';
                } catch (RuntimeException) {
                }
                if (!$ready) {
                    usleep(100000);
                }
            } while (!$ready && microtime(true) < $deadline);
            expect($ready, '本轮数据库没有就绪');
            $prefix = 'TYPE_' . strtoupper($driver) . '_';
            $environment[$prefix . 'HOST'] = '127.0.0.1';
            $environment[$prefix . 'PORT'] = $ports['database'];
            $environment[$prefix . 'DATABASE'] = 'type_app_test';
            $environment[$prefix . 'USER'] = $driver === 'mysql' ? 'root' : 'type_app';
        }
    }
    $commands = [];
    foreach ($packages as $variant => $package) {
        if ($platform === 'Linux' && $nativeServices) {
            $commands[$variant] = ['linux-package' => $package, 'release-sha256' => $prepared['variants'][$variant]['release-sha256']];
            continue;
        }
        if ($platform === 'Darwin') {
            $command = ['/usr/bin/env', 'PATH=/usr/bin:/bin', 'TYPE_HTTP_LISTEN=127.0.0.1',
                'TYPE_APP_RELEASE_SHA256=' . $prepared['variants'][$variant]['release-sha256'],
                ...sandboxPackageCommand($root, $package, [$base . '/data'])];
            $commands[$variant] = ['command' => $command, 'server' => $command];
            continue;
        }
        $image = $images[$variant];
        $command = ['docker', 'run', '--rm', '--pull=never', '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--label', $label,
            '--network', $network, '--tmpfs', '/tmp:rw,nosuid,nodev,size=16m', '--mount', 'type=bind,source={{data-directory}},target=/data',
            '--env', 'TYPE_MODEL_DRIVER', '--env', 'TYPE_ROLLOUT_APP', '--env', 'TYPE_SQLITE_FILE=/data/database.sqlite',
            '--env', 'TYPE_REDIS_HOST=queue', '--env', 'TYPE_REDIS_PORT=6379', '--env', 'TYPE_ROLLOUT_CACHE_HOST=cache', '--env', 'TYPE_ROLLOUT_CACHE_PORT=6379',
            '--env', 'TYPE_MYSQL_HOST=database', '--env', 'TYPE_MYSQL_PORT=3306', '--env', 'TYPE_MYSQL_USER=root', '--env', 'TYPE_MYSQL_DATABASE', '--env', 'TYPE_MYSQL_PASSWORD',
            '--env', 'TYPE_PGSQL_HOST=database', '--env', 'TYPE_PGSQL_PORT=5432', '--env', 'TYPE_PGSQL_USER=type_app', '--env', 'TYPE_PGSQL_DATABASE', '--env', 'TYPE_PGSQL_PASSWORD',
            '--env', 'TYPE_HTTP_LISTEN=0.0.0.0', '--env', 'TYPE_HTTP_PORT',
            '--env', 'TYPE_APP_RELEASE_SHA256=' . $prepared['variants'][$variant]['release-sha256']];
        $commands[$variant] = ['command' => [...$command, $image['image']], 'server' => [...$command, '--publish', '127.0.0.1:{{port}}:{{port}}', $image['image']]];
    }
    $environment['TYPE_ROLLOUT_COMMANDS'] = json_encode($commands, JSON_THROW_ON_ERROR);
    $environment['TYPE_ROLLOUT_REPORT'] = $base . '/scenario.json';
    // 每个角色均启动隔离原生进程；保持切换时旧消息仍延迟及最终消费的原断言。
    $environment['TYPE_ROLLOUT_DELAY_MS'] = '60000';
    $process = new Process([PHP_BINARY, $root . '/tests/rollout.php', $driver, '--native'], $root, $environment, 4194304);
    try {
        $result = $process->wait(300);
        $secrets = array_values(array_filter([$environment['TYPE_MYSQL_PASSWORD'] ?? '', $environment['TYPE_PGSQL_PASSWORD'] ?? ''], static fn (string $secret): bool => $secret !== ''));
        file_put_contents($base . '/scenario.log', str_replace($secrets, '<REDACTED>', $result->stdout . $result->stderr));
        expect($result->successful(), '无源码双版本演练失败，见：' . $base . '/scenario.log');
        echo $result->stdout;
    } finally {
        $process->stop();
    }
    $pointer = json_decode(file_get_contents($base . '/scenario.json'), true, 32, JSON_THROW_ON_ERROR);
    $scenario = json_decode(file_get_contents($pointer['report']), true, 512, JSON_THROW_ON_ERROR);
    expect($scenario['native'] && $scenario['packaged'] && count($scenario['effects']) === 4, '没有真实的双版本演练证据');
    $record = ['platform' => $platform, 'driver' => $driver, 'variants' => $prepared['variants'], 'scenario' => $pointer['report'],
        'services' => $nativeRedis === null ? ['execution' => 'docker'] : $nativeRedis->evidence() + ['database' => $driver === 'sqlite' ? 'local-file' : 'caller-provided-dedicated-connection'],
        'delay-ms' => $scenario['delay-ms'], 'relay-start-to-consumer-switch-ms' => $scenario['relay-start-to-consumer-switch-ms'],
        'images' => array_map(static fn (array $item): array => ['id' => $item['image'], 'files-inspected' => $item['entries']], $images),
        'checks' => ['two-real-versions', $nativeServices ? 'native-source-tool-access-denied' : 'scratch-no-source-cli-sdk',
            'same-rollout-scenario', ...$scenario['checks']]];
} finally {
    if ($nativeServices) {
        $nativeRedis?->close();
    } else {
        $ids = preg_split('/\s+/', trim(cleanPackageCommand(['docker', 'ps', '--all', '--filter', 'label=' . $label, '--format', '{{.ID}}'])));
        foreach ($ids as $id) {
            if ($id !== '') {
                expect(preg_match('/^[a-f0-9]{12,64}$/D', $id) === 1, '测试容器身份无效');
                cleanPackageCommand(['docker', 'rm', '--force', $id]);
            }
        }
        if ($networkCreated) {
            cleanPackageCommand(['docker', 'network', 'rm', $network]);
        }
        foreach ($images as $image) {
            cleanPackageCommand(['docker', 'image', 'rm', $image['tag']]);
        }
    }
}
$record['checks'][] = 'owned-resources-cleaned';
file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
echo '新旧原生发布包无源码升级与兼容回滚通过：' . $base . "/verification.json\n";
