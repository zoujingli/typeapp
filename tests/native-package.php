<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-package-sandbox.php';
require __DIR__ . '/native-application-deployment.php';

use Type\Build\NativePackage;
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
$candidatePackage = getenv('TYPE_RELEASE_PACKAGE');
if (is_string($candidatePackage) && $candidatePackage !== '') {
    $package = realpath($candidatePackage);
    expect(is_string($package) && testPathIsWithin($package, $root . '/build'), '候选发布包必须属于本轮构建目录');
    $created = ['directory' => $package, 'manifest-sha256' => (string) getenv('TYPE_RELEASE_PACKAGE_SHA256')];
} else {
    $created = json_decode(successful([PHP_BINARY, $project . '/vendor/bin/type',
        'package-directory', $artifact, $base . '/release', $project . '/.env.example'], $project), true, 512, JSON_THROW_ON_ERROR);
    $package = $base . '/moved release';
    expect(rename($created['directory'], $package), '无法搬迁实际发布目录');
}
$release = $publisher->verify($package, $created['manifest-sha256']);
expect(isset($release['files']['OPERATIONS.md']) && file_get_contents($package . '/OPERATIONS.md') === file_get_contents($root . '/plugin/type-build/docs/operations.md'), '发布未包含同一份完整操作手册');
// 第一方材料属于当前被发布的应用；独立模板与开发主仓有各自的 NOTICE。
expect(isset($release['files']['LICENSE'], $release['files']['NOTICE'])
    && file_get_contents($package . '/LICENSE') === file_get_contents($project . '/LICENSE')
    && file_get_contents($package . '/NOTICE') === file_get_contents($project . '/NOTICE'), '发布未包含当前应用的完整第一方许可证材料');
