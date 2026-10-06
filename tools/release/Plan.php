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
        $metadata = [];
        foreach ($items as $name => &$item) {
            $prefix = $name === 'type-project' ? 'templates/type-project' : 'plugin/' . $name;
            $composer = json_decode(Process::output(['git', 'show', $source . ':' . $prefix . '/composer.json'], $root), true, 64, JSON_THROW_ON_ERROR);
            self::dependencies($composer, $version, array_column($items, 'package'));
            $metadata[$item['package']] = $composer;
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
        if (self::templateDependencies($metadata['zoujingli/type-project'], $version, $metadata) != $metadata['zoujingli/type-project']) {
            throw new \RuntimeException('模板未在 tag 前固定完整组件批次；请先在 main 执行 release.php prepare 并提交，不能改写已有 tag');
        }
        return ['protocol' => 1, 'version' => $version, 'source' => $source, 'prerelease' => str_contains($version, '-rc.'), 'items' => $items];
    }

    /**
     * 在 tag 之前准备正常模板源码；重复准备同一版本不改字节，不创建提交或标签。
     *
     * @return array{version:string, file:string, changed:bool, sha256:string}
     * @throws \RuntimeException 版本已有标签、组件约束冲突或模板无法安全写入。
     */
    public static function prepareTemplate(string $root, string $version): array
    {
        self::version($version);
        if (Process::output(['git', 'branch', '--show-current'], $root) !== 'main') {
            throw new \RuntimeException('模板版本准备必须在 main 完成并作为正常源码提交');
        }
        if (Process::output(['git', 'tag', '--list', $version], $root) !== '') {
            throw new \RuntimeException('版本标签已存在，不能重新准备其发布源码');
        }
        $mapping = json_decode((string) file_get_contents($root . '/.github/distribution.json'), true, 64, JSON_THROW_ON_ERROR);
        $components = [];
        foreach ($mapping['packages'] ?? [] as $name => $definition) {
            if (!preg_match('/^type-[a-z0-9-]+$/D', $name) || ($definition['prefix'] ?? '') !== 'plugin/' . $name
                || ($definition['composer-name'] ?? '') !== 'zoujingli/' . $name) {
                throw new \RuntimeException('模板版本准备遇到非法组件映射');
            }
            $component = json_decode((string) file_get_contents($root . '/' . $definition['prefix'] . '/composer.json'), true, 64, JSON_THROW_ON_ERROR);
            if (($component['name'] ?? '') !== $definition['composer-name']) {
                throw new \RuntimeException('模板版本准备遇到组件身份冲突');
            }
            $components[$component['name']] = $component;
        }
        // 可选择的三个驱动也必须接受本批次，不能到用户切换驱动后才发现冲突。
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            $component = $components['zoujingli/type-orm-' . $driver] ?? throw new \RuntimeException('模板缺少可选择的驱动组件');
            self::dependencies($component, $version, array_keys($components));
        }
        $relative = 'templates/type-project/composer.json';
        $file = $root . '/' . $relative;
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException('模板依赖声明必须是普通文件');
        }
        $original = (string) file_get_contents($file);
        $composer = json_decode($original, true, 64, JSON_THROW_ON_ERROR);
        $prepared = self::templateDependencies($composer, $version, $components);
        $changed = $composer != $prepared;
        if ($changed) {
            $json = json_encode($prepared, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $temporary = tempnam(dirname($file), '.version-');
            if ($temporary === false) {
                throw new \RuntimeException('无法暂存模板版本');
            }
            try {
                if (realpath(dirname($temporary)) !== realpath(dirname($file))
                    || file_put_contents($temporary, $json) !== strlen($json)
                    || !chmod($temporary, fileperms($file) & 0777) || file_get_contents($file) !== $original
                    || !rename($temporary, $file)) {
                    throw new \RuntimeException('无法安全写入模板版本，原声明保持不变');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
        return ['version' => $version, 'file' => $relative, 'changed' => $changed, 'sha256' => hash_file('sha256', $file)];
    }

    /**
     * 固定模板第一方依赖闭包，生产依赖优先；第三方约束与其他应用配置不变。
     *
     * @param array<string,mixed> $composer 模板原始 Composer 声明。
     * @param array<string,array<string,mixed>> $components 同一源码的组件声明，按 Composer 包名索引。
     * @return array<string,mixed> tag 前应提交的模板声明。
     * @throws \RuntimeException 模板驱动不唯一、闭包缺失或组件不接受该版本。
     */
    public static function templateDependencies(array $composer, string $version, array $components): array
    {
        self::version($version);
        $drivers = array_intersect_key($composer['require'] ?? [], array_flip([
            'zoujingli/type-orm-mysql', 'zoujingli/type-orm-pgsql', 'zoujingli/type-orm-sqlite',
        ]));
        if (($composer['name'] ?? '') !== 'zoujingli/type-project' || count($drivers) !== 1) {
            throw new \RuntimeException('版本模板必须保留唯一的默认数据库驱动');
        }
        if (($composer['repositories'] ?? []) !== []) {
            throw new \RuntimeException('版本模板必须从默认 Packagist 解析依赖，不能携带来源覆盖');
        }
        $production = [];
        foreach (['require', 'require-dev'] as $scope) {
            $composer[$scope] ??= [];
            if (!is_array($composer[$scope])) {
                throw new \RuntimeException('模板依赖声明必须是包名与版本约束映射');
            }
            $pending = array_keys($composer[$scope] ?? []);
            $resolved = [];
            while ($pending !== []) {
                $package = array_pop($pending);
                if (!str_starts_with($package, 'zoujingli/type-') || isset($resolved[$package])) {
                    continue;
                }
                $metadata = $components[$package] ?? throw new \RuntimeException('模板依赖不属于固定组件批次：' . $package);
                self::dependencies($metadata, $version, array_keys($components));
                $resolved[$package] = true;
                $pending = [...$pending, ...array_keys($metadata['require'] ?? [])];
            }
            foreach (array_keys($resolved) as $package) {
                if ($scope === 'require-dev' && isset($production[$package])) {
                    unset($composer['require-dev'][$package]);
                } else {
                    $composer[$scope][$package] = substr($version, 1);
                }
            }
            if ($scope === 'require') {
                $production = $resolved;
            }
            ksort($composer[$scope]);
        }
        $composer['minimum-stability'] = str_contains($version, '-rc.') ? 'RC' : 'stable';
        $composer['prefer-stable'] = true;
        return $composer;
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
