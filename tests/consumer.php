<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$remoteRuntime = $argv[1] ?? null;
if ($remoteRuntime !== null) {
    expect($remoteRuntime === 'https://github.com/zoujingli/type-runtime.git', '远程消费仅接受已登记的公开插件仓库');
}
$consumer = $root . '/build/consumer-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0755, true), '无法创建独立消费目录');
$configuration = [
    'name' => 'type-consumer',
    'entry' => 'app/main.php',
    'output' => 'build/native/type-app',
    'build-directory' => 'build/native/compiler',
];
$composer = [
    'name' => 'type-tests/consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-runtime' => '~1.0.0@dev'],
    'require-dev' => [
        'zoujingli/type-build' => '~1.0.0@dev',
        'swoole/typephp' => '0.9.0',
        'swoole/phpx' => '2.9.0',
    ],
    'repositories' => [
        ['type' => 'path', 'url' => $root . '/plugin/type-runtime', 'options' => ['symlink' => false, 'versions' => ['zoujingli/type-runtime' => '1.0.x-dev']]],
        ['type' => 'path', 'url' => $root . '/plugin/type-build', 'options' => ['symlink' => false, 'versions' => ['zoujingli/type-build' => '1.0.x-dev']]],
    ],
    'minimum-stability' => 'dev', 'prefer-stable' => true,
    'config' => ['allow-plugins' => false, 'vendor-dir' => 'dependencies', 'bin-dir' => 'commands'],
];
if ($remoteRuntime !== null) {
    $composer['require']['zoujingli/type-runtime'] = 'dev-main';
    $composer['repositories'][0] = ['type' => 'git', 'url' => $remoteRuntime];
}
foreach (['composer.json' => $composer, 'type-app.json' => $configuration] as $file => $value) {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    expect(file_put_contents($consumer . '/' . $file, $json) === strlen($json), '无法写入消费项目配置');
}
expect(copy($root . '/examples/native-command.php', $consumer . '/app/main.php'), '无法复制消费示例');
expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法复制工具链约束');
$composerBinary = getenv('COMPOSER_BINARY') ?: 'composer';
successful([$composerBinary, 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'], $consumer);
expect(!is_link($consumer . '/dependencies/zoujingli/type-runtime'), '独立消费不能依赖主仓插件软链接');
expect(!is_link($consumer . '/dependencies/zoujingli/type-build'), '独立消费必须安装自己的构建工具');

// 使用消费项目自己的 Composer 命令代理，原生 SDK 可以由构建环境统一提供。
successful([PHP_BINARY, $consumer . '/commands/type', $consumer . '/type-app.json'], $consumer);
$report = json_decode(file_get_contents($consumer . '/build/native/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect(array_keys($report['production-packages']) === ['zoujingli/type-runtime'], '独立消费混入了开发依赖');
foreach ($report['sources'] as $source) {
    expect(str_starts_with($source, $consumer . '/'), '独立消费仍引用主仓生产源码');
}
$greetingOutput = successful([PHP_BINARY, $root . '/tests/native.php', $consumer . '/build/native/type-app']);
echo $greetingOutput;
$summarize = static fn (array $build): array => array_intersect_key($build, array_flip([
    'build-id', 'sha256', 'typephp', 'typephp-reference', 'phpx', 'phpx-reference',
    'production-packages', 'php', 'zts', 'architecture', 'cache',
]));
$verification = ['greeting' => $summarize($report) + ['output' => $greetingOutput]];
if ($remoteRuntime === null) {
    // 修改独立应用自己的业务；只使用官方 PHP 定义具有真实等价行为的 std API。
    // 容器和高精度类型的占位定义不能作为 PHP 开发路径的运行实现。
    $languageSource = <<<'PHP'
<?php
declare(strict_types=1);

use Type\Runtime\Arguments;

function advance(int &$number): void
{
    $number++;
}

function main(int $argc, array $argv): void
{
    try {
        $arguments = new Arguments($argv, ['value'], []);
        $direct = $arguments->integer('value', 40, 0, 100);
        advance($direct);
        $reference = \std::any($direct);
        $increment = static function (int &$value): void { $value++; };
        $increment(\std::ref($reference));
        $double = static fn (int $value): int => $value * 2;
        $restored = \std::object(\std::any($arguments), Arguments::class);
        echo json_encode([
            'direct' => $direct,
            'reference' => $reference,
            'callback' => $double((int) $reference),
            'fraction' => (float) $direct / 2,
            'provided' => $restored->has('value'),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), "\n";
    } catch (InvalidArgumentException $error) {
        fwrite(STDERR, '参数错误：' . $error->getMessage() . PHP_EOL);
        exit(64);
    }
}
PHP;
    expect(file_put_contents($consumer . '/app/main.php', $languageSource . "\n") === strlen($languageSource) + 1, '无法写入独立业务变更');
    successful([PHP_BINARY, $consumer . '/commands/type', $consumer . '/type-app.json'], $consumer);
    $languageReport = json_decode(file_get_contents($consumer . '/build/native/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($languageReport['build-id'] !== $report['build-id'], '业务变更后仍复用旧构建身份');
    expect(array_keys($languageReport['production-packages']) === ['zoujingli/type-runtime'], '新版语言消费者漏编或混入开发依赖');
    $development = 'require $argv[1]; require $argv[2]; require $argv[3]; main($argc - 3, array_slice($argv, 3));';
    $languageCases = [
        [[], 0, "{\"direct\":41,\"reference\":42,\"callback\":84,\"fraction\":20.5,\"provided\":false}\n"],
        [['--value=1'], 0, "{\"direct\":2,\"reference\":3,\"callback\":6,\"fraction\":1.0,\"provided\":true}\n"],
        [['--value=101'], 64, ''],
    ];
    foreach ($languageCases as [$languageArguments, $expectedStatus, $expectedOutput]) {
        $phpResult = execute([PHP_BINARY, '-r', $development, $consumer . '/dependencies/autoload.php',
            $consumer . '/dependencies/swoole/typephp/src/polyfills.php', $consumer . '/app/main.php', ...$languageArguments], $consumer);
        $nativeResult = execute([...nativeCommand($consumer . '/build/native/type-app'), ...$languageArguments], $consumer);
        expect($phpResult === $nativeResult, '新版标量、引用、回调或对象恢复的PHP/AOT结果不同：' . json_encode([$phpResult, $nativeResult]));
        expect($nativeResult[0] === $expectedStatus && $nativeResult[1] === $expectedOutput, '新版语言业务结果错误');
        expect($expectedStatus === 0 ? $nativeResult[2] === '' : str_contains($nativeResult[2], '选项必须是范围内的整数'), '新版语言业务错误边界不符');
    }
    $verification['language'] = $summarize($languageReport) + ['php-aot-cases' => count($languageCases)];
    echo "新版标量、直接引用、Closure引用及对象恢复的PHP/AOT对照通过。\n";
}
$verificationJson = json_encode($verification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
expect(file_put_contents($consumer . '/verification.json', $verificationJson) === strlen($verificationJson), '无法保存独立消费者验证身份');
echo '独立消费安装与编译通过：' . $consumer . PHP_EOL;
if ($remoteRuntime !== null) {
    $installed = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $runtime = array_values(array_filter($installed['packages'], static fn (array $package): bool => $package['name'] === 'zoujingli/type-runtime'))[0];
    echo '已消费分发子仓源码：' . $runtime['source']['reference'] . PHP_EOL;
    $distributionFile = $root . '/build/distribution/type-runtime.json';
    if (is_file($distributionFile)) {
        $distribution = json_decode(file_get_contents($distributionFile), true, 512, JSON_THROW_ON_ERROR);
        expect($runtime['source']['reference'] === $distribution['split-commit'], '消费安装结果与分发提交不同');
    }
}
