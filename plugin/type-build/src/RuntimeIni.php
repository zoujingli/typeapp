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

    private function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) || str_contains($value, '${')) {
            throw new RuntimeException('运行模块路径不能包含控制字符或INI插值');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
