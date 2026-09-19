<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;

$root = BuildPlatform::resolve(dirname(__DIR__));
expect(in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && posix_geteuid() > 0 && in_array($argc, [4, 5], true), '用法：非root Linux/macOS PHP tests/native-database-orm.php <MySQL根目录> <PostgreSQL根目录> <Composer程序> [mysql|pgsql|sqlite]');
$drivers = $argc === 5 ? [$argv[4]] : ['mysql', 'pgsql', 'sqlite'];
expect(array_diff($drivers, ['mysql', 'pgsql', 'sqlite']) === [], '未知独立ORM驱动');
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[1]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[2])];
$composer = realpath($argv[3]);
$phpHome = getenv('PHP_HOME') ?: '';
$phpxHome = getenv('PHPX_HOME') ?: '';
$nativeIni = realpath(getenv('TYPE_NATIVE_PHP_INI') ?: '');
expect($composer !== false && is_executable($composer), '需要可信且可执行的Composer入口');
expect(is_dir($phpHome) && is_dir($phpxHome) && $nativeIni !== false && is_file($nativeIni)
    && (is_executable(dirname($nativeIni) . '/embed-probe') || is_executable(dirname($nativeIni) . '/probe')), '需要明确SDK和已通过实际embed探测的运行配置');
$buildEnvironment = (new BuildPlatform())->environment($phpHome, $phpxHome);
$base = $root . '/build/native-database-orm-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700) && mkdir($base . '/composer-home', 0700), '无法创建本轮独立ORM验证目录');
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'database-execution' => 'native',
    'docker-used' => false, 'composer-sha256' => hash_file('sha256', $composer), 'drivers' => []];
try {
    foreach ($drivers as $driver) {
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
            $environment['COMPOSER_BINARY'] = $composer;
            $environment['COMPOSER_HOME'] = $base . '/composer-home';
            $environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
            $environment['COMPOSER_PROCESS_TIMEOUT'] = '1200';
            $environment['TYPE_NATIVE_PHP_INI'] = $nativeIni;
            $secrets = $driver === 'sqlite' ? [] : [$environment['TYPE_' . strtoupper($driver) . '_PASSWORD']];
            foreach (['php', 'native'] as $mode) {
                echo '本机原生数据库独立ORM消费：' . $driver . '/' . $mode . "\n";
                $output = nativeDatabaseCommand([PHP_BINARY, $root . '/tests/orm-suite-consumer.php', $driver, '--' . $mode], $environment, $secrets, $work . '/' . $mode . '.log', 1800);
                expect(preg_match('#报告：(.*?/verification\.json)\s*$#u', $output, $matches) === 1, 'ORM消费入口没有返回验证记录');
                $evidence = json_decode((string) file_get_contents($matches[1]), true, 512, JSON_THROW_ON_ERROR);
                expect($evidence['driver'] === $driver && $evidence['mode'] === $mode && $evidence['race'] === ['conflict', 'updated'], 'ORM消费没有保持驱动、模式或双进程唯一更新');
                $expected = ['zoujingli/type-orm', 'zoujingli/type-orm-' . $driver, 'zoujingli/type-runtime'];
                expect($evidence['production_packages'] === $expected, 'ORM消费混入了未选生产依赖');
                $record = ['evidence' => $matches[1], 'evidence-sha256' => hash_file('sha256', $matches[1])];
                if ($mode === 'native') {
                    $artifact = dirname($matches[1]) . '/build/type-app';
                    $built = json_decode((string) file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
                    expect(hash_file('sha256', $artifact) === $built['sha256'], '原生ORM产物摘要不符');
                    $record['artifact-sha256'] = $built['sha256'];
                    $record['build-id'] = $built['build-id'];
                    $record['source-inputs'] = count($built['sources']);
                }
                $entry[$mode] = $record;
                echo $output;
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
echo implode('/', $drivers) . ' 本机原生服务与独立ORM PHP/AOT矩阵通过：' . $base . "/verification.json\n";
