<?php

declare(strict_types=1);

// 宿主验收控制器；不属于应用生产源码，也不会复制进 scratch 镜像。
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Testing\Assert;
use Type\Testing\Process;
use Type\Testing\ProcessResult;

/**
 * 以秒数预算执行模板部署控制命令，输出上限为 4 MiB，退出路径均停止进程。
 *
 * @param list<string> $command
 * @param array<string, string>|null $environment null 表示继承当前环境。
 */
function deploymentProcess(array $command, ?array $environment = null, float $seconds = 15.0): ProcessResult
{
    $process = new Process($command, dirname(__DIR__), $environment, 4194304);
    try {
        return $process->wait($seconds);
    } finally {
        $process->stop();
    }
}

/**
 * 要求模板部署命令在秒数预算内成功，返回标准输出供进一步验证。
 *
 * @param list<string> $command
 * @param array<string, string>|null $environment
 */
function deploymentSuccessful(array $command, ?array $environment = null, float $seconds = 15.0): string
{
    $result = deploymentProcess($command, $environment, $seconds);
    Assert::true($result->successful(), '模板部署子进程失败：' . $result->stdout . $result->stderr);
    return $result->stdout;
}

/**
 * 读取模板部署 JSON 记录，格式或结构无效时直接失败。
 *
 * @return array<string, mixed>
 */
function deploymentJson(string $file): array
{
    $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    Assert::true(is_array($document), '部署验收记录必须是 JSON 对象');
    return $document;
}

/**
 * 完整写入本轮模板部署记录，拒绝短写以免留下貌似成功的截断证据。
 *
 * @param array<string, mixed> $record
 */
function deploymentRecord(string $file, array $record): void
{
    $contents = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    Assert::same(strlen($contents), file_put_contents($file, $contents), '无法保存部署验收记录');
}

/** 只对本测试随机生成的单个数据库执行建库或删库。 */
function deploymentDatabase(string $action, string $driver, string $database): void
{
    Assert::true(in_array($action, ['create', 'drop'], true) && preg_match('/^type_template_[a-f0-9]{16}$/D', $database) === 1, '拒绝操作非本轮模板测试数据库');
    if ($driver === 'mysql') {
        $connection = (new MysqlDriver(
            getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1',
            (int) (getenv('TYPE_MYSQL_PORT') ?: '3306'),
            getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test',
            getenv('TYPE_MYSQL_USER') ?: 'root',
            getenv('TYPE_MYSQL_PASSWORD') ?: ''
        ))->connect();
    } elseif ($driver === 'pgsql') {
        $connection = (new PgsqlDriver(
            getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1',
            (int) (getenv('TYPE_PGSQL_PORT') ?: '5432'),
            getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test',
            getenv('TYPE_PGSQL_USER') ?: 'type_app',
            getenv('TYPE_PGSQL_PASSWORD') ?: ''
        ))->connect();
    } else {
        throw new InvalidArgumentException('独立数据库管理只用于 MySQL 和 PostgreSQL');
    }
    $connection->exec(($action === 'create' ? 'CREATE DATABASE ' : 'DROP DATABASE ') . $database);
}

