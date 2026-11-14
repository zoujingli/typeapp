<?php

declare(strict_types=1);

namespace Type\Orm\Schema;

use Type\Orm\Connection;

/**
 * 迁移里的结构入口，用法对齐常见的 Schema::create / hasTable。
 *
 * 预演时只收集 SQL，hasTable 与 hasColumn 视为不存在，从而得到完整脚本。
 * 真正执行时按当前库跳过已经存在的表和列。
 */
final class Schema
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    /**
     * @var array<string, Blueprint> 按表名保留本次 up 见到的最终列，供生成模型。
     */
    private array $blueprints = [];

    /**
     * 预演只记录 SQL；执行时绑定真实连接，hasTable 才能跳过已有对象。
     */
    public function __construct(private string $driver, private ?Connection $connection, private bool $recording) {}

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * 本次收集到的表结构。建表保存整张 Blueprint，后续加列并入同表。
     *
     * @return array<string, Blueprint>
     */
    public function blueprints(): array
    {
        return $this->blueprints;
    }

    /**
     * 预演时恒为不存在，执行时查询当前库。
     */
    public function hasTable(string $table): bool
    {
        if ($this->recording || $this->connection === null) {
            return false;
        }
        return $this->connection->driver()->migrations()->tableExists($this->connection, $table);
    }

    /**
     * 预演时恒为不存在，执行时查询当前库。
     */
    public function hasColumn(string $table, string $column): bool
    {
        if ($this->recording || $this->connection === null) {
            return false;
        }
        return $this->connection->driver()->migrations()->columnExists($this->connection, $table, $column);
    }

    /**
     * 声明建表。回调只修改 Blueprint。
     *
     * @param \Closure(Blueprint): void $callback
     */
    public function create(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        foreach (Grammar::create($blueprint, $this->driver) as $statement) {
            $this->perform($statement);
        }
        $this->capture($blueprint, true);
    }

    /**
     * 声明加列、删列或索引。新增的非空列必须带默认值。
     *
     * @param \Closure(Blueprint): void $callback
     */
    public function table(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        foreach (Grammar::alter($blueprint, $this->driver) as $statement) {
            $this->perform($statement);
        }
        $this->capture($blueprint, false);
    }

    /**
     * 删除表。表不存在时语句仍然成功。
     */
    public function dropIfExists(string $table): void
    {
        $this->perform(Grammar::dropIfExists($table, $this->driver));
    }

    /**
     * 写入初始化行。表名和列名使用与建表相同的标识符规则。
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insert(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            if ($row === []) {
                throw new \Type\Orm\Migration\MigrationException('TYPE_SCHEMA_INVALID：插入行不能为空');
            }
            $columns = [];
            $values = [];
            foreach ($row as $column => $value) {
                $columns[] = Grammar::identifier((string)$column, $this->driver);
                $values[] = Grammar::literal($value);
            }
            $this->perform('INSERT INTO ' . Grammar::identifier($table, $this->driver) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')');
        }
    }

    /**
     * 建表覆盖同名草稿；加列只补充尚未出现的字段，并按 dropColumn 移除。
     */
    private function capture(Blueprint $blueprint, bool $creating): void
    {
        $name = $blueprint->table;
        if ($creating || !isset($this->blueprints[$name])) {
            $this->blueprints[$name] = $blueprint;
            return;
        }
        $current = $this->blueprints[$name];
        foreach ($blueprint->dropColumns as $column) {
            $current->columns = array_values(array_filter(
                $current->columns,
                static fn (ColumnDefinition $item): bool => $item->name !== $column
            ));
        }
        $known = [];
        foreach ($current->columns as $column) {
            $known[$column->name] = true;
        }
        foreach ($blueprint->columns as $column) {
            if (!isset($known[$column->name])) {
                $current->columns[] = $column;
            }
        }
        if ($blueprint->comment !== null) {
            $current->comment = $blueprint->comment;
        }
        if ($blueprint->primary !== []) {
            $current->primary = $blueprint->primary;
        }
    }

    private function perform(string $sql): void
    {
        $this->statements[] = $sql;
        if (!$this->recording && $this->connection !== null) {
            $this->connection->raw($sql);
        }
    }

}
