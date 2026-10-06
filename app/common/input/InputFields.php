<?php

declare(strict_types=1);

namespace app\common\input;

use Type\Core\Http\HttpError;
use Type\Validate\Input;

/** 应用输入白名单：未知字段明确拒绝，框架默认过滤语义不改变。 */
final class InputFields
{
    /**
     * 在 Field 条件阶段检查整个来源，缺失字段也不能绕过白名单。
     * @param list<string> $allowed 此来源允许出现的字段名。
     * @throws HttpError 输入含未知字段时返回指定的稳定错误码。
     */
    public static function only(Input $input, string $source, array $allowed, string $code): bool
    {
        if (array_diff(array_keys($input->source($source)), $allowed) !== []) {
            throw new HttpError(422, $code);
        }
        return true;
    }
}
