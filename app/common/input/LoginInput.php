<?php

declare(strict_types=1);

namespace app\common\input;

use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidatedInput;

/** 双端登录只接受账号和口令；账号域继续来自固定路由，不能从正文选择。 */
final class LoginInput implements ValidatedInput
{
    /** 口令只属于本次请求，不进入日志、配置或跨任务上下文。 */
    public function __construct(public string $login, public string $password)
    {
    }

    /** 复用原账号语法、长度与字段白名单。 */
    public static function schema(): Schema
    {
        return new Schema([
            'login' => Field::text()->required()->length(3, 100)->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D')
                ->when(static fn (Input $input, string $scenario): bool => InputFields::only($input, 'body', ['login', 'password'], 'identity_input_invalid')),
            'password' => Field::text()->required()->length(1, 72),
        ]);
    }

    /** 由生成动作在完成分源校验后构造，业务不再重读正文。 */
    public static function fromData(Data $data): LoginInput
    {
        return new LoginInput($data->get('login'), $data->get('password'));
    }
}
