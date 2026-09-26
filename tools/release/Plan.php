<?php

declare(strict_types=1);

namespace TypeApp\Release;

use Composer\Semver\Semver;
use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;

/** 版本由不可变tag和固定main历史确定，包依赖不能在发布时偷偷改写。 */
final class Plan
{
    /** @throws \InvalidArgumentException 不接受模糊版本、分支或其他预发布格式。 */
    public static function version(string $version): void
    {
        if (!preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $version)) {
            throw new \InvalidArgumentException('版本必须为vX.Y.Z或vX.Y.Z-rc.N');
        }
    }

    /** @return array<string,mixed> 包含16个准确拆分提交的只读发布计划。 */
    public static function create(string $root, string $version): array
    {
        self::version($version);
        $source = Process::output(['git', 'rev-parse', '--verify', 'refs/tags/' . $version . '^{commit}'], $root);
        if (!preg_match('/^[a-f0-9]{40}$/D', $source)) {
            throw new \RuntimeException('版本没有解析为完整提交');
        }
        Process::output(['git', 'merge-base', '--is-ancestor', $source, 'origin/main'], $root);
        $mapping = json_decode(Process::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 64, JSON_THROW_ON_ERROR);
        $batch = Batch::plan($root, $source, 'tag', $version, $mapping);
        if (count($batch['items']) !== 15) {
            throw new \RuntimeException('版本发布要求完整15组件映射');
        }
        $template = json_decode(Process::output(['git', 'show', $source . ':.github/template-distribution.json'], $root), true, 32, JSON_THROW_ON_ERROR);
        if (($template['repository'] ?? '') !== 'zoujingli/type-project' || ($template['prefix'] ?? '') !== 'templates/type-project') {
            throw new \RuntimeException('模板映射无效');
        }
        $split = Process::output(['git', 'subtree', 'split', '--prefix=templates/type-project', '--ignore-joins', $source], $root);
        $items = $batch['items'];
        $items['type-project'] = ['repository' => $template['repository'], 'package' => $template['composer-name'], 'split' => $split];
        foreach ($items as $name => &$item) {
            $prefix = $name === 'type-project' ? 'templates/type-project' : 'plugin/' . $name;
            $composer = json_decode(Process::output(['git', 'show', $source . ':' . $prefix . '/composer.json'], $root), true, 64, JSON_THROW_ON_ERROR);
            self::dependencies($composer, $version, array_column($items, 'package'));
            $item['description'] = $composer['description'];
            $references = Process::output(['git', 'ls-remote', 'https://github.com/' . $item['repository'] . '.git',
                'refs/tags/' . $version, 'refs/tags/' . $version . '^{}'], $root);
            $actual = null;
            foreach ($references === '' ? [] : explode("\n", $references) as $line) {
                [$sha, $ref] = explode("\t", $line);
                $actual = $sha;
                if (str_ends_with($ref, '^{}')) {
                    break;
                }
            }
            if ($actual !== null && $actual !== $item['split']) {
                throw new \RuntimeException('子仓同名tag内容冲突：' . $name);
            }
        }
        unset($item);
        return ['protocol' => 1, 'version' => $version, 'source' => $source, 'prerelease' => str_contains($version, '-rc.'), 'items' => $items];
    }

    /** 同批第一方组件必须满足真实Composer约束，稳定性由消费者显式声明。 */
    public static function dependencies(array $composer, string $version, array $packages): void
    {
        self::version($version);
        foreach (array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []) as $name => $constraint) {
            if (str_starts_with($name, 'zoujingli/type-')
                && (!in_array($name, $packages, true) || !Semver::satisfies(substr($version, 1), $constraint))) {
                throw new \RuntimeException('同版本不满足组件依赖：' . $composer['name'] . ' → ' . $name . ' ' . $constraint);
            }
        }
    }
}
