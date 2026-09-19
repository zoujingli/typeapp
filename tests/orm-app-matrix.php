<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

// 串行验收当前标准应用的全量编译、三库业务与源码不可访问的运行包。
$root = dirname(__DIR__);
$work = $root . '/build/orm-app-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700), '无法创建应用验收目录');
$drivers = isset($argv[1]) ? explode(',', $argv[1]) : ['sqlite'];
$toolRoots = ['mysql' => $argv[2] ?? '', 'pgsql' => $argv[3] ?? ''];
foreach ($drivers as $driver) {
    expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '应用验收驱动无效');
}
$configuration = json_decode(file_get_contents($root . '/docs/build-config/type-app.json'), true, 512, JSON_THROW_ON_ERROR);
$relative = substr($work, strlen($root) + 1);
$configuration['output'] = $relative . '/type-app';
$configuration['build-directory'] = $relative . '/compiler';
$swooleModule = getenv('TYPE_TEST_SWOOLE_MODULE');
if ($swooleModule !== false) {
    expect(is_file($swooleModule), '显式 Swoole 验收模块不存在');
    $configuration['runtime'][PHP_OS_FAMILY]['modules']['swoole'] = ['file' => realpath($swooleModule),
        'sha256' => hash_file('sha256', $swooleModule)];
}
file_put_contents($work . '/application.json', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo '标准应用全量编译：' . $relative . PHP_EOL;
[$status, $stdout, $stderr] = execute([PHP_BINARY, $root . '/vendor/bin/type', $work . '/application.json'], $root);
file_put_contents($work . '/build.log', $stdout . $stderr);
expect($status === 0, '标准应用编译失败，见 ' . $relative . '/build.log');
$artifact = (new Type\Build\BuildPlatform())->output($work . '/type-app');
$build = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
$installed = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$actual = array_keys($build['production-packages']);
$expected = array_column($installed['packages'], 'name');
sort($actual);
sort($expected);
expect($actual === $expected, '标准应用未编译全部实际生产依赖');
$results = [];
foreach ($drivers as $driver) {
    $database = null;
    try {
        $tools = $driver === 'sqlite' ? [] : NativeDatabase::tools($driver, $toolRoots[$driver]);
        $database = new NativeDatabase($work . '/database-' . $driver, $driver, $tools);
        $environment = array_replace(getenv(), $database->environment());
        $environment['PATH'] = dirname(PHP_BINARY) . PATH_SEPARATOR . (string) getenv('PATH');
        $environment['TYPE_PACKAGE_DRIVER'] = $driver;
        $environment['TYPE_NATIVE_PHP_INI'] = $build['runtime-profile']['ini'];
        foreach (['typeapp-application' => [PHP_BINARY, $root . '/tests/iot-identity.php', $artifact, $driver, '--app'],
            'native-package' => [PHP_BINARY, $root . '/tests/native-package.php', $artifact]] as $test => $command) {
            $process = new Type\Testing\Process($command, $root, $environment, 16777216);
            try {
                $result = $process->wait(300);
                file_put_contents($work . '/' . $driver . '-' . $test . '.log', $result->stdout . $result->stderr);
                expect($result->successful(), $driver . ' ' . $test . ' 失败，见 ' . $relative . '/' . $driver . '-' . $test . '.log');
                echo $result->stdout;
            } finally {
                $process->stop();
            }
        }
        $results[$driver] = ['typeapp-application' => true, 'native-package' => true];
    } finally {
        $database?->close();
    }
}
file_put_contents($work . '/verification.json', json_encode(['platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'build-id' => $build['build-id'], 'artifact-sha256' => $build['sha256'], 'production-packages' => $actual,
    'drivers' => $results], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo '标准应用验收通过：' . $relative . '/verification.json' . PHP_EOL;
