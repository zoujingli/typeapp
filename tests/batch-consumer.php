<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';
require dirname(__DIR__) . '/tools/distribution/Batch.php';

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process as GitProcess;

$root = dirname(__DIR__);
$file = $argv[1] ?? $root . '/build/distribution/batch-result.json';
$native = in_array('--native', $argv, true);
$report = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$source = GitProcess::output(['git', 'rev-parse', 'HEAD'], $root);
$mapping = json_decode(GitProcess::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
Batch::verifyReport($root, $source, $report, $mapping);
$composer = ['name' => 'type-tests/batch-consumer', 'type' => 'project', 'license' => 'Apache-2.0', 'require' => [], 'require-dev' => [],
    'repositories' => [], 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
foreach ($mapping['packages'] as $name => $package) {
    $item = $report['items'][$name] ?? null;
    expect(is_array($item) && ($item['repository'] ?? '') === $package['repository'] && preg_match('/^[a-f0-9]{40}$/D', $item['split'] ?? ''), '缺少准确分发提交：' . $name);
    $version = $report['mode'] === 'tag' ? $report['version'] : 'dev-main#' . $item['split'];
    $composer[in_array($name, ['type-build', 'type-testing'], true) ? 'require-dev' : 'require'][$package['composer-name']] = $version;
    $composer['repositories'][] = ['type' => 'git', 'url' => 'https://github.com/' . $package['repository'] . '.git'];
}
$composer['require-dev']['swoole/typephp'] = '0.9.3';
$composer['require-dev']['swoole/phpx'] = '2.9.2';
$consumer = $root . '/build/batch-consumer-' . bin2hex(random_bytes(6));
expect(mkdir($consumer, 0700, true), '无法创建独立批次消费项目');
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
foreach (['examples/native-command.php' => 'main.php', 'toolchain.lock.json' => 'toolchain.lock.json'] as $input => $target) {
    $content = successful(['git', 'show', $source . ':' . $input], $root);
    expect(file_put_contents($consumer . '/' . $target, $content) === strlen($content), '无法保存固定批次输入：' . $input);
}
file_put_contents($consumer . '/type-app.json', json_encode(['name' => 'batch-consumer', 'entry' => 'main.php', 'output' => 'build/type-app', 'build-directory' => 'build/compiler'], JSON_THROW_ON_ERROR));
$composerBinary = getenv('COMPOSER_BINARY') ?: 'composer';
successful([$composerBinary, 'install', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-source', '--no-progress'], $consumer);
$lock = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$actual = [];
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    if (!str_starts_with($package['name'], 'zoujingli/type-')) {
        continue;
    }
    $name = substr($package['name'], strlen('zoujingli/'));
    $expected = $report['items'][$name];
    expect($package['source']['reference'] === $expected['split'], '实际安装提交与批次不同：' . $name);
    expect($package['source']['type'] === 'git' && !is_link($consumer . '/vendor/' . $package['name']), '实际消费混入 path 或符号链接');
    $actual[$name] = $package['source']['reference'];
}
expect(count($actual) === count($mapping['packages']), '插件没有全部从分发入口安装');
echo successful([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $configuration = new Type\Core\Configuration(["message" => "公开跨包消费"]); '
    . 'if ($configuration->text("message") !== "公开跨包消费" || !class_exists(Type\Orm\Database::class) || !class_exists(Type\Log\Logger::class)) { exit(1); }'], $consumer);
if ($native) {
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/type-app.json'], $consumer);
    echo successful([PHP_BINARY, $root . '/tests/native.php', $consumer . '/build/type-app']);
}
file_put_contents($consumer . '/verification.json', json_encode(['batch' => $report['id'], 'source' => $report['source'], 'native' => $native, 'installed' => $actual], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
echo '公开跨包 Composer 消费并核对固定提交通过：' . $consumer . "\n";
