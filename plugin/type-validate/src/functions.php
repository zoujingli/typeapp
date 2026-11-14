<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Type\Runtime\ExecutionScope;
use Type\Validate\Input;
use Type\Validate\RuleParser;
use Type\Validate\Helper\ValidateHelper;
use Type\Validate\ValidationException;

/**
 * 读取当前请求或显式数组，并按字段规则返回通过校验的值。
 *
 * 第二个参数省略或为 all、query、body 时，从当前执行作用域读取本次请求。
 * 传入数组时只校验这份数组。未声明字段不进入结果。
 *
 * @param array<string, mixed> $rules 字段.规则到说明、默认值或固定值
 * @return array<string, mixed>
 * @throws InvalidArgumentException 规则或输入来源无效
 * @throws ValidationException 输入不满足规则
 */
function _vali(array $rules, array|string|\Type\Validate\Input $input = ''): array
{
    $sample = reset($rules);
    if ($sample instanceof \Type\Validate\Field) {
        if (is_string($input)) {
            throw new InvalidArgumentException('字段规则字符串请改用字段.规则；字段对象需要显式数组或 Input');
        }
        return ValidateHelper::data($rules, $input)->toArray();
    }
    if (is_string($input)) {
        $kind = $input === '' ? 'all' : $input;
        if (!in_array($kind, ['all', 'query', 'body'], true)) {
            throw new InvalidArgumentException('输入来源只接受 all、query、body 或数组');
        }
        $data = inputRequest($kind);
    } else {
        $data = $input;
    }
    $parsedRules = RuleParser::parse($rules);
    foreach ($parsedRules['fixed'] as $name => $value) {
        $data[$name] = $value;
    }
    return ValidateHelper::data($parsedRules['fields'], $data)->toArray();
}

/**
 * 乐观锁版本的必填、整数和闭区间。新建记录的下界传 0。
 *
 * 上界默认 2147483646，使加一后仍落在 32 位有符号整数内。
 *
 * @return array<string, string>
 */
function _version(string $field = 'version', int $minimum = 1, string $label = '版本', int $maximum = 2147483646): array
{
    if ($minimum < 0 || $maximum > 2147483646 || $minimum > $maximum || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) !== 1 || $label === '') {
        throw new InvalidArgumentException('版本规则声明无效');
    }
    return [
        $field . '.required' => $label . '不能为空',
        $field . '.integer' => $label . '不正确',
        $field . '.between:' . $minimum . ',' . $maximum => $label . '超出范围',
    ];
}

/**
 * 读取当前请求的查询或 JSON 正文。
 *
 * JSON 保留在原始流中，不依赖尚未填充的 parsedBody。
 * 未设置媒体类型时才使用调用方预先放入的 parsedBody。
 *
 * @return array<string, mixed>
 */
function _input(string $source = 'body'): array
{
    if (!in_array($source, ['all', 'query', 'body'], true)) {
        throw new InvalidArgumentException('输入来源只接受 all、query 或 body');
    }
    return inputRequest($source);
}

/**
 * @return array<string, mixed>
 */
function inputRequest(string $kind): array
{
    $request = ExecutionScope::current()->held('http.request');
    if (!$request instanceof ServerRequestInterface) {
        throw new InvalidArgumentException('当前作用域没有请求，请传入输入数组');
    }
    $query = $request->getQueryParams();
    $body = [];
    if ($kind !== 'query') {
        $media = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        $raw = inputRead($request->getBody(), 32768);
        if ($media === 'application/json') {
            if ($raw === '') {
                throw new ValidationException(['body' => ['invalid_json']], 400, 'invalid_json');
            }
            $body = inputValues(Input::json($raw, 32768, 12)->source('body'));
        } elseif ($raw !== '' || $media !== '') {
            throw new ValidationException(['body' => ['json_required']], 415, 'json_required');
        } else {
            $parsed = $request->getParsedBody();
            $body = is_array($parsed) ? inputValues($parsed) : [];
        }
    }
    return match ($kind) {
        'query' => $query,
        'body' => $body,
        default => $body + $query,
    };
}

function inputRead(StreamInterface $stream, int $maxBytes): string
{
    if (!$stream->isSeekable()) {
        throw new ValidationException(['body' => ['body_not_replayable']], 400, 'invalid_json');
    }
    $position = $stream->tell();
    try {
        $stream->rewind();
        $body = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(min(8192, $maxBytes + 1 - strlen($body)));
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                throw new ValidationException(['body' => ['too_large']], 413, 'payload_too_large');
            }
            if ($chunk === '' && !$stream->eof()) {
                throw new ValidationException(['body' => ['body_read_stalled']], 400, 'invalid_json');
            }
        }
        return $body;
    } finally {
        $stream->seek($position);
    }
}

/**
 * @return array<string, mixed>
 */
function inputValues(mixed $value): array
{
    if ($value instanceof \stdClass) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        throw new ValidationException(['body' => ['object_required']], 400, 'invalid_json');
    }
    $copy = [];
    foreach ($value as $key => $item) {
        $copy[$key] = inputValue($item);
    }
    return $copy;
}

function inputValue(mixed $value): mixed
{
    if ($value instanceof \stdClass) {
        return inputValues($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    $copy = [];
    foreach ($value as $key => $item) {
        $copy[$key] = inputValue($item);
    }
    return $copy;
}
