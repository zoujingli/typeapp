<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;

$root = BuildPlatform::resolve(dirname(__DIR__));
expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) && posix_geteuid() > 0 && in_array($argc, [4, 5], true), '用法：非root Linux/macOS PHP tests/native-database-onboarding.php <MySQL根目录> <PostgreSQL根目录> <Composer PHAR> [mysql|pgsql|sqlite]');
$drivers = $argc === 5 ? [$argv[4]] : ['mysql', 'pgsql', 'sqlite'];
expect(array_diff($drivers, ['mysql', 'pgsql', 'sqlite']) === [], '未知独立接入驱动');
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[1]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[2])];
$composer = realpath($argv[3]);
$phpSetting = getenv('PHP_HOME');
$phpxSetting = getenv('PHPX_HOME');
expect(is_string($phpSetting) && $phpSetting !== '' && is_string($phpxSetting) && $phpxSetting !== '', '需要显式PHP_HOME和PHPX_HOME');
$phpHome = realpath($phpSetting);
$phpxHome = realpath($phpxSetting);
expect($composer !== false && is_file($composer), '需要显式可信Composer PHAR');
expect($phpHome !== false && is_executable($phpHome . '/bin/php-config') && $phpxHome !== false && is_dir($phpxHome . '/include'), '需要明确准备好的PHP_HOME和PHPX_HOME');
$buildEnvironment = (new BuildPlatform())->environment($phpHome, $phpxHome);
if (PHP_OS_FAMILY === 'Linux') {
    $bwrap = realpath(getenv('TYPE_BWRAP_BINARY') ?: '');
    expect(is_string($bwrap) && is_executable($bwrap) && BuildPlatform::format($bwrap) === 'ELF', 'Linux接入验收需要显式TYPE_BWRAP_BINARY以验证无源码发布');
    $buildEnvironment['TYPE_BWRAP_BINARY'] = $bwrap;
}
$base = $root . '/build/native-database-onboarding-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700) && mkdir($base . '/composer-home', 0700) && mkdir($base . '/php.d', 0700), '无法创建本轮接入工作目录');
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'database-execution' => 'native',
    'docker-used' => false, 'remote-distribution' => false, 'composer-sha256' => hash_file('sha256', $composer), 'drivers' => []];
try {
    foreach ($drivers as $driver) {
        echo '原生数据库空目录接入、业务修改与发布：' . $driver . "\n";
        $work = $base . '/' . $driver;
        $database = null;
        $entry = ['status' => 'running'];
        $failed = null;
        try {
            $database = new NativeDatabase($work, $driver, $tools[$driver] ?? []);
            $environment = array_replace($buildEnvironment, $database->environment());
            $environment['PATH'] = $phpHome . '/bin:' . $environment['PATH'];
            $environment['PHP_HOME'] = $phpHome;
            $environment['PHPX_HOME'] = $phpxHome;
            $environment['PHPRC'] = getenv('PHPRC') ?: '';
            // proc_open会省略空环境值；用真实空目录阻断默认conf.d，避免与显式PHPRC重复加载扩展。
            $environment['PHP_INI_SCAN_DIR'] = getenv('PHP_INI_SCAN_DIR') ?: $base . '/php.d';
            $environment['TYPE_COMPOSER_PHAR'] = $composer;
            $environment['COMPOSER_HOME'] = $base . '/composer-home';
            $environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
            $environment['COMPOSER_PROCESS_TIMEOUT'] = '1200';
            $secrets = $driver === 'sqlite' ? [] : [$environment['TYPE_' . strtoupper($driver) . '_PASSWORD']];
            $output = nativeDatabaseCommand([PHP_BINARY, $root . '/tests/application-template.php', $driver, '--onboarding', '--native', '--package'], $environment, $secrets, $work . '/onboarding.log', 1800);
            expect(preg_match('#应用模板独立 ' . $driver . ' 消费验证通过：(.*)\s*$#u', $output, $matches) === 1, '接入入口没有返回已验证项目');
            $consumer = realpath(trim($matches[1]));
            expect($consumer !== false && str_starts_with($consumer, $root . '/build/template-' . $driver . '-'), '接入结果不属于本轮独立模板范围');
            $evidence = json_decode((string) file_get_contents($consumer . '/verification.json'), true, 512, JSON_THROW_ON_ERROR);
            expect($evidence['native'] && $evidence['onboarding'] && $evidence['package-verified'] && !$evidence['remote'] && $evidence['driver'] === $driver, '接入验收缺少原生、业务修改或发布范围');
            $entry['template-evidence'] = $consumer . '/verification.json';
            $entry['template-evidence-sha256'] = hash_file('sha256', $consumer . '/verification.json');
            $entry['artifact-sha256'] = $evidence['artifact-sha256'];
            $entry['build-id'] = $evidence['build-id'];
            $entry['production-packages'] = $evidence['production-packages'];
            $entry['compiled-source-count'] = $evidence['compiled-source-count'];
            if (PHP_OS_FAMILY === 'Linux' && $driver === 'sqlite') {
                // 用刚完成搬迁/归档的同一模板发布验证用户服务；三库业务由上面的独立入口各自验证。
                expect(preg_match('#原生发布、搬迁、sqlite业务与篡改拒绝通过：([^\r\n]+)#u', $output, $packageMatch) === 1, '接入输出缺少实际发布报告');
                $packageReport = BuildPlatform::resolve(trim($packageMatch[1]));
                expect(BuildPlatform::contains($root . '/build', $packageReport), '发布报告不属于本轮build');
                $packaging = json_decode(file_get_contents($packageReport), true, 512, JSON_THROW_ON_ERROR);
                expect($packaging['artifact-sha256'] === $evidence['artifact-sha256'], '服务与接入产物不一致');
                $runtime = $work . '/service-data';
                expect(mkdir($runtime, 0700), '无法创建用户服务运行根');
                $settings = ['name' => 'typeappsystemd' . bin2hex(random_bytes(6)), 'scope' => 'user',
                    'user' => posix_getpwuid(posix_geteuid())['name'], 'runtime-directory' => $runtime,
                    'restart-seconds' => 1, 'stop-seconds' => 10];
                $service = (new \Type\Build\ServiceDefinition())->create($packaging['package'], $work . '/service', $packaging['release-sha256'], $settings);
                $serviceEnvironment = $environment;
                foreach (['XDG_RUNTIME_DIR', 'DBUS_SESSION_BUS_ADDRESS'] as $key) {
                    $value = getenv($key);
                    if ($value !== false) {
                        $serviceEnvironment[$key] = $value;
                    }
                }
                $serviceEnvironment['TYPE_TEMPLATE_EXPECTED_MESSAGE'] = $evidence['business-message'];
                echo nativeDatabaseCommand(
                    [PHP_BINARY, $root . '/tests/native-service-systemd.php', $service['directory'] . '/service.json', $service['manifest-sha256']],
                    $serviceEnvironment,
                    [],
                    $work . '/systemd.log',
                    180
                );
                $entry['service-evidence'] = $work . '/verification.json';
                $entry['service-evidence-sha256'] = hash_file('sha256', $entry['service-evidence']);
            }
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
echo implode('/', $drivers) . ' 本机原生接入与发布通过：' . $base . "/verification.json\n";
