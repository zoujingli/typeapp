<?php

declare(strict_types=1);

namespace app\common\input;

use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\HttpError;
use Type\Core\Http\RequestBody;
use Type\Validate\Input;

/** 应用输入白名单：未知字段明确拒绝，框架默认过滤语义不改变。 */
final class InputFields
{
    /** 有界 JSON 请求共用字段白名单；声明之外的归属和权限输入一律拒绝。 */
    public static function body(ServerRequestInterface $request, array $fields, string $unknownCode = 'unexpected_field'): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 32768), 32768, 12);
        self::only($input, 'body', array_keys($fields), $unknownCode);
        return \_vali($fields, $input);
    }

    /**
     * 在 Field 条件阶段检查整个来源，缺失字段也不能绕过白名单。
     * @param list<string> $allowed 此来源允许出现的字段名
     * @throws HttpError 输入含未知字段时返回指定的稳定错误码
     */
    public static function only(Input $input, string $source, array $allowed, string $code): bool
    {
        if (array_diff(array_keys($input->source($source)), $allowed) !== []) {
            throw new HttpError(422, $code);
        }
        return true;
    }
}
