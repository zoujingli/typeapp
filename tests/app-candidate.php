<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;

/**
 * 同一生产候选验证 Platform、System、Center 和 Tenant 全新安装闭环。
 * 用法：php tests/app-candidate.php <产物或--php> <sqlite|mysql|pgsql> [--no-source].
 */
$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'sqlite';
$noSource = in_array('--no-source', $argv, true);
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), 'Tenant 候选验收需要明确驱动');
expect(
    ($target === '--php' || is_file($target)) && array_diff(array_slice($argv, 3), $noSource ? ['--no-source'] : []) === [],
    '用法：php tests/app-candidate.php <产物或--php> <sqlite|mysql|pgsql> [--no-source]'
);
expect(!$noSource || $target !== '--php', '无源码验收需要原生产物');
if ($target !== '--php') {
    $resolved = realpath($target);
    expect(is_string($resolved), '候选产物不存在');
    $target = $resolved;
}

$config = json_decode((string)file_get_contents($root . '/docs/build-config/type-app.json'), true, 16, JSON_THROW_ON_ERROR);
expect(
    !isset($config['entry'])
    && ($config['application']['bootstrap'] ?? []) === ['class' => 'app\common\bootstrap\Application', 'method' => 'bootstrap']
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
    $built = json_decode((string)file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $environment['TYPE_NATIVE_PHP_INI'] = getenv('TYPE_NATIVE_PHP_INI') ?: $built['runtime-profile']['ini'];
}

$report = [
    'status' => 'running',
    'suite' => 'app-candidate',
    'driver' => $driver,
    'native' => $target !== '--php',
    'no_source' => $noSource,
    'production_source' => ['entry' => 'generated-main', 'bootstrap' => $config['application']['bootstrap'], 'sources' => $config['sources'], 'output' => $config['output']],
    'tenant_architecture' => true,
    'impersonation_kept' => true,
    'checks' => [],
];
try {
    $tenant = [PHP_BINARY, $root . '/tests/tenant.php', $driver];
    if ($driver !== 'sqlite') {
        $tools = getenv('TYPE_' . strtoupper($driver) . '_TOOLS');
        expect(is_string($tools) && $tools !== '', '网络数据库候选需要 TYPE_' . strtoupper($driver) . '_TOOLS');
        $tenant[] = $tools;
    }
    if ($target !== '--php') {
        $tenant[] = $target;
    }
    $output = nativeDatabaseCommand($tenant, $environment, [], $base . '/tenant.log', 900);
    expect(str_contains($output, 'Tenant 空库安装、独立同名账号'), 'Tenant 候选没有返回当前管理闭环通过证据');
    $report['tenant'] = ['output_sha256' => hash('sha256', $output)];
    $report['checks'][] = 'fresh-tenant-platform-system-center-install';

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
    foreach (['tenant.log'] as $log) {
        if (is_file($base . '/' . $log)) {
            $report['logs'][$log] = hash_file('sha256', $base . '/' . $log);
        }
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '新标准生产候选通过：' . $base . "/verification.json\n";