$compiledIdentity = (new Type\Build\ArtifactManifest())->read($artifact);
$resourcePrefix = $release['artifact']['path'] . '.resources/' . $compiledIdentity['resource-generation'] . '/';
$notices = json_decode((string) file_get_contents($package . '/' . $resourcePrefix . $compiledIdentity['dependency-notices']['index']), true, 128, JSON_THROW_ON_ERROR);
$thirdPartyDocuments = 0;
foreach ($notices['components'] as $component) {
    if ($component['id'] !== 'composer:psr/http-message') {
        continue;
    }
    expect($component['license-declared'] === 'MIT', '第三方许可声明被应用许可替代');
    foreach ($component['documents'] as $document) {
        expect(($release['files'][$resourcePrefix . $document['resource']]['sha256'] ?? null) === $document['sha256'], '第三方许可原文被遗漏或替换');
        $thirdPartyDocuments++;
    }
}
expect($thirdPartyDocuments > 0, '发布验收未覆盖第三方生产依赖的实际许可原文');
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
// 与独立 ORM 消费者一致，直接交给 PHP 的数组进程接口，避免 cmd /c 再次解析路径引号。
$command = [$package . (PHP_OS_FAMILY === 'Windows' ? '/run.cmd' : '/run')];
$isolated = false;
if (PHP_OS_FAMILY === 'Darwin' || (PHP_OS_FAMILY === 'Linux' && getenv('TYPE_BWRAP_BINARY') !== false)) {
    $command = sandboxPackageCommand($root, $package, [$runtime]);
    $isolated = true;
} elseif (PHP_OS_FAMILY === 'Windows') {
    $environment['SystemRoot'] = (string) getenv('SystemRoot');
    $environment['TEMP'] = sys_get_temp_dir();
}
$help = (new Process([...$command, 'help'], $package, $environment))->wait(10);
expect($help->successful() && str_contains($help->stdout, $helpMarker) && $help->stderr === '', '搬迁后原生帮助失败：' . $help->stderr);
$untrustedEnvironment = $environment;
$untrustedEnvironment['TYPE_APP_RELEASE_SHA256'] = str_repeat('0', 64);
foreach ([['help'], ['--help'], ['migrate', 'help']] as $startupArguments) {
    $untrusted = (new Process([...$command, ...$startupArguments], $package, $untrustedEnvironment))->wait(10);
    $startupStatus = json_encode(['arguments' => $startupArguments, 'exit-code' => $untrusted->exitCode,
        'timed-out' => $untrusted->timedOut, 'output-exceeded' => $untrusted->outputExceeded, 'signal' => $untrusted->signal], JSON_THROW_ON_ERROR);
    expect(
        $untrusted->exitCode === 1 && !$untrusted->timedOut && !str_contains($untrusted->stdout, $helpMarker),
        '错误的外部受信摘要没有在原生启动时拒绝 ' . $startupStatus . "：\n" . $untrusted->stdout . $untrusted->stderr
    );
}
$audit = (new Process([...$command, 'verify-runtime'], $package, $environment))->wait(90);
expect($audit->successful() && $audit->stdout === "运行环境完整性校验通过。\n", '独立运行库完整审计失败：' . $audit->stderr);
$initializationStatus = verifyNativeApplicationDeployment($project, $package, $runtime, $command, $environment, $driver, $release['embedded-resources'] ?? [], $isolated);

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
// 旧协议3产物继续发布；使用新验包入口时，顶层应用原文也必须与其构建归属一致。
$noticeFile = $package . '/NOTICE';
$savedNotice = (string) file_get_contents($noticeFile);
$descriptor = $package . '/release.json';
$savedDescriptor = (string) file_get_contents($descriptor);
file_put_contents($noticeFile, "Unrelated application NOTICE\n");
clearstatcache(true, $noticeFile);
$forged = $release;
$forged['files']['NOTICE']['sha256'] = hash_file('sha256', $noticeFile);
$forged['files']['NOTICE']['bytes'] = filesize($noticeFile);
file_put_contents($descriptor, json_encode($forged, JSON_THROW_ON_ERROR));
try {
    $materialRejected = false;
    try {
        $publisher->verify($package, (string) hash_file('sha256', $descriptor));
    } catch (RuntimeException $error) {
        $materialRejected = str_contains($error->getMessage(), '第一方材料不属于编译应用');
    }
    expect($materialRejected, '重写发布摘要绕过了应用NOTICE归属校验');
} finally {
    file_put_contents($noticeFile, $savedNotice);
    clearstatcache(true, $noticeFile);
    file_put_contents($descriptor, $savedDescriptor);
}
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
    'initialization' => $initializationStatus,
    'package' => $package, 'project' => $project, 'relocated' => true, 'source-and-sdk-read-denied' => $isolated, 'scope' => '当前应用' . $driver . '原生发布闭环，不代表其他平台已验收',
    'business-message' => getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE') ?: null,
    'composer-read-and-php-compiler-exec-denied' => $isolated,
    'checks' => ['no-source-payload', 'no-overwrite', 'secret-rejection', 'help', 'external-trusted-digest-rejection', 'deployment-audit', 'application-initialization', 'http-auth-crud-query-validation', 'graceful-stop', 'same-size-tamper-rejection', 'executable-permissions', 'application-original-materials', 'application-material-identity-rejection', 'third-party-materials-preserved']];
if ($driver !== 'sqlite') {
    $record['checks'][] = 'dedicated-database-created-and-removed';
}
if ($project === $root) {
    $record['checks'] = [...$record['checks'], 'embedded-frontend-all-file-digests', 'web-install-repeat-dry-run-force',
        'uploads-preserved-not-public', 'static-get-head-cache', 'admin-customer-login', 'default-site-information', 'role-crud'];
}
file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
if (in_array('--archive', $argv, true)) {
    $archiveEnvironment = getenv();
    $archived = (new Process([PHP_BINARY, $root . '/tests/package-archive.php', $package, $created['manifest-sha256']], $root, $archiveEnvironment))->wait(180);
    // PHP致命错误可能写入stdout；不能只转发stderr而丢失内存耗尽等真实原因。
    $archiveStatus = json_encode(['exit-code' => $archived->exitCode, 'timed-out' => $archived->timedOut,
        'output-exceeded' => $archived->outputExceeded, 'signal' => $archived->signal], JSON_THROW_ON_ERROR);
    expect($archived->successful(), '发布归档后验收失败 ' . $archiveStatus . "：\n" . $archived->stdout . $archived->stderr);
    echo $archived->stdout;
}
echo '原生发布、搬迁、' . $driver . '业务与篡改拒绝通过：' . $base . "/verification.json\n";
