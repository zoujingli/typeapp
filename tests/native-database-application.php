<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;

$root = BuildPlatform::resolve(dirname(__DIR__));
expect(in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && posix_geteuid() > 0 && $argc >= 4 && $argc <= 5, '用法：非root Linux/macOS PHP tests/native-database-application.php <已构建应用> <MySQL根目录> <PostgreSQL根目录> [mysql|pgsql|sqlite]');
$extra = array_slice($argv, 4);
expect(count($extra) <= 1 && array_diff($extra, ['mysql', 'pgsql', 'sqlite']) === [], '未知物联中心数据库驱动');
$drivers = $extra === [] ? ['mysql', 'pgsql', 'sqlite'] : $extra;
$artifact = realpath($argv[1]);
expect($artifact !== false, '需要已构建的原生产物');
(new BuildPlatform())->assertArtifact($artifact);
$manifest = (new ArtifactManifest())->read($artifact);
$built = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect($manifest['runtime']['os'] === PHP_OS_FAMILY && $manifest['runtime']['architecture'] === php_uname('m'), '产物平台或架构与运行主机不一致');
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[2]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[3])];
$phpHome = getenv('PHP_HOME') ?: '';
$phpxHome = getenv('PHPX_HOME') ?: '';
expect(is_dir($phpHome) && is_dir($phpxHome), '需要明确的PHP_HOME和PHPX_HOME');
$buildEnvironment = (new BuildPlatform())->environment($phpHome, $phpxHome);
$base = $root . '/build/native-database-application-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮三库业务验收目录');
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'database-execution' => 'native',
    'docker-used' => false, 'artifact-sha256' => hash_file('sha256', $artifact), 'build-id' => $manifest['build-id'], 'drivers' => []];
try {
    foreach ($drivers as $driver) {
        echo '本机原生数据库与 PHP/AOT 物联中心对照：' . $driver . "\n";
        $work = $base . '/' . $driver;
        $database = null;
        $entry = ['status' => 'running'];
        $failed = null;
        try {
            $database = new NativeDatabase($work, $driver, $tools[$driver] ?? []);
            $environment = array_replace($buildEnvironment, $database->environment());
            $environment['PATH'] = $phpHome . '/bin:' . $environment['PATH'];
            $environment['PHPRC'] = getenv('PHPRC') ?: '';
            $environment['PHP_INI_SCAN_DIR'] = getenv('PHP_INI_SCAN_DIR') ?: '';
            $environment['TYPE_HTTP_DRIVER'] = getenv('TYPE_HTTP_DRIVER') ?: 'stream';
            $secrets = $driver === 'sqlite' ? [] : [$environment['TYPE_' . strtoupper($driver) . '_PASSWORD']];
            foreach (['php' => '--php', 'native' => $artifact] as $mode => $command) {
                foreach ([true] as $application) {
                    $label = $mode . '-application';
                    $arguments = ['--app'];
                    $runEnvironment = $environment;
                    if ($mode === 'native') {
                        $runEnvironment['TYPE_NATIVE_PHP_INI'] = $built['runtime-profile']['ini'];
                    }
                    $output = nativeDatabaseCommand([PHP_BINARY, $root . '/tests/iot-identity.php', $command, $driver, ...$arguments], $runEnvironment, $secrets, $work . '/' . $label . '.log', 120);
                    expect(preg_match('#通过：(.*?/verification\.json)\s*$#u', $output, $matches) === 1, '物联中心入口没有返回真实验收报告');
                    $evidence = json_decode((string) file_get_contents($matches[1]), true, 512, JSON_THROW_ON_ERROR);
                    expect($evidence['status'] === 'passed' && $evidence['driver'] === $driver && $evidence['native'] === ($mode === 'native'), '物联中心报告的驱动、入口模式或结果不符');
                    if ($mode === 'native') {
                        expect($evidence['binary']['sha256'] === $report['artifact-sha256'], '三库没有使用同一原生产物');
                    }
                    $entry[$label . '-evidence'] = $matches[1];
                    $entry[$label . '-evidence-sha256'] = hash_file('sha256', $matches[1]);
                    echo $output;
                }
            }
            $entry['status'] = 'passed';
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
echo implode('/', $drivers) . ' 本机原生服务与同一PHP/AOT应用对照通过：' . $base . "/verification.json\n";
