<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 静态链接时分离 Swoole 协程 PDO 与 PHP 原生 PDO 的内部 C 符号，保留两套驱动及公开接口。 */
final class SwooleStaticSource
{
    public const REFERENCE = '4aff74a9ac086458d1c5251e71ac6e080f68b390';

    /**
     * 仅接受固定上游原文；上游为协程驱动提供独立符号并通过同一回归后撤除。
     * 不修改 PDO 类名、方法名或驱动注册名，宏仅作用于 Swoole 自身的编译单元。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws RuntimeException 原文身份、替换位置或保存失败。
     */
    public function apply(string $directory): array
    {
        $declarations = [
            'ext-src/php_swoole_pgsql.h' => [
                'sha256' => '018cdce89c0809d39dbc56fe8b92eeb9fc195466517b90b96ff5a7fb3e5fd9ab',
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
                throw new RuntimeException('Swoole 静态 PDO 适配需要固定原文：' . $file);
            }
            $source = (string) file_get_contents($path);
            if (substr_count($source, $declaration['guard']) !== 1) {
                throw new RuntimeException('Swoole 静态 PDO 适配位置不唯一：' . $file);
            }
            $macros = $declaration['guard'] . "\n\n/* TypeApp: isolate coroutine PDO symbols when linked with PHP core. */\n";
            foreach ($declaration['symbols'] as $symbol) {
                $macros .= '#define ' . $symbol . ' typeapp_swoole_' . $symbol . "\n";
            }
            $pending[$file] = str_replace($declaration['guard'], rtrim($macros), $source);
        }
        $report = [];
        foreach ($pending as $file => $source) {
            if (file_put_contents($directory . '/' . $file, $source) !== strlen($source)) {
                throw new RuntimeException('无法保存 Swoole 静态 PDO 适配：' . $file);
            }
            $report[$file] = ['before' => $declarations[$file]['sha256'], 'after' => hash('sha256', $source)];
        }
        return $report;
    }
}
