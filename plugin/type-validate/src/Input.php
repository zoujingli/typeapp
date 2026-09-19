<?php

declare(strict_types=1);

namespace Type\Validate;

use InvalidArgumentException;
use JsonException;
use stdClass;
use Type\Runtime\QueryString;
use Type\Runtime\QueryStringException;

/** 解析时即限制大小、深度及重复键；各来源保持独立。 */
final class Input
{
    private array $sources;

    /**
     * 接收已解析的分源映射；直接构造不重复执行JSON/查询文本的大小或重复键检查。
     *
     * @param array<string, array<string, mixed>> $sources body/query/route/header，各来源不自动合并。
     * @throws InvalidArgumentException 来源未知或内容不是数组。
     */
    public function __construct(array $sources)
    {
        foreach ($sources as $source => $values) {
            if (!in_array($source, ['body', 'query', 'route', 'header'], true) || !is_array($values)) {
                throw new InvalidArgumentException('输入来源必须是 body、query、route 或 header 的字段映射');
            }
        }
        $this->sources = $sources;
    }

    /**
     * 有界解析JSON对象，保留大整数文本，拒绝任意层级的重复对象键。
     *
     * @throws InvalidArgumentException 字节上限非正或解析深度不在2至128之间。
     * @throws ValidationException 超过字节上限返回413，非法JSON/深度/根类型/重复键返回400。
     */
    public static function json(string $body, int $maxBytes = 1048576, int $maxDepth = 16): Input
    {
        if ($maxBytes < 1 || $maxDepth < 2 || $maxDepth > 128) {
            throw new InvalidArgumentException('JSON 输入限制无效');
        }
        if (strlen($body) > $maxBytes) {
            throw new ValidationException(['body' => ['too_large']], 413, 'payload_too_large');
        }
        try {
            $decoded = json_decode($body, false, $maxDepth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            throw new ValidationException(['body' => [$error->getCode() === JSON_ERROR_DEPTH ? 'too_deep' : 'invalid_json']], 400, 'invalid_json');
        }
        if (!$decoded instanceof stdClass) {
            throw new ValidationException(['body' => ['object_required']], 400, 'invalid_json');
        }
        self::rejectDuplicateKeys($body);
        return new Input(['body' => get_object_vars($decoded)]);
    }

    /**
     * 返回替换整个指定来源的新Input，其他来源保持不变。
     *
     * @param array<string, mixed> $values 已解析字段映射，不追加合并旧来源。
     * @throws InvalidArgumentException 来源不是body/query/route/header。
     */
    public function with(string $source, array $values): Input
    {
        $sources = $this->sources;
        $sources[$source] = $values;
        return new Input($sources);
    }

    /**
     * 解析原始查询文本并替换query来源，拒绝重复标量和有歧义的编码。
     *
     * @throws ValidationException 查询解析失败；保留解析器的字段、错误码及400/413状态。
     * @throws InvalidArgumentException 字段或字节上限非法。
     */
    public function withQuery(string $query, int $maxFields = 100, int $maxBytes = 16384): Input
    {
        try {
            return $this->with('query', QueryString::parse($query, $maxFields, $maxBytes));
        } catch (QueryStringException $error) {
            throw new ValidationException([$error->field() => [$error->reason()]], $error->status(), $error->status() === 413 ? 'payload_too_large' : 'invalid_query');
        }
    }

    /** @return array<string, mixed> 对应来源的原始字段；不存在的来源返回空数组。 */
    public function source(string $source): array
    {
        return $this->sources[$source] ?? [];
    }

    private static function rejectDuplicateKeys(string $json): void
    {
        // JSON 已通过原生解析，词法遍历仅补充重复对象键检查。
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]/s', $json, $matches, PREG_OFFSET_CAPTURE);
        $stack = [];
        foreach ($matches[0] as $tokenIndex => $token) {
            $value = $token[0];
            if ($value === '{' || $value === '[') {
                $stack[] = ['object' => $value === '{', 'keys' => []];
            } elseif ($value === '}' || $value === ']') {
                array_pop($stack);
            } elseif (str_starts_with($value, '"') && $stack !== [] && $stack[count($stack) - 1]['object']) {
                if (($matches[0][$tokenIndex + 1][0] ?? '') !== ':') {
                    continue;
                }
                $key = json_decode($value, true, 2, JSON_THROW_ON_ERROR);
                $index = count($stack) - 1;
                if (array_key_exists($key, $stack[$index]['keys'])) {
                    throw new ValidationException(['body' => ['duplicate_key']], 400, 'invalid_json');
                }
                $stack[$index]['keys'][$key] = true;
            }
        }
    }
}
