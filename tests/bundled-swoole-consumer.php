<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';
require dirname(__DIR__) . '/tools/distribution/Batch.php';

use Type\Testing\Process;
use TypeApp\Distribution\Batch;

// 从已安装的锁定依赖建立离线 Composer 仓库，消费副本不借用主仓软链接。
$root = dirname(__DIR__);
$work = $root . '/build/swoole consumer-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700), '无法创建独立消费目录');
try {
    // 从真实组件文件形成独立 Git 子树，再按 Windows 换行设置检出。
    $original = $root . '/plugin/type-build';
    $source = $work . '/source';
    $packageRoot = $source . '/plugin/type-build';
    expect(mkdir($packageRoot, 0700, true), '无法创建组件分发输入');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($original, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getFilename() === '.DS_Store') {
            continue;
        }
        expect($file->isFile() && !$file->isLink(), '组件分发输入须为普通文件');
        $target = $packageRoot . substr($file->getPathname(), strlen($original));
        if (!is_dir(dirname($target))) {
            expect(mkdir(dirname($target), 0700, true), '无法复制组件目录');
        }
        expect(copy($file->getPathname(), $target), '无法复制组件内容');
    }
    expect(copy($root . '/LICENSE', $source . '/LICENSE'), '无法复制主仓许可证');
    foreach ([['git', 'init', '-b', 'main'], ['git', 'config', 'user.name', '组件消费验收'],
        ['git', 'config', 'user.email', 'test@type-app.invalid'], ['git', '-c', 'core.autocrlf=false', 'add', '.'],
        ['git', '-c', 'commit.gpgsign=false', 'commit', '-m', 'test: 构建组件分发输入']] as $command) {
        successful($command, $source);
    }
    $mapping = json_decode(file_get_contents($root . '/.github/distribution.json'), true, 32, JSON_THROW_ON_ERROR);
    $split = Batch::package($source, trim(successful(['git', 'rev-parse', 'HEAD'], $source)), 'type-build', $mapping['packages']);
    successful(['git', 'branch', 'component', $split['split']], $source);
    successful(['git', 'clone', '--no-checkout', '--branch', 'component', $source, $work . '/package'], $work);
    successful(['git', '-c', 'core.autocrlf=true', 'checkout', 'component'], $work . '/package');
    $installed = json_decode(file_get_contents($root . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $packages = array_column($installed['packages'], null, 'name');
    $queue = ['zoujingli/type-build'];
    $repositories = [];
    while ($queue !== []) {
        $name = array_shift($queue);
        if (!str_contains($name, '/') || isset($repositories[$name])) {
            continue;
        }
        $package = $packages[$name] ?? throw new RuntimeException('独立消费缺少已安装依赖：' . $name);
        $repositories[$name] = ['type' => 'path', 'url' => $name === 'zoujingli/type-build' ? $work . '/package' : realpath($root . '/vendor/composer/' . $package['install-path']),
            'options' => ['symlink' => false, 'versions' => [$name => $package['version']]]];
        array_push($queue, ...array_keys($package['require'] ?? []));
    }
    $composer = ['name' => 'type-tests/swoole-consumer', 'require-dev' => ['zoujingli/type-build' => $packages['zoujingli/type-build']['version']],
        'repositories' => [...array_values($repositories), ['packagist.org' => false]], 'minimum-stability' => 'dev',
        'config' => ['allow-plugins' => false, 'vendor-dir' => 'dependencies']];
    file_put_contents($work . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $environment = getenv();
    $environment['COMPOSER_DISABLE_NETWORK'] = '1';
    $process = new Process([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'], $work, $environment);
    try {
        $result = $process->wait(120);
        expect($result->successful(), '离线安装失败：' . $result->stdout . $result->stderr);
    } finally {
        $process->stop();
    }
    $component = $work . '/dependencies/zoujingli/type-build';
    expect(!is_link($component), '构建组件必须是独立安装副本');
    $bundle = $component . '/resources/swoole';
    $manifest = json_decode(file_get_contents($bundle . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    foreach (['resources/swoole/manifest.json'] as $file) {
        expect(hash_file('sha256', $component . '/' . $file) === hash_file('sha256', $original . '/' . $file), '安装丢失或改变组件文件：' . $file);
    }
    expect(is_file($component . '/.gitattributes') && is_file($component . '/NOTICE'), '安装缺少分发属性或归属说明');
    foreach ($manifest['modules'] as $module) {
        expect(hash_file('sha256', $bundle . '/' . $module['file']) === $module['sha256'], '安装改变了模块字节');
    }
    foreach ($manifest['licenses'] as $file => $sha256) {
        expect(hash_file('sha256', $bundle . '/' . $file) === $sha256, '安装改变了原始许可证：' . $file);
    }
    $command = [PHP_BINARY, '-n', '-r', 'require $argv[1]; echo json_encode((new Type\\Build\\BundledSwoole())->select(), JSON_THROW_ON_ERROR);',
        $work . '/dependencies/autoload.php'];
    $selected = json_decode(successful($command, $work), true, 32, JSON_THROW_ON_ERROR);
    expect(str_starts_with(str_replace('\\', '/', $selected['file']), str_replace('\\', '/', $bundle) . '/'), '选择器仍在使用主仓资源');
    expect($selected['manifest'] === realpath($bundle . '/manifest.json'), '选择器没有返回安装包的清单');
    expect(json_decode(successful($command, $root . '/tests'), true, 32, JSON_THROW_ON_ERROR) === $selected, '选择结果受当前工作目录影响');
    expect(!file_exists($work . '/bin/swoole') && !file_exists($work . '/plugin'), '独立消费不应复制主仓布局');
    echo json_encode(['status' => 'passed', 'checks' => ['git-subtree-distribution', 'autocrlf-checkout', 'offline-composer-install', 'independent-package-copy', 'space-in-path',
        'different-working-directory', 'four-module-hashes', 'original-license-hashes', 'component-manifest'],
        'module' => substr($selected['file'], strlen($work) + 1), 'sha256' => $selected['sha256']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} finally {
    removeTestDirectory($work);
}
