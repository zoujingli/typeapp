<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/**
 * 记录发布程序的可审计体积组成。
 *
 * code 取原生文件的可执行区段，data 为其余字节（包含文件头和身份清单）。
 * symbols 来自真实符号表；这里的 native 是参与链接的静态归档输入，不是部署时要
 * 额外携带的文件，避免把 SDK 误报成运行依赖。
 */
final class ArtifactSize
{
    /**
     * @param array<string,array{bytes:int}> $embeddedResources
     * @param list<string> $staticArchives
     * @return array{protocol:int,total:int,code:int,data:int,symbols:int,frontend:int,native:int,stripped:bool}
     */
    public static function measure(string $artifact, array $embeddedResources, array $staticArchives): array
    {
        BuildLock::path($artifact);
        if (!is_file($artifact) || is_link($artifact)) {
            throw new RuntimeException('体积测量需要普通程序文件');
        }
        $total = filesize($artifact);
        if (!is_int($total) || $total < 1) {
            throw new RuntimeException('无法读取程序体积');
        }
        $sections = self::sections($artifact, $total);
        $frontend = 0;
        foreach ($embeddedResources as $path => $resource) {
            if (!is_string($path) || !is_array($resource) || !is_int($resource['bytes'] ?? null) || $resource['bytes'] < 0) {
                throw new RuntimeException('内嵌前端体积清单无效');
            }
            // 体积报告中的 frontend 只表示管理前端；许可证和其他内嵌材料
            // 仍计入 data，但不能伪装成前端体积。
            if ($path === 'web' || str_starts_with($path, 'web/')) {
                $frontend += $resource['bytes'];
            }
        }
        $native = 0;
        foreach ($staticArchives as $archive) {
            if (!is_string($archive) || !is_file($archive) || is_link($archive)) {
                throw new RuntimeException('静态归档体积输入无效');
            }
            $bytes = filesize($archive);
            if (!is_int($bytes) || $bytes < 1) {
                throw new RuntimeException('无法读取静态归档体积');
            }
            $native += $bytes;
        }
        $data = $total - $sections['code'];
        if ($frontend > $data) {
            throw new RuntimeException('前端资源体积超出程序数据区');
        }
        return ['protocol' => 1, 'total' => $total, 'code' => $sections['code'],
            'data' => $data, 'symbols' => $sections['symbols'], 'frontend' => $frontend, 'native' => $native, 'stripped' => true];
    }

