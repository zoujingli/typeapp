<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** @internal 探针与发布包共用的纯INI生成器；不读取宿主配置或验证模块ABI。 */
final class RuntimeIni
{
    private const BASE = "expose_php=0\nenable_dl=0\nallow_url_include=0\nauto_prepend_file=\nauto_append_file=\nopcache.preload=\nuser_ini.filename=\ninclude_path=\nopcache.enable=0\nopcache.enable_cli=0\nswoole.enable_library=On\ndisplay_errors=stderr\ndisplay_startup_errors=1\nlog_errors=0\nmemory_limit=256M\ndate.timezone=UTC\n";

    /**
     * 原样保留模块顺序，仅序列化调用者已核对的绝对路径或发布相对文件名。
     *
     * @param array<array-key, string> $modules 不以映射键改变模块路径或加载顺序。
     * @param string $extensionDirectory 空字符串生成空extension_dir，发布方显式提供lib/bin。
     * @throws RuntimeException 模块路径为空，或路径含控制字符/INI环境插值。
     */
    public function generate(array $modules, string $extensionDirectory = ''): string
    {
        $ini = self::BASE . "swoole.enable_fiber_mock=On\n" . 'extension_dir=' . ($extensionDirectory === '' ? '' : $this->quote($extensionDirectory)) . "\n";
        foreach ($modules as $file) {
            if (!is_string($file) || $file === '') {
                throw new RuntimeException('运行模块路径必须是非空字符串');
            }
            $ini .= 'extension=' . $this->quote($file) . "\n";
        }
        return $ini;
    }

    /**
     * 去掉已经链进主程序的扩展行，保留其余指令和顺序。
     *
     * 产物配置需要真实的 extension_dir，官方 php_load_extension 只按模块名解析；登记器不写入 SDK 绝对路径。
     *
     * @param list<string> $extensions 扩展名，不含路径。
     * @param string|null $extensionDirectory 非 null 时覆盖 extension_dir；空字符串写成空值。
     * @throws RuntimeException 探针配置不存在，或扩展名/目录无效。
     */
    public function withoutExtensions(string $ini, array $extensions, ?string $extensionDirectory = null): string
    {
        $text = is_file($ini) ? file_get_contents($ini) : false;
        if (!is_string($text)) {
            throw new RuntimeException('无法读取运行配置');
        }
        $drop = [];
        foreach ($extensions as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z_][a-z0-9_]*$/iD', $extension) !== 1) {
                throw new RuntimeException('运行扩展名称无效');
            }
            $name = strtolower($extension);
            $drop[$name] = true;
            $drop[$name . '.so'] = true;
            $drop['php_' . $name . '.dll'] = true;
        }
        $lines = preg_split('/\R/', $text) ?: [];
        if ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }
        $kept = [];
        $replaced = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*extension\s*=\s*"?([^"\s]+)"?\s*$/i', $line, $match) === 1) {
                $base = strtolower(basename(str_replace('\\', '/', $match[1])));
                if (isset($drop[$base])) {
                    continue;
                }
            }
            if ($extensionDirectory !== null && preg_match('/^\s*extension_dir\s*=/i', $line) === 1) {
                $kept[] = 'extension_dir=' . ($extensionDirectory === '' ? '' : $this->quote($extensionDirectory));
                $replaced = true;
                continue;
            }
            $kept[] = $line;
        }
        if ($extensionDirectory !== null && !$replaced) {
            $kept[] = 'extension_dir=' . ($extensionDirectory === '' ? '' : $this->quote($extensionDirectory));
        }

        return implode("\n", $kept) . "\n";
    }

    private function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) || str_contains($value, '${')) {
            throw new RuntimeException('运行模块路径不能包含控制字符或INI插值');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
