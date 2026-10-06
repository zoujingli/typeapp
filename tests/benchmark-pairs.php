<?php

declare(strict_types=1);
require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Build\BuildIdentity;
use Type\Build\ArtifactManifest;
use Type\Build\StaticRuntimeSdk;
use Type\Testing\Process;

$root = BuildPlatform::resolve(dirname(__DIR__));
$static = ($argv[3] ?? '') === '--static-profile';
$profile = $static ? ($argv[4] ?? '') : null;
$diagnostic = ($argv[5] ?? '') === '--diagnostic';
$diagnosticDriver = $diagnostic ? ($argv[6] ?? '') : null;
$order = $diagnostic ? 'ABBA+BAAB' : ($static ? ($argv[6] ?? 'old-first') : ($argv[5] ?? 'old-first'));
expect(
    ($diagnostic ? ($argc === 7 && in_array($diagnosticDriver, ['sqlite', 'mysql', 'pgsql'], true)
        && ($static ? $profile === $diagnosticDriver : in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)))
        : (in_array($order, ['old-first', 'new-first'], true)
            && ($static ? (in_array($argc, [5, 7], true) && ($argc === 5 || $argv[5] === '--order') && in_array($profile, ['sqlite', 'mysql', 'pgsql'], true))
                : (in_array($argc, [5, 6], true) && in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true))))),
    '用法：PHP tests/benchmark-pairs.php <旧版准备根> <新版准备根> <MySQL工具根> <PostgreSQL工具根> [old-first|new-first]；静态模式改用 --static-profile <数据库> [--order <顺序>]；额外诊断以 --diagnostic <数据库> 替代顺序参数，执行 ABBA+BAAB，不能用于正式比较'
);
$old = BuildPlatform::resolve($argv[1]);
$new = BuildPlatform::resolve($argv[2]);
$mysql = $static ? (getenv('TYPE_MYSQL_TOOLS') ?: null) : BuildPlatform::resolve($argv[3]);
$pgsql = $static ? (getenv('TYPE_PGSQL_TOOLS') ?: null) : BuildPlatform::resolve($argv[4]);
expect($old !== $new, '需要不同版本的已准备目录');
$preparationFile = dirname($old) . '/preparation.json';
expect($old === dirname($old) . '/old' && $new === dirname($old) . '/new'
    && preg_match('#^' . preg_quote($root, '#') . '/build/' . ($static ? 'pb-' : 'platform-benchmarks-') . '[a-f0-9]{12}/preparation[.]json$#D', $preparationFile) === 1
    && is_file($preparationFile) && !is_link($preparationFile), '成对测量必须来自同一轮固定源码准备');
$preparation = json_decode(file_get_contents($preparationFile), true, 512, JSON_THROW_ON_ERROR);
expect(($preparation['protocol'] ?? null) === ($static ? 3 : 2) && ($preparation['status'] ?? '') === 'prepared-not-measured'
    && ($preparation['delivery'] ?? '') === ($static ? 'static-profile-benchmark' : 'shared-runtime-benchmark')
    && (!$static || ($preparation['static_profile'] ?? null) === $profile)
    && $preparation['platform'] === PHP_OS_FAMILY && $preparation['architecture'] === php_uname('m'), '准备报告未完成或平台不符');
