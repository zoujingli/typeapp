<?php

declare(strict_types=1);

namespace Type\Orm\Schema;

/**
 * 把迁移 Blueprint 转成模型同步器使用的结构，保留列注释与空值。
 */
final class ModelStructure
{
    /**
     * 64 位整数按现有模型写成 integer。Unix 秒和版本在 64 位 PHP 中与有符号 BIGINT 同范围。
     *
     * @return array{table: string, comment: string, columns: list<array<string, mixed>>}
     */
    public static function fromBlueprint(Blueprint $blueprint): array
    {
        $primary = array_fill_keys($blueprint->primary, true);
        $columns = [];
        foreach ($blueprint->columns as $column) {
            $type = match ($column->type) {
                'string', 'text' => 'string',
                'integer' => 'integer',
                'boolean' => 'boolean',
                'decimal' => 'decimal',
                'datetime' => 'datetime',
                default => throw new \InvalidArgumentException('模型不能表示列类型：' . $column->name . ':' . $column->type),
            };
            $columns[] = [
                'name' => $column->name,
                'type' => $type,
                'model_type' => $type,
                'declaration' => $column->type,
                'nullable' => $column->nullable ? 'YES' : 'NO',
                'default_value' => $column->hasDefault ? $column->default : null,
                'key_type' => isset($primary[$column->name]) ? 'PRI' : '',
                'extra' => $column->auto ? 'auto_increment' : '',
                'comment' => $column->comment ?? '',
                'precision_value' => $column->precision,
                'scale_value' => $column->scale,
                'expression' => '',
            ];
        }
        return ['table' => $blueprint->table, 'comment' => $blueprint->comment ?? ('表 ' . $blueprint->table . ' 的持久模型'), 'columns' => $columns];
    }
}
