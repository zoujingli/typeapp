<?php

declare(strict_types=1);

namespace Type\Runtime;

use InvalidArgumentException;

/** 有界查询参数编解码，区分单值与 [] 列表，拒绝会产生歧义的输入。 */
final class QueryString
{
    /**
     * 解码 UTF-8 查询参数，不接受重复标量或任意嵌套数组。
     * @param int $maxFields 原始键值对数上限，列表的每一项单独计数。
     * @param int $maxBytes 编码前查询文本的字节上限。
     * @return array<string, string|list<string>>
     * @throws QueryStringException 超量、编码错误或字段歧义。
     */
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

    /**
     * 用百分号编码生成查询文本，列表键统一带 [] 后缀。
     * @param array<string, string|int|float|bool|list<string|int|float|bool>> $values 扁平标量或标量列表。
     */
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
