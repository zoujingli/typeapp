<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 只校验有界 Job 声明，依赖图与作用域工厂由应用统一装配。 */
final class JobCompiler
{
    /**
     * @param list<array<string, mixed>> $jobs application.jobs 声明。
     * @return list<array<string, mixed>> 保持声明顺序的规范化任务。
     * @throws RuntimeException 类型、版本、构造目标、资源列表无效或重复。
     */
    public function validate(array $jobs): array
    {
        if (!array_is_list($jobs) || count($jobs) > 1000) {
            throw new RuntimeException('application.jobs 必须是最多 1000 项的列表');
        }
        $seen = [];
        $result = [];
        foreach ($jobs as $job) {
            if (!is_array($job) || !is_string($job['type'] ?? null) || !preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $job['type'])
                || !is_int($job['version'] ?? null) || $job['version'] < 1
                || array_diff(array_keys($job), ['type', 'version', 'class', 'service', 'resources']) !== []) {
                throw new RuntimeException('Job 类型、版本或字段无效');
            }
            $target = array_values(array_intersect(['class', 'service'], array_keys($job)));
            if (count($target) !== 1 || !is_string($job[$target[0]]) || $job[$target[0]] === ''
                || ($target[0] === 'class' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $job['class']))) {
                throw new RuntimeException('Job 必须声明唯一 class 或 service');
            }
            $resources = $job['resources'] ?? [];
            if (!is_array($resources) || !array_is_list($resources) || count($resources) > 1000
                || count(array_filter($resources, static fn (mixed $id): bool => is_string($id) && $id !== '')) !== count($resources)
                || count(array_unique($resources)) !== count($resources)) {
                throw new RuntimeException('Job resources 必须是无重复的服务标识列表');
            }
            $key = $job['type'] . ':' . $job['version'];
            if (isset($seen[$key])) {
                throw new RuntimeException('任务类型与版本重复：' . $key);
            }
            $seen[$key] = true;
            $job['resources'] = $resources;
            $result[] = $job;
        }
        return $result;
    }
}
