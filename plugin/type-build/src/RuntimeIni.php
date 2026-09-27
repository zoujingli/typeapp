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

    /** 将与探针相同的安全配置链接进程序；普通启动不创建 php.ini 或扫描外部配置。 */
    public function nativeSource(): string
    {
        $ini = $this->generate([]) . "html_errors=0\nimplicit_flush=1\noutput_buffering=0\nmax_execution_time=0\nmax_input_time=-1\n";
        $source = <<<'CPP'
#include <phpx.h>
BEGIN_EXTERN_C()
#include <sapi/embed/php_embed.h>
END_EXTERN_C()

extern "C" const char *type_app_static_runtime_ini() { return TYPE_INI_LITERAL; }

namespace {
int (*original_startup)(sapi_module_struct *) = nullptr;

// 普通 PHPX 入口沿用 php_embed_init；在其设置默认值之后、解析任何 INI 之前注入配置。
// 线程入口直接调用 php_module_startup，使用同一 type_app_static_runtime_ini 数据。
int static_startup(sapi_module_struct *module) {
    module->ini_entries = type_app_static_runtime_ini();
    module->php_ini_ignore = 1;
    module->php_ini_ignore_cwd = 1;
    return original_startup(module);
}

struct InstallStaticIni {
    InstallStaticIni() {
        original_startup = php_embed_module.startup;
        php_embed_module.startup = static_startup;
    }
} install_static_ini;
}
CPP;
        return str_replace('TYPE_INI_LITERAL', json_encode($ini, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $source);
    }

    private function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) || str_contains($value, '${')) {
            throw new RuntimeException('运行模块路径不能包含控制字符或INI插值');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
