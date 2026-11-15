<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 框架异常携带可机器判定的稳定错误码；消息仅供诊断，不作为协议。 */
interface FailureInterface
{
    /** 返回稳定错误码；调用者不得解析异常消息。 */
    public function errorCode(): string;
}