if ($static) {
    $sdk = StaticRuntimeSdk::selected($profile);
    expect($sdk !== null && $sdk->identity() === ($preparation['sdk_identity'] ?? null)
        && hash_file('sha256', $sdk->manifestPath()) === ($preparation['sdk_manifest_sha256'] ?? null), '静态配对 SDK 与准备身份不同');
}
$runtimeConfigurations = [];
$artifacts = [];
foreach (['old' => $old, 'new' => $new] as $version => $directory) {
    $prepared = $preparation['variants'][$version]['roles']['project'] ?? [];
    $artifact = $static ? BuildPlatform::resolve($root . '/' . ($prepared['artifact'] ?? '')) : $directory . '/project/build/benchmark/type-app';
    $reportFile = $static ? BuildPlatform::resolve($root . '/' . ($prepared['report'] ?? '')) : $artifact . '.build.json';
    expect(BuildPlatform::contains($root . '/build', $artifact) && BuildPlatform::contains($root . '/build', $reportFile), '基准产物或构建报告越界');
    (new BuildPlatform())->assertArtifact($artifact);
    $build = json_decode(file_get_contents($reportFile), true, 512, JSON_THROW_ON_ERROR);
    expect(preg_match('/^[a-f0-9]{40}$/D', $preparation['source_commits'][$version] ?? '') === 1
        && hash_file('sha256', $artifact) === $build['sha256'] && $build['sha256'] === ($prepared['sha256'] ?? null)
        && $build['build-id'] === ($prepared['build_id'] ?? null) && filesize($artifact) === ($prepared['bytes'] ?? null)
        && hash_file('sha256', $reportFile) === ($prepared['report_sha256'] ?? null)
        && ($prepared['runtime_linkage'] ?? null) === ($static ? 'static' : 'shared')
        && ($build['manifest']['runtime-linkage'] ?? 'shared') === ($static ? 'static' : 'shared')
        && ($build['declaration-generation'] ?? null) === ($prepared['generation']['identity']['declaration_generation'] ?? null), '成对测量产物或生成身份与固定源码准备不符');
    $artifacts[$directory] = $artifact;
    if ($static) {
        $manifest = (new ArtifactManifest())->read($artifact);
        expect($manifest === $build['manifest'] && BuildIdentity::digest($manifest['static-runtime'] ?? null) === BuildIdentity::digest($sdk->identity())
            && BuildIdentity::digest($manifest['static-runtime'] ?? null) === ($prepared['sdk_identity_sha256'] ?? null)
            && ($manifest['profile']['name'] ?? null) === $profile && ($manifest['profile']['database'] ?? null) === $profile
            && ($manifest['features'] ?? null) === $preparation['candidate']['record']['features']
            && array_filter($manifest['embedded-resources'] ?? [], static fn (string $path): bool => str_starts_with($path, 'web/'), ARRAY_FILTER_USE_KEY) === $preparation['variants'][$version]['frontend']
            && $preparation['variants'][$version]['frontend'] !== []
            && $preparation['variants'][$version]['frontend'] === array_filter($preparation['candidate']['record']['embedded-resources'], static fn (string $path): bool => str_starts_with($path, 'web/'), ARRAY_FILTER_USE_KEY)
            && $prepared['runtime_ini_sha256'] === null, '静态基准的 SDK、功能或前端身份不符');
        if ($version === 'new') {
            expect($artifact === $root . '/build/release-candidate/attachments/' . $preparation['candidate']['record']['file']
                && $build['sha256'] === $preparation['candidate']['record']['sha256'], '新端必须测量已经验收的原候选附件');
        } else {
            expect(BuildPlatform::contains($directory . '/project', $artifact), '旧端程序不属于本轮固定源码');
        }
        $runtimeConfigurations[$directory] = null;
        continue;
    }
    expect(($build['static-archives'] ?? null) === [], '共享基准不能携带静态运行 SDK');
    $ini = realpath($build['runtime-profile']['ini'] ?? '');
    expect(is_string($ini) && is_file($ini) && BuildPlatform::contains($directory, $ini)
        && hash_file('sha256', $ini) === ($prepared['runtime_ini_sha256'] ?? null), '基准运行配置必须属于对应版本的已探测产物');
    $runtimeConfigurations[$directory] = $ini;
}
$base = $root . '/build/benchmark-pairs-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建成对测量目录');
$record = ['protocol' => $static ? 3 : 2, 'status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'order' => $order,
    'delivery' => $preparation['delivery'], 'static_profile' => $profile,
    'preparation' => ['file' => substr($preparationFile, strlen($root) + 1), 'sha256' => hash_file('sha256', $preparationFile)],
    'source_commits' => $preparation['source_commits'],
    'controller_sha256' => hash_file('sha256', $root . '/tests/application-benchmark.php'), 'runs' => []];
