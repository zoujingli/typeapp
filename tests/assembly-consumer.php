<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$consumer = $root . '/build/assembly-consumer-' . bin2hex(random_bytes(4));
expect(mkdir($consumer . '/app', 0755, true), '无法创建装配消费项目');
$repositories = [];
foreach (['type-runtime', 'type-core', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$repositories[] = ['type' => 'path', 'url' => $root . '/tests/fixtures/optional-command',
    'options' => ['symlink' => false, 'versions' => ['type-tests/optional-command' => '1.0.x-dev']]];
$composer = [
    'name' => 'type-tests/assembly-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-core' => '~1.0.0@dev', 'type-tests/optional-command' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true,
    'config' => ['allow-plugins' => false],
];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$configuration = json_decode(file_get_contents($root . '/docs/build-config/type-commands.json'), true, 512, JSON_THROW_ON_ERROR);
unset($configuration['project-root']);
$configuration['sources'] = ['app'];
$configuration['application']['enabled'] = ['type-tests/assembly-consumer'];
file_put_contents($consumer . '/application.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
expect(copy($root . '/examples/commands/Commands.php', $consumer . '/app/Commands.php'), '无法准备消费命令');
expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法准备工具链约束');
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$builder = [PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'];
successful($builder, $consumer);
$binary = $consumer . '/build/commands/type-app';
echo successful([PHP_BINARY, $root . '/tests/assembled-native.php', $binary]);
$report = json_decode(file_get_contents($binary . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect(isset($report['production-packages']['type-tests/optional-command'])
    && in_array('type-tests/optional-command', $report['assembly']['disabled-modules'], true), '已安装可选插件没有经过编译或禁用记录不正确');
foreach (['psr/http-message', 'psr/http-factory', 'psr/http-server-handler', 'psr/http-server-middleware'] as $package) {
    expect(($report['source-sets'][$package]['origin'] ?? '') === 'package-import:zoujingli/type-core', '独立 core 消费未携带自己的 PSR 编译适配：' . $package);
}
foreach ($report['sources'] as $source) {
    expect(str_starts_with($source, $consumer . '/'), '装配消费仍依赖主仓业务源码');
}
$configuration['application']['enabled'][] = 'type-tests/optional-command';
file_put_contents($consumer . '/application.json', json_encode($configuration, JSON_THROW_ON_ERROR));
successful($builder, $consumer);
$previous = getenv('TYPE_APP_NAME');
putenv('TYPE_APP_NAME');
try {
    expect(successful([$binary, 'greet']) === "你好，typeapp！\n", '启用的非目标服务被提前启动');
    expect(str_contains(successful([$binary, 'help']), 'unavailable'), '启用插件的命令未注册');
    expect(successful([$binary, 'check']) === "离线配置检查通过。\n", '离线检查触发了外部服务构造');
    [$status, $stdout, $stderr] = execute([$binary, 'unavailable']);
    expect($status === 70 && $stdout === '' && str_contains($stderr, '可选外部服务不可用'), '目标命令没有触发自己的服务构造');
} finally {
    putenv($previous === false ? 'TYPE_APP_NAME' : 'TYPE_APP_NAME=' . $previous);
}
echo "独立安装、禁用模块仍编译、按目标懒构造验证通过。\n";
