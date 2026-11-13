<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\Comment\Doc;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\ParserFactory;

/** 将简洁模型配置展开为既有类型属性声明；不加载业务类或建立数据库连接。 */
final class ModelSchemaDeclaration
{
    /**
     * 在构建期消费 table/schema 配置，并保留原类业务方法与显式关系。
     *
     * @return null|array{table: array<string, mixed>, fields: array<string, array{nullable: bool}>}
     * @throws \RuntimeException 声明含动态表达式、无效类型、重复字段或冲突配置
     */
    public function expand(Node\Stmt\Class_ $node, string $class): ?array
    {
        $names = ['table', 'schema', 'pk', 'autoIncrement', 'database', 'tenantKey', 'versionKey', 'createTime', 'updateTime', 'deleteTime', 'hidden'];
        $properties = [];
        foreach ($node->getProperties() as $property) {
            foreach ($property->props as $member) {
                if (in_array($member->name->toString(), ['table', 'schema'], true) && !$property->isPublic()) {
                    $properties[$member->name->toString()] = $property;
                }
            }
        }
        if ($properties === []) {
            return null;
        }
        if (!isset($properties['table'], $properties['schema'])) {
            throw new \RuntimeException('模型需要同时声明 protected table 和 schema：' . $class);
        }
        $values = [];
        $retained = [];
        $evaluator = new ConstExprEvaluator(static function (Node\Expr $expression): mixed {
            throw new \RuntimeException('模型配置只接受静态字面值');
        });
        foreach ($node->stmts as $statement) {
            if (!$statement instanceof Node\Stmt\Property || !in_array($statement->props[0]->name->toString(), $names, true)) {
                $retained[] = $statement;
                continue;
            }
            $name = $statement->props[0]->name->toString();
            if (!$statement->isProtected() || $statement->isStatic() || $statement->isReadonly() || count($statement->props) !== 1
                || $statement->props[0]->default === null || $statement->attrGroups !== [] || $statement->hooks !== [] || array_key_exists($name, $values)) {
                throw new \RuntimeException('模型配置必须是有静态默认值的单个 protected 属性：' . $class . '::$' . $name);
            }
            if ($name === 'schema') {
                $schema = $statement->props[0]->default;
                if (!$schema instanceof Node\Expr\Array_ || $schema->items === []) {
                    throw new \RuntimeException('模型 schema 必须是非空字段映射：' . $class);
                }
                $fields = [];
                foreach ($schema->items as $item) {
                    if ($item === null || $item->unpack || $item->byRef || $item->key === null) {
                        throw new \RuntimeException('模型 schema 字段必须使用显式名称');
                    }
                    $key = $evaluator->evaluateDirectly($item->key);
                    if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1 || array_key_exists($key, $fields)) {
                        throw new \RuntimeException('模型 schema 字段名称无效或重复');
                    }
                    $fields[$key] = $evaluator->evaluateDirectly($item->value);
                }
                $values[$name] = $fields;
            } else {
                $values[$name] = $evaluator->evaluateDirectly($statement->props[0]->default);
            }
        }
        foreach (['table', 'pk', 'database'] as $name) {
            if (array_key_exists($name, $values) && (!is_string($values[$name]) || $values[$name] === '')) {
                throw new \RuntimeException('模型配置必须是非空字符串：' . $name);
            }
        }
        if (array_key_exists('autoIncrement', $values) && !is_bool($values['autoIncrement'])) {
            throw new \RuntimeException('模型 autoIncrement 必须是布尔值');
        }
        foreach (['tenantKey', 'versionKey', 'createTime', 'updateTime', 'deleteTime'] as $name) {
            if (isset($values[$name]) && (!is_string($values[$name]) || $values[$name] === '')) {
                throw new \RuntimeException('模型生命周期字段必须是非空字符串或 null：' . $name);
            }
        }
        $hidden = $values['hidden'] ?? [];
        if (!is_array($hidden) || !array_is_list($hidden)) {
            throw new \RuntimeException('模型 hidden 只能包含已声明字段');
        }
        foreach ($hidden as $name) {
            if (!is_string($name) || !array_key_exists($name, $values['schema'])) {
                throw new \RuntimeException('模型 hidden 只能包含已声明字段');
            }
        }
        $overrides = [];
        foreach ($values['schema'] as $name => $declaration) {
            if (array_key_exists($name, $values)) {
                throw new \RuntimeException('模型字段不能覆盖声明配置，请使用 column 映射：' . $name);
            }
            foreach ($node->getProperties() as $existing) {
                foreach ($existing->props as $member) {
                    if ($member->name->toString() === $name) {
                        throw new \RuntimeException('模型 schema 与类型属性重复声明字段：' . $name);
                    }
                }
            }
            $field = is_string($declaration) ? ['type' => $declaration] : $declaration;
            if (!is_array($field) || array_is_list($field) || !is_string($field['type'] ?? null)
                || array_diff(array_keys($field), ['type', 'nullable', 'column', 'fillable', 'visible', 'required', 'precision', 'scale', 'integerRange', 'comment']) !== []) {
                throw new \RuntimeException('模型 schema 字段声明无效：' . $name);
            }
            $nullable = str_starts_with($field['type'], '?');
            if (array_key_exists('nullable', $field) && (!is_bool($field['nullable']) || ($nullable && !$field['nullable']))) {
                throw new \RuntimeException('模型字段 nullable 与类型声明冲突：' . $name);
            }
            $nullable = $field['nullable'] ?? $nullable;
            $declared = $nullable && str_starts_with($field['type'], '?') ? substr($field['type'], 1) : $field['type'];
            $types = ['int' => 'integer', 'integer' => 'integer', 'bool' => 'boolean', 'boolean' => 'boolean',
                'string' => 'string', 'array' => 'json', 'json' => 'json', 'decimal' => 'decimal', 'bigint' => 'bigint', 'datetime' => 'datetime'];
            if (!isset($types[$declared])) {
                throw new \RuntimeException('模型 schema 字段类型不受支持：' . $name . ':' . $declared);
            }
            $type = $types[$declared];
            $php = $declared === 'array' ? 'array' : ['integer' => 'int', 'boolean' => 'bool', 'string' => 'string',
                'json' => 'mixed', 'decimal' => 'string', 'bigint' => 'string', 'datetime' => '\DateTimeImmutable'][$type];
            if ($nullable && $php !== 'mixed') {
                $php = '?' . $php;
            }
            $arguments = ['type: ' . var_export($type, true)];
            foreach (['column' => 'name', 'fillable' => 'fillable', 'visible' => 'visible', 'required' => 'required', 'precision' => 'precision', 'scale' => 'scale', 'integerRange' => 'integerRange'] as $key => $argument) {
                if (array_key_exists($key, $field)) {
                    $arguments[] = $argument . ': ' . var_export($field[$key], true);
                }
            }
            if (in_array($name, $hidden, true)) {
                if (array_key_exists('visible', $field)) {
                    $arguments = array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, 'visible: ')));
                }
                $arguments[] = 'visible: false';
            }
            $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php class Declaration { #[\Type\Orm\Attribute\Column(' . implode(', ', $arguments) . ')] public ' . $php . ' $' . $name . '; }');
            $property = $parsed[0]->stmts[0];
            if (array_key_exists('comment', $field)) {
                if (!is_string($field['comment'])) {
                    throw new \RuntimeException('模型字段 comment 必须是字符串：' . $name);
                }
                $description = str_replace(['*/', "\r", "\n"], ['* /', ' ', ' '], $field['comment']);
                $property->setDocComment(new Doc('/** ' . trim($description) . ' */'));
            }
            $retained[] = $property;
            $overrides[$name] = ['nullable' => $nullable];
        }
        $node->stmts = $retained;
        return ['table' => [
            'name' => $values['table'], 'primary' => $values['pk'] ?? 'id', 'generatedPrimary' => $values['autoIncrement'] ?? true,
            'database' => $values['database'] ?? 'default', 'tenant' => $values['tenantKey'] ?? null, 'version' => $values['versionKey'] ?? null,
            'createdAt' => $values['createTime'] ?? null, 'updatedAt' => $values['updateTime'] ?? null, 'softDelete' => $values['deleteTime'] ?? null,
        ], 'fields' => $overrides];
    }
}
