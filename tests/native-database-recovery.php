<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;

$root = BuildPlatform::resolve(dirname(__DIR__));
expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) && posix_geteuid() > 0 && in_array($argc, [4, 5], true), '用法：非root Linux/macOS PHP tests/native-database-recovery.php <发布preparation.json> <MySQL根目录> <PostgreSQL根目录> [mysql|pgsql|sqlite]');
$drivers = $argc === 5 ? [$argv[4]] : ['mysql', 'pgsql', 'sqlite'];
expect(array_diff($drivers, ['mysql', 'pgsql', 'sqlite']) === [], '未知原生恢复驱动');
expect(array_diff($drivers, PDO::getAvailableDrivers()) === [], '恢复控制器缺少所选PDO驱动，须先配置匹配的原生扩展');
$preparationFile = realpath($argv[1]);
expect($preparationFile !== false && is_file($preparationFile), '需要已生成的发布准备清单');
$preparation = json_decode((string) file_get_contents($preparationFile), true, 512, JSON_THROW_ON_ERROR);
expect(is_array($preparation) && is_string($preparation['directory'] ?? null) && is_string($preparation['manifest-sha256'] ?? null), '发布准备清单缺少目录或受信摘要');
$release = (new NativePackage())->verify($preparation['directory'], $preparation['manifest-sha256']);
expect($release['runtime']['os'] === PHP_OS_FAMILY, '需要本平台原生发布包');
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[2]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[3])];
$base = $root . '/build/native-database-recovery-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮独立验收目录');
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'application-native' => true,
    'database-execution' => 'native', 'maintenance-execution' => 'native', 'docker-used' => false,
    'preparation-sha256' => hash_file('sha256', $preparationFile), 'artifact-sha256' => $release['artifact']['sha256'], 'build-id' => $release['artifact']['build-id'],
    'release-sha256' => $preparation['manifest-sha256'], 'drivers' => []];
try {
    foreach ($drivers as $driver) {
        echo '原生数据库与原生维护工具恢复：' . $driver . "\n";
        $work = $base . '/' . $driver;
        $database = null;
        $entry = ['status' => 'running', 'tools' => [], 'owned-server-stopped' => $driver === 'sqlite' ? null : false];
        $failed = null;
        try {
            $database = new NativeDatabase($work, $driver, $tools[$driver] ?? []);
            $environment = array_replace((new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''), $database->environment());
            $environment['PHPRC'] = getenv('PHPRC') ?: '';
            $environment['PHP_INI_SCAN_DIR'] = getenv('PHP_INI_SCAN_DIR') ?: '';
            foreach (['TYPE_BWRAP_BINARY', 'TYPE_SQLITE_BACKUP_TOOL'] as $name) {
                if (getenv($name) !== false) {
                    $environment[$name] = getenv($name);
                }
            }
            $secrets = $driver === 'sqlite' ? [] : [$environment['TYPE_' . strtoupper($driver) . '_PASSWORD']];
            $output = nativeDatabaseCommand([PHP_BINARY, $root . '/tests/native-package-recovery.php', $preparation['directory'], $preparation['manifest-sha256'], $driver], $environment, $secrets, $work . '/recovery.log', 420);
            expect(preg_match('#通过：(.*?/verification\\.json)\\s*$#u', $output, $matches) === 1, '恢复入口没有返回验收报告');
            $evidence = json_decode((string) file_get_contents($matches[1]), true, 512, JSON_THROW_ON_ERROR);
            expect($evidence['maintenance-execution'] === 'native' && $evidence['artifact-sha256'] === $release['artifact']['sha256']
                && $evidence['build-id'] === $release['artifact']['build-id'] && $evidence['release-sha256'] === $preparation['manifest-sha256'], '恢复报告不是同一原生发布及原生维护工具');
            $entry['recovery-evidence'] = $matches[1];
            $entry['recovery-evidence-sha256'] = hash_file('sha256', $matches[1]);
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
echo implode('/', $drivers) . ' 原生维护恢复与本轮服务器清理通过：' . $base . "/verification.json\n";
