<?php

declare(strict_types=1);

namespace TypeApp\Release;

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;

/** 发布资格来自固定轮次的真实任务和逐包回执，不能由单个完成标志替代。 */
final class Evidence
{
    /** @throws \RuntimeException 本轮消费任务缺失、失败或身份不一致。 */
    public static function consumption(string $root, array $plan): void
    {
        Batch::nativeEvidence($root, $plan['source']);
        $id = (string) getenv('GITHUB_RUN_ID');
        $attempt = (string) getenv('GITHUB_RUN_ATTEMPT');
        if (!preg_match('/^[1-9][0-9]*$/D', $id) || !preg_match('/^[1-9][0-9]*$/D', $attempt)) {
            throw new \RuntimeException('公开版本需要当前Actions执行身份');
        }
        $endpoint = 'repos/zoujingli/typeapp/actions/runs/' . $id . '/attempts/' . $attempt;
        $run = json_decode(Process::output(['gh', 'api', $endpoint], $root), true, 64, JSON_THROW_ON_ERROR);
        $jobs = json_decode(Process::output(['gh', 'api', $endpoint . '/jobs?per_page=100'], $root), true, 64, JSON_THROW_ON_ERROR);
        self::verifyConsumptionJobs($run, $jobs, $plan['source'], $plan['version'], (int) $id, (int) $attempt);
        self::reports($root, $plan);
    }

    /** 独立于工作流needs检查已完成的组件三库消费与模板三库部署任务。 */
    public static function verifyConsumptionJobs(array $run, array $jobs, string $source, string $version, int $id, int $attempt): void
    {
        if (($run['id'] ?? null) !== $id || ($run['run_attempt'] ?? null) !== $attempt
            || ($run['head_sha'] ?? '') !== $source || ($run['head_branch'] ?? '') !== $version
            || ($run['head_repository']['full_name'] ?? '') !== 'zoujingli/typeapp'
            || ($run['path'] ?? '') !== '.github/workflows/release.yml'
            || !in_array($run['event'] ?? '', ['push', 'workflow_dispatch'], true)
            || !is_array($jobs['jobs'] ?? null) || ($jobs['total_count'] ?? 0) !== count($jobs['jobs'])) {
            throw new \RuntimeException('消费验收的源码、版本或执行轮次不一致');
        }
        $required = ['distribute / plan', 'distribute / collect', 'distribute / consume', 'template / template'];
        $seen = [];
        foreach ($jobs['jobs'] as $job) {
            $name = $job['name'] ?? '';
            if (!in_array($name, $required, true)) {
                continue;
            }
            if (isset($seen[$name]) || ($job['head_sha'] ?? '') !== $source || ($job['status'] ?? '') !== 'completed'
                || ($job['conclusion'] ?? '') !== 'success') {
                throw new \RuntimeException('消费验收任务未成功或重复：' . $name);
            }
            $seen[$name] = true;
        }
        if (array_diff($required, array_keys($seen)) !== []) {
            throw new \RuntimeException('缺少本轮完整组件及模板消费验收');
        }
    }

    /** @throws \RuntimeException 模板、组件或Packagist回执不属于同一版本。 */
    public static function reports(string $root, array $plan): void
    {
        $source = $plan['source'];
        $version = $plan['version'];
        $batch = self::read($root . '/build/distribution/batch-result.json');
        $mapping = self::read($root . '/.github/distribution.json');
        Batch::verifyReport($root, $source, $batch, $mapping);
        $template = self::read($root . '/build/distribution/template.json');
        $index = self::read($root . '/build/release/packagist.json');
        if ($batch['mode'] !== 'tag' || $batch['version'] !== $version
            || ($template['source'] ?? '') !== $source || ($template['mode'] ?? '') !== 'tag' || ($template['version'] ?? '') !== $version
            || ($template['split'] ?? '') !== $plan['items']['type-project']['split']
            || ($template['framework-batch'] ?? '') !== $batch['id'] || ($template['checkout-verified'] ?? false) !== true
            || !in_array($template['status'] ?? '', ['published', 'already-current'], true)
            || ($index['source'] ?? '') !== $source || ($index['version'] ?? '') !== $version || count($index['items'] ?? []) !== 16) {
            throw new \RuntimeException('版本缺少完整模板或Packagist验收回执');
        }
        foreach ($plan['items'] as $name => $item) {
            $actual = $index['items'][$name] ?? [];
            if (($actual['package'] ?? '') !== $item['package'] || ($actual['source'] ?? '') !== $item['split']
                || ltrim($actual['version'] ?? '', 'v') !== substr($version, 1)) {
                throw new \RuntimeException('Packagist回执与发布计划不一致：' . $name);
            }
        }
    }

    /** @return array<string,mixed> 非空JSON对象，缺失报告不能按成功处理。 */
    private static function read(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('缺少发布回执：' . basename($file));
        }
        return json_decode((string) file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
    }
}
