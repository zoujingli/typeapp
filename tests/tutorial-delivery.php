<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';
require dirname(__DIR__) . '/tools/distribution/Batch.php';
require dirname(__DIR__) . '/tools/release/Plan.php';
require dirname(__DIR__) . '/tools/release/Packagist.php';
require dirname(__DIR__) . '/tools/release/TutorialEvidence.php';
require __DIR__ . '/fixed-snapshot.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/native-rollout-redis.php';
require __DIR__ . '/tutorial-artifacts.php';

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process as GitProcess;
use TypeApp\Release\Packagist;
use TypeApp\Release\Plan;
use TypeApp\Release\TutorialEvidence;

$root = dirname(__DIR__);
$mode = $argv[1] ?? '';
$reference = $argv[2] ?? '';
$php = in_array('--php', $argv, true);
$options = [];
foreach (array_slice($argv, 3) as $argument) {
    if ($argument === '--php') {
        continue;
    }
    expect(preg_match('/^--(runtime-map|runtime|output|profile)=(.+)$/D', $argument, $match) === 1, '未知教程验收选项');
    $options[$match[1]] = $match[2];
}
expect(in_array($mode, ['candidate', 'public', 'baseline'], true), '用法：php tests/tutorial-delivery.php candidate <完整SHA>|public|baseline <准确版本tag> [--php] [--runtime-map=三profile静态SDK映射JSON] [--output=新报告目录]');
$public = $mode !== 'candidate';
$baseline = $mode === 'baseline';
$selectedProfile = $options['profile'] ?? null;
expect($selectedProfile === null || (!$baseline && in_array($selectedProfile, ['sqlite', 'mysql', 'pgsql'], true)), '单profile只用于明确的教程候选或公开消费');
$drivers = $selectedProfile === null ? ['sqlite', 'mysql', 'pgsql'] : [$selectedProfile];
if ($public) {
    Plan::version($reference);
}
$source = $public ? GitProcess::output(['git', 'rev-parse', '--verify', 'refs/tags/' . $reference . '^{commit}'], $root) : $reference;
expect(preg_match('/^[a-f0-9]{40}$/D', $source) === 1, '候选必须指定完整固定提交SHA');
$version = $public ? $reference : null;
$root = Type\Build\BuildPlatform::resolve($root);
$base = Type\Build\BuildPlatform::path($options['output'] ?? $root . '/build/tutorial-delivery-' . bin2hex(random_bytes(6)));
expect(str_starts_with($base, $root . '/build/') && !file_exists($base) && mkdir($base, 0700, true), '报告目录必须是主仓build下的新目录');
$platform = match (PHP_OS_FAMILY) {
    'Darwin' => 'macos-arm64', 'Windows' => 'windows-x64',
    default => in_array(php_uname('m'), ['aarch64', 'arm64'], true) ? 'linux-arm64' : 'linux-x64'
};
$report = ['protocol' => 1, 'kind' => $baseline ? 'published-template-baseline' : ($php ? 'tutorial-php' : ($selectedProfile === null ? 'tutorial-delivery' : 'tutorial-profile-delivery')),
    'status' => 'running', 'source' => $source, 'channel' => $public ? 'packagist-tag' : 'fixed-candidate',
    'version' => $version, 'platform' => $platform, 'run' => getenv('GITHUB_RUN_ID') ?: null, 'attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: null,
    'published-new-version' => false, 'profiles' => []];
