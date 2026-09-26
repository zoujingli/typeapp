<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use Type\Build\SourceSet;
use Type\Testing\Process;

$root = dirname(__DIR__);
$directory = $root . '/build/package-resources-' . bin2hex(random_bytes(6));
expect(mkdir($directory, 0700), '无法创建资源验收目录');
file_put_contents($directory . '/asset.txt', 'compiled-resource-ok');
file_put_contents($directory . '/empty.bin', '');
file_put_contents($directory . '/.env', 'TOKEN=test-only');
$collector = new SourceSet();
$declaration = ['source' => 'asset.txt', 'target' => 'data/asset.txt'];
$collected = $collector->resources($directory, 'type-tests/resources', [$declaration]);
expect(count($collected) === 1 && $collected[0]['sha256'] === hash_file('sha256', $directory . '/asset.txt'), '资源未绑定内容摘要');
foreach ([[$declaration, $declaration], [['source' => '.env', 'target' => 'data.txt']], [['source' => 'asset.txt', 'target' => '../escape']], [['source' => '../composer.json', 'target' => 'escape']], ['invalid' => $declaration]] as $invalid) {
    $rejected = false;
    try {
        $collector->resources($directory, 'type-tests/resources', $invalid);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '非法或携密资源声明没有明确拒绝');
}
unlink($directory . '/.env');
if (in_array('--php', $argv, true)) {
    echo "资源所有权、摘要、重复目标、越界与秘密文件拒绝通过。\n";
    exit(0);
}
$composer = ['name' => 'type-tests/resources', 'require' => ['zoujingli/type-runtime' => '1.0.x-dev'],
    'require-dev' => ['zoujingli/type-build' => '1.0.x-dev'], 'config' => ['vendor-dir' => '../../vendor', 'allow-plugins' => false],
    'extra' => ['type' => ['resources' => [['source' => 'empty.bin', 'target' => 'empty.bin']]]]];
