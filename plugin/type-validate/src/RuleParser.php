<?php

declare(strict_types=1);

namespace Type\Validate;

/**
 * 把 `字段.规则` 文本声明转换成内部字段对象。
 *
 * 规则值在 default 与 value 中是默认值或固定值，其余位置是给人看的错误说明；
 * 响应仍使用字段和稳定错误码。
 */
final class RuleParser
{
    /**
     * 把字段规则、直接读取项和固定值收成字段对象。
     *
     * @param array<string, mixed> $rules 字段.规则、列表项或固定值
     * @return array{fields: array<string, Field>, fixed: array<string, mixed>}
     */
    public static function parse(array $rules): array
    {
        if ($rules === []) {
            throw new \InvalidArgumentException('校验规则不能为空');
        }
        $grouped = [];
        foreach ($rules as $key => $message) {
            if (is_int($key)) {
                self::passthrough($grouped, $message);
                continue;
            }
            if (is_string($key) && !str_contains($key, '.')) {
                self::fixedField($grouped, $key, $message);
                continue;
            }
            $parsed = self::key((string)$key);
            if ($parsed === null) {
                throw new \InvalidArgumentException('校验规则必须写成字段.规则');
            }
            [$name, $alias, $rule, $argument] = $parsed;
            $grouped[$name]['alias'] ??= $name;
            if ($alias !== '' && $grouped[$name]['alias'] !== $name && $grouped[$name]['alias'] !== $alias) {
                throw new \InvalidArgumentException('同一字段的输入别名不一致：' . $name);
            }
            if ($alias !== '') {
                $grouped[$name]['alias'] = $alias;
            }
            $grouped[$name]['rules'][] = [$rule, $argument, $message];
        }
        $fields = [];
        $fixed = [];
        foreach ($grouped as $name => $spec) {
            if (($spec['passthrough'] ?? false) === true && ($spec['rules'] ?? []) === []) {
                $spec['rules'] = [['nullable', '', null], ['default', '', null]];
            }
            $type = 'string';
            foreach ($spec['rules'] as [$rule]) {
                $type = match ($rule) {
                    'integer', 'int' => 'integer',
                    'number', 'numeric' => 'number',
                    'boolean', 'bool' => 'boolean',
                    'array' => 'array',
                    'list' => 'list',
                    default => $type,
                };
            }
            $itemPattern = null;
            if ($type === 'list') {
                foreach ($spec['rules'] as [$rule, $argument]) {
                    if ($rule === 'matches') {
                        $itemPattern = $argument;
                    }
                }
            }
            $field = match ($type) {
                'integer' => Field::integer()->cast(),
                'number' => Field::number()->cast(),
                'boolean' => Field::boolean()->cast(),
                'array' => Field::anyArray(),
                'list' => Field::listOf($itemPattern === null ? Field::text() : Field::text()->matches($itemPattern)),
                default => Field::text(),
            };
            if ($spec['alias'] !== $name) {
                $field = $field->from('body', $spec['alias']);
            }
            foreach ($spec['rules'] as [$rule, $argument, $message]) {
                if ($rule === 'value') {
                    $fixed[$name] = $message;
                    continue;
                }
                $field = self::apply($field, $type, $rule, $argument, $message, $name);
                $code = self::failureCode($rule, $type);
                if ($code !== '' && is_string($message) && $message !== '') {
                    $field = $field->explain($code, $message);
                }
            }
            $fields[$name] = $field;
        }
        return ['fields' => $fields, 'fixed' => $fixed];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}|null 字段、输入别名、规则和参数。
     */
    private static function key(string $key): ?array
    {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:#([A-Za-z_][A-Za-z0-9_]*))?\.([A-Za-z]+)(?::(.*))?$/D', $key, $matches) === 1) {
            return [$matches[1], $matches[2], $matches[3], $matches[4] ?? ''];
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z]+)(?::([^#]*))?#([A-Za-z_][A-Za-z0-9_]*)$/D', $key, $matches) === 1) {
            return [$matches[1], $matches[4], $matches[2], $matches[3] ?? ''];
        }
        return null;
    }

    /**
     * 列表项按字段或字段#输入名直接读取，缺失时结果为 null。
     *
     * @param array<string, array{alias?: string, rules?: list<array{0: string, 1: string, 2: mixed}>, passthrough?: bool}> $grouped
     */
    private static function passthrough(array &$grouped, mixed $declaration): void
    {
        if (!is_string($declaration) || preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:#([A-Za-z_][A-Za-z0-9_]*))?$/D', $declaration, $matches) !== 1) {
            throw new \InvalidArgumentException('直接读取字段必须写成字段或字段#输入名');
        }
        $name = $matches[1];
        $alias = $matches[2] ?? '';
        $grouped[$name]['alias'] ??= $name;
        if ($alias !== '' && $grouped[$name]['alias'] !== $name && $grouped[$name]['alias'] !== $alias) {
            throw new \InvalidArgumentException('同一字段的输入别名不一致：' . $name);
        }
        if ($alias !== '') {
            $grouped[$name]['alias'] = $alias;
        }
        $grouped[$name]['passthrough'] = true;
        $grouped[$name]['rules'] ??= [];
    }

    /**
     * 不含点号的键写入固定值，请求内容不能覆盖。
     *
     * @param array<string, array{alias?: string, rules?: list<array{0: string, 1: string, 2: mixed}>}> $grouped
     */
    private static function fixedField(array &$grouped, string $name, mixed $value): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException('固定字段名无效');
        }
        $grouped[$name]['alias'] ??= $name;
        $grouped[$name]['rules'][] = ['value', '', $value];
    }

    private static function apply(Field $field, string $type, string $rule, string $argument, mixed $message, string $name): Field
    {
        return match ($rule) {
            'required' => $field->required(),
            'nullable' => $field->nullable(),
            'trim' => $field->trim(),
            'email' => $field->email(),
            'url' => $field->url(),
            'regex' => $field->matches($argument),
            'date' => $field->date($argument !== '' ? $argument : 'Y-m-d'),
            'confirmed' => $field->confirmed($argument !== '' ? $argument : $name . '_confirmation'),
            'integer', 'int', 'number', 'numeric', 'boolean', 'bool', 'string', 'text', 'array', 'list' => $field,
            'default' => $field->defaultValue($message),
            'length' => self::length($field, $argument),
            'between', 'range' => self::range($field, $type, $argument),
            'min' => in_array($type, ['string', 'list', 'array'], true) ? $field->length((int)$argument, 1000000) : $field->range((float)$argument, PHP_INT_MAX),
            'max' => in_array($type, ['string', 'list', 'array'], true) ? $field->length(0, (int)$argument) : $field->range(PHP_INT_MIN, (float)$argument),
            'in' => $field->oneOf(self::choices($type, $argument)),
            'matches' => $type === 'list' ? $field : $field->matches($argument),
            default => throw new \InvalidArgumentException('不支持的校验规则：' . $rule),
        };
    }

    private static function failureCode(string $rule, string $type): string
    {
        return match ($rule) {
            'required' => 'required',
            'integer', 'int' => 'type_integer',
            'number', 'numeric' => 'type_number',
            'boolean', 'bool' => 'type_boolean',
            'string', 'text' => 'type_string',
            'array' => 'type_array',
            'list' => 'type_list',
            'email' => 'email',
            'url' => 'url',
            'regex', 'matches' => 'format',
            'date' => 'date',
            'confirmed' => 'confirmed',
            'in' => 'enum',
            'length', 'min', 'max', 'between', 'range' => in_array($type, ['string', 'list', 'array'], true) ? 'length' : 'range',
            default => '',
        };
    }

    private static function length(Field $field, string $argument): Field
    {
        $parts = explode(',', $argument, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('length 规则需要最小值和最大值');
        }
        return $field->length((int)$parts[0], (int)$parts[1]);
    }

    private static function range(Field $field, string $type, string $argument): Field
    {
        $parts = explode(',', $argument, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('between 规则需要下界和上界');
        }
        if (in_array($type, ['string', 'list', 'array'], true)) {
            return $field->length((int)$parts[0], (int)$parts[1]);
        }
        return $field->range((float)$parts[0], (float)$parts[1]);
    }

    /**
     * @return list<int|string>
     */
    private static function choices(string $type, string $argument): array
    {
        $choices = explode(',', $argument);
        if ($type === 'integer') {
            return array_map(static fn (string $choice): int => (int)$choice, $choices);
        }
        return $choices;
    }
}
