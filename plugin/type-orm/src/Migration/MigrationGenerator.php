<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

use Type\Orm\Connection;

/**
 * 从当前数据库只读生成可审查的 Migration 草稿。
 *
 * 生成结果不写入数据库、不修改既有迁移记录；开发者必须审查输出后再登记到应用迁移计划。
 * 结构读取属于 ORM 的数据库基础设施边界，业务代码不应直接调用本类。
 */
final class MigrationGenerator
{
    /**
     * @return array{driver:string,migration:Migration,script:string,warnings:list<string>,complete:bool,integrity:array<string,mixed>}
     */
    public function generate(Connection $connection, string $version, string $description): array
    {
        $catalog = $connection->driver()->migrations();
        $statements = [...$catalog->structure($connection), ...$catalog->initialization($connection)];
        if ($statements === []) {
            throw new \RuntimeException('TYPE_MIGRATION_GENERATE_EMPTY：当前数据库没有可生成的持久表');
        }
        $tables = $catalog->describe($connection);
        $script = self::blueprintSource($version, $description, $tables, $catalog->initialization($connection));
        $down = [];
        foreach (self::dropOrder($tables) as $table) {
            $down[] = 'DROP TABLE IF EXISTS ' . self::quoteIdentifier((string)$table['name'], $connection->driverName());
        }
        $migration = new Migration($version, $description, $statements, $catalog->transactionalDdl(), $down);
        $integrity = ['complete' => true, 'tables' => [], 'unsupported' => []];
        foreach ($tables as $table) {
            $missing = [];
            foreach (['columns', 'primary', 'indexes', 'foreign', 'checks'] as $key) {
                if (!array_key_exists($key, $table)) {
                    $missing[] = $key;
                }
            }
            $unsupported = array_values(array_filter($table['unsupported'] ?? [], 'is_string'));
            $tableComplete = $missing === [] && $unsupported === [];
            $integrity['tables'][] = ['name' => (string)$table['name'], 'complete' => $tableComplete,
                'missing' => $missing, 'unsupported' => $unsupported];
            if (!$tableComplete) {
                $integrity['complete'] = false;
                array_push($integrity['unsupported'], ...array_map(
                    static fn (string $item): string => (string)$table['name'] . ': ' . $item,
                    [...$missing, ...$unsupported]
                ));
            }
        }
        $complete = (bool)$integrity['complete'];
        $warnings = [
            '生成结果是当前库的 Blueprint 草稿，并包含安装标记、平台默认设置和菜单目录；提交前必须审查数据库特性。',
        ];
        if (!$complete) { $warnings[] = '当前驱动未能完整读取或表达全部结构特性，生成结果不能直接视为结构等价：' . implode('；', $integrity['unsupported']); }
        return ['driver' => $connection->driverName(), 'migration' => $migration, 'script' => $script,
            'warnings' => $warnings, 'complete' => $complete, 'integrity' => $integrity];
    }

