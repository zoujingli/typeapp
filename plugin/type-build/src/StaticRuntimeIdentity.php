<?php

declare(strict_types=1);

namespace Type\Build;

/** 静态程序的部署校验只依赖自身与目标系统，不读取构建机 SDK、目录包清单或动态库副本。 */
final class StaticRuntimeIdentity
{
    /** @param array<string,mixed> $manifest 已确定的应用、模块和静态输入身份。 */
    public function accessor(array $manifest): string
    {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace Type\Generated;

/** 与主程序一起编译的身份；在执行命令前核对 ABI、内置扩展及实际加载项。 */
final class BuildIdentity
{
    public static function info(): array { return TYPE_MANIFEST_LITERAL; }

    /** 不接受外置运行库覆盖；系统更新不要求与构建机使用相同的共享缓存字节。 */
    public static function verifyRuntime(array $libraries = []): void
    {
        if ($libraries !== []) { throw new \RuntimeException('静态程序不接受外置运行库覆盖'); }
        $manifest = self::info();
        foreach (['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m'), 'os' => PHP_OS_FAMILY] as $key => $actual) {
            if ($manifest['runtime'][$key] !== $actual) { throw new \RuntimeException('运行 ABI 与构建身份不一致：' . $key); }
        }
        foreach ($manifest['runtime']['extensions'] as $name => $version) {
            if (!extension_loaded($name) || phpversion($name) !== $version) { throw new \RuntimeException('内置扩展身份不一致：' . $name); }
        }
        foreach ($manifest['runtime']['functions'] ?? [] as $functionName) {
            if (!function_exists($functionName)) { throw new \RuntimeException('缺少内置运行函数：' . $functionName); }
        }
        if (php_ini_loaded_file() !== false || php_ini_scanned_files() !== false) { throw new \RuntimeException('静态程序不能读取外部 PHP INI'); }
        $program = \type_app_native_embedded_core();
        if ($program === '') { throw new \RuntimeException('PHP 核心或扩展未静态链接到主程序'); }
        $executable = (string) realpath($program);
        if ($executable === '') { throw new \RuntimeException('无法定位静态主程序'); }
        $images = \type_app_native_loaded_images();
        if ($images === []) { throw new \RuntimeException('无法读取系统加载项'); }
        foreach ($images as $image) {
            $parts = explode("\n", $image, 2);
            $path = $parts[0];
            if ($path === $program || (string) realpath($path) === $executable) { continue; }
            if (PHP_OS_FAMILY === 'Darwin' && (str_starts_with($path, '/usr/lib/') || str_starts_with($path, '/System/Library/'))) { continue; }
            if (PHP_OS_FAMILY === 'Linux' && ($path === 'linux-vdso.so.1'
                || (preg_match('~^/(?:usr/)?lib(?:64)?/(?:[A-Za-z0-9_-]+/)?(?:libc\.so\.6|libm\.so\.6|libdl\.so\.2|libpthread\.so\.0|libresolv\.so\.2|libgcc_s\.so\.1|libstdc\+\+\.so\.6|ld-linux[^/]+\.so\.[0-9]+)$~D', $path) === 1))) { continue; }
            throw new \RuntimeException('单程序加载了非系统运行库：' . $path);
        }
    }

    /** 显式部署审计同时逐块核对所有内嵌资源；普通启动不解包或释放任何文件。 */
    public static function verifyDeployment(array $libraries = []): void
    {
        self::verifyRuntime($libraries);
        foreach (EmbeddedResources::manifest() as $path => $file) {
            $hash = hash_init('sha256');
            $offset = 0;
            while ($offset < $file['bytes']) {
                $chunk = EmbeddedResources::read($path, $offset, min(65536, $file['bytes'] - $offset));
                if ($chunk === '') { throw new \RuntimeException('内嵌资源不完整：' . $path); }
                hash_update($hash, $chunk);
                $offset += strlen($chunk);
            }
            if (hash_final($hash) !== $file['sha256']) { throw new \RuntimeException('内嵌资源摘要不一致：' . $path); }
        }
    }
}
PHP;
        return str_replace('TYPE_MANIFEST_LITERAL', var_export($manifest, true), $source);
    }
}
