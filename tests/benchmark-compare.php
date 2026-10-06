<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildIdentity;

/** 对三个以上独立轮次比较延迟与吞吐范围；区间分离只产生须复验的回退信号。 */
function compareBenchmarkPair(array $old, array $new): array
{
    expect($old['host'] === $new['host'] && $old['transport'] === 'swoole' && $new['transport'] === 'swoole'
        && $old['driver'] === $new['driver'] && in_array($old['sampling_protocol'], [2, 3], true)
        && $old['sampling_protocol'] === $new['sampling_protocol'] && ($old['sampling'] ?? null) === ($new['sampling'] ?? null)
        && ($old['sampling_protocol'] !== 3 || (($old['host']['os'] ?? null) === 'Windows'
            && ($old['sampling'] ?? null) === ['method' => 'windows-cim-system-diagnostics-v1', 'every_operations' => 10])), '基准平台、传输、驱动或采样协议不可比');
    expect(count($old['repetitions']) >= 3 && count($new['repetitions']) === count($old['repetitions']), '基准须有相同且至少三个独立轮次');
    $results = [];
    foreach (['short-json', 'crud', 'slow-database'] as $workload) {
        $before = array_column($old['repetitions'], $workload);
        $after = array_column($new['repetitions'], $workload);
        expect(count($before) === count($old['repetitions']) && count($after) === count($before), '缺少同功能负载');
        foreach (array_merge($before, $after) as $round) {
            expect($round['iterations'] === $before[0]['iterations'] && $round['warmup'] === $before[0]['warmup']
                && $round['concurrency'] === $before[0]['concurrency'], '基准预热、样本数或并发不同');
        }
        $metrics = [];
        foreach (['p50_ms', 'p95_ms', 'p99_ms', 'operations_per_second', 'cpu_seconds', 'max_sampled_rss_bytes'] as $metric) {
            $left = array_column($before, $metric);
            $right = array_column($after, $metric);
            sort($left, SORT_NUMERIC);
            sort($right, SORT_NUMERIC);
            $median = intdiv(count($left), 2);
            $leftMedian = count($left) % 2 === 0 ? ($left[$median - 1] + $left[$median]) / 2 : $left[$median];
            $rightMedian = count($right) % 2 === 0 ? ($right[$median - 1] + $right[$median]) / 2 : $right[$median];
            $metrics[$metric] = ['old_range' => [min($left), max($left)], 'new_range' => [min($right), max($right)],
                'old_median' => $leftMedian, 'new_median' => $rightMedian,
                'relative_change' => $leftMedian > 0 ? $rightMedian / $leftMedian - 1 : null];
        }
        $slower = ($metrics['p50_ms']['new_range'][0] > $metrics['p50_ms']['old_range'][1]
            && $metrics['p95_ms']['new_range'][0] > $metrics['p95_ms']['old_range'][1])
            || $metrics['operations_per_second']['new_range'][1] < $metrics['operations_per_second']['old_range'][0];
        $results[$workload] = ['repeat_required' => $slower, 'metrics' => $metrics];
    }
    return $results;
}