    private static function quoteIdentifier(string $identifier, string $driver): string
    {
        $quote = $driver === 'mysql' ? '`' : '"';
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    /**
     * 按外键依赖生成安全的删除顺序：被引用的父表排在引用它的子表之后。
     * 约束环无法通过普通 DROP 顺序消除，保留确定顺序并由目标数据库报告真实失败。
     *
     * @param list<array<string, mixed>> $tables
     * @return list<array<string, mixed>>
     */
    private static function dropOrder(array $tables): array
    {
        $byName = [];
        foreach ($tables as $table) {
            $name = (string)($table['name'] ?? '');
            if ($name !== '') {
                $byName[$name] = $table;
            }
        }
        $dependents = [];
        foreach ($tables as $table) {
            $name = (string)($table['name'] ?? '');
            foreach (($table['foreign'] ?? []) as $foreign) {
                $parent = (string)($foreign['on'] ?? '');
                if ($name !== '' && $parent !== '' && isset($byName[$parent])) {
                    $dependents[$parent][] = $name;
                }
            }
        }
        $visited = [];
        $active = [];
        $ordered = [];
        $visit = static function (string $name) use (&$visit, &$visited, &$active, &$ordered, $dependents): void {
            if (isset($visited[$name]) || isset($active[$name])) {
                return;
            }
            $active[$name] = true;
            foreach ($dependents[$name] ?? [] as $child) {
                $visit($child);
            }
            unset($active[$name]);
            $visited[$name] = true;
            $ordered[] = $name;
        };
        foreach (array_keys($byName) as $name) {
            $visit($name);
        }
        return array_values(array_map(static fn (string $name): array => $byName[$name], $ordered));
    }

    /**
     * 渲染可复制到迁移注册表的标准表达式，不执行任何源码写入。
     */
    public static function source(Migration $migration): string
    {
        return 'new \\Type\\Orm\\Migration\\Migration(' . var_export($migration->version(), true) . ', '
            . var_export($migration->description(), true) . ', ' . var_export($migration->statements(), true) . ', '
            . ($migration->transactional() ? 'true' : 'false')
            . ($migration->reversible() ? ', ' . var_export($migration->downStatements(), true) : '') . ')';
    }

    /**
     * 把当前库描述渲染成可执行的 SchemaMigration。
     *
     * @param list<array<string, mixed>> $tables
     * @param list<string> $seed
     */
    public static function blueprintSource(string $version, string $description, array $tables, array $seed = []): string
    {
        $body = '';
        $drops = '';
        foreach (self::dropOrder($tables) as $table) {
            $drops .= "        \$schema->dropIfExists(" . var_export($table['name'], true) . ");\n";
        }
        foreach ($tables as $table) {
            $name = var_export($table['name'], true);
            $body .= "        if (!\$schema->hasTable({$name})) {\n            \$schema->create({$name}, function (Blueprint \$table): void {\n";
            $auto = null;
            foreach ($table['columns'] as $column) {
                if (!empty($column['auto']) && count($table['primary']) === 1 && $table['primary'][0] === $column['name']) {
                    $auto = $column['name'];
                    $method = ($column['bits'] ?? 32) === 64 ? 'bigIncrements' : 'increments';
                    $body .= "                \$table->{$method}(" . var_export($column['name'], true) . ')' . self::modifiers($column) . ";\n";
                    continue;
                }
                $body .= '                ' . self::columnCall($column) . ";\n";
            }
            if ($auto === null && $table['primary'] !== []) {
                $body .= '                $table->primary(' . var_export(array_values($table['primary']), true) . ");\n";
            }
            foreach ($table['indexes'] as $index) {
                $method = !empty($index['unique']) ? 'unique' : 'index';
                $body .= "                \$table->{$method}(" . var_export($index['columns'], true) . ', ' . var_export($index['name'], true) . ");\n";
            }
            foreach ($table['foreign'] as $foreign) {
                $body .= '                $table->foreign(' . var_export($foreign['columns'], true) . ', ' . var_export($foreign['on'], true) . ', ' . var_export($foreign['references'], true)
                    . (isset($foreign['onDelete']) && $foreign['onDelete'] !== null ? ', ' . var_export($foreign['onDelete'], true) : '') . ");\n";
            }
            foreach (($table['checks'] ?? []) as $check) {
                $expression = preg_replace('/^CHECK\s*\((.*)\)$/is', '$1', (string)$check) ?? (string)$check;
                $body .= '                $table->check(' . var_export($expression, true) . ");\n";
            }
            if (($table['comment'] ?? '') !== '') {
                $body .= '                $table->comment(' . var_export($table['comment'], true) . ");\n";
            }
            $body .= "            });\n        }\n";
        }
        foreach ($seed as $statement) {
            if (preg_match('/^INSERT INTO\s+([A-Za-z0-9_]+)\s+\((.+)\)\s+VALUES\s+\((.+)\)\s*$/iD', $statement, $matches) !== 1) {
                continue;
            }
            $body .= "        \$schema->insert(" . var_export($matches[1], true) . ", [[" . self::pairs($matches[2], $matches[3]) . "]]);\n";
        }
        return "<?php\n\ndeclare(strict_types=1);\n\nuse Type\\Orm\\Migration\\SchemaMigration;\nuse Type\\Orm\\Schema\\Blueprint;\nuse Type\\Orm\\Schema\\Schema;\n\n/** 从当前数据库生成的迁移草稿，文件名 {$version}.php 就是版本。提交前审查字段、索引和外键。 */\nreturn new class extends SchemaMigration {\n"
            . '    public function description(): string { return ' . var_export($description, true) . "; }\n"
            . "    public function up(Schema \$schema): void\n    {\n{$body}    }\n"
            . "    public function down(Schema \$schema): void\n    {\n{$drops}    }\n};\n";
    }

    /**
     * @param array<string, mixed> $column
     */
    private static function columnCall(array $column): string
    {
        $name = var_export($column['name'], true);
        $call = match ($column['type']) {
            'string' => "\$table->string({$name}, " . (int)($column['length'] ?? 255) . ')',
            'text' => "\$table->text({$name})",
            'boolean' => "\$table->boolean({$name})",
            'decimal' => "\$table->decimal({$name}, " . (int)($column['precision'] ?? 10) . ', ' . (int)($column['scale'] ?? 0) . ')',
            'datetime' => "\$table->dateTime({$name})",
            default => ($column['bits'] ?? 64) === 64 ? "\$table->bigInteger({$name})" : "\$table->integer({$name})",
        };
        return $call . self::modifiers($column);
    }

    /**
     * @param array<string, mixed> $column
     */
    private static function modifiers(array $column): string
    {
        $code = '';
        if (!empty($column['nullable'])) {
            $code .= '->nullable()';
        }
        if (array_key_exists('default', $column) && $column['default'] !== null && !is_array($column['default'])) {
            $code .= '->default(' . var_export(self::defaultLiteral($column['default']), true) . ')';
        }
        if (($column['comment'] ?? '') !== '') {
            $code .= '->comment(' . var_export($column['comment'], true) . ')';
        }
        return $code;
    }

    private static function defaultLiteral(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int)$value;
        }
        return $value;
    }

    private static function pairs(string $columns, string $values): string
    {
        $names = array_map('trim', explode(',', $columns));
        $parts = str_getcsv($values, ',', "'", '\\');
        $items = [];
        foreach ($names as $index => $name) {
            $raw = $parts[$index] ?? 'NULL';
            $value = strtoupper(trim($raw)) === 'NULL' ? null : trim($raw, " \t'");
            if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
                $value = (int)$value;
            }
            $items[] = var_export(trim($name), true) . ' => ' . var_export($value, true);
        }
        return implode(', ', $items);
    }
}
