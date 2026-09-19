<?php

declare(strict_types=1);

namespace app\iot\service;

use stdClass;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\ValidationException;

/** 物模型定义及业务值共享严格标量规则；不转换类型，不读取可变化的最新模型。 */
final class ModelDefinition
{
    /** 按业务结构比较；忽略显示名称、声明顺序和等价默认值，类型、单位、必填、范围及枚举均参与。 */
    public static function structurallyEqual(array $left, array $right): bool
    {
        return hash_equals(self::structuralHash($left), self::structuralHash($right));
    }

    /** 固件明确支持的契约摘要；产品名和版本号不是结构身份，输入仍执行完整定义校验。 */
    public static function structuralHash(array $definition): string
    {
        $normalized = self::normalize($definition);
        $structure = [];
        foreach (['properties', 'events', 'commands'] as $group) {
            $entries = [];
            foreach ($normalized[$group] as $entry) {
                if ($group === 'properties') {
                    $entries[$entry['identifier']] = self::scalarStructure($entry);
                } else {
                    $parameters = [];
                    foreach ($entry['parameters'] as $parameter) {
                        $parameters[$parameter['identifier']] = self::scalarStructure($parameter);
                    }
                    ksort($parameters, SORT_STRING);
                    $entries[$entry['identifier']] = $parameters;
                }
            }
            ksort($entries, SORT_STRING);
            $structure[$group] = $entries;
        }
        return IngestionService::contentHash(json_encode((object) $structure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function scalarStructure(array $field): array
    {
        unset($field['name'], $field['identifier']);
        if (in_array($field['type'], ['integer', 'number'], true)) {
            $field += ['unit' => ''];
        } elseif ($field['type'] === 'string') {
            $field += ['min_length' => 0, 'max_length' => 1024];
        } elseif ($field['type'] === 'enum') {
            sort($field['values'], SORT_STRING);
        }
        return $field;
    }

    /**
     * 将有界定义规范化为可持久化数组；未知键和非法约束明确失败。
     * @return array{properties: list<array<string, mixed>>, events: list<array<string, mixed>>, commands: list<array<string, mixed>>}
     * @throws ValidationException 定义格式、大小、标识或约束非法。
     */
    public static function normalize(mixed $definition): array
    {
        $root = self::object($definition, 'definition');
        self::keys($root, ['properties', 'events', 'commands'], 'definition');
        if (strlen(json_encode($root, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 16384) {
            self::invalid('definition', 'too_large');
        }
        $result = [];
        foreach (['properties', 'events', 'commands'] as $group) {
            $path = 'definition.' . $group;
            $entries = $root[$group] ?? null;
            if (!is_array($entries) || !array_is_list($entries) || count($entries) > ($group === 'properties' ? 64 : 32)) {
                self::invalid($path, 'bounded_list_required');
            }
            $items = [];
            $seen = [];
            foreach ($entries as $index => $entry) {
                $itemPath = $path . '.' . $index;
                $item = $group === 'properties' ? self::scalar($entry, $itemPath) : self::operation($entry, $itemPath);
                if (isset($seen[$item['identifier']])) {
                    self::invalid($itemPath . '.identifier', 'duplicate_identifier');
                }
                $seen[$item['identifier']] = true;
                $items[] = $item;
            }
            $result[$group] = $items;
        }
        return $result;
    }

    /**
     * 仅按传入的已发布定义校验属性、事件或指令；返回原值，不触发设备动作。
     * @param array<string, mixed> $definition normalize后、由版本持久事实读取的定义。
     * @return array<string, mixed> 校验后的明确字段。
     * @throws ValidationException 类型、必填、未知字段、操作标识或数值范围无效。
     */
    public static function validateValues(array $definition, string $kind, string $identifier, mixed $values): array
    {
        $fields = [];
        if ($kind === 'properties' && $identifier === '') {
            $fields = $definition['properties'];
        } elseif (in_array($kind, ['event', 'command'], true)) {
            $found = false;
            foreach ($definition[$kind === 'event' ? 'events' : 'commands'] as $operation) {
                if ($operation['identifier'] === $identifier) {
                    $fields = $operation['parameters'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                self::invalid('identifier', 'unknown_identifier');
            }
        } else {
            self::invalid('kind', 'invalid_kind');
        }
        $data = self::object($values, 'values');
        if ($kind === 'properties' && $data === []) {
            self::invalid('values', 'empty_properties');
        }
        self::keys($data, array_column($fields, 'identifier'), 'values');
        foreach ($fields as $field) {
            $path = 'values.' . $field['identifier'];
            if (!array_key_exists($field['identifier'], $data)) {
                if ($field['required']) {
                    self::invalid($path, 'required');
                }
                continue;
            }
            $value = $data[$field['identifier']];
            $type = $field['type'];
            $rule = new Field($type === 'enum' ? 'string' : $type);
            $checked = $rule->validate($value, true, new Input([]), 'default', false, $path);
            if ($checked['errors'] !== []) {
                throw new ValidationException($checked['errors']);
            }
            if (in_array($type, ['integer', 'number'], true)) {
                if (($type === 'integer' && abs($value) > 9007199254740991)
                    || (isset($field['min']) && $value < $field['min']) || (isset($field['max']) && $value > $field['max'])) {
                    self::invalid($path, 'out_of_range');
                }
            } elseif ($type === 'string') {
                if (strlen($value) < ($field['min_length'] ?? 0) || strlen($value) > ($field['max_length'] ?? 1024)) {
                    self::invalid($path, 'length');
                }
            } elseif ($type === 'enum' && !in_array($value, $field['values'], true)) {
                self::invalid($path, 'enum_value');
            }
        }
        return $data;
    }

    private static function scalar(mixed $entry, string $path): array
    {
        $data = self::object($entry, $path);
        self::identity($data, $path);
        $type = $data['type'] ?? '';
        if (!is_string($type) || !in_array($type, ['integer', 'number', 'boolean', 'string', 'enum'], true)) {
            self::invalid($path . '.type', 'invalid_scalar_type');
        }
        if (!isset($data['required']) || !is_bool($data['required'])) {
            self::invalid($path . '.required', 'type_boolean');
        }
        $extra = match ($type) {
            'integer', 'number' => ['unit', 'min', 'max'],
            'string' => ['min_length', 'max_length'],
            'enum' => ['values'],
            default => [],
        };
        self::keys($data, array_merge(['identifier', 'name', 'type', 'required'], $extra), $path);
        if (in_array($type, ['integer', 'number'], true)) {
            foreach (['min', 'max'] as $bound) {
                if (array_key_exists($bound, $data)) {
                    $limit = $data[$bound];
                    if ((!is_int($limit) && !is_float($limit)) || !is_finite((float) $limit)
                        || ($type === 'integer' && (!is_int($limit) || abs($limit) > 9007199254740991))) {
                        self::invalid($path . '.' . $bound, 'invalid_numeric_bound');
                    }
                }
            }
            if (isset($data['min'], $data['max']) && $data['min'] > $data['max']) {
                self::invalid($path, 'invalid_range');
            }
            if (array_key_exists('unit', $data)) {
                self::text($data['unit'], 0, 32, $path . '.unit');
            }
        } elseif ($type === 'string') {
            foreach (['min_length', 'max_length'] as $length) {
                if (array_key_exists($length, $data) && (!is_int($data[$length]) || $data[$length] < 0 || $data[$length] > 4096)) {
                    self::invalid($path . '.' . $length, 'invalid_length');
                }
            }
            if (($data['min_length'] ?? 0) > ($data['max_length'] ?? 1024)) {
                self::invalid($path, 'invalid_range');
            }
        } elseif ($type === 'enum') {
            $options = $data['values'] ?? null;
            if (!is_array($options) || !array_is_list($options) || count($options) < 1 || count($options) > 100) {
                self::invalid($path . '.values', 'invalid_enum');
            }
            $seen = [];
            foreach ($options as $option) {
                self::text($option, 1, 100, $path . '.values');
                if (in_array($option, $seen, true)) {
                    self::invalid($path . '.values', 'duplicate_enum_value');
                }
                $seen[] = $option;
            }
        }
        return $data;
    }

    private static function operation(mixed $entry, string $path): array
    {
        $data = self::object($entry, $path);
        self::identity($data, $path);
        self::keys($data, ['identifier', 'name', 'parameters'], $path);
        $parameters = $data['parameters'] ?? null;
        if (!is_array($parameters) || !array_is_list($parameters) || count($parameters) > 32) {
            self::invalid($path . '.parameters', 'bounded_list_required');
        }
        $fields = [];
        $seen = [];
        foreach ($parameters as $index => $parameter) {
            $fieldPath = $path . '.parameters.' . $index;
            $field = self::scalar($parameter, $fieldPath);
            if (isset($seen[$field['identifier']])) {
                self::invalid($fieldPath . '.identifier', 'duplicate_identifier');
            }
            $seen[$field['identifier']] = true;
            $fields[] = $field;
        }
        return ['identifier' => $data['identifier'], 'name' => $data['name'], 'parameters' => $fields];
    }

    private static function identity(array $data, string $path): void
    {
        if (!isset($data['identifier']) || !is_string($data['identifier']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $data['identifier'])) {
            self::invalid($path . '.identifier', 'invalid_identifier');
        }
        self::text($data['name'] ?? null, 1, 100, $path . '.name');
    }

    private static function object(mixed $value, string $path): array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            return $value;
        }
        self::invalid($path, 'object_required');
        return [];
    }

    private static function keys(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                self::invalid($path, 'unknown_field');
            }
        }
    }

    private static function text(mixed $value, int $minimum, int $maximum, string $path): void
    {
        if (!is_string($value) || strlen($value) < $minimum || strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/u', $value) !== 0) {
            self::invalid($path, 'invalid_text');
        }
    }

    private static function invalid(string $path, string $reason): void
    {
        throw new ValidationException([$path => [$reason]]);
    }
}
