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

/**
 * 把数据库结构同步到可编辑的简洁模型，只替换声明与 IDE 提示，保留其余源码字节。
 */
final class ModelSchemaSynchronizer
{
    /**
     * @param array{table: string, comment: string, columns: list<array<string, mixed>>} $structure MySQL 只读结构快照
     * @return array{code: string, added: list<string>, removed: list<string>}
     * @throws \RuntimeException 类型、主键、既有模型、字段删除或声明冲突不能安全处理
     */
    public function synchronize(string $class, array $structure, ?string $source = null, bool $dropMissing = false): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $creating = $source === null;
        if ($creating) {
            $parts = explode('\\', $class);
            $name = array_pop($parts);
            $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . implode('\\', $parts) . ";\n\nuse Type\\Orm\\Model;\n\nfinal class " . $name . " extends Model\n{\n    protected string \$table = '';\n    protected array \$schema = [];\n}\n";
        }
        $ast = (new NodeTraverser(new NameResolver()))->traverse($parser->parse($source) ?? []);
        $classes = (new NodeFinder())->findInstanceOf($ast, Node\Stmt\Class_::class);
        if (count($classes) !== 1 || !isset($classes[0]->namespacedName) || $classes[0]->namespacedName->toString() !== $class
            || $classes[0]->extends?->toString() !== 'Type\Orm\Model') {
            throw new \RuntimeException('模型同步需要文件内唯一且直接继承 Model 的目标类');
        }
        $node = $classes[0];
        if (!$creating) {
            foreach ($node->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($attribute->name->toString() === 'Type\Orm\Attribute\Table') {
                        throw new \RuntimeException('模型同步不能混合 Table Attribute 与 table/schema 声明');
                    }
                }
            }
            (new ModelSchemaDeclaration())->expand(clone $node, $class);
        }
        $properties = [];
        $values = [];
        $evaluator = new ConstExprEvaluator(static function (): mixed {
            throw new \RuntimeException('模型同步不能解释动态配置');
        });
        foreach ($node->getProperties() as $property) {
            foreach ($property->props as $member) {
                $key = $member->name->toString();
                if (isset($properties[$key])) {
                    throw new \RuntimeException('模型同步发现重复属性');
                }
                $properties[$key] = $property;
                if (in_array($key, ['table', 'schema', 'pk', 'autoIncrement', 'tenantKey'], true)) {
                    if (!$property->isProtected() || $property->isStatic() || count($property->props) !== 1 || $member->default === null) {
                        throw new \RuntimeException('模型同步只接受静态 protected 配置');
                    }
                    $values[$key] = $evaluator->evaluateDirectly($member->default);
                }
            }
        }
        if (!array_key_exists('table', $values) || !is_array($values['schema'] ?? null)
            || ($values['table'] !== '' && $values['table'] !== $structure['table'])) {
            throw new \RuntimeException('模型同步需要同表的 table/schema 声明，不能覆盖其他声明方式');
        }
        $existing = $values['schema'];
        $aliases = [];
        foreach ($existing as $name => $field) {
            $column = is_array($field) ? ($field['column'] ?? $name) : $name;
            if (!is_string($column) || isset($aliases[$column])) {
                throw new \RuntimeException('模型同步的字段映射重复或无效');
            }
            $aliases[$column] = $name;
        }
        $schema = [];
        $primary = [];
        $auto = false;
        foreach ($structure['columns'] as $column) {
            $name = $aliases[$column['name']] ?? $column['name'];
            $field = $this->field($column);
            if ($name !== $column['name']) {
                $field['column'] = $column['name'];
            }
            foreach (['visible', 'fillable', 'required'] as $option) {
                if (is_array($existing[$name] ?? null) && array_key_exists($option, $existing[$name])) {
                    $field[$option] = $existing[$name][$option];
                }
            }
            if ($column['expression'] !== '') {
                $field['fillable'] = false;
                $field['required'] = false;
            }
            $schema[$name] = $field;
            if ($column['key_type'] === 'PRI') {
                $primary[] = $name;
                $auto = $auto || str_contains(strtolower($column['extra']), 'auto_increment');
            }
        }
        $tenantDeclared = array_key_exists('tenantKey', $values);
        $inferredTenant = isset($schema['tenant_id']) && ($schema['tenant_id']['nullable'] ?? false) !== true ? 'tenant_id' : null;
        $tenant = $tenantDeclared ? $values['tenantKey'] : ($aliases['tenant_id'] ?? $inferredTenant);
        $keys = array_values(array_diff($primary, [$tenant]));
        if (count($keys) !== 1 || (count($primary) !== 1 && (count($primary) !== 2 || $tenant === null))) {
            throw new \RuntimeException('模型同步需要单主键，或 tenant_id 与单一业务主键组成的复合主键');
        }
        $removed = array_values(array_diff(array_keys($existing), array_keys($schema)));
        if ($removed !== [] && !$dropMissing) {
            throw new \RuntimeException('数据库字段已删除，核对调用者后显式使用 --drop-missing：' . implode(', ', $removed));
        }
        $declarations = ['table' => $structure['table'], 'pk' => $keys[0], 'autoIncrement' => $auto, 'tenantKey' => $tenant, 'schema' => $schema];
        $printer = new Standard();
        $changes = [];
        $insert = '';
        foreach ($declarations as $name => $value) {
            $type = ['table' => 'string', 'pk' => 'string', 'autoIncrement' => 'bool', 'tenantKey' => '?string', 'schema' => 'array'][$name];
            $expression = $printer->prettyPrintExpr($this->literal($value));
            if ($name === 'schema') {
                $rows = [];
                foreach ($schema as $fieldName => $field) {
                    $rows[] = '    ' . var_export($fieldName, true) . ' => ' . $printer->prettyPrintExpr($this->literal($field)) . ',';
                }
                $expression = "[\n" . implode("\n", $rows) . "\n]";
            }
            $rendered = str_replace(' = NULL;', ' = null;', 'protected ' . $type . ' $' . $name . ' = ' . str_replace("\n", "\n    ", $expression) . ';');
            if (isset($properties[$name])) {
                $property = $properties[$name];
                $changes[] = [$property->getStartFilePos(), $property->getEndFilePos() + 1, $rendered];
            } else {
                $insert .= "\n    " . $rendered . "\n";
            }
        }
        if ($insert !== '') {
            $position = $properties['schema']->getStartFilePos();
            $changes[] = [$position, $position, ltrim($insert, "\n ") . "\n    "];
        }
        $doc = $node->getDocComment();
        $description = $doc?->getText() ?? '/** ' . $this->description($structure['comment'] !== '' ? $structure['comment'] : $structure['table']) . ' */';
        $lines = explode("\n", $description);
        // 仅更新持久字段的属性提示，保留手写说明、关系提示与业务方法注释。
        $fieldNames = array_fill_keys(array_merge(array_keys($existing), array_keys($schema)), true);
        $methods = [
            ' * @method static \Type\Orm\ModelQuery<static> query(string $alias = \'\')',
            ' * @method static \Type\Orm\ModelQuery<static> where(string|array $field, mixed $operator = null, mixed $value = null, string $boolean = \'AND\')',
            ' * @method static \Type\Orm\ModelQuery<static> order(string $field, string $direction = \'ASC\')',
            ' * @method static \Type\Orm\ModelQuery<static> limit(int $count, int $offset = 0)',
            ' * @method static \Type\Orm\ModelQuery<static> with(string $name, ?\Closure $relation = null)',
            ' * @method static static|null find(int|string $id)',
            ' * @method static bool destroy(int|string $id)',
            ' * @method static static create(array $values)',
            ' * @method static static firstOrCreate(array $identity, array $values = [])',
            ' * @method static \Type\Orm\Helper\QueryHelper search(array $input = [], string $alias = \'\')',
        ];
        $lines = array_values(array_filter($lines, static function (string $line) use ($fieldNames): bool {
            return preg_match('/@method\s+static\s+\S+\s+(?:query|where|order|limit|with|find|destroy|create|firstOrCreate|search)\(/', $line) !== 1
                && (preg_match('/@property(?:-read|-write)?\s+\S+\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $line, $match) !== 1 || !isset($fieldNames[$match[1]]));
        }));
        $description = implode("\n", $lines);
        if (!str_contains($description, "\n")) {
            $description = "/**\n * " . trim(substr($description, 3, -2)) . "\n */";
        }
        $hints = [];
        foreach ($schema as $name => $field) {
            $php = ['integer' => 'int', 'boolean' => 'bool', 'bigint' => 'string', 'decimal' => 'string', 'string' => 'string', 'json' => 'mixed', 'datetime' => '\DateTimeImmutable'][$field['type']];
            $php .= ($field['nullable'] ?? false) && $php !== 'mixed' ? '|null' : '';
            $hints[] = rtrim(' * @property' . (($field['fillable'] ?? true) ? '' : '-read') . ' ' . $php . ' $' . $name . ' ' . $this->description((string)($field['comment'] ?? '')));
        }
        foreach ($methods as $method) {
            $hints[] = $method;
        }
        $description = rtrim(substr($description, 0, strrpos($description, '*/')), " \t") . implode("\n", $hints) . "\n */";
        $start = $doc?->getStartFilePos() ?? $node->getStartFilePos();
        $end = $doc === null ? $start : $doc->getEndFilePos() + 1;
        $changes[] = [$start, $end, $description . ($doc === null ? "\n" : '')];
        usort($changes, static fn (array $left, array $right): int => $right[0] <=> $left[0] ?: $right[1] <=> $left[1]);
        foreach ($changes as [$start, $end, $text]) {
            $source = substr($source, 0, $start) . $text . substr($source, $end);
        }
        $parser->parse($source);
        return ['code' => $source, 'added' => array_values(array_diff(array_keys($schema), array_keys($existing))), 'removed' => $removed];
    }

    /**
     * 保留精确数值及数据库默认值语义，不将无法表示的 SQL 类型降为字符串。
     */
    private function field(array $column): array
    {
        if (isset($column['model_type'])) {
            $type = (string)$column['model_type'];
            if (!in_array($type, ['integer', 'boolean', 'string', 'decimal', 'bigint', 'datetime', 'json'], true)) {
                throw new \RuntimeException('数据库字段不能自动映射，请显式建模：' . $column['name'] . ':' . $type);
            }
            $field = ['type' => $type];
            if ($column['nullable'] === 'YES') {
                $field['nullable'] = true;
            }
            if ($type === 'decimal') {
                $field['precision'] = (int)$column['precision_value'];
                $field['scale'] = (int)$column['scale_value'];
            }
            if (($field['nullable'] ?? false) || $column['default_value'] !== null || str_contains(strtolower((string)$column['extra']), 'auto_increment')) {
                $field['required'] = false;
            }
            if ((string)$column['comment'] !== '') {
                $field['comment'] = (string)$column['comment'];
            }
            return $field;
        }
        $type = strtolower((string)$column['type']);
        $types = ['tinyint' => 'integer', 'smallint' => 'integer', 'mediumint' => 'integer', 'int' => 'integer', 'bigint' => 'bigint',
            'decimal' => 'decimal', 'char' => 'string', 'varchar' => 'string', 'tinytext' => 'string', 'text' => 'string',
            'mediumtext' => 'string', 'longtext' => 'string', 'enum' => 'string', 'set' => 'string', 'json' => 'json',
            'datetime' => 'datetime', 'timestamp' => 'datetime'];
        if (!isset($types[$type]) || ($column['expression'] !== '' && $column['key_type'] === 'PRI')
            || (in_array($type, ['datetime', 'timestamp'], true) && preg_match('/\(6\)$/D', $column['declaration']) !== 1)) {
            throw new \RuntimeException('数据库字段不能自动映射，请显式建模：' . $column['name'] . ':' . $type);
        }
        $field = ['type' => $types[$type], 'nullable' => $column['nullable'] === 'YES'];
        if ($type === 'decimal') {
            $field['precision'] = (int)$column['precision_value'];
            $field['scale'] = (int)$column['scale_value'];
        }
        if ($type === 'bigint') {
            $field['integerRange'] = str_contains(strtolower($column['declaration']), 'unsigned') ? 'unsigned64' : 'signed64';
        }
        if ($field['nullable'] || $column['default_value'] !== null || str_contains(strtolower($column['extra']), 'auto_increment')) {
            $field['required'] = false;
        }
        $field['comment'] = (string)$column['comment'];
        return $field;
    }

    /**
     * 只把已经取得的结构值转换为字面 AST；不执行表达式。
     */
    private function literal(mixed $value): Node\Expr
    {
        $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php return ' . var_export($value, true) . ';');
        foreach ((new NodeFinder())->findInstanceOf($parsed, Node\Expr\Array_::class) as $array) {
            $array->setAttribute('kind', Node\Expr\Array_::KIND_SHORT);
        }
        return $parsed[0]->expr;
    }

    private function description(string $text): string
    {
        return trim(str_replace(['*/', "\r", "\n"], ['* /', ' ', ' '], $text));
    }
}
