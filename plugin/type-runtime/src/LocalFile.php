<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 受信任本地目录中的普通文件边界；不支持 URL、UNC、设备路径及链接别名。 */
final class LocalFile
{
    /**
     * 校验显式绝对文件路径，父目录必须已存在；Windows 仅支持完整盘符路径。
     * 返回统一分隔符，不依赖工作目录，也不创建文件或目录。
     * @param bool $inspect false 仅检查路径语法；延迟打开的调用方须在打开前后执行完整检查。
     * @throws \InvalidArgumentException 路径越界、目录缺失或存在链接/特殊文件。
     */
    public static function path(string $path, bool $inspect = true): string
    {
        $normalized = PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $path) : $path;
        $absolute = PHP_OS_FAMILY === 'Windows' ? preg_match('/^[A-Za-z]:\//D', $normalized) === 1 : str_starts_with($normalized, '/');
        if (!$absolute || preg_match('/[\x00-\x1f\x7f]/', $normalized) === 1 || str_contains($normalized, '://')
            || str_contains($normalized, '//') || str_ends_with($normalized, '/') || str_contains($normalized, '\\')) {
            throw new \InvalidArgumentException('本地文件需要完整绝对路径，不接受 URL、UNC 或设备路径');
        }
        $relative = PHP_OS_FAMILY === 'Windows' ? substr($normalized, 3) : substr($normalized, 1);
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || (PHP_OS_FAMILY === 'Windows' && (preg_match('/[:*?"<>|]|[. ]$/D', $part) === 1
                    || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part) === 1))) {
                throw new \InvalidArgumentException('本地文件路径含越界或不支持的分量');
            }
        }
        if (!$inspect) {
            return $normalized;
        }
        $directory = dirname($normalized);
        while (true) {
            clearstatcache(true, $directory);
            if (is_link($directory) || !is_dir($directory)) {
                throw new \InvalidArgumentException('本地文件父目录必须存在且不能是符号链接');
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }
        clearstatcache(true, $normalized);
        $stat = @lstat($normalized);
        if (is_link($normalized) || ($stat !== false && (($stat['mode'] & 0170000) !== 0100000
            || (PHP_OS_FAMILY !== 'Windows' && ($stat['nlink'] ?? 0) !== 1)))) {
            throw new \InvalidArgumentException('本地文件不能是链接或特殊文件');
        }
        return $normalized;
    }

    /** 打开后复核路径与句柄身份；调用方负责关闭句柄，父目录须由受信任账户管理。 */
    public static function assertOpened(string $path, mixed $stream): void
    {
        self::path($path);
        $entry = @lstat($path);
        $opened = is_resource($stream) ? fstat($stream) : false;
        if ($entry === false || $opened === false || ($opened['mode'] & 0170000) !== 0100000
            || (PHP_OS_FAMILY !== 'Windows' && ($entry['dev'] !== $opened['dev'] || $entry['ino'] !== $opened['ino']))) {
            throw new \RuntimeException('本地文件打开前后身份发生变化');
        }
    }
}
