<?php

declare(strict_types=1);

namespace Type\Orm\Schema;

/**
 * Blueprint 中的一列；修改器返回自身，便于连续声明。
 */
final class ColumnDefinition
{
    public bool $nullable = false;

    public bool $auto = false;

    public bool $hasDefault = false;

    public mixed $default = null;

    public ?string $comment = null;

    public ?string $after = null;

    public bool $unique = false;

    public ?string $check = null;

    public ?string $charset = null;

    public ?string $collation = null;

    public ?int $precision = null;

    public ?int $scale = null;

    /**
     * 名称、类型和可选长度在构造后不再改变。
     */
    public function __construct(public string $name, public string $type, public ?int $length = null) {}

    /**
     * 允许保存 NULL。未调用时列为 NOT NULL。
     */
    public function nullable(): self
    {
        $this->nullable = true;
        return $this;
    }

    /**
     * 写入显式默认值。null 也是默认值，与未声明默认值不同。
     */
    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    /**
     * 列注释。只有 MySQL 会写入 COMMENT。
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * MySQL 新增列时可指定位置；其他数据库忽略该提示。
     */
    public function after(string $column): self
    {
        $this->after = $column;
        return $this;
    }

    /**
     * 单列唯一约束。组合唯一使用 Blueprint::unique。
     */
    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    /**
     * 列检查表达式，例如 id = 1。调用方只传入约束内容，不包含 CHECK。
     */
    public function check(string $expression): self
    {
        $this->check = $expression;
        return $this;
    }

    /**
     * MySQL 列字符集；其他数据库忽略。
     */
    public function charset(string $charset, ?string $collation = null): self
    {
        $this->charset = $charset;
        $this->collation = $collation;
        return $this;
    }
}
