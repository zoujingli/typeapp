<?php

declare(strict_types=1);

// 开发教程的明确源码编辑步骤；生产应用不加载此脚本。
if (count($argv) !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "用法：php examples/catalog/setup.php <独立应用/type-app.json>\n");
    exit(1);
}
$configuration = realpath($argv[1]);
require dirname($configuration) . '/vendor/autoload.php';
$configuration = Type\Build\BuildPlatform::resolve($configuration);
$root = dirname($configuration);
$project = (new Type\Build\BuildProject())->read($configuration);
if ($project['root'] !== $root || is_dir($root . '/app/catalog')) {
    throw new RuntimeException('目录教程需要尚未创建 catalog 模块的独立应用');
}
$registry = $root . '/app/common/database/Schema.php';
$historical = file_get_contents($registry);
$manifestBefore = hash_file('sha256', $root . '/composer.json');
// 与教程手工命令相同的公开 make，完整检查失败后不能留下半个模块。
$process = new Type\Testing\Process([PHP_BINARY, $root . '/vendor/bin/type', 'make', $configuration, 'module', 'app\\catalog\\Product',
    '--table=catalog_products', '--route=/products', '--role=users', '--version=002_catalog_products', '--migration-registry=app\\common\\database\\Schema'], dirname($root));
try {
    $result = $process->wait(15);
    if (!$result->successful()) {
        throw new RuntimeException('教程脚手架失败：' . $result->stderr);
    }
} finally {
    $process->stop();
}
// 这些完整文件就是各节可阅读、可复制的业务编辑结果；不包含运行期模板机制。
foreach (['model', 'input', 'service', 'controller', 'middleware', 'database', 'runtime', 'event'] as $directory) {
    if (!is_dir($root . '/app/catalog/' . $directory)) {
        mkdir($root . '/app/catalog/' . $directory, 0755, true);
    }
    foreach (glob(__DIR__ . '/' . $directory . '/*.php') as $source) {
        copy($source, $root . '/app/catalog/' . $directory . '/' . basename($source));
    }
}
$registrySource = file_get_contents($registry);
$registration = '\app\catalog\database\CreateProduct::migration($driver)';
if (substr_count($registrySource, $registration) !== 1) {
    throw new RuntimeException('教程迁移登记位置不唯一');
}
file_put_contents($registry, str_replace($registration, $registration . ', \app\catalog\database\CreateLabels::migration($driver), \app\catalog\database\AddProductNote::migration($driver), \app\catalog\database\CreateDelivery::migration($driver), (new \Type\Orm\Outbox\Store("catalog_outbox", 250))->migration($driver, "006_catalog_outbox")', $registrySource));
$settings = json_decode(file_get_contents($configuration), true, 512, JSON_THROW_ON_ERROR);
$settings['application']['services'][] = ['id' => 'catalog.tenant', 'class' => 'app\\catalog\\middleware\\CatalogTenant'];
$settings['application']['http']['middleware']['catalog.tenant'] = 'catalog.tenant';
$settings['application']['services'][] = ['id' => 'catalog.infrastructure', 'class' => 'app\\catalog\\runtime\\Infrastructure', 'factory' => ['class' => 'app\\catalog\\runtime\\Infrastructure', 'method' => 'create']];
$settings['application']['services'][] = ['id' => 'catalog.outbox', 'class' => 'Type\\Orm\\Outbox\\Store', 'arguments' => [['value' => 'catalog_outbox'], ['value' => 250]]];
$settings['application']['services'][] = ['id' => 'catalog.audit', 'class' => 'app\\catalog\\event\\AuditChanged'];
$settings['application']['events'][] = ['class' => 'app\\catalog\\event\\ProductChanged', 'listeners' => [['service' => 'catalog.audit', 'method' => 'changed']]];
$settings['config']['files'][] = 'config/catalog.php';
copy(__DIR__ . '/config.php', $root . '/config/catalog.php');
$bootstrap = $root . '/app/common/bootstrap/Application.php';
$bootstrapSource = file_get_contents($bootstrap);
$databaseSources = "['default' => DatabaseFactory::create(\$settings, \$basePath)]";
if (substr_count($bootstrapSource, $databaseSources) !== 1) {
    throw new RuntimeException('教程需要明确的模板数据库装配位置');
}
file_put_contents($bootstrap, str_replace($databaseSources, "['default' => DatabaseFactory::create(\$settings, \$basePath), 'catalog' => DatabaseFactory::create(\$settings, \$basePath)]", $bootstrapSource));
$settings['development']['test'] = ['tests/tutorial.php'];
$settings['native-inputs'] = array_values(array_unique([...($settings['native-inputs'] ?? []), 'catalog-candidate.json']));
$profiles = json_decode(file_get_contents(__DIR__ . '/build-profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$settings['build-profiles'] = $profiles['build-profiles'];
file_put_contents($configuration, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
foreach (['CreateProduct', 'CreateLabels', 'AddProductNote', 'CreateDelivery'] as $schema) {
    (new Type\Build\SchemaCompiler())->prepare($root . '/app/catalog/database/' . $schema . '.php');
}
foreach ([
    ['command', 'app\\catalog\\command\\CatalogCommand', '--service=app\\catalog\\service\\Reliability', '--name=catalog'],
    ['job', 'app\\catalog\\job\\DeliverProduct', '--service=app\\catalog\\service\\DeliveryService', '--type=catalog.deliver', '--version=1'],
    ['task', 'app\\catalog\\task\\CatalogTask', '--service=app\\catalog\\service\\Maintenance', '--name=catalog.maintenance', '--interval=60'],
] as $arguments) {
    $make = new Type\Testing\Process([PHP_BINARY, $root . '/vendor/bin/type', 'make', $configuration, ...$arguments], dirname($root));
    try {
        $made = $make->wait(15);
        if (!$made->successful()) {
            throw new RuntimeException('教程后台脚手架失败：' . $made->stderr);
        }
    } finally {
        $make->stop();
    }
}
// Job 在生成的固定类型/版本上补充消费协议；Command、Task 保留原生成结果。
copy(__DIR__ . '/job/DeliverProduct.php', $root . '/app/catalog/job/DeliverProduct.php');
$assembled = json_decode(file_get_contents($configuration), true, 512, JSON_THROW_ON_ERROR);
foreach ($assembled['application']['commands'] as &$command) {
    if ($command['name'] === 'catalog') {
        $command['resources'] = ['catalog.infrastructure'];
    }
}
unset($command);
file_put_contents($configuration, json_encode($assembled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
copy(__DIR__ . '/test.php', $root . '/tests/catalog.php');
copy(__DIR__ . '/test-entry.php', $root . '/tests/tutorial.php');
copy(__DIR__ . '/reliability-test.php', $root . '/tests/catalog-reliability.php');
copy(__DIR__ . '/https-peer.mjs', $root . '/tests/catalog-https-peer.mjs');
$identity = [];
foreach ((new Type\Build\BuildIdentity())->files([__DIR__]) as $path => $entry) {
    $identity[substr($path, strlen(__DIR__) + 1)] = $entry['sha256'];
}
$lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$templateOrigin = json_decode(file_get_contents($root . '/.project-origin.json'), true, 512, JSON_THROW_ON_ERROR);
$packages = [];
foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
    $packages[$package['name']] = ['version' => $package['version'], 'reference' => $package['source']['reference'] ?? $package['dist']['reference'] ?? null];
}
$composerInputs = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$developmentSource = false;
foreach ($composerInputs['repositories'] ?? [] as $repository) {
    $developmentSource = $developmentSource || (is_array($repository) && ($repository['type'] ?? null) === 'path');
}
$production = (new Type\Build\SourceSet())->productionSources($root, $assembled);
$installedBytes = [];
foreach ((new Type\Build\BuildIdentity())->files([$root . '/app', ...$production['sources']]) as $path => $entry) {
    if (!str_starts_with($path, $root . '/')) {
        throw new RuntimeException('教程消费不能引用项目外生产源码');
    }
    $installedBytes[substr($path, strlen($root) + 1)] = $entry['sha256'];
}
file_put_contents($root . '/catalog-candidate.json', json_encode(['protocol' => 1, 'channel' => $developmentSource ? 'development' : 'published-inputs-unverified',
    'tutorial-sources' => $identity, 'template-source-sha256' => $templateOrigin['source-sha256'], 'composer-sha256' => $manifestBefore, 'lock-sha256' => hash_file('sha256', $root . '/composer.lock'),
    'historical-registry-sha256' => hash('sha256', $historical), 'packages' => $packages, 'installed-production-inputs' => $installedBytes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
if (hash_file('sha256', $root . '/composer.json') !== $manifestBefore) {
    throw new RuntimeException('教程不能修改已选择的消费者依赖约束');
}
echo "目录教程源码与三库迁移已准备；尚未迁移数据库或启动HTTP。\n";
