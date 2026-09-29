<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-package-sandbox.php';
require __DIR__ . '/native-application-deployment.php';
require __DIR__ . '/windows-native-sandbox.php';

use Type\Build\ArtifactManifest;
use Type\Testing\Process;

$root = dirname(__DIR__);
$project = realpath(getenv('TYPE_PACKAGE_PROJECT') ?: $root);
expect(is_string($project) && is_file($project . '/.env.example'), '需要被验收应用的配置示例');
expect($argc === 2 && in_array(PHP_OS_FAMILY, ['Darwin', 'Linux', 'Windows'], true), '用法：PHP tests/native-single-program.php <完整静态应用>');
expect(PHP_OS_FAMILY !== 'Linux' || getenv('TYPE_BWRAP_BINARY') !== false, 'Linux 单程序验收必须提供原生隔离工具');
$artifact = realpath($argv[1]);
expect($artifact !== false && is_file($artifact), '需要完整静态应用产物');
$identity = (new ArtifactManifest())->read($artifact);
expect(($identity['runtime-linkage'] ?? null) === 'static'
    && $identity['native-libraries'] === [] && $identity['extension-modules'] === [] && $identity['resources'] === [], '程序仍声明外置运行库或资源');
$driver = getenv('TYPE_PACKAGE_DRIVER') ?: 'sqlite';
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '需要明确的三库驱动');
$base = $root . '/build/single program-' . bin2hex(random_bytes(6));
// 有意放在名为 bin 的目录，捕获把任意同名目录误认成历史目录包的回归。
$package = $base . '/program only/bin';
$runtime = $base . '/runtime data';
$filename = PHP_OS_FAMILY === 'Windows' ? 'app.exe' : 'app';
expect(mkdir($package, 0700, true) && mkdir($runtime, 0700), '无法创建单程序隔离目录');
$created = json_decode(successful([PHP_BINARY, $project . '/vendor/bin/type', 'package', $artifact, $package . '/' . $filename], $project), true, 32, JSON_THROW_ON_ERROR);
expect(($created['delivery'] ?? '') === 'single-executable' && $created['sha256'] === hash_file('sha256', $artifact), '交付入口没有保留同一可执行文件');
$again = (new Process([PHP_BINARY, $project . '/vendor/bin/type', 'package', $artifact, $package . '/' . $filename], $project))->wait(30);
expect($again->exitCode === 1 && !$again->timedOut && hash_file('sha256', $package . '/' . $filename) === $created['sha256'], '单程序交付覆盖了既有目标');
successful([PHP_BINARY, $project . '/vendor/bin/type', 'verify-package', $package . '/' . $filename, $created['sha256']], $project);
if (PHP_OS_FAMILY !== 'Windows') {
    expect(chmod($package . '/' . $filename, 0555) && chmod($package, 0555), '无法准备只读程序目录');
}
$cleanEnvironment = PHP_OS_FAMILY === 'Windows' ? WindowsNativeSandbox::environment($runtime) : ['PATH' => '/usr/bin:/bin'];
$environment = ['PATH' => '/usr/bin:/bin', 'APP_BASE_PATH' => $runtime, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
    'APP_CACHE_ENABLED' => 'false', 'DB_DRIVER' => $driver, 'DB_SQLITE_FILE' => 'var/app.sqlite',
    'APP_API_TOKEN' => 'single-test-' . bin2hex(random_bytes(20)), 'TYPE_APP_TRACE' => '1'];
