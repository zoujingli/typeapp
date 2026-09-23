<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use TypeApp\Distribution\Process as GitProcess;

/**
 * 只导出已经核对的本地 Git 对象，不执行 push 或联系远端仓库。
 *
 * @return array{split: string, archive_sha256: string, files: array<string, string>}
 */
function brokerCandidateArchive(string $root, string $split, string $directory): array
{
    expect(preg_match('/^[a-f0-9]{40}$/D', $split) === 1 && !file_exists($directory), '候选快照须有固定 Git 身份及新目录');
    expect(mkdir($directory, 0700), '无法建立候选包目录');
    $tar = $directory . '.tar';
    GitProcess::output(['git', 'archive', '--format=tar', '--output=' . $tar, $split], $root);
    expect((new PharData($tar))->extractTo($directory), '无法解包候选 Git 内容');
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        expect($file->isFile() && !$file->isLink(), '候选只允许普通文件');
        $files[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return ['split' => $split, 'archive_sha256' => hash_file('sha256', $tar), 'files' => $files];
}

/**
 * 在隔离目录安装 mqtt.js 5.15.0，供 完整目标平台验收 接收链标准客户端使用。
 */
function brokerCandidateMqttJs(string $directory): string
{
    expect(mkdir($directory, 0700), '无法创建 MQTT.js 目录');
    file_put_contents($directory . '/package.json', json_encode([
        'name' => 'typeapp-broker-candidate-client',
        'private' => true,
        'dependencies' => ['mqtt' => '5.15.0'],
    ], JSON_THROW_ON_ERROR));
    $install = new \Type\Testing\Process(
        ['npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund'],
        $directory,
        getenv()
    );
    try {
        $result = $install->wait(120);
        $output = $result->stdout . $result->stderr;
        expect(file_put_contents($directory . '/install.log', $output) === strlen($output), '无法保存 MQTT.js 安装日志');
        expect($result->successful(), 'MQTT.js 5.15.0 安装失败：' . $output);
    } finally {
        $install->stop();
    }
    expect(is_file($directory . '/node_modules/mqtt/package.json'), 'MQTT.js 5.15.0 未安装到独立目录');
    $version = json_decode((string) file_get_contents($directory . '/node_modules/mqtt/package.json'), true, 16, JSON_THROW_ON_ERROR);
    expect(($version['version'] ?? '') === '5.15.0', 'MQTT.js 版本不是锁定的 5.15.0');
    return $directory;
}

/**
 * 从固定快照独立安装组件与前端，不借用主仓 vendor。
 *
 * @return array<string, mixed>
 */
function brokerCandidateIndependent(string $root, string $source, string $base, string $artifact): array
{
    expect(PHP_OS_FAMILY === 'Darwin', 'Broker 独立安装目前复用 macOS 内核禁源码装置；Linux/Windows 由 各目标平台 验收');
    $consumer = $base . '/consumer';
    $record = ['kind' => 'independent-broker-candidate', 'source' => $source, 'internal_links' => []];
    $record['snapshot'] = brokerCandidateArchive($root, $source, $consumer);
    $composer = realpath((string) getenv('TYPE_COMPOSER_PHAR'));
    expect(is_string($composer) && is_file($composer), '独立安装需要显式 TYPE_COMPOSER_PHAR');
    $environment = array_replace(getenv(), (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''));
    $environment['PHPRC'] = getenv('PHPRC') ?: '';
    $environment['PHP_INI_SCAN_DIR'] = $base . '/php.d';
    $environment['COMPOSER_HOME'] = $base . '/composer-home';
    $environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
    $environment['TYPE_COMPOSER_PHAR'] = $composer;
    expect(mkdir($base . '/php.d', 0700) && mkdir($base . '/composer-home', 0700), '无法创建独立安装运行目录');
    $record['composer_lock_sha256'] = hash_file('sha256', $consumer . '/composer.lock');
    $record['toolchain_lock_sha256'] = hash_file('sha256', $consumer . '/toolchain.lock.json');
    $record['web_lock_sha256'] = hash_file('sha256', $consumer . '/web/pnpm-lock.yaml');
    echo "Broker 完整快照已导出，正在按原锁独立安装组件。\n";
    nativeDatabaseCommand(
        [PHP_BINARY, $composer, '--working-dir=' . $consumer, 'install', '--no-scripts', '--no-plugins',
            '--no-interaction', '--prefer-dist', '--no-progress'],
        $environment,
        [],
        $base . '/install.log',
        300
    );
    expect(hash_file('sha256', $consumer . '/composer.lock') === $record['composer_lock_sha256'], '安装改变了候选 Composer 锁');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer . '/vendor', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        if (!$entry->isLink()) {
            continue;
        }
        $resolved = $entry->getRealPath();
        expect(is_string($resolved) && str_starts_with($resolved, $consumer . '/') && !str_starts_with((string) readlink($entry->getPathname()), '/'), '安装链接越出独立快照');
        $record['internal_links'][substr($entry->getPathname(), strlen($consumer) + 1)] = substr($resolved, strlen($consumer) + 1);
    }
    $lock = json_decode((string) file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
        if (($package['dist']['type'] ?? '') !== 'path') {
            continue;
        }
        $prefix = $package['dist']['url'] . '/';
        expect(str_starts_with($prefix, 'plugin/') && !str_contains($prefix, '..'), '候选组件不是快照内部路径');
        foreach ($record['snapshot']['files'] as $relative => $digest) {
            if (str_starts_with($relative, $prefix)) {
                $installed = $consumer . '/vendor/' . $package['name'] . '/' . substr($relative, strlen($prefix));
                expect(is_file($installed) && hash_file('sha256', $installed) === $digest, '安装字节偏离固定组件：' . $relative);
            }
        }
    }
    $web = json_decode((string) file_get_contents($consumer . '/web/package.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(preg_match('/^pnpm@(\d+\.\d+\.\d+)$/', (string) ($web['packageManager'] ?? ''), $pnpmLock) === 1, 'Web 未锁定 pnpm 版本');
    $pnpm = ['npx', '--yes', $web['packageManager']];
    $record['node'] = trim(nativeDatabaseCommand(['node', '--version'], $environment, [], $base . '/node.log', 10));
    $record['pnpm'] = trim(nativeDatabaseCommand([...$pnpm, '--version'], $environment, [], $base . '/pnpm.log', 60));
    expect($record['pnpm'] === $pnpmLock[1], 'pnpm 与 Web 锁定工具不符');
    foreach (['install' => ['install', '--frozen-lockfile'], 'typecheck' => ['typecheck'], 'build' => ['build']] as $step => $arguments) {
        nativeDatabaseCommand([...$pnpm, '--dir', $consumer . '/web', ...$arguments], $environment, [], $base . '/web-' . $step . '.log', 300);
        $record['checks'][] = 'web-' . $step;
    }
    expect(hash_file('sha256', $consumer . '/web/pnpm-lock.yaml') === $record['web_lock_sha256'], '前端验证改变了冻结锁');
    $record['web_dist'] = $consumer . '/web/dist';
    expect(is_file($record['web_dist'] . '/index.html'), '独立前端生产构建缺少 index.html');
    $manifest = (new ArtifactManifest())->read($artifact);
    expect($manifest['composer-lock-sha256'] === $record['composer_lock_sha256'], '复用原生产物与独立安装锁不一致，须在本票重新全量 AOT');
    expect(in_array('zoujingli/type-mqtt', array_keys($manifest['production-packages']), true), '完整候选缺少 type-mqtt');
    $record['artifact_sha256'] = hash_file('sha256', $artifact);
    $record['build_id'] = $manifest['build-id'];
    $record['production_packages'] = array_keys($manifest['production-packages']);
    $release = $base . '/release';
    $packaged = (new NativePackage())->create($artifact, $release);
    expect(!is_dir($release . '/vendor') && !is_dir($release . '/app') && is_file($release . '/run'), '独立运行包仍依赖源码或缺少启动器');
    $record['release_manifest_sha256'] = $packaged['manifest-sha256'];
    $record['checks'][] = 'independent-composer-and-native-package';
    return $record;
}

/**
 * @param list<string> $arguments
 * @return array{evidence: string, sha256: string, report: array<string, mixed>}
 */
function brokerCandidateScenario(string $name, array $arguments, array $environment, string $log, int $seconds, string $root): array
{
    echo '正在运行 Broker 候选公开验收：' . $name . "\n";
    $output = nativeDatabaseCommand($arguments, $environment, [], $log, $seconds);
    expect(preg_match('#(?:通过|证据)：([^\r\n]*?/verification\.json)#u', $output, $matches) === 1, $name . ' 没有返回通过证据');
    $report = json_decode((string) file_get_contents($matches[1]), true, 512, JSON_THROW_ON_ERROR);
    expect(($report['status'] ?? '') === 'passed', $name . ' 报告尚未通过：' . $matches[1]);
    return [
        'evidence' => substr($matches[1], strlen($root) + 1),
        'sha256' => hash_file('sha256', $matches[1]),
        'report' => $report,
    ];
}

$root = dirname(__DIR__);
$phpMode = in_array('--php', $argv, true);
$independent = in_array('--independent', $argv, true);
$noSource = in_array('--no-source', $argv, true);
$browserOptions = array_values(array_filter($argv, static fn (string $value): bool => str_starts_with($value, '--browser-dist=')));
$reuseOptions = array_values(array_filter($argv, static fn (string $value): bool => str_starts_with($value, '--reuse-artifact=')));
$positional = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--php' || $argument === '--independent' || $argument === '--no-source'
        || str_starts_with($argument, '--browser-dist=') || str_starts_with($argument, '--reuse-artifact=')) {
        continue;
    }
    $positional[] = $argument;
}
expect(count($browserOptions) <= 1 && count($reuseOptions) <= 1, '浏览器产物和复用产物只能各指定一次');
expect(
    $positional === [] || (count($positional) === 1 && (is_file($positional[0]) || preg_match('/^[a-f0-9]{40}$/D', $positional[0]) === 1)),
    '用法：php tests/broker-candidate.php [--php|<产物>] [--no-source] [--independent] [--browser-dist=<隔离前端>] [--reuse-artifact=<产物>]'
);
$source = GitProcess::output(['git', 'rev-parse', 'HEAD'], $root);
if ($positional !== [] && preg_match('/^[a-f0-9]{40}$/D', $positional[0]) === 1) {
    expect($positional[0] === $source, '独立安装只接受当前完整提交 SHA');
}
$target = $phpMode ? '--php' : ($reuseOptions !== [] ? substr($reuseOptions[0], 16) : ($positional !== [] && is_file($positional[0]) ? $positional[0] : $root . '/build/app/type-app'));
if ($target !== '--php') {
    $resolved = realpath($target);
    expect(is_string($resolved), '候选产物不存在');
    $target = $resolved;
}
expect(!$noSource || $target !== '--php', '无源码验收需要原生产物');
expect(!$independent || $target !== '--php', '独立安装候选需要原生产物');
expect(count($positional) < 2, '多余位置参数');

