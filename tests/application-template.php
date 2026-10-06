<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';
require dirname(__DIR__) . '/tools/distribution/Batch.php';
require dirname(__DIR__) . '/tools/release/TutorialEvidence.php';

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
$tutorial = in_array('--tutorial', $argv, true);
if ($tutorial && $native) {
    expect(getenv('TYPEAPP_BUILD_PROFILE') === false || getenv('TYPEAPP_BUILD_PROFILE') === $driver, '教程构建profile必须匹配所选数据库');
    putenv('TYPEAPP_BUILD_PROFILE=' . $driver);
}
$packageDeployment = in_array('--package', $argv, true);
$baseline = in_array('--published-baseline', $argv, true);
expect(!$baseline || ($remote && !$tutorial && !$native), '历史批次基线只能验证原始公开模板PHP消费');
expect(!$buildOnly || $native, '只构建模板模式必须同时指定 --native');
expect(!$packageDeployment || ($onboarding && $native && !$buildOnly), '接入发布验收要求 --onboarding --native --package');
$consumer = $root . '/build/' . ($tutorial ? 'tutorial space ' : 'template-') . $driver . '-' . bin2hex(random_bytes(6));
$consumerReceipt = getenv('TYPE_TEMPLATE_CONSUMER_RECEIPT');
if ($consumerReceipt !== false) {
    expect(is_dir(dirname($consumerReceipt)) && file_put_contents($consumerReceipt, $consumer) === strlen($consumer), '无法登记待回收消费者目录');
}
$template = getenv('TYPE_TEMPLATE_SOURCE') ?: $root . '/templates/type-project';
$tutorialRoot = getenv('TYPE_TEMPLATE_TUTORIAL_DIRECTORY') ?: $root . '/examples/catalog';
$componentRoot = getenv('TYPE_TEMPLATE_COMPONENT_DIRECTORY');
$candidateFile = getenv('TYPE_TEMPLATE_CANDIDATE_MANIFEST');
$candidate = null;
if ($candidateFile !== false) {
    expect(!$remote && $componentRoot !== false, '固定候选不能冒充公开消费或缺少组件快照');
    $candidate = json_decode(file_get_contents($candidateFile), true, 512, JSON_THROW_ON_ERROR);
    expect(preg_match('/^[a-f0-9]{40}$/D', $candidate['source'] ?? '') === 1, '候选缺少固定源码身份');
    TypeApp\Release\TutorialEvidence::snapshot($template, $candidate['template']['files'] ?? []);
    if ($tutorial) {
        TypeApp\Release\TutorialEvidence::snapshot($tutorialRoot, $candidate['tutorial']['files'] ?? []);
    }
}
if ($remote) {
    // 配置脚本执行前，先证明模板检出与完整组件批次均来自同一固定源码。
    $source = getenv('TYPE_TEMPLATE_BATCH_SOURCE') ?: GitProcess::output(['git', 'rev-parse', 'HEAD'], $root);
    $mapping = json_decode(GitProcess::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    $reportDirectory = getenv('TYPE_TEMPLATE_REPORT_DIRECTORY') ?: $root . '/build/distribution';
    $batch = json_decode(file_get_contents($reportDirectory . '/batch-result.json'), true, 512, JSON_THROW_ON_ERROR);
    Batch::verifyReport($root, $source, $batch, $mapping);
    $templateSource = getenv('TYPE_TEMPLATE_SOURCE');
    expect(is_string($templateSource) && $templateSource !== '' && is_dir($templateSource), '远端模板消费需要显式 TYPE_TEMPLATE_SOURCE 检出目录');
    $template = Type\Build\BuildPlatform::resolve($templateSource);
    $templateReportPath = $reportDirectory . '/template.json';
    expect(is_file($templateReportPath), '远端模板消费需要分发与克隆验证报告');
    $templateReport = json_decode(file_get_contents($templateReportPath), true, 512, JSON_THROW_ON_ERROR);
    expect(in_array($templateReport['status'] ?? '', ['published', 'already-current'], true)
        && ($templateReport['publish-status'] ?? null) === $templateReport['status']
        && ($templateReport['checkout-verified'] ?? null) === true, '模板分发与克隆验证尚未全部通过');
    $templateTree = GitProcess::output(['git', 'rev-parse', $source . ':templates/type-project'], $root);
    $templateSplit = GitProcess::output(['git', 'subtree', 'split', '--prefix=templates/type-project', '--ignore-joins', $source], $root);
    foreach (['source' => $source, 'framework-batch' => $batch['id'], 'batch' => hash('sha256', $source . ':' . $templateTree . ':' . $batch['mode'] . ':' . $batch['version']),
        'package' => 'zoujingli/type-project', 'repository' => 'zoujingli/type-project', 'mode' => $batch['mode'], 'version' => $batch['version'],
        'reference' => $batch['mode'] === 'tag' ? 'refs/tags/' . $batch['version'] : 'refs/heads/main', 'tree' => $templateTree, 'split' => $templateSplit] as $field => $expectedValue) {
        expect(($templateReport[$field] ?? null) === $expectedValue, '模板报告与固定批次不一致：' . $field);
    }
    if (getenv('TYPE_TEMPLATE_PACKAGIST') === '1') {
        expect($batch['mode'] === 'tag', 'Packagist模板验收要求准确版本tag');
        $expectedFiles = [];
        foreach (explode("\n", GitProcess::output(['git', 'ls-tree', '-r', $source . ':templates/type-project'], $root)) as $entry) {
            [$metadata, $relative] = explode("\t", $entry, 2);
            $blob = explode(' ', $metadata)[2];
            expect(is_file($template . '/' . $relative) && !is_link($template . '/' . $relative)
                && GitProcess::output(['git', 'hash-object', '--no-filters', $template . '/' . $relative], $root) === $blob, 'Packagist模板文件与版本树不一致：' . $relative);
            $expectedFiles[] = $relative;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($template, FilesystemIterator::SKIP_DOTS)) as $entry) {
            expect(!$entry->isLink() && in_array(str_replace('\\', '/', substr($entry->getPathname(), strlen($template) + 1)), $expectedFiles, true), 'Packagist模板包含版本外文件');
        }
    } else {
        expect(
            realpath(GitProcess::output(['git', 'rev-parse', '--show-toplevel'], $template)) === realpath($template)
        && GitProcess::output(['git', 'rev-parse', 'HEAD'], $template) === $templateSplit
        && GitProcess::output(['git', 'rev-parse', 'HEAD^{tree}'], $template) === $templateTree
        && GitProcess::output(['git', 'status', '--porcelain', '--untracked-files=all', '--ignored'], $template) === '',
            '模板检出必须与已验证提交一致且没有额外或修改的文件'
        );
    }
}
if ($tutorial) {
    echo successful([PHP_BINARY, $tutorialRoot . '/create.php', $consumer, $driver, $template, $root], $root);
} elseif ($onboarding) {
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
$configuredComposerHash = hash_file('sha256', $consumer . '/composer.json');
expect(!$remote || ($composer['repositories'] ?? []) === [], '公开模板必须从默认 Packagist 原样安装依赖');
foreach ($composer['repositories'] ?? [] as $repository) {
    expect($repository['type'] === 'git' && str_starts_with($repository['url'], 'https://github.com/zoujingli/'), '原始模板包含开发主仓路径');
}
// 公开消费原样安装；开发候选仅显式替换来源，不修补模板自身的版本约束。
$componentNames = [];
$pending = array_keys(array_merge($composer['require'], $composer['require-dev']));
while ($pending !== []) {
    $packageName = array_pop($pending);
    if (!str_starts_with($packageName, 'zoujingli/type-') || isset($componentNames[$packageName])) {
        continue;
    }
    $name = substr($packageName, strlen('zoujingli/'));
    $componentNames[$packageName] = $name;
    $metadata = $remote
        ? json_decode(GitProcess::output(['git', 'show', $source . ':plugin/' . $name . '/composer.json'], $root), true, 512, JSON_THROW_ON_ERROR)
        : json_decode(file_get_contents(($componentRoot === false ? $root . '/plugin' : $componentRoot) . '/' . $name . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $pending = [...$pending, ...array_keys($metadata['require'] ?? [])];
}
$expected = [];
if (!$remote) {
    if ($componentRoot !== false) {
        $componentRoot = Type\Build\BuildPlatform::resolve($componentRoot);
        expect(str_starts_with($componentRoot, $root . '/build/') && is_dir($componentRoot), '候选组件必须来自主仓build下的独立快照');
    }
    $composer['repositories'] = [];
    foreach ($componentNames as $packageName => $name) {
        if ($candidate !== null) {
            expect(isset($candidate['components'][$name]['split']), '固定候选缺少模板需要的组件：' . $name);
            $expected[$name] = $candidate['components'][$name]['split'];
        }
        $constraint = $composer['require'][$packageName] ?? $composer['require-dev'][$packageName] ?? null;
        expect(
            is_string($constraint)
            && preg_match('/^(?:[0-9]+\.[0-9]+\.x-dev|v?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?)$/D', $constraint) === 1,
            '候选模板需要显式声明准确组件版本：' . $packageName
        );
        $relativePackage = $componentRoot === false ? 'plugin/' . $name : substr($componentRoot, strlen($root) + 1) . '/' . $name;
        $composer['repositories'][] = ['type' => 'path', 'url' => '../../' . $relativePackage, 'options' => ['symlink' => false, 'versions' => [$packageName => $constraint]]];
    }
} else {
    foreach ($componentNames as $name) {
        expect(isset($mapping['packages'][$name]), '模板依赖不属于固定分发映射：' . $name);
        $item = $batch['items'][$name];
        $expected[$name] = $item['split'];
        if ($batch['mode'] === 'tag' && !$baseline) {
            $constraint = $composer['require'][$item['package']] ?? $composer['require-dev'][$item['package']] ?? null;
            expect(
                is_string($constraint) && ltrim($constraint, 'v') === ltrim($batch['version'], 'v'),
                '公开模板未在 tag 前固定完整组件批次：' . $item['package']
            );
        }
    }
}
if (!$remote) {
    $composer['config']['platform'] = [];
    foreach (['mysql', 'pgsql', 'sqlite'] as $name) {
        if ($name !== $driver) {
            $composer['config']['platform']['ext-pdo_' . $name] = false;
        }
    }
    file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
$consumptionMode = $remote ? ($batch['mode'] === 'tag' ? 'packagist-tag' : 'public-development-branch') : ($candidate !== null ? 'fixed-candidate' : 'development-path');
echo '独立安装模板依赖（' . $consumptionMode . '）：' . $consumer . "\n";
$composerPhar = getenv('TYPE_COMPOSER_PHAR');
$composerCommand = [getenv('COMPOSER_BINARY') ?: 'composer'];
if ($composerPhar !== false) {
    expect(is_file($composerPhar), '显式Composer PHAR不存在');
    $composerCommand = [PHP_BINARY, Type\Build\BuildPlatform::resolve($composerPhar)];
}
successful([...$composerCommand, 'install', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--no-progress'], $consumer);
expect(!$remote || hash_file('sha256', $consumer . '/composer.json') === $configuredComposerHash, '公开消费修改了模板依赖声明');
$installed = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$packages = array_column($installed['packages'], 'name');
expect(!in_array('zoujingli/type-build', $packages, true) && !in_array('zoujingli/type-testing', $packages, true), '构建或测试工具进入了生产依赖');
foreach (['mysql', 'pgsql', 'sqlite'] as $name) {
    expect(in_array('zoujingli/type-orm-' . $name, $packages, true) === ($name === $driver), '模板安装了未选择的数据库驱动');
}
foreach (array_merge($installed['packages'], $installed['packages-dev']) as $package) {
    if ($candidate !== null && str_starts_with($package['name'], 'zoujingli/type-')) {
        $name = substr($package['name'], strlen('zoujingli/'));
        expect(isset($candidate['components'][$name]), '安装出现候选之外的组件');
        TypeApp\Release\TutorialEvidence::snapshot($consumer . '/vendor/' . $package['name'], $candidate['components'][$name]['files']);
    }
    if ($remote && str_starts_with($package['name'], 'zoujingli/type-')) {
        expect($package['source']['reference'] === $expected[substr($package['name'], 10)], '模板安装提交与分发批次不同');
        expect($batch['mode'] !== 'tag' || ltrim($package['version'], 'v') === ltrim($batch['version'], 'v'), '模板没有安装要求的组件版本');
    }
}
$marker = 'onboarding-' . bin2hex(random_bytes(8));
if ($onboarding) {
    $homeFile = $consumer . '/app/controller/HomeController.php';
    $homeSource = file_get_contents($homeFile);
    $changedSource = str_replace('Type 业务应用模板已启动。', $marker, $homeSource, $replacements);
    expect($replacements === 1 && file_put_contents($homeFile, $changedSource) === strlen($changedSource), '用户业务修改没有落入唯一控制器');
}
if ($tutorial) {
    // 用户示例修改完成后再封存源码，随后准备与编译必须使用同一组字节。
    echo successful([PHP_BINARY, $tutorialRoot . '/setup.php', $consumer . '/type-app.json'], sys_get_temp_dir());
}
if ($onboarding) {
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
    $declarationSources = $buildReport['original-sources'];
    $declarationMetadata = $buildReport['declaration-metadata'];
    expect(
        ($declarationMetadata['protocol'] ?? null) === Type\Build\BuildIdentity::GENERATORS['declarations']
        && ($declarationMetadata['generators'] ?? null) === Type\Build\BuildIdentity::GENERATORS
        && $declarationSources !== [] && array_values(array_unique($declarationSources)) === $declarationSources,
        '独立模板缺少当前声明协议和原始源码列表'
    );
    $declarationHashes = [];
    foreach ($declarationSources as $sourcePath) {
        expect(isset($originalSources[$sourcePath]) && is_file($sourcePath)
            && $originalSources[$sourcePath]['sha256'] === hash_file('sha256', $sourcePath), '声明原始源码与构建身份不一致');
        $declarationHashes[] = $originalSources[$sourcePath]['sha256'];
    }
    expect(Type\Build\BuildIdentity::digest(['sources' => $declarationHashes, 'generation' => $declarationMetadata]) === $manifest['declaration-generation']
        && $buildReport['declaration-generation'] === $manifest['declaration-generation'], '独立模板声明代次不能由当前真实输入回算');
    $generatedSources = [];
    foreach ($declarationMetadata['files'] as $relative => $sha) {
        $path = $consumer . '/build/compiler/' . $relative;
        expect(!str_contains($relative, '..') && !str_starts_with($relative, '/') && is_file($path)
            && hash_file('sha256', $path) === $sha, '声明生成文件与代次摘要不一致');
        $generatedSources['generated:' . $relative] = $path;
    }
    expect(count($compiledSources) === count(array_unique($compiledSources)), '独立模板编译源码存在重复');
    foreach ($declarationMetadata['replacements'] as $original => $replacement) {
        expect(
            preg_match('/^source:([0-9]+)$/D', $original, $match) === 1 && isset($declarationSources[(int) $match[1]])
            && isset($generatedSources[$replacement]) && in_array($generatedSources[$replacement], $compiledSources, true),
            '源码替换未指向实际编译的声明产物'
        );
    }
    foreach ($declarationSources as $index => $sourcePath) {
        $replacement = $declarationMetadata['replacements']['source:' . $index] ?? null;
        expect(
            in_array($replacement === null ? $sourcePath : ($generatedSources[$replacement] ?? ''), $compiledSources, true),
            '独立模板遗漏已登记生产源码：' . $sourcePath
        );
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer . '/app', FilesystemIterator::SKIP_DOTS)) as $sourceFile) {
        if ($sourceFile->isFile() && $sourceFile->getExtension() === 'php') {
            $sourcePath = Type\Build\BuildPlatform::path($sourceFile->getPathname());
            expect(in_array($sourcePath, $declarationSources, true), '独立模板遗漏业务源码：' . $sourceFile->getFilename());
        }
    }
}
if ($buildOnly) {
    $prepared = ['driver' => $driver, 'consumer' => $consumer, 'consumer-relative' => substr($consumer, strlen($root) + 1), 'native' => true, 'remote' => $remote,
        'consumption-mode' => $consumptionMode, 'version' => $remote ? $batch['version'] : null, 'configured-composer-sha256' => $configuredComposerHash,
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
    if ($native) {
        // 原生产物核对实际加载路径，不能继承构建控制器或其他产物的模块配置。
        // 开发入口已用原 CLI 环境验证，此处只绑定本产物声明的运行配置。
        $runtimeIni = $buildReport['runtime-profile']['ini'] ?? null;
        expect(is_string($runtimeIni) && is_file($runtimeIni) && is_dir(dirname($runtimeIni) . '/php.d'), '模板原生产物缺少独立运行配置');
        $environment['PHPRC'] = $runtimeIni;
        $environment['PHP_INI_SCAN_DIR'] = dirname($runtimeIni) . '/php.d';
    }
    $testCommand = ($onboarding || $tutorial) ? [PHP_BINARY, $consumer . '/vendor/bin/type', 'test', 'type-app.json'] : [PHP_BINARY, $consumer . '/tests/smoke.php'];
    $process = new Process($testCommand, $consumer, $environment, 2097152);
    try {
        $result = $process->wait($tutorial ? 120 : 30);
        echo $result->stdout;
        expect($result->successful(), '应用模板公开行为失败 ' . json_encode([
            'exit-code' => $result->exitCode, 'timed-out' => $result->timedOut,
            'output-exceeded' => $result->outputExceeded, 'signal' => $result->signal,
        ], JSON_THROW_ON_ERROR) . '：' . $result->stderr);
    } finally {
        $process->stop();
    }
    if ($packageDeployment) {
        $packageEnvironment = getenv();
        $packageEnvironment['TYPE_PACKAGE_PROJECT'] = $consumer;
        $packageEnvironment['TYPE_PACKAGE_DRIVER'] = $driver;
        $packageEnvironment['TYPE_TEMPLATE_EXPECTED_MESSAGE'] = $marker;
        $packageEnvironment['TYPE_PACKAGE_TUTORIAL'] = $tutorial ? '1' : '0';
        $single = ($manifest['runtime-linkage'] ?? null) === 'static';
        expect(!$tutorial || ($single && ($manifest['profile']['database'] ?? null) === $driver), '教程部署验收仅接受匹配数据库profile的静态单程序');
        $packageCommand = $single
            ? [PHP_BINARY, $root . '/tests/native-single-program.php', $environment['TYPE_APP_BINARY']]
            : [PHP_BINARY, $root . '/tests/native-package.php', $environment['TYPE_APP_BINARY'], '--archive'];
        $packaging = new Process($packageCommand, $root, $packageEnvironment, 2097152);
        try {
            $packaged = $packaging->wait(180);
            $secrets = array_values(array_filter([$packageEnvironment['TYPE_MYSQL_PASSWORD'] ?? '', $packageEnvironment['TYPE_PGSQL_PASSWORD'] ?? ''], static fn (string $secret): bool => $secret !== ''));
            file_put_contents($consumer . '/package.log', str_replace($secrets, '<REDACTED>', $packaged->stdout . $packaged->stderr));
            expect($packaged->successful(), '独立模板发布和搬迁验收失败，见：' . $consumer . '/package.log'
                . (is_file($consumer . '/package.log') ? "\n" . file_get_contents($consumer . '/package.log') : ''));
            echo $packaged->stdout;
            if ($tutorial) {
                expect(preg_match('#单程序真实业务验收通过：([^\r\n]+/verification\.json)#u', $packaged->stdout, $match) === 1, '教程缺少单程序部署回执');
                $deployment = Type\Build\BuildPlatform::resolve($match[1]);
                expect(is_string($deployment) && str_starts_with($deployment, $root . '/build/single program-'), '教程部署回执不属于本轮隔离范围');
                copy($deployment, $consumer . '/deployment.json');
                expect(copy(dirname($deployment) . '/tutorial.log', $consumer . '/deployment-tutorial.log'), '无法保全隔离教程公开断言日志');
            }
        } finally {
            $packaging->stop();
        }
    }
    file_put_contents($consumer . '/verification.json', json_encode(['status' => 'passed', 'driver' => $driver, 'native' => $native, 'remote' => $remote, 'onboarding' => $onboarding, 'tutorial' => $tutorial,
        'consumption-mode' => $consumptionMode, 'version' => $remote ? $batch['version'] : null, 'configured-composer-sha256' => $configuredComposerHash,
        'package-verified' => $packageDeployment, 'business-message' => $onboarding ? $marker : null, 'production-packages' => $packages,
        'build-id' => $native ? $manifest['build-id'] : null, 'artifact-sha256' => $native ? hash_file('sha256', $artifact) : null,
        'delivery' => $native && ($manifest['runtime-linkage'] ?? null) === 'static' ? 'single-executable' : ($native ? 'native-directory' : 'php'),
        'profile' => $native ? ($manifest['profile']['database'] ?? null) : null,
        'compiled-source-count' => $native ? count($compiledSources) : null,
        'verified-splits' => $expected, 'checks' => ['help', 'check', 'migrations', 'authentication', 'crud', 'pagination', 'sorting', 'filtering', 'static-attributes', 'patch', 'soft-delete', 'stale-version', 'graceful-stop']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    echo '应用模板独立 ' . $driver . ' 消费验证通过：' . $consumer . "\n";
} finally {
    if ($admin !== null && $createdDatabase) {
        $admin->exec('DROP DATABASE ' . $database);
    }
}