/** 从镜像文件系统而非开发目录检查缺失项，不向应用容器挂载源码。 */
function deploymentImage(string $image, string $container, string $directory, string $artifact): array
{
    Assert::true(preg_match('/^sha256:[a-f0-9]{64}$/D', $image) === 1, '必须固定已经构建好的镜像摘要');
    $configuration = json_decode(deploymentSuccessful(['docker', 'image', 'inspect', $image]), true, 512, JSON_THROW_ON_ERROR)[0];
    Assert::same(['/app/type-app'], $configuration['Config']['Entrypoint'], '镜像入口不是唯一原生程序');
    Assert::same(1, count($configuration['RootFS']['Layers']), 'scratch 部署镜像只能有一层本次运行文件');
    $imageEnvironment = $configuration['Config']['Env'];
    $defaultPath = 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    // Docker legacy builder 给 FROM scratch 补默认 PATH；只允许这个确定的无凭据值。
    $imageEnvironment = array_values(array_filter($imageEnvironment, static fn (string $value): bool => $value !== $defaultPath));
    sort($imageEnvironment);
    Assert::same(['PHPRC=/app/php.ini', 'PHP_INI_SCAN_DIR=/app/php.d'], $imageEnvironment, '镜像环境带入了非运行配置');
    deploymentSuccessful(['docker', 'create', '--name', $container, '--network=none', $image, 'help']);
    try {
        $manifest = (new Type\Build\ArtifactManifest())->read($artifact);
        $vendorLibraries = [];
        foreach ($manifest['native-libraries'] as $library) {
            if (str_contains($library['path'], '/vendor/')) {
                $vendorLibraries[ltrim($library['path'], '/')] = $library['sha256'];
            }
        }
        deploymentSuccessful(['docker', 'export', '--output', $directory . '/image.tar', $container], seconds: 30);
        $entries = explode("\n", trim(deploymentSuccessful(['tar', '-tf', $directory . '/image.tar'])));
        foreach ($entries as $entry) {
            $path = str_starts_with($entry, './') ? substr($entry, 2) : $entry;
            Assert::true($path !== '' && !str_starts_with($path, '/') && !in_array('..', explode('/', $path), true), '镜像归档路径无效');
            Assert::true(!preg_match('/\.(?:php[0-9]?|phtml|phar|inc)$/i', $path), '镜像包含 PHP 源码或归档：' . $path);
            Assert::true(!preg_match('/\.(?:h|hh|hpp|hxx|c|cc|cpp)$/i', $path), '镜像包含编译 SDK 源码：' . $path);
            Assert::true(str_ends_with($entry, '/') || !preg_match('~(?:^|/)(?:composer(?:\.json|\.lock)?|installed\.json|php(?:[0-9.]+)?|php-cgi|php-fpm|phpdbg)$~', $path), '镜像包含解释器或 Composer 元数据：' . $path);
            if (preg_match('~(?:^|/)vendor(?:/|$)~', $path)) {
                // CI 的 PHPX 运行库可保留其构建绝对路径；只接受 ELF 内声明且摘要匹配的那个文件及其父目录。
                $nativeOnly = isset($vendorLibraries[$path]);
                if (str_ends_with($entry, '/')) {
                    foreach (array_keys($vendorLibraries) as $libraryPath) {
                        if (str_starts_with($libraryPath, $path)) {
                            $nativeOnly = true;
                            break;
                        }
                    }
                } elseif ($nativeOnly) {
                    $copy = $directory . '/native-' . hash('sha256', $path);
                    deploymentSuccessful(['docker', 'cp', '--follow-link', $container . ':/' . $path, $copy]);
                    Assert::true(file_get_contents($copy, false, null, 0, 4) === "\x7fELF" && hash_file('sha256', $copy) === $vendorLibraries[$path], 'vendor 路径中的运行库与原生产物身份不一致');
                }
                Assert::true($nativeOnly, '镜像包含非声明原生运行库的 vendor 内容：' . $path);
            }
            Assert::true(!preg_match('~(?:^|/)(?:\.git|\.ssh|auth\.json|\.env(?:\.[^/]*)?|id_rsa|id_ed25519)(?:/|$)~', $path), '镜像包含开发元数据或认证文件：' . $path);
        }
        deploymentSuccessful(['docker', 'cp', $container . ':/app/type-app', $directory . '/packaged-type-app'], seconds: 30);
        Assert::same(hash_file('sha256', $artifact), hash_file('sha256', $directory . '/packaged-type-app'), '镜像内 ELF 与独立安装编译的产物不同');
        return ['file-entries' => count($entries), 'layers' => 1, 'php-cli' => 'absent', 'vendor-source' => 'absent', 'php-source' => 'absent',
            'native-libraries-under-vendor' => array_keys($vendorLibraries),
            'artifact-sha256' => hash_file('sha256', $artifact)];
    } finally {
        deploymentSuccessful(['docker', 'rm', $container]);
    }
}

