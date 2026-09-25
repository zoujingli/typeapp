<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use Type\Build\PackageArchive;
use Type\Testing\Process;

$root = BuildPlatform::resolve(dirname(__DIR__));
$base = $root . '/build/notices-build-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮材料AOT验收目录');
$relative = substr($base, strlen($root) + 1);
$configuration = json_decode(file_get_contents($root . '/docs/build-config/type-app.json'), true, 512, JSON_THROW_ON_ERROR);
$configuration['output'] = $relative . '/type-app';
$configuration['build-directory'] = $relative . '/compiler';
$configuration['cache-directory'] = $relative . '/cache';
$pcntl = getenv('TYPE_TEST_PCNTL_MODULE');
if (PHP_OS_FAMILY === 'Linux' && $pcntl !== false) {
    $configuration['runtime']['Linux']['modules']['pcntl'] = ['file' => $pcntl, 'sha256' => hash_file('sha256', $pcntl)];
}
$phpx = (new BuildPlatform())->runtimeLibraries((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
$phpxLibrary = null;
foreach ($phpx as $file) {
    if (str_contains(strtolower(basename($file)), 'phpx')) {
        $phpxLibrary = $file;
    }
}
expect($phpxLibrary !== null, '不能确定本轮PHPX运行库');
$phpxMetadata = json_decode(file_get_contents($root . '/vendor/swoole/phpx/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$texts = [];
foreach (['LICENSE', 'thirdparty/wren-gc/LICENSE'] as $name) {
    $file = $root . '/vendor/swoole/phpx/' . $name;
    $texts[] = ['file' => $file, 'sha256' => hash_file('sha256', $file)];
}
$configuration['notices']['native'][PHP_OS_FAMILY][basename($phpxLibrary)] = ['binary-sha256' => hash_file('sha256', $phpxLibrary),
    'component' => $phpxMetadata['name'], 'version' => '2.9.2', 'license' => $phpxMetadata['license'], 'files' => $texts];
$configFile = $base . '/type.json';
file_put_contents($configFile, json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo "正在编译携带依赖原始材料的标准应用。\n";
[$status, $stdout, $stderr] = execute([PHP_BINARY, $root . '/vendor/bin/type', 'build', $configFile], $root);
file_put_contents($base . '/compile.log', $stdout . $stderr);
expect($status === 0, '材料AOT失败，见：' . $base . '/compile.log');
$artifact = (new BuildPlatform())->output($base . '/type-app');
$report = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
$notice = $report['manifest']['dependency-notices'];
expect($notice['material-coverage'] === 'incomplete' && $notice['legal-review'] === 'not-assessed', '未补齐材料却宣称完整或已审查');
$publisher = new NativePackage();
$package = $publisher->create($artifact, $base . '/release', $root . '/.env.example');
$release = $publisher->verify($package['directory'], $package['manifest-sha256']);
expect($release['dependency-notices'] === $notice && is_file($package['directory'] . '/NOTICES.md')
    && is_file($package['directory'] . '/LICENSE') && is_file($package['directory'] . '/NOTICE'), '材料摘要、第一方许可证或阅读入口没有发布');
$prefix = $release['artifact']['path'] . '.resources/' . $report['manifest']['resource-generation'] . '/';
$index = json_decode(file_get_contents($package['directory'] . '/' . $prefix . $notice['index']), true, 512, JSON_THROW_ON_ERROR);
$found = false;
foreach ($index['components'] as $component) {
    if ($component['id'] === 'composer:psr/http-message') {
        $found = true;
        expect($component['license-declared'] === 'MIT', 'Composer许可声明不符');
        expect(file_get_contents($package['directory'] . '/' . $prefix . $component['documents'][0]['resource'])
            === file_get_contents($root . '/vendor/psr/http-message/LICENSE'), '原文在编译/发布时被改动');
    }
}
expect($found, '原生发布材料遗漏实际生产依赖');
$selectedResource = $index['components'][1]['documents'][0]['resource'] ?? null;
foreach ($index['components'] as $component) {
    if ($component['documents'] !== []) {
        $selectedResource = $component['documents'][0]['resource'];
        break;
    }
}
expect(is_string($selectedResource), '缺少可以验证的原始材料');
$textFile = $package['directory'] . '/' . $prefix . $selectedResource;
$original = file_get_contents($textFile);
file_put_contents($textFile, $original . 'tamper');
$rejected = false;
try {
    $publisher->verify($package['directory'], $package['manifest-sha256']);
} catch (RuntimeException) {
    $rejected = true;
}
$environment = getenv();
$environment['TYPE_APP_RELEASE_SHA256'] = $package['manifest-sha256'];
$command = [$package['directory'] . (PHP_OS_FAMILY === 'Windows' ? '/run.cmd' : '/run')];
$tampered = new Process([...$command, 'help'], $package['directory'], $environment);
try {
    expect($rejected && !$tampered->wait(15)->successful(), '材料篡改没有在静态/原生启动两层拒绝');
} finally {
    $tampered->stop();
    file_put_contents($textFile, $original);
}
$releaseFile = $package['directory'] . '/release.json';
$originalRelease = file_get_contents($releaseFile);
$forged = json_decode($originalRelease, true, 512, JSON_THROW_ON_ERROR);
$forged['dependency-notices']['material-coverage'] = 'complete';
$forged['dependency-notices']['missing'] = [];
$forgedBytes = json_encode($forged, JSON_THROW_ON_ERROR);
file_put_contents($releaseFile, $forgedBytes);
$rejected = false;
try {
    $publisher->verify($package['directory'], hash('sha256', $forgedBytes));
} catch (RuntimeException) {
    $rejected = true;
}
$forgedEnvironment = $environment;
$forgedEnvironment['TYPE_APP_RELEASE_SHA256'] = hash('sha256', $forgedBytes);
$forgedRun = new Process([...$command, 'help'], $package['directory'], $forgedEnvironment);
try {
    expect(!$forgedRun->wait(15)->successful(), '原生启动接受了伪造的材料完整状态');
} finally {
    $forgedRun->stop();
    file_put_contents($releaseFile, $originalRelease);
}
expect($rejected, '重新计算发布摘要即可伪造材料完整状态');
$publisher->verify($package['directory'], $package['manifest-sha256']);
$started = new Process([...$command, 'help'], $package['directory'], $environment);
try {
    expect($started->wait(15)->successful(), '恢复材料后原生程序不能启动');
} finally {
    $started->stop();
}
$archive = (new PackageArchive())->create($package['directory'], $base . '/release.zip', $package['manifest-sha256']);
$packed = new PharData($archive['file']);
expect(isset($packed['NOTICES.md'], $packed['LICENSE'], $packed['NOTICE']) && isset($packed[$prefix . $notice['index']]), '归档遗漏许可证、材料入口或索引');
unset($packed);
file_put_contents($base . '/verification.json', json_encode(['platform' => PHP_OS_FAMILY, 'artifact-sha256' => hash_file('sha256', $artifact),
    'build-id' => $report['build-id'], 'release-sha256' => $package['manifest-sha256'], 'notice-summary' => $notice,
    'checks' => ['full-aot', 'composer-original-license', 'native-binary-bound-documents', 'declared-gaps', 'no-legal-claim', 'published-original-bytes',
        'static-and-native-tamper-rejection', 'false-coverage-rejected', 'restored-native-start', 'archive-notices']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '原生AOT、原文发布、缺失状态、双层篡改拒绝及归档通过：' . $base . "/verification.json\n";