    /** 按文件格式读取真实区段；缺失区段表、调试信息和未清理符号均拒绝封存。 */
    private static function sections(string $artifact, int $total): array
    {
        $handle = fopen($artifact, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法读取原生区段');
        }
        $read = static function (int $offset, int $size) use ($handle, $total): string {
            if ($offset < 0 || $size < 0 || $size > 16777216 || $offset + $size > $total || fseek($handle, $offset) !== 0) {
                throw new RuntimeException('原生区段表越界');
            }
            $bytes = $size === 0 ? '' : fread($handle, $size);
            if (!is_string($bytes) || strlen($bytes) !== $size) {
                throw new RuntimeException('原生区段读取不完整');
            }
            return $bytes;
        };
        $u16 = static fn (string $bytes, int $offset): int => unpack('v', $bytes, $offset)[1];
        $u32 = static fn (string $bytes, int $offset): int => unpack('V', $bytes, $offset)[1];
        $u64 = static fn (string $bytes, int $offset): int => unpack('P', $bytes, $offset)[1];
        $code = 0;
        $symbols = 0;
        try {
            $header = $read(0, 64);
            if (substr($header, 0, 6) === "\x7fELF\x02\x01") {
                $offset = $u64($header, 40);
                $entry = $u16($header, 58);
                $count = $u16($header, 60);
                $strings = $u16($header, 62);
                if ($entry !== 64 || $count < 1 || $strings >= $count) {
                    throw new RuntimeException('ELF 区段表无效');
                }
                $namesSection = $read($offset + $strings * $entry, $entry);
                $names = $read($u64($namesSection, 24), $u64($namesSection, 32));
                for ($index = 0; $index < $count; $index++) {
                    $section = $read($offset + $index * $entry, $entry);
                    $name = explode("\0", substr($names, $u32($section, 0)), 2)[0];
                    if (str_starts_with($name, '.debug') || str_starts_with($name, '.zdebug') || in_array($name, ['.symtab', '.strtab', '.gnu_debuglink', '.gnu_debugaltlink', '.gnu_debugdata'], true)) {
                        throw new RuntimeException('ELF 仍含调试或非必要符号区段：' . $name);
                    }
                    if ($u32($section, 4) !== 8 && ($u64($section, 8) & 4) !== 0) {
                        $code += $u64($section, 32);
                    }
                }
            } elseif (substr($header, 0, 4) === "\xcf\xfa\xed\xfe") {
                $count = $u32($header, 16);
                $offset = 32;
                for ($index = 0; $index < $count; $index++) {
                    $command = $read($offset, 8);
                    $length = $u32($command, 4);
                    if ($length < 8) {
                        throw new RuntimeException('Mach-O 加载命令长度无效');
                    }
                    $command = $read($offset, $length);
                    if ($u32($command, 0) === 0x19) {
                        if ($length < 72) {
                            throw new RuntimeException('Mach-O 段命令不完整');
                        }
                        if (rtrim(substr($command, 8, 16), "\0") === '__DWARF') {
                            throw new RuntimeException('Mach-O 仍包含 DWARF');
                        }
                        for ($part = 0; $part < $u32($command, 64); $part++) {
                            $section = substr($command, 72 + $part * 80, 80);
                            if (strlen($section) !== 80) {
                                throw new RuntimeException('Mach-O 区段表不完整');
                            }
                            if (($u32($section, 64) & 0x02000000) !== 0) {
                                throw new RuntimeException('Mach-O 仍包含调试区段');
                            }
                            if (($u32($section, 64) & 0x80000400) !== 0) {
                                $code += $u64($section, 40);
                            }
                        }
                    } elseif ($u32($command, 0) === 2) {
                        if ($length !== 24) {
                            throw new RuntimeException('Mach-O 符号命令无效');
                        }
                        $strings = $read($u32($command, 16), $u32($command, 20));
                        $symbolCount = $u32($command, 12);
                        $symbols = $symbolCount * 16 + $u32($command, 20);
                        $table = $read($u32($command, 8), $symbolCount * 16);
                        for ($part = 0; $part < $symbolCount; $part++) {
                            $type = ord($table[$part * 16 + 4]);
                            $name = explode("\0", substr($strings, $u32($table, $part * 16)), 2)[0];
                            // Apple 链接器保留的 N_AST 兼容标记不是源码调试符号；strip -x 也保留它。
                            if ($type === 0x3c && $name === 'radr://5614542' && $u64($table, $part * 16 + 8) === 0x5614542) {
                                continue;
                            }
                            if (($type & 0xe0) !== 0 || ($type & 1) === 0) {
                                throw new RuntimeException('Mach-O 仍包含调试或本地符号，需执行 strip -x');
                            }
                        }
                    }
                    $offset += $length;
                }
            } elseif (substr($header, 0, 2) === 'MZ') {
                $offset = $u32($header, 60);
                $pe = $read($offset, 24);
                $optional = $read($offset + 24, $u16($pe, 20));
                if (strlen($optional) < 168 || substr($pe, 0, 4) !== "PE\0\0" || $u16($optional, 0) !== 0x20b
                    || $u32($pe, 12) !== 0 || $u32($pe, 16) !== 0
                    || $u32($optional, 160) !== 0 || $u32($optional, 164) !== 0) {
                    throw new RuntimeException('PE 仍含调试目录、PDB 引用或非必要符号');
                }
                for ($index = 0; $index < $u16($pe, 6); $index++) {
                    $section = $read($offset + 24 + strlen($optional) + $index * 40, 40);
                    if (($u32($section, 36) & 0x20) !== 0) {
                        $code += $u32($section, 16);
                    }
                }
            } else {
                throw new RuntimeException('不支持的原生区段格式');
            }
            if ($code < 1 || $code > $total || $symbols > $total) {
                throw new RuntimeException('原生代码或符号区段体积无效');
            }
            return ['code' => $code, 'symbols' => $symbols];
        } finally {
            fclose($handle);
        }
    }
}
