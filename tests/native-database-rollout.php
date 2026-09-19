<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;

$root = BuildPlatform::resolve(dirname(__DIR__));
expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) && posix_geteuid() > 0 && $argc === 5, '用法：非root Linux/macOS PHP tests/native-database-rollout.php <双版本preparation.json> <MySQL根目录> <PostgreSQL根目录> <redis-server>');
$preparationFile = realpath($argv[1]);
expect($preparationFile !== false && is_file($preparationFile), '需要已构建的双版本准备清单');
$prepared = json_decode((string) file_get_contents($preparationFile), true, 512, JSON_THROW_ON_ERROR);
expect(($prepared['platform'] ?? '') === PHP_OS_FAMILY, '需要本平台原生双版本产物');
foreach (['old' => '1.0.0', 'new' => '1.1.0'] as $variant => $version) {
    $item = $prepared['variants'][$variant] ?? [];
    expect(is_string($item['release'] ?? null) && preg_match('#^build/packaged-rollout-build-[a-f0-9]{12}/' . $variant . '/release$#D', $item['release']) === 1, '双版本发布目录无效');
    $manifest = (new NativePackage())->verify($root . '/' . $item['release'], $item['release-sha256'] ?? '');
    expect($manifest['version'] === $version && $manifest['runtime']['os'] === PHP_OS_FAMILY && $manifest['artifact']['sha256'] === ($item['artifact-sha256'] ?? ''), '发布版本、平台或二进制摘要不匹配');
}
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[2]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[3])];
$redis = realpath($argv[4]);
expect($redis !== false && is_executable($redis) && BuildPlatform::format($redis) === (PHP_OS_FAMILY === 'Darwin' ? 'Mach-O' : 'ELF'), '需要可信的本机原生redis-server');
$base = $root . '/build/native-database-rollout-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮原生回滚工作目录');
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'database-execution' => 'native', 'redis-execution' => 'native',
    'docker-used' => false, 'preparation-sha256' => hash_file('sha256', $preparationFile), 'variants' => $prepared['variants'], 'drivers' => []];
try {
    foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
        echo '本机原生数据库与Redis双版本回滚：' . $driver . "\n";
        $work = $base . '/' . $driver;
        $database = null;
        $entry = ['status' => 'running'];
        $failed = null;
        try {
            $database = new NativeDatabase($work, $driver, $tools[$driver] ?? []);
            $environment = array_replace((new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''), $database->environment());
            foreach (['PHPRC', 'PHP_INI_SCAN_DIR', 'TYPE_BWRAP_BINARY'] as $name) {
                if (getenv($name) !== false) {
                    $environment[$name] = getenv($name);
                }
            }
            $environment['TYPE_REDIS_SERVER'] = $redis;
            // 不把Docker加入PATH；已有演练明确选择原生服务，原生发布仍由受限视图运行。
            $secrets = $driver === 'sqlite' ? [] : [$environment['TYPE_' . strtoupper($driver) . '_PASSWORD']];
            $output = nativeDatabaseCommand([PHP_BINARY, $root . '/tests/packaged-rollout.php', $preparationFile, $driver, '--native-services'], $environment, $secrets, $work . '/rollout.log', 420);
            expect(preg_match('#新旧原生发布包无源码升级与兼容回滚通过：(.*?/verification\.json)\s*$#u', $output, $matches) === 1, '回滚入口没有返回验证记录');
            $evidence = json_decode((string) file_get_contents($matches[1]), true, 512, JSON_THROW_ON_ERROR);
            $scenario = json_decode((string) file_get_contents($evidence['scenario']), true, 512, JSON_THROW_ON_ERROR);
            $effects = array_column($scenario['effects'], 'id');
            sort($effects);
            expect($evidence['variants'] === $prepared['variants'] && $evidence['services']['execution'] === 'native'
                && $scenario['native'] && $scenario['packaged'] && $effects === ['delayed-old', 'new-ready', 'old-ready', 'rollback-ready'], '回滚不是同一双版本原生场景或产生了缺失/重复效果');
            $entry['rollout-evidence'] = $matches[1];
            $entry['rollout-evidence-sha256'] = hash_file('sha256', $matches[1]);
            $entry['scenario-evidence'] = $evidence['scenario'];
            $entry['effects'] = $effects;
            $entry['redis'] = $evidence['services'];
            $entry['status'] = 'passed';
            echo $output;
        } catch (Throwable $failure) {
            $failed = $failure;
            $entry['status'] = 'failed';
        } finally {
            if ($database !== null) {
                try {
                    $database->close();
                } catch (Throwable $cleanupFailure) {
                    $entry['status'] = 'failed';
                    $failed ??= $cleanupFailure;
                }
                $entry = array_replace($entry, $database->evidence());
            }
            $report['drivers'][$driver] = $entry;
        }
        if ($failed !== null) {
            throw $failed;
        }
    }
    $report['status'] = 'passed';
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '三库本机原生双版本回滚通过：' . $base . "/verification.json\n";
