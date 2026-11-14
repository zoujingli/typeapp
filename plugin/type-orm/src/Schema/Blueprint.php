<?php

declare(strict_types=1);

namespace Type\Orm\Schema;

/**
 * 一张表的列、索引和注释，由语法器编译成当前数据库的 SQL。
 */
final class Blueprint
{
    public ?string $comment = null;

    /**
     * @var list<ColumnDefinition>
     */
    public array $columns = [];

    /**
     * @var list<string>
     */
    public array $primary = [];

    /**
     * @var list<array{name:string,columns:list<string>,unique:bool}>
     */
    public array $indexes = [];

    /**
     * @var list<array{columns:list<string>,on:string,references:list<string>,onDelete:?string}>
     */
    public array $foreignKeys = [];

    /**
     * @var list<string>
     */
    public array $checks = [];

    public ?string $charset = null;

    public ?string $collation = null;

    /**
     * @var list<string>
     */
    public array $dropColumns = [];

    /**
     * 表名在语法器里再按驱动加引号。
     */
    public function __construct(public string $table) {}

    /**
     * 32 位自增主键。SQLite 写成 INTEGER PRIMARY KEY AUTOINCREMENT。
     */
    public function increments(string $name): ColumnDefinition
    {
        $column = $this->column($name, 'integer', 32);
        $column->auto = true;
        $this->primary = [$name];
        return $column;
    }

    /**
     * 64 位自增主键。各驱动都写成 BIGINT。
     */
    public function bigIncrements(string $name): ColumnDefinition
    {
        $column = $this->column($name, 'integer', 64);
        $column->auto = true;
        $this->primary = [$name];
        return $column;
    }

    /**
     * 变长文本，默认 255。长度由调用方按真实上限声明。
     */
    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->column($name, 'string', $length);
    }

    /**
     * 32 位整数。Unix 秒和版本如果需要 BIGINT，改用 bigInteger。
     */
    public function integer(string $name): ColumnDefinition
    {
        return $this->column($name, 'integer', 32);
    }

    /**
     * 64 位整数。各驱动都写成 BIGINT，SQLite 也不降成 INTEGER。
     */
    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->column($name, 'integer', 64);
    }

    /**
     * 布尔值。SQLite 落成 INTEGER，其余驱动使用 BOOLEAN。
     */
    public function boolean(string $name): ColumnDefinition
    {
        return $this->column($name, 'boolean');
    }

    /**
     * 定点小数。SQLite 落成 TEXT，避免二进制浮点。
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        $column = $this->column($name, 'decimal');
        $column->precision = $precision;
        $column->scale = $scale;
        return $column;
    }

    /**
     * 不限长度的文本。不声明字符集。
     */
    public function text(string $name): ColumnDefinition
    {
        return $this->column($name, 'text');
    }

    /**
     * 日期时间列。具体类型由驱动语法决定，不在这里换算时区。
     */
    public function dateTime(string $name): ColumnDefinition
    {
        return $this->column($name, 'datetime');
    }

    /**
     * 追加 created_at 与 updated_at，注释分别为创建时间和更新时间。
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->comment('创建时间');
        $this->dateTime('updated_at')->comment('更新时间');
    }

    /**
     * 追加可空的 deleted_at。未删除时保持 NULL。
     */
    public function softDeletes(): void
    {
        $this->dateTime('deleted_at')->nullable()->comment('删除时间');
    }

    /**
     * @param list<string> $columns
     */
    public function index(array $columns, string $name): void
    {
        $this->indexes[] = ['name' => $name, 'columns' => $columns, 'unique' => false];
    }

    /**
     * @param list<string> $columns
     */
    public function unique(array $columns, string $name): void
    {
        $this->indexes[] = ['name' => $name, 'columns' => $columns, 'unique' => true];
    }

    /**
     * 表注释。只有 MySQL 会写入 COMMENT。
     */
    public function comment(string $comment): void
    {
        $this->comment = $comment;
    }

    /**
     * @param list<string> $columns
     */
    public function primary(array $columns): void
    {
        $this->primary = $columns;
    }

    /**
     * @param list<string>|string $columns @param list<string>|string $references
     */
    public function foreign(array|string $columns, string $on, array|string $references, ?string $onDelete = null): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $references = is_array($references) ? $references : [$references];
        $this->foreignKeys[] = ['columns' => $columns, 'on' => $on, 'references' => $references, 'onDelete' => $onDelete];
    }

    /**
     * 表级检查。表达式只写条件本身。
     */
    public function check(string $expression): void
    {
        $this->checks[] = $expression;
    }

    /**
     * MySQL 表字符集；其他数据库忽略。
     */
    public function charset(string $charset, ?string $collation = null): void
    {
        $this->charset = $charset;
        $this->collation = $collation;
    }

    /**
     * 变更已有表时删除一列。建表脚本不要调用。
     */
    public function dropColumn(string $name): void
    {
        $this->dropColumns[] = $name;
    }

    private function column(string $name, string $type, ?int $length = null): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $type, $length);
        $this->columns[] = $column;
        return $column;
    }
}