/** 观察指定测试容器的镜像、权限与只读根文件系统边界，并保存真实运行证据。 */
function deploymentObserve(string $container, string $image, string $directory): void
{
    $state = json_decode(deploymentSuccessful(['docker', 'inspect', $container]), true, 512, JSON_THROW_ON_ERROR)[0];
    Assert::same($image, $state['Image'], 'HTTP 进程不是已核验的镜像');
    Assert::true($state['State']['Running'] && !$state['HostConfig']['Privileged'] && $state['HostConfig']['ReadonlyRootfs'], 'HTTP 容器运行边界无效');
    Assert::same(['ALL'], $state['HostConfig']['CapDrop'], 'HTTP 容器没有清除 capabilities');
    Assert::true(in_array('no-new-privileges', $state['HostConfig']['SecurityOpt'], true), 'HTTP 容器没有限制新权限');
    Assert::same('', $state['HostConfig']['PidMode'], 'HTTP 容器不应共享宿主 PID 命名空间');
    foreach ($state['Mounts'] as $mount) {
        Assert::true($mount['Type'] === 'volume' && $mount['Destination'] === '/var/lib/type-project', '运行容器出现非数据卷挂载');
    }
    $processes = deploymentSuccessful(['docker', 'top', $container, '-eo', 'pid,args']);
    Assert::true(str_contains($processes, '/app/type-app serve'), '没有观察到原生 HTTP 进程');
    Assert::true(!preg_match('/(?:^|\s)(?:php|php-cgi|php-fpm|composer)(?:\s|$)/m', $processes), '运行容器出现解释器进程');
    deploymentRecord($directory . '/runtime.json', ['status' => 'running', 'image' => $image, 'root-filesystem' => 'readonly',
        'capabilities' => [], 'no-new-privileges' => true, 'source-mounts' => [], 'pid-namespace' => 'private',
        'processes' => trim($processes), 'library-identity' => '启动入口严格验证后 HTTP 已就绪']);
}