if ($diagnostic) {
    $record['diagnostic'] = ['driver' => $diagnosticDriver, 'blocks' => ['ABBA', 'BAAB'], 'variants' => ['A' => 'old', 'B' => 'new'],
        'pair_controller_sha256' => hash_file('sha256', __FILE__), 'cells' => []];
}
try {
    foreach ($diagnostic ? [$diagnosticDriver] : ($static ? [$profile] : ['sqlite', 'mysql', 'pgsql']) as $driver) {
        // 复测可交换执行顺序，区分工具链变化与持续负载造成的环境漂移。
        $versions = $diagnostic ? ['old', 'new', 'new', 'old', 'new', 'old', 'old', 'new']
            : ($order === 'new-first' ? ['new', 'old'] : ['old', 'new']);
        foreach ($versions as $position => $version) {
            $directory = $version === 'old' ? $old : $new;
            if ($static) {
                // PDO 锁持有者属于控制端，保留其真实 INI；application-benchmark 为静态程序另建干净环境。
                $environment = getenv();
            } else {
                $phpx = is_dir($directory . '/phpx') ? $directory . '/phpx' : realpath(getenv('PHPX_HOME'));
                $environment = (new BuildPlatform())->environment(realpath(getenv('PHP_HOME')), $phpx);
                $environment['PHPRC'] = $runtimeConfigurations[$directory];
                $environment['PHP_INI_SCAN_DIR'] = $base;
            }
            $command = [PHP_BINARY, $root . '/tests/application-benchmark.php',
                '--binary', $artifacts[$directory],
                '--driver', $driver, '--repetitions', $diagnostic ? '1' : '3', '--iterations', '100', '--warmup', '10'];
            if ($diagnostic) {
                $command[] = '--diagnostic';
            }
            if ($static) {
                $command = [...$command, '--static-profile', $profile];
            }
            if ($static && PHP_OS_FAMILY === 'Windows' && $driver !== 'sqlite') {
                expect(($environment['TYPE_BENCHMARK_EXTERNAL_DATABASE'] ?? '') === '1', 'Windows 性能测量必须由专用数据库装置启动');
                $command[] = '--external-database';
            } elseif ($driver !== 'sqlite') {
                expect(is_string($driver === 'mysql' ? $mysql : $pgsql), '性能测量缺少对应数据库工具');
                $command = [...$command, '--database-tools', $driver === 'mysql' ? $mysql : $pgsql];
            }
            $label = $driver . '-' . $version;
            if ($diagnostic) {
                $block = $position < 4 ? 'ABBA' : 'BAAB';
                $label = sprintf('%02d-%s-%d-%s', $position + 1, $block, $position % 4 + 1, $label);
                $record['diagnostic']['cells'][] = ['sequence' => $position + 1, 'block' => $block, 'position' => $position % 4 + 1,
                    'version' => $version, 'driver' => $driver, 'status' => 'running', 'started_monotonic_ns' => hrtime(true),
                    'finished_monotonic_ns' => null, 'log' => substr($base, strlen($root) + 1) . '/' . $label . '.log'];
            }
            echo ($diagnostic ? '额外诊断测量：' : '正式成对测量：') . $label . "\n";
            $process = new Process($command, $root, $environment, 4194304);
            try {
                $result = $process->wait(600);
            } finally {
                $process->stop();
                if ($diagnostic) {
                    $record['diagnostic']['cells'][$position]['finished_monotonic_ns'] = hrtime(true);
                }
            }
            file_put_contents($base . '/' . $label . '.log', $result->stdout . $result->stderr);
            if ($diagnostic) {
                $record['diagnostic']['cells'][$position]['status'] = $result->successful() ? 'measured' : 'failed';
                $record['diagnostic']['cells'][$position]['exit_code'] = $result->exitCode;
            }
            expect($result->successful(), ($diagnostic ? '额外诊断' : '正式') . '测量失败，见本轮日志：' . $label);
            expect(preg_match('#真实应用三类负载测量完成：(build/[^\\r\\n]+)#u', $result->stdout, $matches) === 1, '缺少测量报告');
            $report = trim($matches[1]);
            $measurement = json_decode(file_get_contents($root . '/' . $report), true, 512, JSON_THROW_ON_ERROR);
            expect($measurement['status'] === ($diagnostic ? 'diagnostic-not-compared' : 'passed')
                && $measurement['transport'] === 'swoole' && $measurement['driver'] === $driver, '测量身份不符');
            if ($diagnostic) {
                expect(count($measurement['repetitions']) === 1
                    && ($measurement['diagnostic']['controller']['script_sha256'] ?? '') === $record['controller_sha256']
                    && ($measurement['diagnostic']['program_ini_sha256'] ?? null) === ($static ? null : hash_file('sha256', $runtimeConfigurations[$directory])), '诊断控制器、运行配置或轮次不同');
                foreach (['short-json', 'crud', 'slow-database'] as $workload) {
                    $sample = $measurement['repetitions'][0][$workload];
                    expect($sample['warmup'] === 10 && $sample['iterations'] === 100 && $sample['concurrency'] === 1
                        && count($sample['diagnostic']['ordered_latencies_ms'] ?? []) === 100, '诊断缺少固定负载或顺序样本');
                }
            }
            expect(!$static || (($measurement['static_profile'] ?? null) === $profile
                && ($measurement['delivery'] ?? null) === 'static-profile-benchmark'), '静态测量没有保持 profile 边界');
            expect(hash_file('sha256', $artifacts[$directory]) === $preparation['variants'][$version]['roles']['project']['sha256'], '测量改变了程序字节');
            $record['runs'][] = ['version' => $version, 'transport' => 'swoole', 'driver' => $driver,
                'source' => $preparation['source_commits'][$version], 'build_id' => $preparation['variants'][$version]['roles']['project']['build_id'],
                'runtime_ini_sha256' => $static ? null : hash_file('sha256', $runtimeConfigurations[$directory]),
                'report' => $report, 'sha256' => hash_file('sha256', $root . '/' . $report), 'measurement' => $measurement];
            file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        }
    }
    expect(hash_file('sha256', $preparationFile) === $record['preparation']['sha256'], '测量期间固定源码准备报告变化');
    $record['status'] = $diagnostic ? 'diagnostic-not-compared' : 'measured-not-compared';
} finally {
    if ($record['status'] === 'running') {
        $record['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
echo ($diagnostic ? '成对额外诊断完成：' : '成对正式测量完成：') . substr($base, strlen($root) + 1) . "/verification.json\n";
