<?php

declare(strict_types=1);

namespace Type\Build;

/**
 * 静态链接时分离 Swoole 协程 PDO 与 PHP 原生 PDO 的内部 C 符号，并修复 Unix embed 的 wrapper 初始化顺序。
 * Windows 保留既有顺序，由 Windows 源码适配统一处理运行时入口。
 */
final class SwooleStaticSource
{
    public const REFERENCE = '814049090dd9ba8000adc6cd09ad09dd3f0c9989';

    /**
     * 仅接受固定上游原文；上游为协程驱动提供独立符号并通过同一回归后撤除。
     * 不修改 PDO 类名、方法名或驱动注册名，宏仅作用于 Swoole 自身的编译单元。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws \RuntimeException 原文身份、替换位置或保存失败
     */
    public function apply(string $directory): array
    {
        $runtimeSource = null;
        $runtimeBefore = '7319e162e3646d4c603b5c67e3dbe1b8d8a636966b9fc6691544374a46d285e0';
        if (PHP_OS_FAMILY !== 'Windows') {
            $runtime = $directory . '/ext-src/swoole_runtime.cc';
            if (!is_file($runtime) || is_link($runtime) || hash_file('sha256', $runtime) !== $runtimeBefore) {
                throw new \RuntimeException('Swoole 静态 embed 适配需要固定原文：ext-src/swoole_runtime.cc');
            }
            $runtimeSource = (string)file_get_contents($runtime);
        }
        $runtimeInclude = '#include "ext/standard/file.h"';
        $runtimeAssignment = <<<'CPP'
    auto *wrappers = php_stream_get_url_stream_wrappers_hash_global();
    ori_php_stdio_wrapper =
        static_cast<const php_stream_wrapper *>(zend_hash_str_find_ptr(wrappers, ZEND_STRL("php")));
CPP;
        $runtimeReplacement = <<<'CPP'
    // Static PHP embed can enter Swoole MINIT before the standard extension registers
    // the "php" wrapper in the runtime hash. Use PHP's immutable built-in wrapper object.
    ori_php_stdio_wrapper = &php_stream_php_wrapper;
CPP;
        if ($runtimeSource !== null && (substr_count($runtimeSource, $runtimeInclude) !== 1 || substr_count($runtimeSource, $runtimeAssignment) !== 1)) {
            throw new \RuntimeException('Swoole 静态 embed 适配位置不唯一：ext-src/swoole_runtime.cc');
        }
        if ($runtimeSource !== null) {
            $runtimeSource = str_replace($runtimeInclude, $runtimeInclude . "\n#include \"ext/standard/php_fopen_wrappers.h\"", $runtimeSource);
            $runtimeSource = str_replace($runtimeAssignment, $runtimeReplacement, $runtimeSource);
        }

        $declarations = [
            'ext-src/php_swoole_pgsql.h' => [
                'sha256' => 'c35f53f5f409b051905042426477f0b32903dc18241baa97295ac0477f5382e8',
                'guard' => '#define PHP_SWOOLE_PGSQL_H',
                'symbols' => [
                    '_pdo_pgsql_error', 'pdo_libpq_version', 'pdo_pgsql_cleanup_notice_callback',
                    'pdo_pgsql_close_lob_streams', 'pdo_pgsql_create_lob_stream', 'pdo_pgsql_lob_stream_ops',
                    'pdo_pgsql_scanner', 'pgsqlCopyFromArray_internal', 'pgsqlCopyFromFile_internal',
                    'pgsqlCopyToArray_internal', 'pgsqlCopyToFile_internal', 'pgsqlGetNotify_internal',
                    'pgsqlGetPid_internal', 'pgsqlLOBCreate_internal', 'pgsqlLOBOpen_internal', 'pgsqlLOBUnlink_internal',
                    'zim_PDO_PGSql_Ext_pgsqlCopyFromArray', 'zim_PDO_PGSql_Ext_pgsqlCopyFromFile',
                    'zim_PDO_PGSql_Ext_pgsqlCopyToArray', 'zim_PDO_PGSql_Ext_pgsqlCopyToFile',
                    'zim_PDO_PGSql_Ext_pgsqlGetNotify', 'zim_PDO_PGSql_Ext_pgsqlGetPid',
                    'zim_PDO_PGSql_Ext_pgsqlLOBCreate', 'zim_PDO_PGSql_Ext_pgsqlLOBOpen',
                    'zim_PDO_PGSql_Ext_pgsqlLOBUnlink', 'zim_PDO_PGSql_Ext_pgsqlSetNoticeCallback',
                ],
            ],
            'ext-src/php_swoole_sqlite.h' => [
                'sha256' => '5934ef602cf7ec66e11d13cb4485949732a145901fe58fa507e7f990cd7c2a60',
                'guard' => '#define SWOOLE_SRC_PHP_SWOOLE_SQLITE_H',
                'symbols' => ['_pdo_sqlite_error', 'pdo_sqlite_scanner'],
            ],
            // re2c 的独立翻译单元不包含上述 Swoole 头，也要保持同一符号归属。
            'thirdparty/php85/pdo_pgsql/pgsql_sql_parser.c' => [
                'sha256' => 'fcdcbb227a62962613f9a8736c76df2d344cd28f23ee2424e567c6d22017bff8',
                'guard' => '#include "php.h"',
                'symbols' => ['pdo_pgsql_scanner'],
            ],
            'thirdparty/pdo_sqlite/sqlite_sql_parser.c' => [
                'sha256' => 'f758ab8855eb5716336b675c1bc3d1fb5def4bccae155b08c4ece5567646d91e',
                'guard' => '#include "php.h"',
                'symbols' => ['pdo_sqlite_scanner'],
            ],
        ];
        // 先核对所有原文，避免第二个文件无效时留下只完成一半的源码适配。
        $pending = [];
        foreach ($declarations as $file => $declaration) {
            $path = $directory . '/' . $file;
            if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $declaration['sha256']) {
                throw new \RuntimeException('Swoole 静态 PDO 适配需要固定原文：' . $file);
            }
            $source = (string)file_get_contents($path);
            if (substr_count($source, $declaration['guard']) !== 1) {
                throw new \RuntimeException('Swoole 静态 PDO 适配位置不唯一：' . $file);
            }
            $macros = $declaration['guard'] . "\n\n/* TypeApp: isolate coroutine PDO symbols when linked with PHP core. */\n";
            foreach ($declaration['symbols'] as $symbol) {
                $macros .= '#define ' . $symbol . ' typeapp_swoole_' . $symbol . "\n";
            }
            $pending[$file] = str_replace($declaration['guard'], rtrim($macros), $source);
        }
        if ($runtimeSource !== null) {
            $pending['ext-src/swoole_runtime.cc'] = $runtimeSource;
        }
        $report = [];
        foreach ($pending as $file => $source) {
            if (file_put_contents($directory . '/' . $file, $source) !== strlen($source)) {
                throw new \RuntimeException('无法保存 Swoole 静态适配：' . $file);
            }
            $report[$file] = ['before' => $file === 'ext-src/swoole_runtime.cc' ? $runtimeBefore : $declarations[$file]['sha256'], 'after' => hash('sha256', $source)];
        }
        return $report;
    }
}
