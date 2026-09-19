<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;

/**
 * 同一生产候选验证全新安装、旧入口失效、双端物联目录与独立 Broker。
 * 用法：php tests/app-candidate.php <产物或--php> <sqlite|mysql|pgsql> [--no-source] [--browser-dist=<隔离前端>]
 */
$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'sqlite';
$noSource = in_array('--no-source', $argv, true);
$browserOptions = array_values(array_filter($argv, static fn (string $value): bool => str_starts_with($value, '--browser-dist=')));
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '候选验收需要明确驱动');
expect(
    ($target === '--php' || is_file($target))
    && array_diff(array_slice($argv, 3), array_merge($noSource ? ['--no-source'] : [], $browserOptions)) === [],
    '用法：php tests/app-candidate.php <产物或--php> <sqlite|mysql|pgsql> [--no-source] [--browser-dist=<隔离前端>]'
);
expect(count($browserOptions) <= 1, '浏览器产物只能指定一次');
expect(!$noSource || $target !== '--php', '无源码验收需要原生产物');
if ($target !== '--php') {
    $resolved = realpath($target);
    expect(is_string($resolved), '候选产物不存在');
    $target = $resolved;
}

$config = json_decode((string) file_get_contents($root . '/docs/build-config/type-app.json'), true, 16, JSON_THROW_ON_ERROR);
expect(
    ($config['entry'] ?? '') === 'app/main.php'
    && ($config['sources'] ?? []) === ['app']
    && ($config['output'] ?? '') === 'build/app/type-app'
    && !str_contains(json_encode($config, JSON_THROW_ON_ERROR), 'templates/type-project'),
    '生产候选源不是主仓 app/，或把极简模板编入了生产源'
);

$base = $root . '/build/app-candidate-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建候选验收目录');
$environment = getenv();
if ($target !== '--php') {
    $environment = array_replace($environment, (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''));
    $built = json_decode((string) file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $environment['TYPE_NATIVE_PHP_INI'] = getenv('TYPE_NATIVE_PHP_INI') ?: $built['runtime-profile']['ini'];
}

$report = [
    'status' => 'running',
    'suite' => 'app-candidate',
    'driver' => $driver,
    'native' => $target !== '--php',
    'no_source' => $noSource,
    'production_source' => ['entry' => $config['entry'], 'sources' => $config['sources'], 'output' => $config['output']],
    'type_project_is_not_iot_candidate' => true,
    'historical_evidence_unchanged' => true,
    'impersonation_kept' => true,
    'checks' => [],
];
try {
    try {
        nativeDatabaseCommand(
            [PHP_BINARY, $root . '/tests/iot-identity.php', $target, $driver],
            $environment,
            [],
            $base . '/legacy-identity.log',
            30
        );
        expect(false, '未带 --app/--broker 的旧身份入口仍可通过');
    } catch (RuntimeException $failure) {
        $legacyLog = is_file($base . '/legacy-identity.log') ? (string) file_get_contents($base . '/legacy-identity.log') : $failure->getMessage();
        expect(str_contains($legacyLog, '旧 /iot/auth'), '旧身份入口失败原因不符');
    }
    $report['checks'][] = 'legacy-iot-auth-entry-rejected';

    $identity = [PHP_BINARY, $root . '/tests/iot-identity.php', $target, $driver, '--app'];
    if ($noSource) {
        $identity[] = '--no-source';
    }
    if ($browserOptions !== []) {
        $identity[] = $browserOptions[0];
    }
    $output = nativeDatabaseCommand($identity, $environment, [], $base . '/app-identity.log', $browserOptions === [] ? 240 : 900);
    expect(preg_match('#通过：(.*?/verification\.json)\s*$#u', $output, $matches) === 1, '双端候选没有返回通过证据');
    $report['app_identity'] = ['evidence' => substr($matches[1], strlen($root) + 1), 'sha256' => hash_file('sha256', $matches[1])];
    $report['checks'][] = 'fresh-app-install-dual-end-old-entries-rejected';

    $broker = [PHP_BINARY, $root . '/tests/iot-identity.php', $target, $driver, '--broker'];
    if ($noSource) {
        $broker[] = '--no-source';
    }
    $output = nativeDatabaseCommand($broker, $environment, [], $base . '/broker.log', 300);
    expect(preg_match('#通过：(.*?/verification\.json)\s*$#u', $output, $matches) === 1, '独立 Broker 候选没有返回通过证据');
    $report['broker'] = ['evidence' => substr($matches[1], strlen($root) + 1), 'sha256' => hash_file('sha256', $matches[1])];
    $report['checks'][] = 'same-binary-broker-install-and-login';

    if ($target !== '--php') {
        $manifest = (new ArtifactManifest())->read($target);
        $report['artifact'] = [
            'path' => substr($target, strlen($root) + 1),
            'sha256' => hash_file('sha256', $target),
            'build_id' => $manifest['build-id'],
            'production_packages' => array_keys($manifest['production-packages']),
        ];
        $report['checks'][] = 'reused-complete-production-aot';
    }
    $report['status'] = 'passed';
} catch (Throwable $failure) {
    $report['failure'] = str_replace($root, '.', $failure->getMessage());
    throw $failure;
} finally {
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    foreach (['legacy-identity.log', 'app-identity.log', 'broker.log'] as $log) {
        if (is_file($base . '/' . $log)) {
            $report['logs'][$log] = hash_file('sha256', $base . '/' . $log);
        }
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '新标准生产候选通过：' . $base . "/verification.json\n";
