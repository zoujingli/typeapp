<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\Comment\Doc;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\ParserFactory;

/**
 * 将简洁模型配置展开为既有类型属性声明；不加载业务类或建立数据库连接。
 */
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
        $relations = $this->relations($node, $class);
        return ['table' => [
            'name' => $values['table'], 'primary' => $values['pk'] ?? 'id', 'generatedPrimary' => $values['autoIncrement'] ?? true,
            'database' => $values['database'] ?? 'default', 'tenant' => $values['tenantKey'] ?? null,
            'tenantDeclared' => array_key_exists('tenantKey', $values), 'version' => $values['versionKey'] ?? null,
            'createdAt' => $values['createTime'] ?? null, 'updatedAt' => $values['updateTime'] ?? null, 'softDelete' => $values['deleteTime'] ?? null,
        ], 'fields' => $overrides, 'relations' => $relations];
    }

    /**
     * 读取只含一条字面量返回的关系方法，并换成供构建器生成访问钩子的公开属性。
     *
     * @return array<string, array<string, mixed>>
     */
    private function relations(Node\Stmt\Class_ $node, string $class): array
    {
        $parameters = [
            'hasOne' => ['target', 'foreignKey', 'localKey'],
            'hasMany' => ['target', 'foreignKey', 'localKey'],
            'belongsTo' => ['target', 'foreignKey', 'ownerKey'],
            'belongsToMany' => ['target', 'table', 'sourcePivotKey', 'targetPivotKey', 'sourceKey', 'targetKey', 'pivotFields', 'pivotTenant'],
        ];
        $kinds = ['hasOne' => 'HasOne', 'hasMany' => 'HasMany', 'belongsTo' => 'BelongsTo', 'belongsToMany' => 'BelongsToMany'];
        $relations = [];
        $retained = [];
        $evaluator = new ConstExprEvaluator(static function (Node\Expr $expression): mixed {
            if ($expression instanceof Node\Expr\ClassConstFetch && $expression->class instanceof Node\Name
                && $expression->name instanceof Node\Identifier && $expression->name->toLowerString() === 'class'
                && !in_array(strtolower($expression->class->toString()), ['self', 'parent', 'static'], true)) {
                return $expression->class->toString();
            }
            throw new \RuntimeException('关系方法只接受字面值与明确的类名');
        });
        foreach ($node->stmts as $statement) {
            if (!$statement instanceof Node\Stmt\ClassMethod || $statement->stmts === null || count($statement->stmts) !== 1
                || !$statement->stmts[0] instanceof Node\Stmt\Return_ || !$statement->stmts[0]->expr instanceof Node\Expr\MethodCall) {
                $retained[] = $statement;
                continue;
            }
            $call = $statement->stmts[0]->expr;
            $kind = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            if (!$call->var instanceof Node\Expr\Variable || $call->var->name !== 'this' || !isset($parameters[$kind])) {
                $retained[] = $statement;
                continue;
            }
            if ($statement->params !== [] || $statement->isStatic() || !$statement->isPublic()) {
                throw new \RuntimeException('关系方法必须是无参数的公开实例方法：' . $class . '::' . $statement->name);
            }
            $name = $statement->name->toString();
            if (isset($relations[$name])) {
                throw new \RuntimeException('关系方法重复：' . $class . '::' . $name);
            }
            $arguments = [];
            $named = false;
            foreach ($call->args as $index => $argument) {
                if (!$argument instanceof Node\Arg || $argument->unpack) {
                    throw new \RuntimeException('关系方法参数无效：' . $name);
                }
                $key = $argument->name?->toString() ?? ($parameters[$kind][$index] ?? '');
                if (!in_array($key, $parameters[$kind], true) || array_key_exists($key, $arguments) || ($named && $argument->name === null)) {
                    throw new \RuntimeException('关系方法参数无效：' . $name . '.' . $key);
                }
                $named = $named || $argument->name !== null;
                $arguments[$key] = $evaluator->evaluateDirectly($argument->value);
            }
            if (!is_string($arguments['target'] ?? null) || $arguments['target'] === '') {
                throw new \RuntimeException('关系方法必须声明目标模型：' . $name);
            }
            $relation = ['kind' => $kinds[$kind]] + $arguments;
            $relations[$name] = $relation;
            $many = in_array($relation['kind'], ['HasMany', 'BelongsToMany'], true);
            $property = new Node\Stmt\Property(Node\Stmt\Class_::MODIFIER_PUBLIC, [new Node\Stmt\PropertyProperty($name)]);
            $property->type = $many ? new Node\Identifier('array') : new Node\NullableType(new FullyQualified($arguments['target']));
            if ($statement->getDocComment() !== null) {
                $property->setDocComment($statement->getDocComment());
            }
            $retained[] = $property;
        }
        $node->stmts = $retained;
        return $relations;
    }
}
