<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 收集显式源码并核对 Composer 的生产自动加载入口。 */
final class SourceSet
{
    /**
     * 开发生成同样扫描实际安装的生产依赖，不扫描 require-dev 或执行自动加载文件。
     * @return array{sources:list<string>, source-sets:array, declarations:list<string>} 经协议核对的源码、适配与依赖声明。
     */
    public function productionSources(string $root, array $build): array
    {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $vendor = $composer['config']['vendor-dir'] ?? 'vendor';
        $vendor = BuildPlatform::resolve((new BuildPlatform())->absolute($vendor) ? $vendor : $root . '/' . $vendor);
        $installed = json_decode((string) file_get_contents($vendor . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = [];
        foreach ($installed['packages'] ?? [] as $package) {
            $packages[$package['name']] = $package;
        }
        $selected = [];
        $queue = array_keys($composer['require'] ?? []);
        while ($queue !== []) {
            $name = array_shift($queue);
            if (!str_contains($name, '/') || isset($selected[$name])) {
                continue;
            }
            $package = $packages[$name] ?? throw new RuntimeException('生产依赖未安装：' . $name);
            $selected[$name] = $package;
            array_push($queue, ...array_keys($package['require'] ?? []));
        }
        $imports = ($build['imports'] ?? []) + ($composer['extra']['type']['imports'] ?? []);
        $applicationImports = array_fill_keys(array_keys($imports), true);
        foreach ($selected as $package) {
            foreach ($package['extra']['type']['imports'] ?? [] as $name => $declaration) {
                if (isset($applicationImports[$name])) {
                    continue;
                }
                if (isset($imports[$name]) && $imports[$name] !== $declaration) {
                    throw new RuntimeException('多个生产包提供冲突的依赖适配：' . $name);
                }
                $imports[$name] = $declaration;
            }
        }
        $sources = [];
        $sets = [];
        foreach ($selected as $name => $package) {
            $metadata = $package['extra']['type'] ?? [];
            if ($metadata === [] && isset($imports[$name])) {
                $metadata = $imports[$name];
                if (($metadata['version'] ?? null) !== ltrim($package['version'], 'v')) {
                    throw new RuntimeException('第三方适配版本与已安装包不一致：' . $name);
                }
            }
            $packageRoot = BuildPlatform::resolve($vendor . '/composer/' . $package['install-path']);
            $sets[$name] = $this->describe($packageRoot, $package, $metadata);
            array_push($sources, ...$sets[$name]['sources']);
        }
        return ['sources' => array_values(array_unique($sources)), 'source-sets' => $sets,
            'declarations' => [$vendor . '/composer/installed.json']];
    }

    public function describe(string $root, array $package, array $metadata): array
    {
        if (($metadata['protocol'] ?? null) !== 1 || !is_array($metadata['sources'] ?? null) || $metadata['sources'] === []) {
            throw new RuntimeException('生产依赖缺少支持的编译声明：' . $package['name']);
        }
        $excluded = [];
        foreach ($metadata['exclusions'] ?? [] as $item) {
            if (!is_array($item) || !is_string($item['path'] ?? null) || !is_string($item['reason'] ?? null) || trim($item['reason']) === '') {
                throw new RuntimeException('排除声明必须有路径与原因：' . $package['name']);
            }
            $excluded[] = ['path' => $this->resolve($root, $item['path']), 'reason' => $item['reason']];
        }
        $files = [];
        foreach ($metadata['sources'] as $source) {
            if (!is_string($source)) {
                throw new RuntimeException('源码声明必须是字符串：' . $package['name']);
            }
            foreach ($this->files($this->resolve($root, $source), $root) as $file) {
                $ignored = false;
                foreach ($excluded as $exclusion) {
                    if ($file === $exclusion['path'] || str_starts_with($file, $exclusion['path'] . '/')) {
                        $ignored = true;
                        break;
                    }
                }
                if (!$ignored) {
                    $files[$file] = true;
                }
            }
        }
        if ($files === []) {
            throw new RuntimeException('生产包没有可编译源码：' . $package['name']);
        }
        $audited = $this->auditAutoload($root, $package, array_keys($files));
        $resources = $this->resources($root, $package['name'], $metadata['resources'] ?? []);
        ksort($files);
        $rewrites = [];
        if (!is_array($metadata['rewrites'] ?? []) || !array_is_list($metadata['rewrites'] ?? []) || count($metadata['rewrites'] ?? []) > 256) {
            throw new RuntimeException('源码适配必须为有限的声明列表');
        }
        foreach ($metadata['rewrites'] ?? [] as $rewrite) {
            if (!is_array($rewrite) || !is_string($rewrite['source'] ?? null) || !is_string($rewrite['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $rewrite['sha256']) || !is_string($rewrite['reason'] ?? null) || trim($rewrite['reason']) === ''
                || !is_array($rewrite['replacements'] ?? null) || !array_is_list($rewrite['replacements']) || $rewrite['replacements'] === [] || count($rewrite['replacements']) > 100) {
                throw new RuntimeException('源码适配需要明确文件、原始摘要、原因及有限替换：' . $package['name']);
            }
            $file = $this->resolve($root, $rewrite['source']);
            if (!isset($files[$file]) || pathinfo($file, PATHINFO_EXTENSION) !== 'php' || isset($rewrites[$file])) {
                throw new RuntimeException('源码适配必须指向已完整审计且不重复的 PHP 输入');
            }
            foreach ($rewrite['replacements'] as $replacement) {
                if (!is_array($replacement) || !is_string($replacement['from'] ?? null) || $replacement['from'] === '' || !is_string($replacement['to'] ?? null)
                    || strlen($replacement['from']) > 1048576 || strlen($replacement['to']) > 1048576 || !is_int($replacement['count'] ?? null) || $replacement['count'] < 1 || $replacement['count'] > 1000
                    || substr_count($replacement['from'], "\n") !== substr_count($replacement['to'], "\n")) {
                    throw new RuntimeException('源码替换必须声明次数并保持原行号');
                }
            }
            $rewrites[$file] = ['package' => $package['name'], 'source' => $file, 'sha256' => $rewrite['sha256'], 'reason' => $rewrite['reason'], 'replacements' => $rewrite['replacements']];
        }
        return ['sources' => array_keys($files), 'autoload-inputs' => $audited, 'exclusions' => $excluded, 'resources' => $resources, 'rewrites' => array_values($rewrites)];
    }

    /**
     * 对照实际编译输入审计根应用或依赖包的生产自动加载入口。
     *
     * @param list<string> $sources 已展开并解析的源码文件。
     * @return list<string> 完整审计的自动加载路径，供隔离输入保留目录。
     * @throws RuntimeException 路径无效、越界或生产自动加载源码未纳入编译。
     */
    public function auditAutoload(string $root, array $package, array $sources): array
    {
        $files = array_fill_keys($sources, true);
        $autoloadPaths = [];
        foreach (['psr-4', 'psr-0'] as $kind) {
            foreach ($package['autoload'][$kind] ?? [] as $paths) {
                array_push($autoloadPaths, ...(is_array($paths) ? $paths : [$paths]));
            }
        }
        array_push($autoloadPaths, ...($package['autoload']['classmap'] ?? []), ...($package['autoload']['files'] ?? []));
        $audited = [];
        foreach ($autoloadPaths as $autoloadPath) {
            if (!is_string($autoloadPath)) {
                throw new RuntimeException('自动加载路径无效：' . $package['name']);
            }
            $matches = glob($root . '/' . $autoloadPath, GLOB_BRACE);
            if ($matches === false || $matches === []) {
                throw new RuntimeException('自动加载入口不存在或无法解释：' . $package['name'] . ' -> ' . $autoloadPath);
            }
            foreach ($matches as $match) {
                $path = $this->resolve($root, substr($match, strlen($root) + 1));
                foreach ($this->files($path, $root) as $file) {
                    if (pathinfo($file, PATHINFO_EXTENSION) === 'php' && !isset($files[$file])) {
                        throw new RuntimeException('编译声明遗漏生产自动加载源码：' . $package['name'] . ' -> ' . $file);
                    }
                }
                $audited[$path] = true;
            }
        }
        return array_keys($audited);
    }

    /** 应用与依赖包共享同一资源所有权、路径和摘要规则。 */
    public function resources(string $root, string $owner, array $declarations): array
    {
        if (!array_is_list($declarations) || count($declarations) > 1000) {
            throw new RuntimeException('资源声明必须为最多1000项的列表');
        }
        $resources = [];
        foreach ($declarations as $resource) {
            if (!is_array($resource) || !is_string($resource['source'] ?? null) || !is_string($resource['target'] ?? null)
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $resource['target'])) {
                throw new RuntimeException('资源声明必须指定安全的相对目标：' . $owner);
            }
            foreach (explode('/', $resource['target']) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new RuntimeException('资源目标不能包含路径跳转');
                }
            }
            $file = $this->resolve($root, $resource['source']);
            if (!is_file($file)) {
                throw new RuntimeException('当前资源声明只接受文件：' . $owner);
            }
            if (preg_match('/^(?:\.env(?:\..*)?|auth\.json|id_(?:rsa|ed25519|ecdsa|dsa))$/iD', basename($file))
                || preg_match('/\.(?:php[0-9]?|phtml|phar|inc)$/iD', $file)) {
                throw new RuntimeException('配置秘密与PHP源码不能声明为运行资源；配置示例使用专用发布入口');
            }
            if (isset($resources[$resource['target']])) {
                throw new RuntimeException('资源目标重复：' . $resource['target']);
            }
            $resources[$resource['target']] = ['package' => $owner, 'source' => $file, 'target' => $resource['target'], 'sha256' => hash_file('sha256', $file)];
        }
        return array_values($resources);
    }

    private function resolve(string $root, string $relative): string
    {
        $path = BuildPlatform::resolve($root . '/' . $relative);
        if ($path !== $root && !BuildPlatform::contains($root, $path)) {
            throw new RuntimeException('源码或资源路径不存在或超出所属包：' . $relative);
        }

        return $path;
    }

    private function files(string $path, string $root): array
    {
        $candidates = is_file($path) ? [$path] : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($candidates as $candidate) {
            $filename = is_string($candidate) ? $candidate : $candidate->getPathname();
            if (!is_file($filename) || !in_array(pathinfo($filename, PATHINFO_EXTENSION), ['php', 'c', 'cc', 'cpp', 'cxx'], true)) {
                continue;
            }
            $real = BuildPlatform::resolve($filename);
            if (!BuildPlatform::contains($root, $real)) {
                throw new RuntimeException('源码链接超出所属包：' . $filename);
            }
            $files[$real] = true;
        }

        return array_keys($files);
    }
}