/** 核验已构建模板与镜像身份，再执行无源码业务闭环；按本轮标签回收容器、数据库和卷，失败保全报告。 */
function deploymentRun(string $preparedFile, string $image, string $directory): void
{
    $directory = realpath($directory) ?: throw new InvalidArgumentException('模板部署记录目录不存在');
    deploymentRecord($directory . '/report.json', ['protocol' => 1, 'status' => 'running']);
    $root = dirname(__DIR__);
    $prepared = deploymentJson($preparedFile);
    Assert::same('built-not-verified', $prepared['status'] ?? null, '需要本轮独立安装且已完成 AOT 的模板记录');
    $relative = $prepared['consumer-relative'] ?? '';
    Assert::true(is_string($relative) && preg_match('~^build/template-(mysql|pgsql|sqlite)-[a-f0-9]{12}$~D', $relative) === 1, '模板构建记录路径无效');
    $consumer = $root . '/' . $relative;
    $artifact = $consumer . '/build/type-project';
    deploymentRecord($consumer . '/verification.json', ['protocol' => 1, 'status' => 'running']);
    Assert::true(is_executable($artifact) && file_get_contents($artifact, false, null, 0, 4) === "\x7fELF", '缺少独立模板原生产物');
    $build = deploymentJson($artifact . '.build.json');
    $driver = $prepared['driver'];
    Assert::true(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '模板驱动记录无效');
    Assert::same(hash_file('sha256', $artifact), $build['sha256'], '模板构建记录与 ELF 不一致');
    $project = deploymentJson($consumer . '/build/compiler/project.yml');
    $sources = $project['sources'];
    $originalSources = $build['identity']['description']['inputs']['original-sources'] ?? [];
    Assert::true(is_array($originalSources), '模型编译原始源码记录无效');
    Assert::same(count($sources), count(array_unique($sources)), '实际 TypePHP 源文件清单存在重复路径');
    $entry = $prepared['consumer'] . '/app/main.php';
    Assert::same(1, count(array_filter($sources, static fn (string $path): bool => $path === $entry)), '应用入口必须且只能编译一次');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer . '/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sourcePath = Type\Build\BuildPlatform::path($file->getPathname());
            $transformed = isset($originalSources[$sourcePath])
                && is_array($originalSources[$sourcePath])
                && ($originalSources[$sourcePath]['sha256'] ?? null) === hash_file('sha256', $sourcePath)
                && in_array($consumer . '/build/compiler/generated-models.php', $sources, true);
            Assert::true(in_array($sourcePath, $sources, true) || $transformed, 'TypePHP 源文件清单遗漏模板业务类：' . basename($sourcePath));
        }
    }
    $packages = array_keys($build['production-packages']);
    sort($packages);
    $installed = $prepared['production-packages'];
    sort($installed);
    Assert::same($installed, $packages, '模板产物没有覆盖全部安装的生产依赖');
    foreach (['mysql', 'pgsql', 'sqlite'] as $name) {
        Assert::same($name === $driver, in_array('zoujingli/type-orm-' . $name, $packages, true), '模板产物编入了未选择驱动或漏掉已选择驱动');
    }
    $identity = bin2hex(random_bytes(8));
    $container = 'type-template-test-' . $identity;
    $volume = $container . '-data';
    $database = 'type_template_' . $identity;
    $imageEvidence = deploymentImage($image, $container . '-inspect', $directory, $artifact);
    $environment = getenv();
    $environment['TYPE_APP_TEST_DIRECTORY'] = $consumer;
    $environment['DB_SQLITE_FILE'] = '/var/lib/type-project/app.sqlite';
    $prefix = $driver === 'mysql' ? 'TYPE_MYSQL_' : 'TYPE_PGSQL_';
    $environment['DB_HOST'] = getenv($prefix . 'HOST') ?: '127.0.0.1';
    $environment['DB_PORT'] = getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? '3306' : '5432');
    $environment['DB_USERNAME'] = getenv($prefix . 'USER') ?: ($driver === 'mysql' ? 'root' : 'type_app');
    $environment['DB_PASSWORD'] = getenv($prefix . 'PASSWORD') ?: '';
    $environment['DB_DATABASE'] = $database;
    $admin = getenv('TYPE_TEMPLATE_ADMIN_COMMAND');
    $adminCommand = $admin === false ? [PHP_BINARY, __FILE__] : json_decode($admin, true, 512, JSON_THROW_ON_ERROR);
    $network = getenv('TYPE_TEMPLATE_NETWORK') ?: 'host';
    $label = 'type-template-test=' . $identity;
    $base = ['docker', 'run', '--rm', '--pull=never', '--label', $label, '--network', $network, '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges',
        '--tmpfs', '/tmp:rw,nosuid,nodev,size=64m', '--mount', 'type=volume,source=' . $volume . ',target=/var/lib/type-project', '--workdir', '/app'];
    foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SQLITE_FILE', 'APP_NAME', 'APP_PORT', 'APP_ALLOWED_HOSTS',
        'APP_API_TOKEN', 'APP_TRUSTED_PROXIES', 'APP_UPLOAD_TEMP', 'DB_SERVER_BUDGET', 'APP_MAX_REPLICAS', 'APP_ROLLING_SURGE', 'APP_DATABASE_PROCESSES', 'DB_ADMIN_RESERVE'] as $key) {
        $base[] = '--env';
        $base[] = $key;
    }
    $server = [...$base, '--name', $container, '--env', 'APP_LISTEN=0.0.0.0'];
    if ($network !== 'host') {
        array_push($server, '--publish', '127.0.0.1:{{port}}:{{port}}');
    }
    $environment['TYPE_APP_COMMAND'] = json_encode([...$base, $image], JSON_THROW_ON_ERROR);
    $environment['TYPE_APP_SERVER_COMMAND'] = json_encode([...$server, $image], JSON_THROW_ON_ERROR);
    $environment['TYPE_APP_OBSERVE_COMMAND'] = json_encode([PHP_BINARY, __FILE__, 'observe', $container, $image, $directory], JSON_THROW_ON_ERROR);
    $createdDatabase = false;
    $createdVolume = false;
    $report = null;
    $failure = null;
    try {
        deploymentSuccessful(['docker', 'volume', 'create', $volume]);
        $createdVolume = true;
        if ($driver !== 'sqlite') {
            deploymentSuccessful([...$adminCommand, 'database', 'create', $driver, $database], $environment);
            $createdDatabase = true;
        }
        // 测试工具在宿主运行；同一个 smoke 的每个应用子进程都只启动上面核验过的 scratch 镜像。
        $smoke = deploymentProcess([PHP_BINARY, $consumer . '/tests/smoke.php'], $environment, 75);
        file_put_contents($directory . '/smoke.log', $smoke->stdout . $smoke->stderr);
        echo $smoke->stdout;
        Assert::true($smoke->successful(), '无源码模板公共行为失败：' . $smoke->stderr);
        Assert::true(is_file($directory . '/runtime.json'), '未观察到真实运行的 HTTP 容器');
        Assert::same('', trim(deploymentSuccessful(['docker', 'ps', '-a', '--filter', 'name=^/' . $container . '$', '--format', '{{.ID}}'])), '停止后仍遗留 HTTP 容器');
        $report = ['protocol' => 1, 'status' => 'passed', 'driver' => $driver, 'native' => true, 'remote' => $prepared['remote'],
            'consumer' => $relative, 'verified-splits' => $prepared['verified-splits'], 'production-packages' => $packages, 'build-id' => $build['build-id'],
            'source-files' => count($sources), 'entry-occurrences' => 1,
            'image' => $image, 'image-filesystem' => $imageEvidence, 'runtime' => deploymentJson($directory . '/runtime.json'),
            'checks' => ['help-offline', 'check-offline', 'migration-repeat', 'migration-status', 'migration-history', 'authentication', 'crud',
                'pagination', 'patch-null', 'soft-delete', 'stale-version', 'bad-input', 'method-rejection', 'graceful-stop'], 'graceful-stop' => 'zero-exit-and-container-removed'];
    } catch (Throwable $error) {
        $failure = $error;
    }
    // 即使某个清理项失败，也尝试其他项；所有角色共用随机唯一标签，CLI 超时也能准确找回容器。
    $cleanupErrors = [];
    try {
        $identifiers = array_filter(explode("\n", trim(deploymentSuccessful(['docker', 'ps', '-a', '--quiet', '--filter', 'label=' . $label]))));
        foreach ($identifiers as $identifier) {
            Assert::true(preg_match('/^[a-f0-9]{12,64}$/D', $identifier) === 1, '测试容器标识无效');
        }
        if ($identifiers !== []) {
            deploymentSuccessful(['docker', 'rm', '--force', ...$identifiers]);
        }
    } catch (Throwable $cleanupError) {
        $cleanupErrors[] = '回收容器失败：' . $cleanupError->getMessage();
    }
    try {
        if ($createdDatabase) {
            deploymentSuccessful([...$adminCommand, 'database', 'drop', $driver, $database], $environment);
        }
    } catch (Throwable $cleanupError) {
        $cleanupErrors[] = '回收测试数据库失败：' . $cleanupError->getMessage();
    }
    try {
        if ($createdVolume) {
            deploymentSuccessful(['docker', 'volume', 'rm', $volume]);
        }
    } catch (Throwable $cleanupError) {
        $cleanupErrors[] = '回收测试数据卷失败：' . $cleanupError->getMessage();
    }
    if ($failure !== null || $cleanupErrors !== []) {
        $failed = ['protocol' => 1, 'status' => 'failed', 'driver' => $driver, 'phase' => 'public-behavior-or-cleanup'];
        deploymentRecord($directory . '/report.json', $failed);
        deploymentRecord($consumer . '/verification.json', $failed);
        throw new RuntimeException(($failure === null ? '' : $failure->getMessage() . "\n") . implode("\n", $cleanupErrors));
    }
    Assert::true(is_array($report), '模板公开行为未形成通过报告');
    $report['test-resources'] = 'containers-database-volume-removed';
    deploymentRecord($directory . '/report.json', $report);
    deploymentRecord($consumer . '/verification.json', $report);
    echo '模板 ' . $driver . ' 独立 AOT、scratch 无源码部署及公开行为全部通过：' . $directory . "/report.json\n";
}

try {
    $mode = $argv[1] ?? '';
    if ($mode === 'database') {
        deploymentDatabase($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
    } elseif ($mode === 'observe') {
        deploymentObserve($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
    } elseif ($mode === 'image') {
        $evidence = deploymentImage($argv[2] ?? '', 'type-template-test-' . bin2hex(random_bytes(8)) . '-inspect', $argv[4] ?? '', $argv[3] ?? '');
        echo json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } elseif ($mode === 'run') {
        deploymentRun($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
    } else {
        throw new InvalidArgumentException('用法：template-deployment.php run <构建记录> <镜像摘要> <验收目录> | image <镜像> <原生产物> <目录> | observe <容器> <镜像> <目录> | database <create|drop> <驱动> <测试库>');
    }
} catch (Throwable $error) {
    fwrite(STDERR, '模板部署验收失败：' . $error->getMessage() . "\n");
    exit(1);
}