$base = $root . '/build/broker-candidate-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建 Broker 候选验收目录');
$environment = getenv();
if ($target !== '--php') {
    $environment = array_replace($environment, (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''));
    $built = json_decode((string) file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $environment['TYPE_NATIVE_PHP_INI'] = getenv('TYPE_NATIVE_PHP_INI') ?: $built['runtime-profile']['ini'];
}

$report = [
    'status' => 'running',
    'suite' => 'broker-candidate',
    'native' => $target !== '--php',
    'no_source' => $noSource,
    'independent' => $independent,
    'source' => $source,
    'protocol_ack_is_not_business_success' => true,
    'historical_intermittent_tickets_remain_open' => [109, 127, 128],
    'reused_io_t21' => 163,
    'checks' => [],
];
try {
    $clientRoot = getenv('TYPE_MQTT_CLIENT_ROOT');
    if (!is_string($clientRoot) || $clientRoot === '' || !is_file($clientRoot . '/node_modules/mqtt/package.json')) {
        $clientRoot = brokerCandidateMqttJs($base . '/mqtt-js');
        $report['checks'][] = 'installed-locked-mqttjs-5.15.0';
    }
    $environment['TYPE_MQTT_CLIENT_ROOT'] = $clientRoot;
    putenv('TYPE_MQTT_CLIENT_ROOT=' . $clientRoot);

    $browserDist = $browserOptions !== [] ? substr($browserOptions[0], 15) : '';
    if ($independent) {
        $report['independent_install'] = brokerCandidateIndependent($root, $source, $base, $target);
        $report['checks'][] = 'independent-component-install';
        if ($browserDist === '') {
            $browserDist = $report['independent_install']['web_dist'];
        }
        $report['checks'][] = 'reused-complete-production-aot';
    }

    $php = PHP_BINARY;
    $nativeFlags = $noSource ? ['--no-source'] : [];
    $browserFlag = ($browserDist !== '' && $target !== '--php') ? ['--browser-dist=' . $browserDist] : [];

    $management = brokerCandidateScenario(
        'broker-management',
        [$php, $root . '/tests/iot-identity.php', $target, 'sqlite', '--broker', ...$nativeFlags],
        $environment,
        $base . '/broker-management.log',
        180,
        $root
    );
    expect(($management['report']['iot_tables_absent'] ?? false) === true, '独立 Broker 安装混入了 IoT 人员表');
    $report['broker_management'] = ['evidence' => $management['evidence'], 'sha256' => $management['sha256'],
        'http_checks' => $management['report']['http_checks'] ?? null];
    $report['checks'][] = 'independent-broker-install-and-nodes';

    $certificates = brokerCandidateScenario(
        'broker-certificates',
        [$php, $root . '/tests/broker-certificates.php', $target, 'sqlite', ...$nativeFlags, ...$browserFlag],
        $environment,
        $base . '/broker-certificates.log',
        $browserFlag === [] ? 180 : 300,
        $root
    );
    $report['broker_certificates'] = ['evidence' => $certificates['evidence'], 'sha256' => $certificates['sha256'],
        'http_checks' => $certificates['report']['http_checks'] ?? null,
        'browser' => $certificates['report']['browser']['status'] ?? null];
    $report['checks'][] = 'certificate-identity-public-api';

    $debug = brokerCandidateScenario(
        'broker-debug',
        [$php, $root . '/tests/broker-debug.php', $target, 'pgsql', ...$nativeFlags, ...$browserFlag],
        $environment,
        $base . '/broker-debug.log',
        $browserFlag === [] ? 300 : 420,
        $root
    );
    $report['broker_debug'] = ['evidence' => $debug['evidence'], 'sha256' => $debug['sha256'],
        'http_checks' => $debug['report']['http_checks'] ?? null,
        'browser' => $debug['report']['browser']['status'] ?? null];
    $report['checks'][] = 'debug-public-api';

    $recovery = brokerCandidateScenario(
        'broker-recovery',
        [$php, $root . '/tests/broker-recovery.php', $target, 'pgsql', ...$nativeFlags, ...$browserFlag],
        $environment,
        $base . '/broker-recovery.log',
        $browserFlag === [] ? 420 : 540,
        $root
    );
    $report['broker_recovery'] = ['evidence' => $recovery['evidence'], 'sha256' => $recovery['sha256'],
        'http_checks' => $recovery['report']['http_checks'] ?? null,
        'browser' => $recovery['report']['browser']['status'] ?? null];
    $report['checks'][] = 'recovery-and-config-public-api';

    $receipts = brokerCandidateScenario(
        'iot-receipts',
        [$php, $root . '/tests/iot-device-mqtt.php', $target, '--ingestion', ...$nativeFlags],
        $environment,
        $base . '/iot-receipts.log',
        720,
        $root
    );
    $mqtt = $receipts['report']['device_mqtt'] ?? [];
    $unknown = $mqtt['unknown_commit'] ?? [];
    expect(($mqtt['facts'] ?? 0) === 4, '标准上报没有形成四条持久原始事实');
    expect(($unknown['primary_visible_without_receipt'] ?? false) === true
        && ($unknown['cancelled_sync_rep'] ?? false) === true
        && ($unknown['new_synchronous_write_on_recovery'] ?? false) === true
        && ($unknown['first_received_at_preserved'] ?? false) === true, '协议确认被当成业务成功，或未知提交没有按 完整目标平台验收 原路径解释');
    $report['iot_receipts'] = [
        'evidence' => $receipts['evidence'],
        'sha256' => $receipts['sha256'],
        'http_checks' => $receipts['report']['http_checks'] ?? null,
        'facts' => $mqtt['facts'],
        'client' => $mqtt['client'] ?? null,
        'unknown_commit' => [
            'primary_visible_without_receipt' => true,
            'cancelled_sync_rep' => true,
            'new_synchronous_write_on_recovery' => true,
            'first_received_at_preserved' => true,
        ],
    ];
    $report['checks'][] = 'persistent-business-receipt-chain';

    if ($target !== '--php') {
        $manifest = (new ArtifactManifest())->read($target);
        $report['artifact'] = [
            'path' => substr($target, strlen($root) + 1),
            'sha256' => hash_file('sha256', $target),
            'build_id' => $manifest['build-id'],
            'production_packages' => array_keys($manifest['production-packages']),
        ];
    }
    $report['status'] = 'passed';
} catch (Throwable $failure) {
    $report['failure'] = str_replace($root, '.', $failure->getMessage());
    throw $failure;
} finally {
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    foreach (['install.log', 'broker-management.log', 'broker-certificates.log', 'broker-debug.log', 'broker-recovery.log', 'iot-receipts.log'] as $log) {
        if (is_file($base . '/' . $log)) {
            $report['logs'][$log] = hash_file('sha256', $base . '/' . $log);
        }
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '完整 Broker 管理候选通过：' . $base . "/verification.json\n";
