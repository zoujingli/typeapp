<?php

declare(strict_types=1);

namespace Type\Build;

/** 生成与应用一起AOT的发布目录校验；只读取声明的数据文件，不加载PHP源码。 */
final class PackageRuntime
{
    /** 返回BuildIdentity内的方法；仅Darwin选择随产物编译的原生摘要函数。 */
    public function methods(string $platform = 'Linux'): string
    {
        return str_replace('TYPE_PACKAGE_SHA256(', $platform === 'Darwin' ? '\\type_app_native_file_sha256(' : "hash_file('sha256', ", <<<'PHP'
    /** 启动器明确指定发布根；缺失表示传统构建目录运行，不猜测当前工作目录。 */
    private static function packageRoot(array $manifest): string
    {
        $configuredRoot = getenv('TYPE_APP_RUNTIME_ROOT');
        if ($configuredRoot === false || $configuredRoot === '') { return ''; }
        $configuredPath = (string) $configuredRoot;
        if (PHP_OS_FAMILY === 'Windows') { $configuredPath = (string) str_replace('\\', '/', $configuredPath); }
        $absolute = str_starts_with($configuredPath, '/');
        if (PHP_OS_FAMILY === 'Windows') { $absolute = preg_match('~^[A-Za-z]:/~D', $configuredPath) === 1; }
        if (preg_match('/[\x00-\x1f\x7f]/', $configuredPath) || !$absolute) {
            throw new \RuntimeException('发布根必须是明确的本地绝对路径');
        }
        $resolvedRoot = realpath($configuredRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) { throw new \RuntimeException('发布目录不存在'); }
        $root = (string) $resolvedRoot;
        if (PHP_OS_FAMILY === 'Windows') { $root = (string) str_replace('\\', '/', $root); }
        $descriptor = $root . '/release.json';
        if (!is_file($descriptor) || is_link($descriptor) || filesize($descriptor) > 4194304) { throw new \RuntimeException('发布清单缺失或过大'); }
        $trusted = getenv('TYPE_APP_RELEASE_SHA256');
        if ($trusted !== false && $trusted !== '' && (preg_match('/^[a-f0-9]{64}$/D', $trusted) !== 1
            || TYPE_PACKAGE_SHA256($descriptor) !== $trusted)) { throw new \RuntimeException('发布清单与外部受信摘要不一致'); }
        $release = json_decode((string) file_get_contents($descriptor), true, 128, JSON_THROW_ON_ERROR);
        $binary = 'bin/app' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (!is_array($release) || ($release['protocol'] ?? null) !== 1 || ($release['artifact']['path'] ?? null) !== $binary
            || ($release['artifact']['build-id'] ?? null) !== $manifest['build-id']
            || ($release['version'] ?? null) !== $manifest['version'] || !is_array($release['files'] ?? null)
            || count($release['files']) < 2 || count($release['files']) > 4096
            || ($release['files'][$binary]['sha256'] ?? null) !== ($release['artifact']['sha256'] ?? null)) {
            throw new \RuntimeException('发布清单不属于当前编译产物');
        }
        $launcher = PHP_OS_FAMILY === 'Windows' ? 'run.cmd' : 'run';
        if (($release['dependency-notices'] ?? null) !== ($manifest['dependency-notices'] ?? null)) {
            throw new \RuntimeException('发布依赖材料状态与编译身份不一致');
        }
        if (is_array($manifest['dependency-notices'] ?? null) && !isset($release['files']['NOTICES.md'])) {
            throw new \RuntimeException('发布遗漏依赖材料阅读入口');
        }
        foreach ([$launcher, 'runtime/php.ini', 'config/env.example', 'DEPLOY.md', 'LICENSE', 'NOTICE'] as $requiredFile) {
            if (!isset($release['files'][$requiredFile])) { throw new \RuntimeException('发布清单遗漏必需文件'); }
        }
        foreach ($release['files'] as $relative => $entry) {
            if (!is_string($relative) || !is_array($entry) || !is_string($entry['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1 || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0) {
                throw new \RuntimeException('发布文件摘要声明无效');
            }
            $file = self::packageFile($root, $relative);
            if (filesize($file) !== $entry['bytes'] || TYPE_PACKAGE_SHA256($file) !== $entry['sha256']) { throw new \RuntimeException('发布文件完整性校验失败'); }
        }
        if (PHP_OS_FAMILY !== 'Windows' && (!is_executable(self::packageFile($root, $binary)) || !is_executable(self::packageFile($root, 'run')))) {
            throw new \RuntimeException('发布程序或启动器缺少执行权限');
        }
        foreach ($manifest['resources'] ?? [] as $resource) {
            $resourceName = $binary . '.resources/' . $manifest['resource-generation'] . '/' . $resource['target'];
            $resourceFile = self::packageFile($root, $resourceName);
            if (TYPE_PACKAGE_SHA256($resourceFile) !== $resource['sha256']) { throw new \RuntimeException('资源与编译身份不一致'); }
        }
        return $root;
    }

    /** 路径必须在发布根内，不能通过遍历或目录链接读取外部文件。 */
    private static function packageFile(string $root, string $relative): string
    {
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._/+\-]{0,511}$~D', $relative) !== 1
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $relative) || str_contains($relative, '//')
            || preg_match('/\.(?:php[0-9]?|phtml|phar|inc)$/iD', $relative)) { throw new \RuntimeException('发布文件路径无效'); }
        $candidate = $root . '/' . $relative;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || is_link($candidate)) { throw new \RuntimeException('发布文件缺失或为链接'); }
        $normalized = (string) $resolved;
        if (PHP_OS_FAMILY === 'Windows') { $normalized = (string) str_replace('\\', '/', $normalized); }
        $prefix = rtrim($root, '/') . '/';
        $inside = (bool) str_starts_with($normalized, $prefix);
        if (PHP_OS_FAMILY === 'Windows') { $inside = (bool) str_starts_with(strtolower($normalized), strtolower($prefix)); }
        if (!$inside) { throw new \RuntimeException('发布文件越过部署目录'); }
        return $normalized;
    }

    /** 发布模式不回退到构建SDK；系统DLL保持操作系统原路径。 */
    private static function libraryPath(array $library, array $overrides, string $root): string
    {
        $name = $library['name'];
        if (array_key_exists($name, $overrides)) { return (string) $overrides[$name]; }
        if ($root === '' || ($library['system'] ?? false)) { return (string) $library['path']; }
        return self::packageFile($root, (PHP_OS_FAMILY === 'Windows' ? 'bin/' : 'lib/') . $name);
    }
PHP);
    }
}
