<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 构建期选择本组件携带的固定 Swoole 模块；实际加载仍由 RuntimeProfile 验证。 */
final class BundledSwoole
{
    /**
     * 以组件安装位置定位，不受消费应用目录或当前工作目录影响。
     *
     * @return array{file:string,sha256:string,manifest:string}
     * @throws RuntimeException 清单缺失、模块不匹配或内容校验失败。
     */
    public function select(): array
    {
        $manifest = dirname(__DIR__) . '/resources/swoole/manifest.json';
        if (!is_file($manifest)) {
            throw new RuntimeException('构建组件缺少内置 Swoole 清单，请重新安装完整的 type-build');
        }
        $data = json_decode((string) file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['modules'] ?? null)) {
            throw new RuntimeException('内置 Swoole 清单格式无效');
        }
        foreach (['SwooleThreadSource', 'SwooleHttpSource', 'SwooleSocketSource'] as $patch) {
            $expected = $data['patches'][$patch] ?? null;
            if (!is_string($expected) || !hash_equals($expected, (string) hash_file('sha256', __DIR__ . '/' . $patch . '.php'))) {
                throw new RuntimeException('内置 Swoole 与当前源码适配不一致，请重新构建模块：' . $patch);
            }
        }
        $architecture = strtolower(php_uname('m'));
        $architecture = match ($architecture) {
            'x86_64', 'amd64', 'x64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => $architecture,
        };
        $target = PHP_OS_FAMILY . '-' . $architecture . '-' . PHP_VERSION . (PHP_ZTS ? '-zts' : '-nts');
        $module = $data['modules'][$target] ?? null;
        if (PHP_DEBUG || PHP_INT_SIZE !== 8 || !is_array($module)) {
            throw new RuntimeException('没有匹配当前 PHP ABI 的内置 Swoole：' . $target);
        }
        $relative = $module['file'] ?? null;
        $expected = $module['sha256'] ?? null;
        if (!is_string($relative) || preg_match('#^[a-z0-9_-]+/php-[0-9.]+-zts/(?:swoole\.so|php_swoole\.dll)$#D', $relative) !== 1
            || !is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            throw new RuntimeException('内置 Swoole 文件路径或摘要无效');
        }
        $directory = realpath(dirname($manifest));
        $file = realpath(dirname($manifest) . '/' . $relative);
        if ($directory === false || $file === false || !is_file($file)
            || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $directory) . '/')) {
            throw new RuntimeException('内置 Swoole 文件缺失或超出模块目录：' . $relative);
        }
        if (!hash_equals($expected, (string) hash_file('sha256', $file))) {
            throw new RuntimeException('内置 Swoole 文件摘要不一致：' . $relative);
        }
        return ['file' => $file, 'sha256' => $expected, 'manifest' => (string) realpath($manifest)];
    }
}
