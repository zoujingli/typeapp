<?php

declare(strict_types=1);

namespace TypeApp\Distribution;

/** 不使用 force；标签创建竞争由远端原子引用更新裁决。 */
final class Publisher
{
    /**
     * 按固定计划推进一个子仓的分支或不可变标签，回读远端引用后才报告成功。
     * @param array<string, mixed> $plan Batch 生成且已完成范围与许可核验的计划。
     * @return array<string, mixed> 当前组件的发布回执；历史偏离或写入失败返回 failed。
     * @throws \RuntimeException 组件不属于计划，尚未进行任何远端写入。
     */
    public static function publish(string $root, string $remote, array $plan, string $name): array
    {
        $item = $plan['items'][$name] ?? throw new \RuntimeException('插件不在本批次内');
        $report = ['batch' => $plan['id'], 'source' => $plan['source'], 'package' => $item['package'], 'repository' => $item['repository'],
            'split' => $item['split'], 'version' => $plan['version'], 'mode' => $plan['mode']];
        try {
            $branch = 'refs/heads/' . $item['branch'];
            $ref = $plan['mode'] === 'branch' ? $branch : 'refs/tags/' . $plan['version'];
            $previous = self::reference($root, $remote, $ref);
            if ($plan['mode'] === 'tag' && $previous !== null && $previous !== $item['split']) {
                throw new \RuntimeException('同名稳定标签指向不同内容，禁止移动');
            }
            if ($plan['mode'] === 'tag' && $previous === $item['split']) {
                return $report + ['status' => 'already-current', 'reference' => $ref, 'previous' => $previous];
            }
            $head = self::reference($root, $remote, $branch);
            if ($head !== null && $head !== $item['split']) {
                Process::output(['git', 'fetch', '--no-tags', $remote, $branch], $root);
                [$status] = Process::run(['git', 'merge-base', '--is-ancestor', $head, $item['split']], $root);
                if ($status !== 0) {
                    throw new \RuntimeException('子仓历史偏离或批次过期，拒绝覆盖');
                }
            }
            if ($previous !== $item['split']) {
                [$status, , $error] = Process::run(['git', 'push', $remote, $item['split'] . ':' . $ref], $root);
                if ($status !== 0 && self::reference($root, $remote, $ref) !== $item['split']) {
                    throw new \RuntimeException('分发引用更新失败：' . $error);
                }
            }
            if (self::reference($root, $remote, $ref) !== $item['split']) {
                throw new \RuntimeException('分发回读与预期内容不一致');
            }
            return $report + ['status' => $previous === $item['split'] ? 'already-current' : 'published', 'reference' => $ref, 'previous' => $previous];
        } catch (\Throwable $error) {
            return $report + ['status' => 'failed', 'error' => $error->getMessage()];
        }
    }

    private static function reference(string $root, string $remote, string $ref): ?string
    {
        $lines = Process::output(['git', 'ls-remote', $remote, $ref, $ref . '^{}'], $root);
        $value = null;
        foreach ($lines === '' ? [] : explode("\n", $lines) as $line) {
            [$sha, $name] = explode("\t", $line);
            if ($name === $ref . '^{}') {
                return $sha;
            }
            if ($name === $ref) {
                $value = $sha;
            }
        }
        return $value;
    }
}
