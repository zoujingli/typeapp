<?php

declare(strict_types=1);

namespace Type\Build;

use Closure;
use RuntimeException;

/** 构建文件锁及写入路径门禁，隔离并发构建并拒绝路径跳转。 */
final class BuildLock
{
    /** @param Closure(): mixed $operation 持锁后零参数调用。 */
    public static function run(string $path, Closure $operation, float $seconds = 30.0): mixed
    {
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 3600) {
            throw new RuntimeException('构建锁等待预算无效');
        }
        self::path($path);
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('无法打开构建锁');
        }
        $deadline = hrtime(true) / 1e9 + $seconds;
        try {
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    return $operation();
                }
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new RuntimeException('构建锁等待超时');
                }
                usleep(10000);
            } while (true);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 校验绝对写入路径的每一段，禁止跳转、符号链接和 Windows 设备名。
     * @throws RuntimeException 路径不规范或可能写出预期位置。
     */
    public static function path(string $path): void
    {
        $platform = new BuildPlatform();
        if (!$platform->absolute($path)) {
            throw new RuntimeException('构建文件需要绝对路径');
        }
        $normalized = BuildPlatform::path($path);
        $windows = $platform->family() === 'Windows';
        $cursor = $windows ? substr($normalized, 0, 2) : '';
        foreach (explode('/', substr($normalized, $windows ? 3 : 1)) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('构建路径不能包含跳转或空段');
            }
            if ($windows && (preg_match('/[<>:"|?*\x00-\x1f]/', $part) || preg_match('/[. ]$/D', $part)
                || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part))) {
                throw new RuntimeException('Windows构建路径不能使用设备名、数据流或非规范段');
            }
            $cursor .= '/' . $part;
            $resolved = $windows && file_exists($cursor) ? BuildPlatform::resolve($cursor) : $cursor;
            if (is_link($cursor) || ($windows && strcasecmp($resolved, $cursor) !== 0)) {
                throw new RuntimeException('构建写入路径不能经过符号链接');
            }
        }
    }
}
