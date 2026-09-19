<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

/** 静态转换 PHP 类型属性模型；开发与 AOT 使用相同类名、业务方法和虚拟属性。 */
final class ModelCompiler
{
    /**
     * 扫描已声明的生产源码，返回完整转换文件，不加载或解释业务代码。
     * @param list<string> $sources 源码文件或目录的绝对路径。
     * @return array{code:string, originals:list<string>, models:array}
     */
    public function compile(array $sources): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $printer = new Standard();
        $code = "<?php\n\ndeclare(strict_types=1);\n";
        $originals = [];
        $models = [];
        $classes = [];
        foreach ((new BuildIdentity())->sources($sources) as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($file);
            // 名称解析仅用于含类的源码，避免扫描原生输入或生成数据。
            if (!str_contains($text, 'class')) {
                continue;
            }
            $ast = $parser->parse($text) ?? [];
            $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
            $this->validateAttributeTargets($ast);
            $changed = false;
            foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $node) {
                if ($node->name === null) {
                    continue;
                }
                $class = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                $parent = $node->extends?->toString() ?? '';
                $classes[strtolower($class)] = strtolower($parent);
                $table = $this->attribute($node->attrGroups, 'Table', ['name', 'primary', 'generatedPrimary', 'softDelete', 'version']);
                if ($table === null) {
                    foreach ($node->getProperties() as $property) {
                        foreach ($property->attrGroups as $group) {
                            foreach ($group->attrs as $attribute) {
                                if (str_starts_with($attribute->name->toString(), 'Type\\Orm\\Attribute\\')) {
                                    throw new RuntimeException('模型字段或关系需要所属类声明 Table：' . $class);
                                }
                            }
                        }
                    }
                    continue;
                }
                if ($parent !== 'Type\\Orm\\Model' || $node->isAbstract() || $node->isReadonly() || !str_contains($class, '\\')) {
                    throw new RuntimeException('属性模型必须是带命名空间、直接继承 Type\\Orm\\Model 的普通具体类：' . $class);
                }
                if (isset($models[strtolower($class)])) {
                    throw new RuntimeException('模型类重复：' . $class);
                }
                $declaration = $this->model($node, $class, $table);
                $generated = $parser->parse($this->render([$declaration])) ?? [];
                $generatedClass = $finder->findFirstInstanceOf($generated, Node\Stmt\Class_::class);
                $members = [];
                foreach ($node->getMethods() as $method) {
                    $members[strtolower($method->name->toString())] = true;
                }
                foreach ($generatedClass->getMethods() as $method) {
                    if (isset($members[strtolower($method->name->toString())])) {
                        throw new RuntimeException('业务方法与模型生成成员冲突：' . $class . '::' . $method->name);
                    }
                    $node->stmts[] = $method;
                }
                $models[strtolower($class)] = $declaration;
                $changed = true;
            }
            if (!$changed) {
                continue;
            }
            if ($finder->findFirst($ast, static fn (Node $node): bool => $node instanceof Node\Scalar\MagicConst\Dir
                || $node instanceof Node\Scalar\MagicConst\File) !== null) {
                throw new RuntimeException('模型源码不能依赖 __DIR__ 或 __FILE__，请通过显式配置传入资源位置');
            }
            // 保留原文件全部声明和业务行为，统一为括号命名空间再组合。
            $global = [];
            foreach ($ast as $statement) {
                if ($statement instanceof Node\Stmt\Declare_) {
                    foreach ($statement->declares as $declare) {
                        if ($declare->key->toString() !== 'strict_types' || $declare->value->value !== 1) {
                            throw new RuntimeException('模型源码只接受 strict_types=1 声明');
                        }
                    }
                } elseif ($statement instanceof Node\Stmt\Namespace_) {
                    if ($global !== []) {
                        $code .= "\nnamespace {\n" . $printer->prettyPrint($global) . "\n}\n";
                        $global = [];
                    }
                    $code .= "\nnamespace " . ($statement->name?->toString() ?? '') . " {\n" . $printer->prettyPrint($statement->stmts) . "\n}\n";
                } else {
                    $global[] = $statement;
                }
            }
            if ($global !== []) {
                $code .= "\nnamespace {\n" . $printer->prettyPrint($global) . "\n}\n";
            }
            $originals[] = $file;
        }
        foreach ($classes as $class => $parent) {
            if (isset($models[$parent])) {
                throw new RuntimeException('属性模型不能通过继承复用字段，请直接继承 Model：' . $class);
            }
        }
        $this->validateRelations($models);
        $parser->parse($code);
        return ['code' => $code, 'originals' => $originals, 'models' => array_values($models)];
    }

    /** 旧配置必须迁移，不能用旧缓存继续执行 JSON 模型。 */
    public function assertConfiguration(array $configuration): void
    {
        if (array_key_exists('models', $configuration)) {
            throw new RuntimeException('models 配置已移除，请将模型迁移为继承 Type\\Orm\\Model 的 PHP 类型属性与 Table/Column Attribute，并纳入 sources');
        }
    }

    /** 错误位置和拼写不能被当作普通 PHP Attribute 静默略过。 */
    private function validateAttributeTargets(array $ast): void
    {
        foreach ((new NodeFinder())->find($ast, static fn (Node $node): bool => property_exists($node, 'attrGroups')) as $node) {
            foreach ($node->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $name = $attribute->name->toString();
                    if (!str_starts_with($name, 'Type\\Orm\\Attribute\\') || $name === 'Type\\Orm\\Attribute\\Transactional') {
                        continue;
                    }
                    $short = substr($name, strlen('Type\\Orm\\Attribute\\'));
                    $valid = $node instanceof Node\Stmt\Class_ ? $short === 'Table'
                        : ($node instanceof Node\Stmt\Property && in_array($short, ['Column', 'HasOne', 'HasMany', 'BelongsTo', 'BelongsToMany'], true));
                    if (!$valid) {
                        throw new RuntimeException('模型 Attribute 名称或声明位置无效：' . $name);
                    }
                }
            }
        }
    }

    private function attribute(array $groups, string $name, array $parameters): ?array
    {
        $found = null;
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() !== 'Type\\Orm\\Attribute\\' . $name) {
                    continue;
                }
                if ($found !== null) {
                    throw new RuntimeException('模型 Attribute 不能重复：' . $name);
                }
                $found = [];
                $named = false;
                foreach ($attribute->args as $index => $argument) {
                    $key = $argument->name?->toString() ?? ($parameters[$index] ?? '');
                    if ($argument->unpack || !in_array($key, $parameters, true) || array_key_exists($key, $found)
                        || ($named && $argument->name === null)) {
                        throw new RuntimeException('模型 Attribute 参数无效：' . $name . '.' . $key);
                    }
                    $named = $named || $argument->name !== null;
                    $evaluator = new ConstExprEvaluator(static function (Node\Expr $expression): mixed {
                        if ($expression instanceof Node\Expr\ClassConstFetch && $expression->class instanceof Node\Name
                            && $expression->name instanceof Node\Identifier && $expression->name->toLowerString() === 'class'
                            && !in_array(strtolower($expression->class->toString()), ['self', 'parent', 'static'], true)) {
                            return $expression->class->toString();
                        }
                        throw new RuntimeException('模型 Attribute 只接受字面值与明确的类名常量');
                    });
                    $found[$key] = $evaluator->evaluateDirectly($argument->value);
                }
            }
        }
        return $found;
    }

    private function model(Node\Stmt\Class_ $node, string $class, array $table): array
    {
        $model = ['class' => $class, 'table' => $table['name'] ?? '', 'primary' => $table['primary'] ?? 'id',
            'generated-primary' => $table['generatedPrimary'] ?? true, 'soft-delete' => $table['softDelete'] ?? null,
            'version' => $table['version'] ?? null, 'fields' => [], 'relations' => []];
        $properties = [];
        foreach ($node->stmts as $member) {
            if ($member instanceof Node\Stmt\ClassMethod
                && in_array(strtolower($member->name->toString()), ['get', 'set', 'related', '__get', '__set'], true)) {
                throw new RuntimeException('模型不能覆盖属性状态入口，请使用 ModelBehavior：' . $class . '::' . $member->name);
            }
            if ($member instanceof Node\Stmt\TraitUse) {
                throw new RuntimeException('属性模型不能通过 trait 隐式引入成员：' . $class);
            }
            if (!$member instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($member->props as $property) {
                $propertyName = $property->name->toString();
                if (isset($properties[$propertyName])) {
                    throw new RuntimeException('模型属性重复：' . $class . '::' . $propertyName);
                }
                $properties[$propertyName] = true;
            }
            $column = $this->attribute($member->attrGroups, 'Column', ['name', 'type', 'fillable', 'visible', 'required', 'precision', 'scale']);
            $relation = $this->relation($member->attrGroups);
            if (!$member->isPublic() && $column === null && $relation === null) {
                continue;
            }
            if (!$member->isPublic() || $member->isStatic() || $member->isReadonly() || count($member->props) !== 1
                || $member->props[0]->default !== null || $member->hooks !== [] || $member->type === null) {
                throw new RuntimeException('模型属性必须是无默认值、无自定义钩子的公开类型属性：' . $class);
            }
            $name = $member->props[0]->name->toString();
            $nullable = $member->type instanceof Node\NullableType;
            $typeNode = $nullable ? $member->type->type : $member->type;
            if (!$typeNode instanceof Node\Identifier && !$typeNode instanceof Node\Name) {
                throw new RuntimeException('模型属性不接受联合或交集类型：' . $name);
            }
            $phpType = $typeNode->toString();
            $nullable = $nullable || $phpType === 'mixed';
            $literal = var_export($name, true);
            if ($relation !== null) {
                if ($column !== null || (in_array($relation['kind'], ['HasMany', 'BelongsToMany'], true)
                    ? $phpType !== 'array' || $nullable : $phpType !== $relation['target'] || !$nullable)) {
                    throw new RuntimeException('关系属性需要非空 array 或可空目标模型类型，且不能同时声明 Column：' . $name);
                }
                $model['relations'][$name] = $relation;
                $hookCode = "get { return \$this->related({$literal}); }";
            } else {
                $inferred = ['int' => 'integer', 'string' => 'string', 'bool' => 'boolean', 'array' => 'json', 'mixed' => 'json', 'DateTimeImmutable' => 'datetime'];
                $type = $column['type'] ?? ($inferred[$phpType] ?? '');
                if ($type === '' || (!in_array($type, ['decimal', 'bigint'], true) && ($inferred[$phpType] ?? '') !== $type)
                    || (in_array($type, ['decimal', 'bigint'], true) && $phpType !== 'string')) {
                    throw new RuntimeException('PHP 属性类型与模型列类型不一致：' . $name);
                }
                $field = ['type' => $type, 'nullable' => $nullable];
                if ($phpType === 'array') {
                    $field['array-only'] = true;
                }
                foreach ($column ?? [] as $key => $value) {
                    if ($value !== null) {
                        $field[$key === 'name' ? 'column' : $key] = $value;
                    }
                }
                if ($name === $model['version'] || $name === $model['soft-delete']) {
                    $field['fillable'] ??= false;
                    $field['required'] ??= false;
                }
                $model['fields'][$name] = $field;
                $hookCode = "get { return \$this->get({$literal}); } set { \$this->set({$literal}, \$value); }";
            }
            $hooks = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php class Property { public mixed \$field { {$hookCode} } }");
            $member->hooks = $hooks[0]->stmts[0]->hooks;
        }
        return $model;
    }

    private function relation(array $groups): ?array
    {
        $result = null;
        foreach (['HasOne' => ['target', 'foreignKey', 'localKey'], 'HasMany' => ['target', 'foreignKey', 'localKey'],
            'BelongsTo' => ['target', 'foreignKey', 'ownerKey'],
            'BelongsToMany' => ['target', 'table', 'sourcePivotKey', 'targetPivotKey', 'sourceKey', 'targetKey', 'pivotFields']] as $kind => $parameters) {
            $arguments = $this->attribute($groups, $kind, $parameters);
            if ($arguments === null) {
                continue;
            }
            if ($result !== null || !is_string($arguments['target'] ?? null)) {
                throw new RuntimeException('关系必须只声明一种类型和明确的目标模型');
            }
            $result = ['kind' => $kind] + $arguments;
        }
        return $result;
    }

    private function validateRelations(array $models): void
    {
        foreach ($models as $model) {
            foreach ($model['relations'] as $name => $relation) {
                $target = $models[strtolower($relation['target'])] ?? null;
                $sourceKey = $relation['kind'] === 'BelongsTo' ? ($relation['foreignKey'] ?? '') : ($relation['localKey'] ?? $relation['sourceKey'] ?? 'id');
                $targetKey = in_array($relation['kind'], ['HasOne', 'HasMany'], true) ? ($relation['foreignKey'] ?? '') : ($relation['ownerKey'] ?? $relation['targetKey'] ?? 'id');
                if ($target === null || !isset($model['fields'][$sourceKey]) || !isset($target['fields'][$targetKey])
                    || $model['fields'][$sourceKey]['type'] !== $target['fields'][$targetKey]['type']) {
                    throw new RuntimeException('关系目标、键或类型无效：' . $model['class'] . '::' . $name);
                }
                if ($relation['kind'] === 'BelongsToMany') {
                    foreach (['table', 'sourcePivotKey', 'targetPivotKey'] as $key) {
                        if (!is_string($relation[$key] ?? null) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $relation[$key])) {
                            throw new RuntimeException('中间表关系声明无效：' . $name . '.' . $key);
                        }
                    }
                }
            }
        }
    }

    private function render(array $models): string
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n";
        $known = [];
        foreach ($models as $model) {
            if (!is_array($model) || !is_string($model['class'] ?? null)
                || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $model['class'])) {
                throw new RuntimeException('模型必须声明带命名空间的类名');
            }
            if (isset($known[strtolower($model['class'])])) {
                throw new RuntimeException('模型类重复：' . $model['class']);
            }
            $known[strtolower($model['class'])] = true;
            foreach (array_keys($model) as $key) {
                if (!in_array($key, ['class', 'table', 'primary', 'generated-primary', 'fields', 'soft-delete', 'version', 'relations'], true)) {
                    throw new RuntimeException('未知模型配置：' . $key);
                }
            }
            $table = $model['table'] ?? '';
            $primary = $model['primary'] ?? 'id';
            $generated = $model['generated-primary'] ?? true;
            $fields = $model['fields'] ?? [];
            $softDelete = $model['soft-delete'] ?? null;
            $version = $model['version'] ?? null;
            if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $table)
                || !is_string($primary) || !is_array($fields) || !isset($fields[$primary]) || !is_bool($generated)) {
                throw new RuntimeException('模型表、主键或字段声明无效：' . $model['class']);
            }
            if ($softDelete !== null && (!is_string($softDelete) || !isset($fields[$softDelete])
                || ($fields[$softDelete]['type'] ?? '') !== 'datetime' || ($fields[$softDelete]['nullable'] ?? false) !== true
                || ($fields[$softDelete]['fillable'] ?? true) !== false)) {
                throw new RuntimeException('软删除声明需要不可批量赋值的可空时间字段');
            }
            if ($version !== null && (!is_string($version) || !isset($fields[$version]) || $version === $primary
                || ($fields[$version]['type'] ?? '') !== 'integer' || ($fields[$version]['nullable'] ?? false) !== false
                || ($fields[$version]['fillable'] ?? true) !== false)) {
                throw new RuntimeException('版本声明需要不可赋值的非空整数字段');
            }
            $columns = [];
            $methods = [];
            $declarations = [];
            $accessors = '';
            foreach ($fields as $name => $field) {
                if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_array($field)) {
                    throw new RuntimeException('模型字段声明无效');
                }
                foreach (array_keys($field) as $key) {
                    if (!in_array($key, ['column', 'type', 'nullable', 'fillable', 'visible', 'required', 'precision', 'scale', 'array-only'], true)) {
                        throw new RuntimeException('未知模型字段配置：' . $name . '.' . $key);
                    }
                }
                $column = $field['column'] ?? $name;
                $type = $field['type'] ?? 'string';
                if (!is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)
                    || isset($columns[strtolower($column)]) || !in_array($type, ['string', 'integer', 'boolean', 'json', 'decimal', 'bigint', 'datetime'], true)) {
                    throw new RuntimeException('模型列重复或类型无效：' . $name);
                }
                $columns[strtolower($column)] = true;
                $nullable = $field['nullable'] ?? false;
                $fillable = $field['fillable'] ?? !($name === $primary && $generated);
                $visible = $field['visible'] ?? true;
                $required = $field['required'] ?? !($name === $primary && $generated);
                $precision = $field['precision'] ?? 65;
                $scale = $field['scale'] ?? ($type === 'decimal' ? 2 : 0);
                if (!is_int($precision) || !is_int($scale) || $precision < 1 || $precision > 65 || $scale < 0 || $scale > min(30, $precision)
                    || (isset($field['precision']) && !in_array($type, ['decimal', 'bigint'], true)) || (isset($field['scale']) && $type !== 'decimal')) {
                    throw new RuntimeException('模型精度声明无效：' . $name);
                }
                foreach ([$nullable, $fillable, $visible, $required] as $flag) {
                    if (!is_bool($flag)) {
                        throw new RuntimeException('模型字段开关必须是布尔值：' . $name);
                    }
                }
                $suffix = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
                if ($suffix === '' || isset($methods[strtolower($suffix)])) {
                    throw new RuntimeException('生成访问器名称冲突：' . $name);
                }
                $methods[strtolower($suffix)] = true;
                $phpType = ['string' => 'string', 'integer' => 'int', 'boolean' => 'bool', 'json' => 'mixed',
                    'decimal' => 'string', 'bigint' => 'string', 'datetime' => '\\DateTimeImmutable'][$type];
                if ($nullable && $phpType !== 'mixed') {
                    $phpType = '?' . $phpType;
                }
                $literal = var_export($name, true);
                $accessors .= "    /** 读取已加载属性，未加载或模型失效时拒绝。 */\n    public function get{$suffix}(): {$phpType} { return \$this->get({$literal}); }\n";
                if ($fillable) {
                    $inputType = $type === 'datetime' ? '\\DateTimeInterface|string' . ($nullable ? '|null' : '')
                        : (in_array($type, ['decimal', 'bigint'], true) ? 'int|string' . ($nullable ? '|null' : '') : $phpType);
                    $accessors .= "    /** 规范化赋值并登记变更，不执行数据库写入。 */\n    public function set{$suffix}({$inputType} \$value): void { \$this->set({$literal}, \$value); }\n";
                }
                $arguments = array_map(static fn (mixed $value): string => var_export($value, true), [$column, $type, $nullable, $fillable, $visible, $required, $precision, $scale, $field['array-only'] ?? false]);
                $declarations[] = $literal . ' => new \\Type\\Orm\\ModelField(' . implode(', ', $arguments) . ')';
            }
            $parts = explode('\\', $model['class']);
            $class = array_pop($parts);
            $namespace = implode('\\', $parts);
            $definition = 'new \\Type\\Orm\\ModelDefinition(' . var_export($table, true) . ', ' . var_export($primary, true)
                . ', [' . implode(', ', $declarations) . '], ' . var_export($generated, true) . ', ' . var_export($softDelete, true) . ', ' . var_export($version, true)
                . ', ' . $this->renderRelations($model['relations']) . ')';
            $code .= "\nnamespace {$namespace} {\nclass {$class} extends \\Type\\Orm\\Model\n{\n";
            $code .= "    /** @param array<string, mixed> \$values 新建字段；persisted 仅供水合工厂使用。 */\n    public function __construct(array \$values = [], bool \$persisted = false, ?\\Type\\Orm\\ModelBehavior \$behavior = null) { parent::__construct(self::mapping(), \$values, \$persisted, \$behavior); }\n";
            $code .= "    /** 返回静态声明的字段和关系映射，不访问数据库。 */\n    public static function mapping(): \\Type\\Orm\\ModelDefinition { return {$definition}; }\n";
            $code .= "    /** 创建绑定当前连接的不可变模型查询；结果保持本业务类。 */\n    public static function query(\\Type\\Orm\\Connection \$connection, string \$alias = ''): \\Type\\Orm\\ModelQuery\n    {\n"
                . "        return new \\Type\\Orm\\ModelQuery(\$connection, self::mapping(), static fn (array \$row): {$class} => new {$class}(\$row, true), \$alias);\n    }\n";
            $code .= $accessors . "}\n}\n";
        }
        try {
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (\PhpParser\Error $error) {
            throw new RuntimeException('模型生成包含非法 PHP 声明：' . $error->getMessage(), 0, $error);
        }
        return $code;
    }

    private function renderRelations(array $relations): string
    {
        $code = [];
        foreach ($relations as $name => $relation) {
            $target = '\\' . $relation['target'];
            $sourceKey = $relation['kind'] === 'BelongsTo' ? $relation['foreignKey'] : ($relation['localKey'] ?? $relation['sourceKey'] ?? 'id');
            $targetKey = in_array($relation['kind'], ['HasOne', 'HasMany'], true) ? $relation['foreignKey'] : ($relation['ownerKey'] ?? $relation['targetKey'] ?? 'id');
            $code[] = var_export($name, true) . ' => new \\Type\\Orm\\RelationDefinition('
                . var_export($relation['kind'], true) . ', static fn (\\Type\\Orm\\Connection $connection, string $alias): \\Type\\Orm\\ModelQuery => '
                . $target . '::query($connection, $alias), ' . var_export($sourceKey, true) . ', ' . var_export($targetKey, true)
                . ', ' . var_export($relation['table'] ?? '', true) . ', ' . var_export($relation['sourcePivotKey'] ?? '', true)
                . ', ' . var_export($relation['targetPivotKey'] ?? '', true) . ', ' . var_export($relation['pivotFields'] ?? [], true) . ')';
        }
        return '[' . implode(', ', $code) . ']';
    }
}