$consumers = [];
$packagistTemplate = null;
$redis = null;
try {
    $mapping = json_decode(GitProcess::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    $plan = Batch::plan($root, $source, $public ? 'tag' : 'branch', $version ?? '', $mapping);
    $templateSplit = GitProcess::output(['git', 'subtree', 'split', '--prefix=templates/type-project', '--ignore-joins', $source], $root);
    $items = $plan['items'] + ['type-project' => ['package' => 'zoujingli/type-project', 'repository' => 'zoujingli/type-project', 'split' => $templateSplit]];
    $report['template-split'] = $templateSplit;
    $report['items'] = $items;
    $snapshot = ['source' => $source, 'components' => [], 'template' => fixedSnapshot($root, $templateSplit, $base . '/template')];
    if (!$baseline) {
        $tree = GitProcess::output(['git', 'rev-parse', $source . ':examples/catalog'], $root);
        $snapshot['tutorial'] = fixedSnapshot($root, $tree, $base . '/tutorial');
    }
    $environment = getenv();
    $environment['COMPOSER_HOME'] = $base . '/composer-home';
    $environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
    $environment['TYPE_TEMPLATE_TUTORIAL_DIRECTORY'] = $base . '/tutorial';
    if (!$baseline && getenv('TYPE_REDIS_SERVER') !== false) {
        $redis = new NativeRolloutRedis($base . '/redis', getenv('TYPE_REDIS_SERVER'));
        $environment = array_replace($environment, $redis->environment());
    }
    if (!$public) {
        expect(mkdir($base . '/packages', 0700), '无法准备组件快照');
        foreach ($plan['items'] as $name => $item) {
            $snapshot['components'][$name] = fixedSnapshot($root, $item['split'], $base . '/packages/' . $name);
        }
        $environment['TYPE_TEMPLATE_SOURCE'] = $base . '/template';
        $environment['TYPE_TEMPLATE_COMPONENT_DIRECTORY'] = $base . '/packages';
        $environment['TYPE_TEMPLATE_CANDIDATE_MANIFEST'] = $base . '/snapshot.json';
    } else {
        // 只回读默认索引和准确远端tag；此入口没有push、分发或Release写入。
        $report['packagist'] = Packagist::wait($items, (string) $version, 60);
        $receipts = [];
        foreach ($plan['items'] as $name => $item) {
            $remote = GitProcess::output(['git', 'ls-remote', 'https://github.com/' . $item['repository'] . '.git', 'refs/tags/' . $version, 'refs/tags/' . $version . '^{}'], $root);
            $lines = explode("\n", $remote);
            $actual = explode("\t", end($lines))[0];
            expect($actual === $item['split'], '公开组件tag与固定源码拆分不符：' . $name);
            $receipts[$name] = $item + ['batch' => $plan['id'], 'source' => $source, 'mode' => 'tag', 'version' => $version,
                'reference' => 'refs/tags/' . $version, 'status' => 'already-current'];
        }
        file_put_contents($base . '/batch-result.json', json_encode(Batch::collect($plan, $receipts), JSON_THROW_ON_ERROR));
        $packagistTemplate = Type\Build\BuildPlatform::path(trim(nativeDatabaseCommand([PHP_BINARY, $root . '/tools/prepare-packagist-template.php', (string) $version], $environment, [], $base . '/template-install.log', 180)));
        expect(str_starts_with($packagistTemplate, $root . '/build/template-packagist-'), '公开模板下载目录越界');
        TutorialEvidence::snapshot($packagistTemplate, $snapshot['template']['files']);
        $tree = GitProcess::output(['git', 'rev-parse', $source . ':templates/type-project'], $root);
        file_put_contents($base . '/template.json', json_encode(['source' => $source, 'framework-batch' => $plan['id'],
            'batch' => hash('sha256', $source . ':' . $tree . ':tag:' . $version), 'package' => 'zoujingli/type-project',
            'repository' => 'zoujingli/type-project', 'mode' => 'tag', 'version' => $version, 'reference' => 'refs/tags/' . $version,
            'tree' => $tree, 'split' => $templateSplit, 'status' => 'already-current', 'publish-status' => 'already-current', 'checkout-verified' => true], JSON_THROW_ON_ERROR));
        $environment['TYPE_TEMPLATE_SOURCE'] = $packagistTemplate;
        $environment['TYPE_TEMPLATE_PACKAGIST'] = '1';
        $environment['TYPE_TEMPLATE_BATCH_SOURCE'] = $source;
        $environment['TYPE_TEMPLATE_REPORT_DIRECTORY'] = $base;
        unset($environment['TYPE_TEMPLATE_COMPONENT_DIRECTORY'], $environment['TYPE_TEMPLATE_CANDIDATE_MANIFEST']);
    }
    file_put_contents($base . '/snapshot.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    expect(!isset($options['runtime']) || ($selectedProfile !== null && !isset($options['runtime-map'])), '单SDK参数需要明确profile且不能混用映射');
    $runtimeMap = isset($options['runtime']) ? [$selectedProfile => $options['runtime']]
        : (!$baseline && !$php ? json_decode(file_get_contents($options['runtime-map'] ?? throw new RuntimeException('单程序教程验收需要显式三profile静态SDK映射')), true, 512, JSON_THROW_ON_ERROR) : []);
    if (!$baseline && !$php) {
        foreach ($drivers as $driver) {
            expect(is_file($runtimeMap[$driver] ?? ''), '静态SDK映射缺少profile：' . $driver);
        }
    }
    foreach ($drivers as $driver) {
        expect(mkdir($base . '/' . $driver, 0700), '无法建立profile证据目录');
        $settings = $environment;
        $settings['TYPE_TEMPLATE_CONSUMER_RECEIPT'] = $base . '/' . $driver . '/consumer-path.txt';
        $database = null;
        $tools = getenv('TYPE_' . strtoupper($driver) . '_TOOLS');
        if ($driver !== 'sqlite' && $tools !== false) {
            $database = new NativeDatabase($base . '/' . $driver . '/database', $driver, NativeDatabase::tools($driver, $tools));
            $settings = array_replace($settings, $database->environment());
            $settings['PATH'] = ($environment['PATH'] ?? '') . PATH_SEPARATOR . $settings['PATH'];
        }
        if (!$baseline && !$php) {
            $settings['TYPE_STATIC_RUNTIME'] = $runtimeMap[$driver];
            $settings['TYPEAPP_BUILD_PROFILE'] = $driver;
        } else {
            unset($settings['TYPE_STATIC_RUNTIME'], $settings['TYPEAPP_BUILD_PROFILE']);
        }
        $flags = $baseline ? ['--remote', '--published-baseline'] : ['--tutorial', '--onboarding', ...($public ? ['--remote'] : []), ...($php ? [] : ['--native', '--package'])];
        try {
            $output = nativeDatabaseCommand(
                [PHP_BINARY, $root . '/tests/application-template.php', $driver, ...$flags],
                $settings,
                array_values(array_filter([$settings['TYPE_MYSQL_PASSWORD'] ?? '', $settings['TYPE_PGSQL_PASSWORD'] ?? ''])),
                $base . '/' . $driver . '/run.log',
                $php || $baseline ? 180 : 1800
            );
        } finally {
            if ($database !== null) {
                $database->close();
                $report['databases'][$driver] = $database->evidence();
                if (is_file($base . '/' . $driver . '/database/server.log')) {
                    copy($base . '/' . $driver . '/database/server.log', $base . '/' . $driver . '/server.log');
                }
                removeTestDirectory($base . '/' . $driver . '/database');
                $report['databases'][$driver]['temporary-data-removed'] = true;
            }
        }
        expect(preg_match('#应用模板独立 ' . $driver . ' 消费验证通过：([^\r\n]+)#u', $output, $match) === 1, '教程缺少真实消费者回执');
        $consumer = Type\Build\BuildPlatform::resolve(trim($match[1]));
        expect(is_string($consumer) && str_starts_with($consumer, $root . '/build/'), '消费者目录越界');
        $consumers[$consumer] = $driver;
        $files = ['consumer' => ['verification.json', 'consumer.json'], 'lock' => ['composer.lock', 'composer.lock']];
        if (!$baseline) {
            $files['tutorial'] = ['catalog-candidate.json', 'tutorial.json'];
        }
        if (!$baseline && !$php) {
            $artifact = (new Type\Build\BuildPlatform())->output($consumer . '/build/type-project');
            $files += ['deployment' => ['deployment.json', 'deployment.json'], 'program' => [substr($artifact, strlen($consumer) + 1), PHP_OS_FAMILY === 'Windows' ? 'app.exe' : 'app'],
                'deployment-log' => ['deployment-tutorial.log', 'deployment-tutorial.log'],
                'build' => [substr($artifact, strlen($consumer) + 1) . '.build.json', 'build.json']];
        }
        foreach ($files as $kind => [$from, $to]) {
            expect(copy($consumer . '/' . $from, $base . '/' . $driver . '/' . $to), '无法保全教程证据');
            $digest = hash_file('sha256', $consumer . '/' . $from);
            expect(hash_file('sha256', $base . '/' . $driver . '/' . $to) === $digest, '教程证据与消费者原件摘要不同');
            $report['profiles'][$driver][$kind] = ['file' => $driver . '/' . $to, 'sha256' => $digest];
        }
        removeTestDirectory($consumer);
    }
    $report['status'] = 'passed';
} catch (Throwable $error) {
    $report['status'] = 'failed';
    $report['failure'] = $error->getMessage();
    throw $error;
} finally {
    try {
        if ($redis !== null) {
            $redis->close();
            $report['redis'] = $redis->evidence();
            removeTestDirectory($base . '/redis');
            $report['redis']['temporary-data-removed'] = true;
        }
        foreach (glob($base . '/*/consumer-path.txt') ?: [] as $receipt) {
            $path = trim(file_get_contents($receipt));
            expect(preg_match('~^' . preg_quote($root, '~') . '/build/(?:template-|tutorial space )(?:mysql|pgsql|sqlite)-[a-f0-9]{12}$~D', $path) === 1, '消费者清理路径不属于本轮测试');
            $consumers[$path] = basename(dirname($receipt));
        }
        foreach ($consumers as $consumer => $driver) {
            if (is_dir($consumer)) {
                expect(in_array($driver, $drivers, true), '消费者证据profile不属于本轮测试');
                archiveTutorialConsumer($consumer, $base . '/' . $driver . '/artifacts', $root);
                $report['preservation'][$driver] = ['file' => $driver . '/artifacts/preservation.json',
                    'sha256' => hash_file('sha256', $base . '/' . $driver . '/artifacts/preservation.json')];
            }
        }
        foreach (['template', 'tutorial', 'packages', 'composer-home'] as $directory) {
            if (is_dir($base . '/' . $directory)) {
                removeTestDirectory($base . '/' . $directory);
            }
        }
        if ($packagistTemplate !== null && is_dir($packagistTemplate)) {
            removeTestDirectory($packagistTemplate);
        }
        $report['temporary-inputs-removed'] = true;
    } catch (Throwable $cleanup) {
        $report['status'] = 'failed';
        $report['cleanup-failure'] = $cleanup->getMessage();
        $report['retained-consumers'] = array_values(array_filter(array_keys($consumers), 'is_dir'));
        throw $cleanup;
    } finally {
        file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
if (!$baseline && !$php) {
    try {
        TutorialEvidence::verify($base . '/verification.json', $source, $report['channel'], $version, $items, $selectedProfile);
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        $report['failure'] = $failure->getMessage();
        file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        throw $failure;
    }
}
echo ($baseline ? '历史公开批次入口基线（不证明新接口）' : '教程消费与交付') . '证据：' . $base . "/verification.json\n";
