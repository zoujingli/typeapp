<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$consumer = $root . '/build/imports-consumer space-' . bin2hex(random_bytes(4));
expect(mkdir($consumer . '/app', 0755, true), '无法创建适配消费项目');
$composer = ['name' => 'type-tests/import-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['type-tests/imported-library' => '1.0.0', 'psr/log' => '3.0.2'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.3', 'swoole/phpx' => '2.9.2'],
    'autoload' => ['psr-4' => ['Consumer\\' => 'app/']],
    'repositories' => [
        ['type' => 'path', 'url' => $root . '/plugin/type-build', 'options' => ['symlink' => false, 'versions' => ['zoujingli/type-build' => '1.0.x-dev']]],
        ['type' => 'path', 'url' => $root . '/plugin/type-runtime', 'options' => ['symlink' => false, 'versions' => ['zoujingli/type-runtime' => '1.0.x-dev']]],
        ['type' => 'path', 'url' => $root . '/tests/fixtures/imported-library', 'options' => ['symlink' => false, 'versions' => ['type-tests/imported-library' => '1.0.0']]],
    ], 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$settings = ['name' => 'import-consumer', 'entry' => 'app/main.php', 'sources' => ['app'], 'output' => 'build/native/type-app', 'build-directory' => 'build/native/compiler',
    'imports' => [
        'type-tests/imported-library' => ['protocol' => 1, 'version' => '1.0.0', 'sources' => ['.'],
            'exclusions' => [['path' => 'tests', 'reason' => '库自身的非生产测试']],
            'resources' => [['source' => 'assets/message.txt', 'target' => 'fixture/message.txt']]],
        'psr/log' => ['protocol' => 1, 'version' => '3.0.2', 'sources' => ['src']],
    ]];
$configFile = $consumer . '/application.json';
file_put_contents($configFile, json_encode($settings, JSON_THROW_ON_ERROR));
expect(copy($root . '/examples/imported-command.php', $consumer . '/app/main.php'), '无法准备适配命令');
expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法准备工具链');
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
expect(is_dir($consumer . '/vendor/zoujingli/type-runtime') && !is_link($consumer . '/vendor/zoujingli/type-runtime'), '构建工具的私有传递依赖没有独立安装');
$build = [PHP_BINARY, $consumer . '/vendor/bin/type', $configFile];
successful($build, $consumer);
$binary = $consumer . '/build/native/type-app';
$report = json_decode(file_get_contents($binary . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect(successful([$binary, $report['resources'][0]['output']]) === "PSR-4/classmap/files/7/info/资源内容\n", '适配后的第三方代码、stub 或资源行为不符');
expect(isset($report['production-packages']['psr/log']) && count($report['source-sets']['type-tests/imported-library']['exclusions']) === 1, '来源或排除原因未记录');
expect(!isset($report['production-packages']['zoujingli/type-runtime']), '仅供构建工具使用的开发依赖混入了应用生产闭包');
$originalBinary = hash_file('sha256', $binary);
$invalid = [];
$bad = $settings;
$bad['imports']['psr/log']['version'] = '0.0.0';
$invalid[] = [$bad, '适配版本'];
$bad = $settings;
$bad['imports']['type-tests/imported-library']['sources'] = ['src', 'native'];
$invalid[] = [$bad, '遗漏生产自动加载源码'];
$bad = $settings;
$bad['imports']['psr/log']['protocol'] = 99;
$invalid[] = [$bad, '缺少支持的编译声明'];
$bad = $settings;
$bad['imports']['type-tests/imported-library']['resources'][0]['target'] = '../escape';
$invalid[] = [$bad, '资源声明'];
foreach ($invalid as [$value, $message]) {
    file_put_contents($configFile, json_encode($value, JSON_THROW_ON_ERROR));
    [$status, , $stderr] = execute($build, $consumer);
    expect($status !== 0 && str_contains($stderr, $message), '错误适配没有明确拒绝：' . $stderr);
    expect(hash_file('sha256', $binary) === $originalBinary, '失败适配替换了原有产物');
}
file_put_contents($configFile, json_encode($settings, JSON_THROW_ON_ERROR));
$originalComposer = $composer;
$rootOmissions = [
    ['psr-4', ['ConsumerExtra\\' => 'extra/'], 'extra/Value.php', "<?php\nnamespace ConsumerExtra; final class Value { public function value(): int { return 17; } }\n"],
    ['classmap', ['legacy/'], 'legacy/LegacyValue.php', "<?php\nfinal class ConsumerLegacyValue {}\n"],
    ['files', ['extra-functions.php'], 'extra-functions.php', "<?php\nfunction consumerExtraValue(): int { return 19; }\n"],
];
foreach ($rootOmissions as [$kind, $declaration, $relative, $contents]) {
    $composer = $originalComposer;
    $composer['autoload'][$kind] = array_merge($composer['autoload'][$kind] ?? [], $declaration);
    $file = $consumer . '/' . $relative;
    if (!is_dir(dirname($file))) {
        expect(mkdir(dirname($file), 0755, true), '无法创建自定义业务目录');
    }
    file_put_contents($file, $contents);
    file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    [$status, , $stderr] = execute($build, $root);
    expect($status !== 0 && str_contains($stderr, '遗漏生产自动加载源码：type-tests/import-consumer') && str_contains($stderr, $relative), '根应用漏编未被拒绝：' . $stderr);
    expect(hash_file('sha256', $binary) === $originalBinary, '根应用拒绝覆盖了旧产物');
}
$composer = $originalComposer;
$composer['autoload']['psr-4']['ConsumerExtra\\'] = 'extra/';
$composer['autoload']['classmap'] = ['legacy/'];
$composer['autoload']['files'] = ['extra-functions.php'];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
$settings['sources'] = ['app', 'extra', 'legacy', 'extra-functions.php'];
file_put_contents($configFile, json_encode($settings, JSON_THROW_ON_ERROR));
$extraEntry = "<?php\ndeclare(strict_types=1);\nfunction main(): void { echo (new \\ConsumerExtra\\Value())->value(), '/', consumerExtraValue(), '/', \\Imported\\Value::class, \"\\n\"; }\n";
file_put_contents($consumer . '/app/main.php', $extraEntry);
successful($build, $root);
expect(successful([$binary]) === "17/19/Imported\\Value\n", '补齐根应用声明后的新业务没有实际执行');
$originalBinary = hash_file('sha256', $binary);
$functionsFile = $consumer . '/vendor/type-tests/imported-library/functions.php';
$originalFunctions = file_get_contents($functionsFile);
file_put_contents($functionsFile, "\nfile_put_contents(getenv('TYPE_BUILD_SENTINEL'), '不能在构建期执行');\n", FILE_APPEND);
$sentinel = $consumer . '/must-not-exist.txt';
$previous = getenv('TYPE_BUILD_SENTINEL');
putenv('TYPE_BUILD_SENTINEL=' . $sentinel);
try {
    [$status] = execute($build, $consumer);
    expect($status !== 0, '生产自动加载中的顶层执行语句未拒绝');
    expect(!file_exists($sentinel), '构建启动执行了应用的 autoload.files');
    expect(hash_file('sha256', $binary) === $originalBinary, '非法自动加载源码覆盖了原有产物');
} finally {
    putenv($previous === false ? 'TYPE_BUILD_SENTINEL' : 'TYPE_BUILD_SENTINEL=' . $previous);
    file_put_contents($functionsFile, $originalFunctions);
}
echo "实际第三方包及自定义根应用全量编译通过；PSR-4/classmap/files根应用漏编、5类错误适配和含空格消费者验证通过。\n";