$environment = array_replace($environment, $cleanEnvironment);
$passed = false;
$windows = null;
try {
    if (PHP_OS_FAMILY === 'Windows') {
        $windows = new WindowsNativeSandbox($root, $package, $runtime);
        $command = $windows->command();
    } else {
        $command = sandboxPackageCommand($root, $package, [$runtime], null, 'app');
    }
    foreach (['help', 'verify-runtime'] as $operation) {
        $result = (new Process([...$command, $operation], $runtime, $cleanEnvironment))->wait(90);
        expect($result->successful() && $result->stderr === '', '单程序直接启动失败：' . $operation . ' ' . $result->stdout . $result->stderr);
    }
    expect(scandir($package) === ['.', '..', $filename], '普通启动释放了额外文件');
    file_put_contents($runtime . '/php.ini', "extension=missing-extension.so\nauto_prepend_file=missing.php\nswoole.enable_library=Off\n");
    $poisoned = $cleanEnvironment + ['PHPRC' => $runtime . '/php.ini', 'PHP_INI_SCAN_DIR' => $runtime];
    $result = (new Process([...$command, 'verify-runtime'], $runtime, $poisoned))->wait(90);
    expect($result->successful() && $result->stderr === '', '外部 PHP INI 影响了静态程序：' . $result->stdout . $result->stderr);
    unlink($runtime . '/php.ini');

    if (($identity['profile']['name'] ?? null) !== null) {
        expect($identity['profile']['database'] === $driver, '候选 profile 与被验收数据库不一致');
        foreach (array_diff(['sqlite', 'mysql', 'pgsql'], [$driver]) as $disabled) {
            foreach (['check', 'migrate'] as $operation) {
                $arguments = $operation === 'migrate' ? ['migrate', 'status'] : ['check'];
                $refused = (new Process([...$command, ...$arguments], $runtime, array_replace($environment, ['DB_DRIVER' => $disabled])))->wait(30);
                expect($refused->exitCode === 1 && !$refused->timedOut
                    && str_contains($refused->stdout . $refused->stderr, 'runtime_profile_database_mismatch'), '未选数据库未在连接前明确拒绝');
            }
        }
        if ($driver !== 'sqlite') {
            $refused = (new Process([...$command, 'iot:device', 'state'], $runtime, $environment))->wait(30);
            expect($refused->exitCode === 1 && !$refused->timedOut
                && str_contains($refused->stdout . $refused->stderr, 'runtime_profile_database_mismatch'), '非 SQLite 程序未在设备缓冲连接前明确拒绝');
        }
        if (!in_array('cache', $identity['profile']['features'], true) || !in_array('redis', $identity['profile']['features'], true)) {
            $refused = (new Process([...$command, 'check'], $runtime, array_replace($environment, ['APP_CACHE_ENABLED' => 'true'])))->wait(30);
            expect($refused->exitCode === 1 && !$refused->timedOut
                && str_contains($refused->stdout . $refused->stderr, 'feature_unavailable'), '关闭的缓存能力未明确拒绝');
        }
    }

    $index = (new Process([...$command, 'licenses'], $runtime, $cleanEnvironment))->wait(30);
    expect($index->successful(), '无法直接读取内嵌许可证索引');
    $notices = json_decode($index->stdout, true, 128, JSON_THROW_ON_ERROR);
    expect($notices['material-coverage'] === 'complete', '单程序仍缺少已声明依赖的许可材料');
    $documents = [];
    foreach ($notices['components'] as $component) {
        foreach ($component['documents'] as $document) {
            $documents[$document['resource']] = $document['sha256'];
        }
    }
    expect($documents !== [], '许可清单没有原始材料');
    foreach ($documents as $path => $sha) {
        $text = (new Process([...$command, 'licenses', $path], $runtime, $cleanEnvironment))->wait(30);
        expect($text->successful() && hash('sha256', $text->stdout) === $sha, '许可原文与内嵌索引不一致');
    }
    $rejected = (new Process([...$command, 'licenses', 'web/index.html'], $runtime, $cleanEnvironment))->wait(30);
    expect($rejected->exitCode === 1 && !$rejected->timedOut, '许可证入口接受了未登记的许可路径');

    $initialization = verifyNativeApplicationDeployment($project, $package, $runtime, $command, $environment, $driver, $identity['embedded-resources'], true, true);
    if ($project === $root && ($identity['profile']['name'] ?? null) !== null) {
        foreach (['alerts' => ['iot:notices', '1'], 'exports' => ['iot:exports', '1'], 'scheduler' => ['app:schedule', 'history']] as $feature => $arguments) {
            if (!in_array($feature, $identity['profile']['features'], true)) {
                $refused = (new Process([...$command, ...$arguments], $runtime, $environment))->wait(30);
                expect($refused->exitCode === 1 && !$refused->timedOut
                    && str_contains($refused->stdout . $refused->stderr, 'feature_unavailable'), '关闭的业务角色未明确拒绝：' . $feature);
            }
        }
    }
    expect(scandir($package) === ['.', '..', $filename], '业务运行向只读程序目录释放了文件');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS)) as $file) {
        expect(!preg_match('/\.(?:so|dylib|dll)$/iD', $file->getFilename()), '运行目录出现释放的原生运行库');
    }
    $record = ['platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'driver' => $driver,
        'artifact-sha256' => hash_file('sha256', $package . '/' . $filename), 'build-id' => $identity['build-id'], 'initialization' => $initialization,
        'source-and-sdk-read-denied' => true, 'ordinary-start-writes-no-files' => true, 'external-ini-ignored' => true,
        'runtime-profile-enforced' => ($identity['profile']['name'] ?? null) !== null,
        'single-executable-only' => true, 'readonly-program-directory' => true, 'different-cwd' => true,
        'license-documents-verified' => count($documents), 'application' => $project === $root ? 'iot-center' : 'independent-template',
        'checks' => $project === $root ? ['single-program-export', 'export-no-overwrite', 'embedded-resources-audit', 'app-install',
            'web-install-repeat-dry-run-force', 'web-digests', 'uploads-preserved', 'http-get-head-cache',
            'admin-customer-login', 'site-defaults', 'role-crud', 'graceful-stop']
            : ['single-program-export', 'export-no-overwrite', 'migrations', 'modified-business', 'authentication', 'users-create-read-sort', 'input-validation', 'graceful-stop']];
    file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $passed = true;
} finally {
    $windows?->close();
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($package, 0700);
    }
    if ($passed) {
        removeTestDirectory($base . '/program only');
        removeTestDirectory($runtime);
    }
}
echo '单程序真实业务验收通过：' . $base . "/verification.json\n";
