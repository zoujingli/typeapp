<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 从已授权取得的标准模板创建项目；不运行模板PHP，不携带vendor、测试实验或秘密。 */
final class ProjectCreator
{
    /** @return array{directory:string, driver:string, files:int, source-sha256:string} */
    public function create(string $template, string $destination, string $driver = 'sqlite'): array
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('创建项目只接受已支持的三种驱动');
        }
        $template = BuildPlatform::resolve($template);
        $composer = json_decode((string) file_get_contents($template . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $definition = $composer['extra']['type-template'] ?? null;
        if (!is_array($definition) || ($definition['protocol'] ?? null) !== 1 || !is_array($definition['paths'] ?? null)
            || !is_string($definition['driver-target'] ?? null) || !is_string($definition['drivers'][$driver] ?? null)) {
            throw new RuntimeException('需要支持所选驱动的协议1标准模板');
        }
        $parent = BuildPlatform::resolve(dirname($destination));
        $destination = $parent . '/' . basename($destination);
        BuildLock::path($destination);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('新项目目录已存在，不能覆盖');
        }
        $sources = [];
        $sourceBytes = 0;
        foreach ($definition['paths'] as $relative) {
            if (!is_string($relative)) {
                throw new RuntimeException('模板路径声明无效');
            }
            $path = $this->source($template, $relative);
            $entries = is_file($path) ? [$path] : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                $file = is_string($entry) ? $entry : $entry->getPathname();
                if (!is_file($file) || is_link($file)) {
                    throw new RuntimeException('模板只允许普通文件');
                }
                $name = substr(BuildPlatform::path($file), strlen($template) + 1);
                foreach (explode('/', $name) as $segment) {
                    if (in_array($segment, ['.git', '.env', 'auth.json', 'vendor', 'build', '.cache', 'node_modules', 'id_rsa', 'id_ed25519'], true)) {
                        throw new RuntimeException('模板包含开发缓存、依赖目录或秘密文件');
                    }
                }
                if (filesize($file) > 2097152 || (!isset($sources[$name]) && count($sources) >= 1000)) {
                    throw new RuntimeException('标准模板超过大小上限');
                }
                if (!isset($sources[$name])) {
                    $sourceBytes += filesize($file);
                }
                if ($sourceBytes > 33554432) {
                    throw new RuntimeException('标准模板总大小超过32MiB');
                }
                $sources[$name] = ['source' => $file, 'sha256' => hash_file('sha256', $file)];
            }
        }
        if (!isset($sources['composer.json'], $sources['type-app.json'], $sources[$definition['drivers'][$driver]])) {
            throw new RuntimeException('模板没有完整声明项目、构建配置和驱动输入');
        }
        $target = $definition['driver-target'];
        if (!isset($sources[$target], $sources['.env.example'])) {
            throw new RuntimeException('模板遗漏驱动目标或配置示例');
        }
        $this->source($template, $target);
        $stage = $destination . '.creating-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('无法创建新项目暂存目录');
        }
        try {
            foreach ($sources as $name => $source) {
                $file = $stage . '/' . $name;
                BuildLock::path($file);
                if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true)) {
                    throw new RuntimeException('无法创建项目目录');
                }
                if (!copy($source['source'], $file) || hash_file('sha256', $file) !== $source['sha256']) {
                    throw new RuntimeException('模板输入在复制中发生变化');
                }
            }
            foreach (['mysql', 'pgsql', 'sqlite'] as $candidate) {
                unset($composer['require']['zoujingli/type-orm-' . $candidate]);
            }
            $composer['require']['zoujingli/type-orm-' . $driver] = '~1.0.0@dev';
            foreach ($composer['repositories'] ?? [] as $index => $repository) {
                if (preg_match('~/type-orm-(mysql|pgsql|sqlite)\.git$~', $repository['url'])) {
                    $composer['repositories'][$index]['url'] = 'https://github.com/zoujingli/type-orm-' . $driver . '.git';
                }
            }
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($destination))), '-');
            $composer['name'] = 'app/' . ($slug === '' ? 'application' : $slug);
            unset($composer['extra']['type-template']);
            if (($composer['extra'] ?? null) === []) {
                unset($composer['extra']);
            }
            if (!copy($stage . '/' . $definition['drivers'][$driver], $stage . '/' . $target)
                || hash_file('sha256', $stage . '/' . $target) !== $sources[$definition['drivers'][$driver]]['sha256']) {
                throw new RuntimeException('无法配置已选择驱动');
            }
            $json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($stage . '/composer.json', $json) !== strlen($json)) {
                throw new RuntimeException('无法完整写入项目依赖声明');
            }
            (new ConfigurationExample())->read($stage . '/.env.example');
            ksort($sources);
            $identity = BuildIdentity::digest(array_map(static fn (array $source): string => $source['sha256'], $sources));
            $origin = json_encode(['protocol' => 1, 'source-sha256' => $identity, 'driver' => $driver], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($stage . '/.project-origin.json', $origin) !== strlen($origin)) {
                throw new RuntimeException('无法完整写入模板来源');
            }
            if (!rename($stage, $destination)) {
                throw new RuntimeException('无法发布新项目目录');
            }
            return ['directory' => $destination, 'driver' => $driver, 'files' => count($sources), 'source-sha256' => $identity];
        } finally {
            if (is_dir($stage)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                    if ($entry->isDir() && !$entry->isLink()) {
                        rmdir($entry->getPathname());
                    } else {
                        unlink($entry->getPathname());
                    }
                }
                rmdir($stage);
            }
        }
    }

    private function source(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, "\0") || str_starts_with($relative, '/')) {
            throw new RuntimeException('模板路径必须为相对路径');
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('模板路径不能跳转');
            }
        }
        $file = $root . '/' . $relative;
        BuildLock::path($file);
        $resolved = BuildPlatform::resolve($file);
        if (!BuildPlatform::contains($root, $resolved)) {
            throw new RuntimeException('模板路径越界');
        }
        return $resolved;
    }
}
