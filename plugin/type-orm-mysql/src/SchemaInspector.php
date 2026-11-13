<?php

declare(strict_types=1);

namespace Type\Orm\Mysql;

use Type\Orm\Connection;
use Type\Orm\DatabaseException;

/** 只读检查当前 MySQL 数据库的持久表，供开发工具生成模型声明。 */
final class SchemaInspector
{
    /**
     * 使用当前作用域的受管连接读取结构，不执行 DDL，也不解析任意 SQL。
     *
     * @return array{table: string, comment: string, columns: list<array<string, mixed>>}
     * @throws DatabaseException 非 MySQL、标识符无效、表不存在或目标不是持久基础表
     */
    public function inspect(Connection $connection, string $table): array
    {
        if ($connection->driverName() !== 'mysql' || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $table) !== 1) {
            throw new DatabaseException('模型反向生成只接受当前 MySQL 数据库内的规范表名');
        }
        $tables = $connection->query(
            'SELECT TABLE_COMMENT AS comment FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() '
            . "AND CAST(TABLE_NAME AS BINARY) = CAST(? AS BINARY) AND TABLE_TYPE = 'BASE TABLE'",
            [$table]
        );
        if (count($tables) !== 1) {
            throw new DatabaseException('模型反向生成的持久基础表不存在');
        }
        $columns = $connection->query(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS declaration, IS_NULLABLE AS nullable, '
            . 'COLUMN_DEFAULT AS default_value, COLUMN_KEY AS key_type, EXTRA AS extra, COLUMN_COMMENT AS comment, '
            . 'NUMERIC_PRECISION AS precision_value, NUMERIC_SCALE AS scale_value, GENERATION_EXPRESSION AS expression '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND CAST(TABLE_NAME AS BINARY) = CAST(? AS BINARY) ORDER BY ORDINAL_POSITION',
            [$table]
        );
        if ($columns === []) {
            throw new DatabaseException('模型反向生成未读取到字段');
        }
        return ['table' => $table, 'comment' => (string)$tables[0]['comment'], 'columns' => $columns];
    }
}