$file = realpath($argv[1] ?? '');
expect($argc === 2 && is_string($file) && is_file($file), '用法：PHP tests/benchmark-compare.php <完整成对测量verification.json>');
$report = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$static = ($report['protocol'] ?? null) === 3;
$profile = $static ? ($report['static_profile'] ?? '') : null;
expect(!$static || (in_array($profile, ['sqlite', 'mysql', 'pgsql'], true) && ($report['delivery'] ?? null) === 'static-profile-benchmark'), '静态测量缺少明确 profile');
expect(
    $report['status'] === 'measured-not-compared' && count($report['runs']) === ($static ? 2 : 6),
    $static ? '须先完成同 profile 两个版本的全部 Swoole 测量' : '须先完成三库、两个版本的全部Swoole测量'
);
expect(!array_key_exists('protocol', $report) || in_array($report['protocol'], [2, 3], true), '测量报告协议无效');
$preparation = null;
$builds = [];
if (in_array($report['protocol'] ?? null, [2, 3], true)) {
    $preparedFile = $report['preparation']['file'] ?? '';
    expect(is_string($preparedFile) && preg_match('#^build/' . ($static ? 'pb-' : 'platform-benchmarks-') . '[a-f0-9]{12}/preparation[.]json$#D', $preparedFile) === 1, '固定源码准备报告标识无效');
    $preparedPath = dirname(__DIR__) . '/' . $preparedFile;
    expect(is_file($preparedPath) && !is_link($preparedPath)
        && hash_file('sha256', $preparedPath) === ($report['preparation']['sha256'] ?? null), '固定源码准备报告缺失或摘要不符');
    $preparation = json_decode(file_get_contents($preparedPath), true, 512, JSON_THROW_ON_ERROR);
    expect(($preparation['protocol'] ?? null) === ($static ? 3 : 2) && ($preparation['status'] ?? '') === 'prepared-not-measured'
        && ($preparation['delivery'] ?? '') === ($static ? 'static-profile-benchmark' : 'shared-runtime-benchmark')
        && (!$static || ($preparation['static_profile'] ?? null) === $profile)
        && ($preparation['platform'] ?? null) === ($report['platform'] ?? null)
        && ($preparation['architecture'] ?? null) === ($report['architecture'] ?? null)
        && ($preparation['source_commits'] ?? null) === ($report['source_commits'] ?? null)
        && array_keys($preparation['source_commits'] ?? []) === ['old', 'new'], '准备与测量的源码或平台身份不符');
    foreach (['old', 'new'] as $version) {
        $prepared = $preparation['variants'][$version]['roles']['project'] ?? [];
        $generation = $prepared['generation'] ?? [];
        $generationIdentity = $generation['identity'] ?? [];
        $manifest = $generationIdentity['manifest'] ?? [];
        $protocol = $manifest['protocol'] ?? null;
        expect(preg_match('/^[a-f0-9]{40}$/D', $preparation['source_commits'][$version] ?? '') === 1
            && ($generation['repetitions'] ?? null) === 3 && ($generation['reuse_warmup'] ?? null) === 1
            && is_array($manifest) && in_array($protocol, [1, 2], true)
            && is_array($protocol === 2 ? ($manifest['declarations']['files'] ?? null) : ($manifest['files'] ?? null))
            && ($protocol === 1 ? ($generationIdentity['declaration_generation'] ?? null) === null
                : preg_match('/^[a-f0-9]{64}$/D', $generationIdentity['declaration_generation'] ?? '') === 1)
            && hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === ($generationIdentity['generation'] ?? null)
            && ($manifest['declaration-generation'] ?? null) === ($generationIdentity['declaration_generation'] ?? null)
            && is_int($generationIdentity['generated_bytes'] ?? null) && $generationIdentity['generated_bytes'] >= 0
            && is_int($prepared['bytes'] ?? null) && $prepared['bytes'] > 0
            && is_numeric($prepared['build_seconds'] ?? null) && is_finite((float) $prepared['build_seconds']) && $prepared['build_seconds'] > 0
            && ($prepared['runtime_linkage'] ?? null) === ($static ? 'static' : 'shared'), '准备报告缺少固定源码和完整生成身份');
        foreach ($static ? ['sha256', 'build_id', 'sdk_manifest_sha256'] : ['sha256', 'build_id', 'runtime_ini_sha256'] as $field) {
            expect(preg_match('/^[a-f0-9]{64}$/D', $prepared[$field] ?? '') === 1, '准备报告缺少产物或运行配置身份');
        }
        if ($static) {
            expect(array_key_exists('runtime_ini_sha256', $prepared) && $prepared['runtime_ini_sha256'] === null
                && ($prepared['profile']['name'] ?? null) === $profile && ($prepared['profile']['database'] ?? null) === $profile
                && $prepared['sdk_manifest_sha256'] === ($preparation['sdk_manifest_sha256'] ?? null)
                && ($prepared['sdk_identity_sha256'] ?? null) === BuildIdentity::digest($preparation['sdk_identity'] ?? null)
                && ($preparation['sdk_identity']['profile'] ?? null) === $profile
                && ($prepared['profile']['features'] ?? null) === ($preparation['sdk_identity']['features'] ?? null)
                && ($preparation['variants'][$version]['sdk_inputs'] ?? []) !== [], '静态准备的 profile、SDK 或部署环境身份不符');
        }
        foreach (['cold_seconds', 'reuse_seconds'] as $phase) {
            expect(is_array($generation[$phase] ?? null) && array_is_list($generation[$phase]) && count($generation[$phase]) === 3, '生成计时缺少三轮原始样本');
            foreach ($generation[$phase] as $seconds) {
                expect(is_numeric($seconds) && is_finite((float) $seconds) && $seconds > 0, '生成计时样本无效');
            }
        }
        $builds[$version] = ['source' => $preparation['source_commits'][$version], 'artifact_sha256' => $prepared['sha256'],
            'generation' => $generationIdentity['generation'], 'declaration_generation' => $generationIdentity['declaration_generation'],
            'cold_seconds' => $generation['cold_seconds'], 'reuse_seconds' => $generation['reuse_seconds'],
            'repetitions' => 3, 'reuse_warmup' => 1, 'generated_bytes' => $generationIdentity['generated_bytes'],
            'build_seconds' => $prepared['build_seconds'], 'bytes' => $prepared['bytes'], 'runtime_linkage' => $prepared['runtime_linkage']];
    }
    if ($static) {
        foreach (['candidate' => '/new/candidate.json', 'candidate_build' => '/new/build-timing.json'] as $key => $suffix) {
            $source = $preparation[$key] ?? [];
            $path = dirname(__DIR__) . '/' . ($source['file'] ?? '');
            expect(($source['file'] ?? '') === dirname($preparedFile) . $suffix && is_file($path) && !is_link($path)
                && hash_file('sha256', $path) === ($source['sha256'] ?? null)
                && json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) === ($source['record'] ?? null), '原候选或原编译计时缺失、被替换');
        }
        $candidate = $preparation['candidate']['record'];
        $timing = $preparation['candidate_build']['record'];
        $before = $preparation['variants']['old']['roles']['project'];
        $after = $preparation['variants']['new']['roles']['project'];
        expect(($candidate['protocol'] ?? null) === 2 && ($candidate['status'] ?? null) === 'passed'
            && ($candidate['delivery'] ?? null) === 'single-executable' && ($candidate['profile'] ?? null) === $profile
            && ($candidate['database'] ?? null) === $profile && ($candidate['source'] ?? null) === $preparation['source_commits']['new']
            && ($candidate['sha256'] ?? null) === $after['sha256'] && ($candidate['build-id'] ?? null) === $after['build_id']
            && ($candidate['bytes'] ?? null) === $after['bytes'] && ($candidate['sdk-manifest-sha256'] ?? null) === $after['sdk_manifest_sha256']
            && ($candidate['features'] ?? null) === $after['profile']['features']
            && ($candidate['acceptance'][$profile]['status'] ?? null) === 'passed'
            && ($candidate['acceptance'][$profile]['artifact-sha256'] ?? null) === $after['sha256']
            && ($timing['protocol'] ?? null) === 1 && ($timing['source'] ?? null) === $preparation['source_commits']['new']
            && ($timing['profile'] ?? null) === $profile && ($timing['sha256'] ?? null) === $after['sha256']
            && ($timing['build_id'] ?? null) === $after['build_id'] && ($timing['build_seconds'] ?? null) === $after['build_seconds']
            && ($timing['report_sha256'] ?? null) === ($after['report_sha256'] ?? null)
            && ($timing['sdk_manifest_sha256'] ?? null) === $after['sdk_manifest_sha256']
            && is_string($timing['php_memory_limit'] ?? null) && $timing['php_memory_limit'] === ($preparation['php_memory_limit'] ?? null)
            && array_key_exists('controller_ini_sha256', $timing)
            && $timing['controller_ini_sha256'] === ($preparation['controller_ini_sha256'] ?? null)
            && ($timing['timing_scope'] ?? null) === 'vendor/bin/type process; frontend and SDK preparation excluded'
            && ($before['timing_scope'] ?? null) === ($timing['timing_scope'] ?? null)
            && ($after['timing_scope'] ?? null) === ($timing['timing_scope'] ?? null)
            && $preparation['variants']['old']['sdk_inputs'] === $preparation['variants']['new']['sdk_inputs']
            && ($before['runtime_extensions'] ?? null) === ($after['runtime_extensions'] ?? null)
            && is_array($before['runtime_extensions'] ?? null) && $before['runtime_extensions'] !== []
            && ($before['static_archives'] ?? null) === ($after['static_archives'] ?? null)
            && is_array($before['static_archives'] ?? null) && $before['static_archives'] !== []
            && ($before['system_libraries'] ?? null) === ($after['system_libraries'] ?? null)
            && is_array($before['system_libraries'] ?? null) && $before['system_libraries'] !== [], '静态性能证据没有绑定同 SDK 的旧端及已验收原候选');
    }
}
$groups = [];
$artifacts = [];
foreach ($report['runs'] as $run) {
    expect($run['transport'] === 'swoole' && in_array($run['driver'], ['sqlite', 'mysql', 'pgsql'], true), '无效的传输或数据库组合');
    expect(!$static || ($run['driver'] === $profile && ($run['measurement']['static_profile'] ?? null) === $profile
        && ($run['measurement']['delivery'] ?? null) === 'static-profile-benchmark'), '静态成对测量混入其他 profile');
    expect(preg_match('#^build/application-benchmark-[a-f0-9]{12}/verification[.]json$#D', $run['report']) === 1, '原始测量报告标识无效');
    $measurementFile = dirname(__DIR__) . '/' . $run['report'];
    expect(is_file($measurementFile) && hash_file('sha256', $measurementFile) === $run['sha256']
        && json_decode(file_get_contents($measurementFile), true, 512, JSON_THROW_ON_ERROR) === $run['measurement'], '原始测量报告缺失或摘要/内嵌结果不符');
    expect($run['measurement']['status'] === 'passed' && $run['measurement']['transport'] === $run['transport']
        && $run['measurement']['driver'] === $run['driver'], '测量结果没有通过或组合身份不符');
    $key = $run['driver'];
    expect(in_array($run['version'], ['old', 'new'], true) && !isset($groups[$key][$run['version']]), '重复或无效的版本测量');
    $identity = $run['measurement']['artifact_sha256'];
    if ($preparation !== null) {
        $prepared = $preparation['variants'][$run['version']]['roles']['project'];
        expect(($run['source'] ?? null) === $preparation['source_commits'][$run['version']]
            && ($run['build_id'] ?? null) === $prepared['build_id'] && $identity === $prepared['sha256']
            && ($run['runtime_ini_sha256'] ?? null) === $prepared['runtime_ini_sha256']
            && ($run['measurement']['host']['os'] ?? null) === $preparation['platform']
            && ($run['measurement']['host']['architecture'] ?? null) === $preparation['architecture'], '测量程序、运行配置或源码不属于固定准备身份');
    }
    expect(!isset($artifacts[$run['version']]) || $artifacts[$run['version']] === $identity, '同一版本的跨数据库测量更换了产物');
    $artifacts[$run['version']] = $identity;
    $groups[$key][$run['version']] = $run['measurement'];
}
expect(count($groups) === ($static ? 1 : 3), '成对测量没有覆盖声明的数据库组合');
$comparison = ['source_report_sha256' => hash_file('sha256', $file), 'status' => 'no-consistent-latency-regression-detected',
    'delivery' => $static ? 'static-profile-benchmark' : 'shared-runtime-benchmark', 'static_profile' => $profile,
    'preparation' => $report['preparation'] ?? null, 'generation_and_build' => $builds,
    'criterion' => '三个独立轮次的p50与p95均全部高于旧版，或吞吐全部低于旧版时，产生必须复验的回退信号；吞吐含采样开销，不能因其区间重叠而掩盖独立延迟回退。',
    'resource_scope' => '报告全部CPU和进程树采样RSS；有界资源和停止保证由对应业务/压力矩阵验证，采样最大值不冒称OS峰值。', 'pairs' => []];
if ($static) {
    $comparison['candidate'] = $preparation['candidate'];
    $comparison['size_relative_change'] = $builds['new']['bytes'] / $builds['old']['bytes'] - 1;
    $comparison['size_growth_requires_explanation'] = $comparison['size_relative_change'] > 0.05;
    $comparison['size_scope'] = '本轮同 profile 原程序字节比较；不替代正式版本的 SizeGate 或改写历史版本身份。';
}
foreach ($groups as $key => $pair) {
    expect(isset($pair['old'], $pair['new']), '缺少一个版本的测量');
    $comparison['pairs'][$key] = compareBenchmarkPair($pair['old'], $pair['new']);
    if (in_array(true, array_column($comparison['pairs'][$key], 'repeat_required'), true)) {
        $comparison['status'] = 'regression-signal-needs-repeat';
    }
}
$destination = dirname($file) . '/comparison-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($destination, json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
echo $comparison['status'] . '：' . $destination . "\n";
exit($comparison['status'] === 'no-consistent-latency-regression-detected' ? 0 : 2);
