<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

/** 对三个以上独立轮次比较延迟与吞吐范围；区间分离只产生须复验的回退信号。 */
function compareBenchmarkPair(array $old, array $new): array
{
    expect($old['host'] === $new['host'] && $old['transport'] === 'swoole' && $new['transport'] === 'swoole'
        && $old['driver'] === $new['driver'] && $old['sampling_protocol'] === 2 && $new['sampling_protocol'] === 2, '基准平台、传输、驱动或采样协议不可比');
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
expect($report['status'] === 'measured-not-compared' && count($report['runs']) === 6, '须先完成三库、两个版本的全部Swoole测量');
$groups = [];
$artifacts = [];
foreach ($report['runs'] as $run) {
    expect($run['transport'] === 'swoole' && in_array($run['driver'], ['sqlite', 'mysql', 'pgsql'], true), '无效的传输或数据库组合');
    expect(preg_match('#^build/application-benchmark-[a-f0-9]{12}/verification[.]json$#D', $run['report']) === 1, '原始测量报告标识无效');
    $measurementFile = dirname(__DIR__) . '/' . $run['report'];
    expect(is_file($measurementFile) && hash_file('sha256', $measurementFile) === $run['sha256']
        && json_decode(file_get_contents($measurementFile), true, 512, JSON_THROW_ON_ERROR) === $run['measurement'], '原始测量报告缺失或摘要/内嵌结果不符');
    expect($run['measurement']['status'] === 'passed' && $run['measurement']['transport'] === $run['transport']
        && $run['measurement']['driver'] === $run['driver'], '测量结果没有通过或组合身份不符');
    $key = $run['driver'];
    expect(in_array($run['version'], ['old', 'new'], true) && !isset($groups[$key][$run['version']]), '重复或无效的版本测量');
    $identity = $run['measurement']['artifact_sha256'];
    expect(!isset($artifacts[$run['version']]) || $artifacts[$run['version']] === $identity, '同一版本的跨数据库测量更换了产物');
    $artifacts[$run['version']] = $identity;
    $groups[$key][$run['version']] = $run['measurement'];
}
expect(count($groups) === 3, '成对测量没有覆盖三个数据库组合');
$comparison = ['source_report_sha256' => hash_file('sha256', $file), 'status' => 'no-consistent-latency-regression-detected',
    'criterion' => '三个独立轮次的p50与p95均全部高于旧版，或吞吐全部低于旧版时，产生必须复验的回退信号；吞吐含采样开销，不能因其区间重叠而掩盖独立延迟回退。',
    'resource_scope' => '报告全部CPU和进程树采样RSS；有界资源和停止保证由对应业务/压力矩阵验证，采样最大值不冒称OS峰值。', 'pairs' => []];
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
