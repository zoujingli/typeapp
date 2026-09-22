<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';
require dirname(__DIR__) . '/tools/distribution/Batch.php';

use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Testing\Process;
use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process as GitProcess;

$root = Type\Build\BuildPlatform::resolve(dirname(__DIR__));
$driver = $argv[1] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '应用模板验收驱动无效');
$native = in_array('--native', $argv, true);
$remote = in_array('--remote', $argv, true);
$buildOnly = in_array('--build-only', $argv, true);
$onboarding = in_array('--onboarding', $argv, true);
$packageDeployment = in_array('--package', $argv, true);
expect(!$buildOnly || $native, '只构建模板模式必须同时指定 --native');
expect(!$packageDeployment || ($onboarding && $native && !$buildOnly), '接入发布验收要求 --onboarding --native --package');
$consumer = $root . '/build/template-' . $driver . '-' . bin2hex(random_bytes(6));
$template = getenv('TYPE_TEMPLATE_SOURCE') ?: $root . '/templates/type-project';
if ($remote) {
    // 配置脚本执行前，先证明模板检出与完整组件批次均来自同一固定源码。
    $source = GitProcess::output(['git', 'rev-parse', 'HEAD'], $root);
    $mapping = json_decode(GitProcess::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    $batch = json_decode(file_get_contents($root . '/build/distribution/batch-result.json'), true, 512, JSON_THROW_ON_ERROR);
    Batch::verifyReport($root, $source, $batch, $mapping);
    $templateSource = getenv('TYPE_TEMPLATE_SOURCE');
    expect(is_string($templateSource) && $templateSource !== '' && is_dir($templateSource), '远端模板消费需要显式 TYPE_TEMPLATE_SOURCE 检出目录');
    $template = Type\Build\BuildPlatform::resolve($templateSource);
    $templateReportPath = $root . '/build/distribution/template.json';
    expect(is_file($templateReportPath), '远端模板消费需要分发与克隆验证报告');
    $templateReport = json_decode(file_get_contents($templateReportPath), true, 512, JSON_THROW_ON_ERROR);
    expect(in_array($templateReport['status'] ?? '', ['published', 'already-current'], true)
        && ($templateReport['publish-status'] ?? null) === $templateReport['status']
        && ($templateReport['checkout-verified'] ?? null) === true, '模板分发与克隆验证尚未全部通过');
    $templateTree = GitProcess::output(['git', 'rev-parse', $source . ':templates/type-project'], $root);
    $templateSplit = GitProcess::output(['git', 'subtree', 'split', '--prefix=templates/type-project', '--ignore-joins', $source], $root);
    foreach (['source' => $source, 'framework-batch' => $batch['id'], 'batch' => hash('sha256', $source . ':' . $templateTree),
        'package' => 'zoujingli/type-project', 'repository' => 'zoujingli/type-project', 'mode' => 'branch', 'version' => '',
        'reference' => 'refs/heads/main', 'tree' => $templateTree, 'split' => $templateSplit] as $field => $expectedValue) {
        expect(($templateReport[$field] ?? null) === $expectedValue, '模板报告与固定批次不一致：' . $field);
    }
    expect(
        realpath(GitProcess::output(['git', 'rev-parse', '--show-toplevel'], $template)) === realpath($template)
        && GitProcess::output(['git', 'rev-parse', 'HEAD'], $template) === $templateSplit
        && GitProcess::output(['git', 'rev-parse', 'HEAD^{tree}'], $template) === $templateTree
        && GitProcess::output(['git', 'status', '--porcelain', '--untracked-files=all', '--ignored'], $template) === '',
        '模板检出必须与已验证提交一致且没有额外或修改的文件'
    );
}
if ($onboarding) {
    // 用户入口负责驱动选择；验收控制器不再执行模板配置脚本或补业务配置。
    $created = json_decode(successful([PHP_BINARY, $root . '/vendor/bin/type', 'create', $template, $consumer, $driver], $root), true, 512, JSON_THROW_ON_ERROR);
    expect($created['driver'] === $driver && $created['directory'] === $consumer, '统一创建入口返回了错误项目');
} else {
    expect(mkdir($consumer, 0700, true), '无法创建独立应用目录');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($template, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $relative = substr($file->getPathname(), strlen($template) + 1);
        if (str_starts_with($relative, '.git/') || $relative === '.git') {
            continue;
        }
        expect(!$file->isLink(), '模板不允许符号链接');
        if ($file->isDir()) {
            mkdir($consumer . '/' . $relative, 0700, true);
        } else {
            copy($file->getPathname(), $consumer . '/' . $relative);
        }
    }
    echo successful([PHP_BINARY, $consumer . '/configure.php', $driver], $consumer);
}
$composer = json_decode(file_get_contents($consumer . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($composer['repositories'] as $repository) {
    expect($repository['type'] === 'git' && str_starts_with($repository['url'], 'https://github.com/zoujingli/'), '原始模板包含开发主仓路径');
}
$expected = [];
if (!$remote) {
    $componentRoot = getenv('TYPE_TEMPLATE_COMPONENT_DIRECTORY');
    if ($componentRoot !== false) {
        $componentRoot = Type\Build\BuildPlatform::resolve($componentRoot);
        expect(str_starts_with($componentRoot, $root . '/build/') && is_dir($componentRoot), '候选组件必须来自主仓build下的独立快照');
    }
    foreach ($composer['repositories'] as $index => $repository) {
        $name = basename($repository['url'], '.git');
        $relativePackage = $componentRoot === false ? 'plugin/' . $name : substr($componentRoot, strlen($root) + 1) . '/' . $name;
        $composer['repositories'][$index] = ['type' => 'path', 'url' => '../../' . $relativePackage, 'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $name => '1.0.x-dev']]];
    }
} else {
    foreach ($composer['repositories'] as $index => $repository) {
        $name = basename($repository['url'], '.git');
        expect(
            isset($mapping['packages'][$name]) && $repository['url'] === 'https://github.com/' . $mapping['packages'][$name]['repository'] . '.git',
            '模板依赖地址不属于固定分发映射：' . $name
        );
        $item = $batch['items'][$name];
        $expected[$name] = $item['split'];
        $composer[in_array($name, ['type-build', 'type-testing'], true) ? 'require-dev' : 'require'][$item['package']]
            = $batch['mode'] === 'tag' ? $batch['version'] : 'dev-main#' . $item['split'];
    }
}
$composer['config']['platform'] = [];
foreach (['mysql', 'pgsql', 'sqlite'] as $name) {
    if ($name !== $driver) {
        $composer['config']['platform']['ext-pdo_' . $name] = false;
    }
}
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo '独立安装模板依赖：' . $consumer . "\n";
$composerPhar = getenv('TYPE_COMPOSER_PHAR');
$composerCommand = [getenv('COMPOSER_BINARY') ?: 'composer'];
if ($composerPhar !== false) {
    expect(is_file($composerPhar), '显式Composer PHAR不存在');
    $composerCommand = [PHP_BINARY, Type\Build\BuildPlatform::resolve($composerPhar)];
}
successful([...$composerCommand, 'install', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--no-progress'], $consumer);
$installed = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$packages = array_column($installed['packages'], 'name');
expect(!in_array('zoujingli/type-build', $packages, true) && !in_array('zoujingli/type-testing', $packages, true), '构建或测试工具进入了生产依赖');
foreach (['mysql', 'pgsql', 'sqlite'] as $name) {
    expect(in_array('zoujingli/type-orm-' . $name, $packages, true) === ($name === $driver), '模板安装了未选择的数据库驱动');
}
foreach (array_merge($installed['packages'], $installed['packages-dev']) as $package) {
    if ($remote && str_starts_with($package['name'], 'zoujingli/type-')) {
        expect($package['source']['reference'] === $expected[substr($package['name'], 10)], '模板安装提交与分发批次不同');
    }
}
$marker = 'onboarding-' . bin2hex(random_bytes(8));
if ($onboarding) {
    $homeFile = $consumer . '/app/controller/HomeController.php';
    $homeSource = file_get_contents($homeFile);
    $changedSource = str_replace('Type 业务应用模板已启动。', $marker, $homeSource, $replacements);
    expect($replacements === 1 && file_put_contents($homeFile, $changedSource) === strlen($changedSource), '用户业务修改没有落入唯一控制器');
    echo successful([PHP_BINARY, $consumer . '/vendor/bin/type', 'doctor', 'type-app.json', 'development'], $consumer);
    if ($native) {
        echo successful([PHP_BINARY, $consumer . '/vendor/bin/type', 'doctor', 'type-app.json', 'build'], $consumer);
    }
    echo successful([PHP_BINARY, $consumer . '/vendor/bin/type', 'prepare', 'type-app.json'], $consumer);
} else {
    echo successful([PHP_BINARY, $consumer . '/prepare.php'], $consumer);
}
if ($native) {
    echo "独立模板全量AOT构建中。\n";
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', ...($onboarding ? ['build', 'type-app.json'] : [$consumer . '/type-app.json'])], $consumer);
    $artifact = (new Type\Build\BuildPlatform())->output($consumer . '/build/type-project');
    $manifest = (new Type\Build\ArtifactManifest())->read($artifact);
    $compiledPackages = array_keys($manifest['production-packages']);
    $installedPackages = $packages;
    sort($compiledPackages);
    sort($installedPackages);
    expect($compiledPackages === $installedPackages, '独立模板没有编译全部已安装生产依赖');
    $compilerProject = json_decode(file_get_contents($consumer . '/build/compiler/project.yml'), true, 512, JSON_THROW_ON_ERROR);
    $compiledSources = $compilerProject['sources'];
    $buildReport = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $originalSources = $buildReport['identity']['description']['inputs']['original-sources'];
    expect(count($compiledSources) === count(array_unique($compiledSources)), '独立模板编译源码存在重复');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer . '/app', FilesystemIterator::SKIP_DOTS)) as $sourceFile) {
        if ($sourceFile->isFile() && $sourceFile->getExtension() === 'php') {
            $sourcePath = Type\Build\BuildPlatform::path($sourceFile->getPathname());
            $transformed = isset($originalSources[$sourcePath])
                && $originalSources[$sourcePath]['sha256'] === hash_file('sha256', $sourcePath)
                && in_array($consumer . '/build/compiler/generated-models.php', $compiledSources, true);
            expect(in_array($sourcePath, $compiledSources, true) || $transformed, '独立模板遗漏业务源码：' . $sourceFile->getFilename());
        }
    }
}
if ($buildOnly) {
    $prepared = ['driver' => $driver, 'consumer' => $consumer, 'consumer-relative' => substr($consumer, strlen($root) + 1), 'native' => true, 'remote' => $remote,
        'production-packages' => $packages, 'verified-splits' => $expected, 'status' => 'built-not-verified'];
    $record = getenv('TYPE_TEMPLATE_PREPARED_FILE');
    expect(is_string($record) && is_dir(dirname($record)), '只构建模式需要可写的 TYPE_TEMPLATE_PREPARED_FILE 记录目录');
    file_put_contents($record, json_encode($prepared, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo '应用模板已独立编译，尚未验收：' . $consumer . "\n";
    exit(0);
}
$environment = getenv();
$environment['APP_BASE_PATH'] = $consumer;
$environment['APP_ENV'] = 'production';
$environment['APP_DEBUG'] = 'false';
if ($onboarding) {
    $environment['TYPE_TEMPLATE_EXPECTED_MESSAGE'] = $marker;
}
$admin = null;
$createdDatabase = false;
$database = 'type_template_' . bin2hex(random_bytes(6));
try {
    if ($driver === 'sqlite') {
        $environment['DB_SQLITE_FILE'] = $consumer . '/var/app.sqlite';
    } elseif ($driver === 'mysql') {
        $environment['DB_HOST'] = getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1';
        $environment['DB_PORT'] = getenv('TYPE_MYSQL_PORT') ?: '3306';
        $environment['DB_USERNAME'] = getenv('TYPE_MYSQL_USER') ?: 'root';
        $environment['DB_PASSWORD'] = getenv('TYPE_MYSQL_PASSWORD') ?: '';
        $admin = (new MysqlDriver($environment['DB_HOST'], (int) $environment['DB_PORT'], getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test', $environment['DB_USERNAME'], $environment['DB_PASSWORD']))->connect();
        $admin->exec('CREATE DATABASE ' . $database);
        $createdDatabase = true;
        $environment['DB_DATABASE'] = $database;
    } else {
        $environment['DB_HOST'] = getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1';
        $environment['DB_PORT'] = getenv('TYPE_PGSQL_PORT') ?: '5432';
        $environment['DB_USERNAME'] = getenv('TYPE_PGSQL_USER') ?: 'type_app';
        $environment['DB_PASSWORD'] = getenv('TYPE_PGSQL_PASSWORD') ?: '';
        $admin = (new PgsqlDriver($environment['DB_HOST'], (int) $environment['DB_PORT'], getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test', $environment['DB_USERNAME'], $environment['DB_PASSWORD']))->connect();
        $admin->exec('CREATE DATABASE ' . $database);
        $createdDatabase = true;
        $environment['DB_DATABASE'] = $database;
    }
    if ($native) {
        $environment['TYPE_APP_BINARY'] = (new Type\Build\BuildPlatform())->output($consumer . '/build/type-project');
    } else {
        unset($environment['TYPE_APP_BINARY']);
    }
    if ($onboarding && $native) {
        // 与原生验收分别用新数据库执行同一测试，证明改过的业务确实在两种入口生效。
        $developmentEnvironment = $environment;
        unset($developmentEnvironment['TYPE_APP_BINARY']);
        if ($driver === 'sqlite') {
            $developmentEnvironment['DB_SQLITE_FILE'] = $consumer . '/var/development.sqlite';
        } else {
            $admin->exec('CREATE DATABASE ' . $database . '_dev');
            $developmentEnvironment['DB_DATABASE'] = $database . '_dev';
        }
        $developmentProcess = null;
        try {
            $developmentProcess = new Process([PHP_BINARY, $consumer . '/vendor/bin/type', 'test', 'type-app.json'], $consumer, $developmentEnvironment, 2097152);
            $developmentResult = $developmentProcess->wait(45);
            expect($developmentResult->successful(), '接入业务开发入口失败：' . $developmentResult->stderr);
            echo $developmentResult->stdout;
        } finally {
            $developmentProcess?->stop();
            if ($driver !== 'sqlite') {
                $admin->exec('DROP DATABASE ' . $database . '_dev');
            }
        }
    }
    $testCommand = $onboarding ? [PHP_BINARY, $consumer . '/vendor/bin/type', 'test', 'type-app.json'] : [PHP_BINARY, $consumer . '/tests/smoke.php'];
    $process = new Process($testCommand, $consumer, $environment, 2097152);
    try {
        $result = $process->wait(30);
        echo $result->stdout;
        expect($result->successful(), '应用模板公开行为失败：' . $result->stderr);
    } finally {
        $process->stop();
    }
    if ($packageDeployment) {
        $packageEnvironment = getenv();
        $packageEnvironment['TYPE_PACKAGE_PROJECT'] = $consumer;
        $packageEnvironment['TYPE_PACKAGE_DRIVER'] = $driver;
        $packageEnvironment['TYPE_TEMPLATE_EXPECTED_MESSAGE'] = $marker;
        $packaging = new Process([PHP_BINARY, $root . '/tests/native-package.php', $environment['TYPE_APP_BINARY'], '--archive'], $root, $packageEnvironment, 2097152);
        try {
            // 覆盖 native-package.php 的打包、业务核对以及 zip/tar.gz 两轮归档。
            $packaged = $packaging->wait(360);
            $secrets = array_values(array_filter([$packageEnvironment['TYPE_MYSQL_PASSWORD'] ?? '', $packageEnvironment['TYPE_PGSQL_PASSWORD'] ?? ''], static fn (string $secret): bool => $secret !== ''));
            file_put_contents($consumer . '/package.log', str_replace($secrets, '<REDACTED>', $packaged->stdout . $packaged->stderr));
            expect($packaged->successful(), '独立模板发布和搬迁验收失败，见：' . $consumer . '/package.log');
            echo $packaged->stdout;
        } finally {
            $packaging->stop();
        }
    }
    file_put_contents($consumer . '/verification.json', json_encode(['driver' => $driver, 'native' => $native, 'remote' => $remote, 'onboarding' => $onboarding,
        'package-verified' => $packageDeployment, 'business-message' => $onboarding ? $marker : null, 'production-packages' => $packages,
        'build-id' => $native ? $manifest['build-id'] : null, 'artifact-sha256' => $native ? hash_file('sha256', $artifact) : null,
        'compiled-source-count' => $native ? count($compiledSources) : null,
        'verified-splits' => $expected, 'checks' => ['help', 'check', 'migrations', 'authentication', 'crud', 'pagination', 'sorting', 'filtering', 'static-attributes', 'patch', 'soft-delete', 'stale-version', 'graceful-stop']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    echo '应用模板独立 ' . $driver . ' 消费验证通过：' . $consumer . "\n";
} finally {
    if ($admin !== null && $createdDatabase) {
        $admin->exec('DROP DATABASE ' . $database);
    }
}
