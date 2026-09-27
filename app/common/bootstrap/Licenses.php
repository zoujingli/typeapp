<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use InvalidArgumentException;
use Type\Generated\EmbeddedResources;

/** 只读展示静态程序内的许可证索引和原文，不在普通启动时释放材料。 */
final class Licenses
{
    /**
     * @param list<string> $arguments 完整命令参数；省略资源路径时输出内嵌许可索引。
     * @throws InvalidArgumentException 当前不是带许可材料的原生产物，或资源路径未登记。
     */
    public static function run(array $arguments): void
    {
        if (count($arguments) > 3 || !class_exists(EmbeddedResources::class, false)) {
            throw new InvalidArgumentException('用法：原生主程序 licenses [notices/资源路径]');
        }
        $path = $arguments[2] ?? 'notices/dependencies.json';
        $files = EmbeddedResources::manifest();
        if (!str_starts_with($path, 'notices/') || !isset($files[$path])) {
            throw new InvalidArgumentException('许可资源未内嵌；请先通过 licenses 查看索引');
        }
        $offset = 0;
        $bytes = $files[$path]['bytes'];
        while ($offset < $bytes) {
            $chunk = EmbeddedResources::read($path, $offset, min(65536, $bytes - $offset));
            if ($chunk === '') {
                throw new \RuntimeException('内嵌许可材料不完整');
            }
            echo $chunk;
            $offset += strlen($chunk);
        }
    }
}
