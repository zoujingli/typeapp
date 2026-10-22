<?php

declare(strict_types=1);

namespace TypeApp\Migrations;

use Type\Orm\Migration\Migration;

/** 示例迁移定义随应用一起编译，不在生产扫描或解释 PHP 文件。 */
final class Plan
{
    /**
     * 生成当前数据库方言与故障场景的迁移声明，先限制测试表前缀。
     *
     * @return list<\Type\Orm\Migration\Migration> 按版本顺序排列的迁移。
     */
    public static function migrations(string $driver, string $prefix): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $prefix)) {
            throw new \InvalidArgumentException('迁移示例表前缀无效');
        }

        $scenario = getenv('TYPE_MIGRATION_SCENARIO') ?: 'normal';
        if (in_array($scenario, ['unsafe-defer', 'unsafe-foreign-keys'], true)) {
            return [new Migration('202609080001', '拒绝越界外键开关', [
                $scenario === 'unsafe-defer' ? 'PRAGMA defer_foreign_keys = ON' : 'PRAGMA foreign_keys = OFF',
            ], false)];
        }
        if ($scenario === 'sqlite-rebuild') {
            return [
                new Migration('202609080001', '建立带引用的迁移记录', [
                    'CREATE TABLE ' . $prefix . '_items (id INTEGER PRIMARY KEY, label VARCHAR(100) NOT NULL)',
                    'CREATE TABLE ' . $prefix . '_references (id INTEGER PRIMARY KEY, item_id INTEGER NOT NULL REFERENCES ' . $prefix . '_items(id))',
                    'INSERT INTO ' . $prefix . "_items VALUES (1, '保留引用')",
                    'INSERT INTO ' . $prefix . '_references VALUES (1, 1)',
                ]),
                new Migration('202609080002', '在同一事务中调整引用表的空值约束', [
                    'PRAGMA defer_foreign_keys = ON',
                    'CREATE TABLE ' . $prefix . '_replacement (id INTEGER PRIMARY KEY, label VARCHAR(100) NULL)',
                    'INSERT INTO ' . $prefix . '_replacement SELECT * FROM ' . $prefix . '_items WHERE id <> (SELECT id FROM ' . $prefix . '_gate)',
                    'DROP TABLE ' . $prefix . '_items',
                    'ALTER TABLE ' . $prefix . '_replacement RENAME TO ' . $prefix . '_items',
                    'PRAGMA defer_foreign_keys = OFF',
                ]),
            ];
        }
        $statements = [
            'CREATE TABLE ' . $prefix . '_items (id INTEGER PRIMARY KEY, label VARCHAR(100) NOT NULL)',
            'INSERT INTO ' . $prefix . "_items VALUES (1, '中文迁移')",
        ];
        if ($scenario === 'failure') {
            $statements[] = 'INSERT INTO ' . $prefix . "_items SELECT id, '故障恢复' FROM " . $prefix . '_gate';
        }
        if ($scenario === 'crash') {
            $expression = $driver === 'mysql' ? 'CAST(SLEEP((SELECT delay FROM ' . $prefix . '_gate)) AS CHAR)'
                : ($driver === 'pgsql' ? 'CAST(pg_sleep((SELECT delay FROM ' . $prefix . '_gate)) AS TEXT)'
                    : '(WITH RECURSIVE counter(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM counter WHERE n < (SELECT delay * 10000000 FROM ' . $prefix . '_gate)) SELECT CAST(SUM(n) AS TEXT) FROM counter)');
            $statements[] = 'INSERT INTO ' . $prefix . '_items SELECT 2, ' . $expression;
        }
        if ($scenario === 'compound') {
            $statements[0] .= '; COMMIT';
        }
        if ($scenario === 'ambiguous-escape') {
            $statements[1] = 'INSERT INTO ' . $prefix . "_items VALUES (1, '路径\\字符')";
        }
        if ($scenario === 'unsupported-ddl') {
            $statements = ['VACUUM'];
        }
        if ($scenario === 'missing') {
            return [];
        }
        $migration = new Migration(
            '202609080001',
            $scenario === 'changed' ? '被修改的历史迁移' : '建立示例记录表',
            $statements,
            $driver !== 'mysql' || $scenario === 'unsupported-transaction'
        );
        if ($scenario === 'index') {
            return [$migration, new Migration('202609080002', '为记录增加查询索引', [
                'CREATE INDEX ' . ($driver === 'pgsql' ? 'CONCURRENTLY ' : '') . $prefix . '_label_idx ON ' . $prefix . '_items (label)',
            ], $driver === 'sqlite')];
        }

        return $scenario === 'duplicate' ? [$migration, $migration] : [$migration];
    }
}
