<?php

declare(strict_types=1);

namespace Type\Runtime;

use InvalidArgumentException;

final class QueryString
{
    public static function parse(string $query, int $maxFields = 100, int $maxBytes = 16384): array
    {
        if ($maxFields < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('查询输入限制无效');
        }
        if (strlen($query) > $maxBytes) {
            throw new QueryStringException('query', 'too_large', 413);
        }
        $pairs = $query === '' ? [] : explode('&', $query);
        if (count($pairs) > $maxFields) {
            throw new QueryStringException('query', 'too_many_fields');
        }
        $values = [];
        $kinds = [];
        foreach ($pairs as $pair) {
            if (preg_match('/%(?![0-9a-fA-F]{2})/', $pair)) {
                throw new QueryStringException('query', 'invalid_encoding');
            }
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            $list = str_ends_with($key, '[]');
            $key = $list ? substr($key, 0, -2) : $key;
            if ($key === '' || preg_match('/[\x00-\x1f\x7f\[\]]/', $key) || preg_match('//u', $key . $value) !== 1) {
                throw new QueryStringException('query', 'invalid_field');
            }
            if (isset($kinds[$key]) && (!$list || $kinds[$key] !== 'list')) {
                throw new QueryStringException('query.' . $key, 'duplicate_scalar');
            }
            $kinds[$key] = $list ? 'list' : 'scalar';
            if ($list) {
                $values[$key][] = $value;
            } else {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    public static function encode(array $values): string
    {
        $parts = [];
        foreach ($values as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $parts[] = rawurlencode((string) $key . (is_array($value) ? '[]' : '')) . '=' . rawurlencode((string) $item);
            }
        }
        return implode('&', $parts);
    }
}
