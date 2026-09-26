<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tests/support.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Type\Build\NativePackage;
use Type\Testing\Process;

// 仅诊断已失败的原候选；控制器可以有源码，子进程仍使用候选入口及显式受限环境。
expect(PHP_OS_FAMILY === 'Windows' && getenv('GITHUB_ACTIONS') === 'true', '需要可丢弃的 Windows runner');
$root = dirname(__DIR__, 2);
$original = $root . '/build/original-windows-evidence/release-candidate';
$record = json_decode((string) file_get_contents($original . '/preparation.json'), true, 64, JSON_THROW_ON_ERROR);
expect($record['source'] === getenv('TYPE_PROBE_SOURCE') && $record['sha256'] === getenv('TYPE_PROBE_ARCHIVE_SHA256'), '原候选来源或摘要不符');
expect(basename($record['archive']) === $record['archive'], '原候选文件名越界');
$archive = $original . '/attachments/' . $record['archive'];
expect(hash_file('sha256', $archive) === $record['sha256'], '原 ZIP 字节改变');
$work = $root . '/build/windows-candidate-probe';
expect(!file_exists($work) && mkdir($work, 0700), '诊断目录已存在');
$package = $work . '/original unpacked release';
expect(mkdir($package, 0700), '无法创建解包目录');
$zip = new PharData($archive);
foreach (new RecursiveIteratorIterator($zip) as $entry) {
    expect(!$entry->isLink(), '原候选包含符号链接');
}
$zip->extractTo($package);
$zip = null;
$release = (new NativePackage())->verify($package, $record['manifest-sha256']);
expect($release['artifact']['sha256'] === $record['artifact-sha256'], '原程序身份不同');
$admin = new PDO('pgsql:host=' . getenv('TYPE_PGSQL_HOST') . ';port=' . getenv('TYPE_PGSQL_PORT') . ';dbname=' . getenv('TYPE_PGSQL_DATABASE'), getenv('TYPE_PGSQL_USER'), getenv('TYPE_PGSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$report = ['scope' => 'diagnosis-only', 'original-run' => getenv('TYPE_PROBE_RUN'), 'source' => $record['source'],
    'archive-sha256' => $record['sha256'], 'artifact-sha256' => $record['artifact-sha256'], 'attempts' => []];
try {
    foreach ([20, 90, 90] as $attempt => $budget) {
        $database = 'type_candidate_probe_' . bin2hex(random_bytes(6));
        $runtime = $work . '/runtime ' . $attempt;
        expect(mkdir($runtime, 0700), '无法创建安装数据根');
        $admin->exec('CREATE DATABASE ' . $database);
        $password = bin2hex(random_bytes(24));
        $environment = ['PATH' => '/usr/bin:/bin', 'SystemRoot' => (string) getenv('SystemRoot'), 'TEMP' => sys_get_temp_dir(),
            'APP_BASE_PATH' => $runtime, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_CACHE_ENABLED' => 'false',
            'DB_DRIVER' => 'pgsql', 'DB_HOST' => (string) getenv('TYPE_PGSQL_HOST'), 'DB_PORT' => (string) getenv('TYPE_PGSQL_PORT'),
            'DB_DATABASE' => $database, 'DB_USERNAME' => (string) getenv('TYPE_PGSQL_USER'), 'DB_PASSWORD' => (string) getenv('TYPE_PGSQL_PASSWORD'),
            'APP_API_TOKEN' => bin2hex(random_bytes(20)), 'TYPE_APP_RELEASE_SHA256' => $record['manifest-sha256'], 'TYPE_APP_TRACE' => '1',
            'APP_ADMIN_PASSWORD' => $password, 'APP_CUSTOMER_PASSWORD' => $password . '-customer'];
        $process = null;
        try {
            $started = microtime(true);
            $process = new Process([$package . '/run.cmd', 'app:install', 'package-admin', '发布管理员', 'package-customer', '发布客户', '发布租户'], $package, $environment);
            $samples = [];
            // 只观察状态和等待类型；不记录 SQL、参数、连接凭据或业务值。
            $inspection = $admin->prepare('SELECT state, wait_event_type, wait_event, EXTRACT(EPOCH FROM clock_timestamp() - query_start) AS query_seconds FROM pg_stat_activity WHERE datname = ?');
            while ($process->running() && microtime(true) - $started < $budget) {
                $inspection->execute([$database]);
                $samples[] = ['elapsed' => round(microtime(true) - $started, 3), 'connections' => $inspection->fetchAll(PDO::FETCH_ASSOC)];
                usleep(500000);
            }
            $result = $process->wait(max(0, $budget - (microtime(true) - $started)));
            $elapsed = microtime(true) - $started;
            $files = 0;
            if ($result->successful()) {
                foreach ($record['embedded-resources'] as $path => $file) {
                    expect(hash_file('sha256', $runtime . '/public/' . substr($path, 4)) === $file['sha256'], '成功安装的前端字节不同');
                    $files++;
                }
            }
            $report['attempts'][] = ['budget-seconds' => $budget, 'elapsed-seconds' => round($elapsed, 3),
                'exit-code' => $result->exitCode, 'timed-out' => $result->timedOut, 'signal' => $result->signal,
                'output-exceeded' => $result->outputExceeded, 'successful' => $result->successful(), 'frontend-files-verified' => $files,
                'stdout' => str_replace([$password, $environment['DB_PASSWORD']], '<REDACTED>', $result->stdout),
                'stderr' => str_replace([$password, $environment['DB_PASSWORD']], '<REDACTED>', $result->stderr), 'database-samples' => $samples];
            echo '安装测量：budget=' . $budget . '; elapsed=' . round($elapsed, 3) . '; timeout=' . (int) $result->timedOut . '; exit=' . $result->exitCode . "\n";
        } finally {
            $process?->stop();
            $admin->exec('DROP DATABASE ' . $database . ' WITH (FORCE)');
            removeTestDirectory($runtime);
            file_put_contents($work . '/measurement.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        }
    }
} finally {
    $admin = null;
    removeTestDirectory($package);
}
expect($report['attempts'][1]['successful'] && $report['attempts'][2]['successful'], '原候选在较长预算内仍未完成安装，见诊断报告');