// 外部应用可以使用其他许可，也可以尚未补齐原文；完整性门禁由构建声明决定。
$licenseOnly = in_array('--license-only', $argv, true);
if ($licenseOnly) {
    $composer['license'] = 'MIT';
    copy($root . '/vendor/psr/log/LICENSE', $directory . '/LICENSE');
}
file_put_contents($directory . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/composer.lock', $directory . '/composer.lock');
copy($root . '/toolchain.lock.json', $directory . '/toolchain.lock.json');
file_put_contents($directory . '/main.php', <<<'PHP'
<?php

declare(strict_types=1);

function main(int $argc, array $argv): void
{
    \Type\Generated\BuildIdentity::verifyRuntime();
    $manifest = \Type\Generated\BuildIdentity::info();
    $root = (string) getenv('TYPE_APP_RUNTIME_ROOT');
    $binary = PHP_OS_FAMILY === 'Windows' ? '/bin/app.exe' : '/bin/app';
    $file = $root . $binary . '.resources/' . $manifest['resource-generation'] . '/data/asset.txt';
    echo file_get_contents($file) . "\n";
}
PHP);
$settings = ['name' => 'type-resources', 'entry' => 'main.php', 'resources' => [$declaration],
    'output' => 'build/native/type-app', 'build-directory' => 'build/native/compiler', 'compiler' => ['optimize' => 2, 'debug' => false, 'jobs' => 2]];
file_put_contents($directory . '/build.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
[$status, $output, $error] = execute([PHP_BINARY, $root . '/vendor/bin/type', $directory . '/build.json'], $directory);
file_put_contents($directory . '/compile.log', $output . $error);
expect($status === 0, '资源AOT失败，见 ' . $directory . '/compile.log' . "\n" . substr($error, -2000));
$artifact = (new BuildPlatform())->output($directory . '/build/native/type-app');
$publisher = new NativePackage();
$created = $publisher->create($artifact, $directory . '/release');
$release = $publisher->verify($created['directory'], $created['manifest-sha256']);
$environment = getenv();
$environment['TYPE_APP_RELEASE_SHA256'] = $created['manifest-sha256'];
$command = [$created['directory'] . (PHP_OS_FAMILY === 'Windows' ? '/run.cmd' : '/run')];
$run = (new Process($command, $created['directory'], $environment))->wait(10);
expect($run->successful() && $run->stdout === "compiled-resource-ok\n" && $run->stderr === '', '资源发布后原生读取失败：' . $run->stderr);
$resourcePaths = [];
foreach ($release['files'] as $name => $file) {
    if ($file['kind'] === 'resource') {
        $resourcePaths[] = $name;
    }
}
$buildManifest = (new Type\Build\ArtifactManifest())->read($artifact);
expect(($buildManifest['generator-protocols']['identity'] ?? null) === 5, '原生材料契约需要身份生成协议5');
expect(!isset($release['files']['NOTICE']) && !is_file($created['directory'] . '/NOTICE'), '无NOTICE的应用被附加其他项目的NOTICE');
if ($licenseOnly) {
    expect(isset($release['files']['LICENSE']) && file_get_contents($created['directory'] . '/LICENSE') === file_get_contents($directory . '/LICENSE'), '外部应用许可未按构建原文保留');
} else {
    expect(!isset($release['files']['LICENSE']) && !is_file($created['directory'] . '/LICENSE'), '无许可原文的应用被误用构建组件许可');
}
$resourcePrefix = $release['artifact']['path'] . '.resources/' . $buildManifest['resource-generation'] . '/';
$applicationResources = array_values(array_filter($resourcePaths, static fn (string $path): bool => !str_starts_with($path, $resourcePrefix . 'notices/')));
expect(count($applicationResources) === 2 && in_array($resourcePrefix . 'data/asset.txt', $applicationResources, true)
    && in_array($resourcePrefix . 'empty.bin', $applicationResources, true), '应用配置或Composer中的资源被遗漏');
expect(in_array($resourcePrefix . 'notices/dependencies.json', $resourcePaths, true), '构建材料没有进入同一资源清单');
$notices = json_decode((string) file_get_contents($created['directory'] . '/' . $resourcePrefix . 'notices/dependencies.json'), true, 128, JSON_THROW_ON_ERROR);
$dependencyDocuments = 0;
foreach ($notices['components'] as $component) {
    if ($component['kind'] === 'application') {
        continue;
    }
    foreach ($component['documents'] as $document) {
        expect(($release['files'][$resourcePrefix . $document['resource']]['sha256'] ?? null) === $document['sha256'], '依赖原始材料在发布时被遗漏或替换');
        $dependencyDocuments++;
    }
}
expect($dependencyDocuments > 0, '资源验收未覆盖实际生产依赖的许可原文');
// 改写外部受信摘要仍不能给应用套用另一个项目的许可。
$descriptor = $created['directory'] . '/release.json';
$savedDescriptor = (string) file_get_contents($descriptor);
$licenseFile = $created['directory'] . '/LICENSE';
$savedLicense = is_file($licenseFile) ? (string) file_get_contents($licenseFile) : null;
file_put_contents($licenseFile, "Unrelated application license\n");
clearstatcache(true, $licenseFile);
$forged = $release;
$forged['files']['LICENSE'] = ['sha256' => hash_file('sha256', $licenseFile), 'bytes' => filesize($licenseFile), 'kind' => 'first-party-license-material'];
file_put_contents($descriptor, json_encode($forged, JSON_THROW_ON_ERROR));
$forgedEnvironment = $environment;
$forgedEnvironment['TYPE_APP_RELEASE_SHA256'] = hash_file('sha256', $descriptor);
try {
    $materialRejected = false;
    try {
        $publisher->verify($created['directory'], $forgedEnvironment['TYPE_APP_RELEASE_SHA256']);
    } catch (RuntimeException $error) {
        $materialRejected = str_contains($error->getMessage(), '第一方材料不属于编译应用');
    }
    $forgedRun = (new Process($command, $created['directory'], $forgedEnvironment))->wait(10);
    expect($materialRejected && !$forgedRun->successful() && str_contains($forgedRun->stdout . $forgedRun->stderr, '第一方材料不属于编译应用'), '重写发布摘要绕过了应用许可归属');
} finally {
    file_put_contents($descriptor, $savedDescriptor);
    if ($savedLicense === null) {
        unlink($licenseFile);
    } else {
        file_put_contents($licenseFile, $savedLicense);
    }
    clearstatcache(true, $licenseFile);
}
$resourceFile = $created['directory'] . '/' . $resourcePrefix . 'data/asset.txt';
$saved = (string) file_get_contents($resourceFile);
file_put_contents($resourceFile, chr(ord($saved[0]) ^ 1) . substr($saved, 1));
$tampered = (new Process($command, $created['directory'], $environment))->wait(10);
expect(!$tampered->successful() && str_contains($tampered->stdout . $tampered->stderr, '发布文件完整性校验失败'), '资源等长篡改没有被编译后的发布文件校验拒绝');
$descriptor = $created['directory'] . '/release.json';
$savedDescriptor = (string) file_get_contents($descriptor);
$forged = $release;
$forged['files'][$resourcePrefix . 'data/asset.txt']['sha256'] = hash_file('sha256', $resourceFile);
file_put_contents($descriptor, json_encode($forged, JSON_THROW_ON_ERROR));
$forgedEnvironment = $environment;
$forgedEnvironment['TYPE_APP_RELEASE_SHA256'] = hash_file('sha256', $descriptor);
try {
    $forgedRun = (new Process($command, $created['directory'], $forgedEnvironment))->wait(10);
    expect(!$forgedRun->successful() && str_contains($forgedRun->stdout . $forgedRun->stderr, '资源与编译身份不一致'), '重写发布摘要不能绕过编译资源身份');
} finally {
    file_put_contents($descriptor, $savedDescriptor);
}
file_put_contents($resourceFile, $saved);
$publisher->verify($created['directory'], $created['manifest-sha256']);
file_put_contents($directory . '/verification.json', json_encode(['os' => PHP_OS_FAMILY, 'native' => true, 'artifact' => $artifact,
    'artifact-sha256' => $release['artifact']['sha256'], 'resources' => $resourcePaths, 'release-sha256' => $created['manifest-sha256'],
    'application-license' => $licenseOnly ? 'MIT-without-NOTICE' : 'no-original-materials',
    'checks' => ['application-and-composer-resources', 'native-resource-read', 'native-same-size-tamper-rejection', 'compiled-resource-identity-rejection', 'no-secret-resources',
        'optional-application-materials', 'dependency-original-materials-preserved', 'offline-and-native-application-material-identity-rejection']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
echo '应用资源AOT、发布、读取与篡改拒绝通过：' . $directory . "/verification.json\n";
