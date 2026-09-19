<?php

declare(strict_types=1);

namespace Type\Orm;

use Throwable;

/** @internal 游标只是查询位置，不能作为授权；值始终重新绑定到 SQL。 */
final class CursorToken
{
    public static function encode(string $shape, array $values): string
    {
        try {
            $token = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'query' => $shape, 'values' => $values], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), '+/', '-_'), '=');
        } catch (Throwable $error) {
            throw new DatabaseException('游标排序值不能编码，文本需要有效 UTF-8', 0, $error);
        }
        if (strlen($token) > 8192) {
            throw new DatabaseException('游标排序值超过长度上限');
        }
        return $token;
    }

    public static function decode(string $token, string $shape, int $count): array
    {
        if (strlen($token) > 8192 || !preg_match('/^[A-Za-z0-9_-]+$/D', $token)) {
            throw new DatabaseException('游标格式无效');
        }
        try {
            $json = base64_decode(strtr($token, '-_', '+/'), true);
            $value = json_decode($json === false ? '' : $json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new DatabaseException('游标格式无效', 0, $error);
        }
        if (!is_array($value) || ($value['v'] ?? null) !== 1 || ($value['query'] ?? null) !== $shape
            || !is_array($value['values'] ?? null) || !array_is_list($value['values']) || count($value['values']) !== $count) {
            throw new DatabaseException('游标不属于该查询、排序或数据库身份');
        }
        foreach ($value['values'] as $item) {
            if ((!is_int($item) && !is_string($item) && !is_bool($item) && !is_float($item)) || (is_float($item) && !is_finite($item))) {
                throw new DatabaseException('游标排序值无效');
            }
        }
        return $value['values'];
    }
}
