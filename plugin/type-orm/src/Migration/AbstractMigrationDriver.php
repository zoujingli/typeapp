<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

use Type\Orm\Connection;

/**
 * 各驱动共用的初始化数据导出。
 */
abstract class AbstractMigrationDriver implements MigrationDriver
{
    /**
     * 导出安装标记、平台设置和菜单。跳过不存在的表，并去掉 password_hash。
     *
     * @return list<string>
     */
    public function initialization(Connection $connection): array
    {
        $statements = [];
        foreach (['platform_installation', 'platform_setting', 'platform_menu', 'system_menu'] as $table) {
            if (!$this->tableExists($connection, $table)) {
                continue;
            }
            foreach ($connection->query('SELECT * FROM ' . $table) as $row) {
                unset($row['password_hash']);
                $statements[] = $this->insert($table, $row);
            }
        }
        return $statements;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function insert(string $table, array $row): string
    {
        $columns = [];
        $values = [];
        foreach ($row as $column => $value) {
            $columns[] = $column;
            $values[] = $this->literal($value);
        }
        return 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
    }

    /**
     * 把 PHP 标量写成 SQL 字面量。文本中的单引号加倍。
     */
    protected function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
