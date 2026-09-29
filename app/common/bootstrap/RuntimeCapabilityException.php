<?php

declare(strict_types=1);

namespace app\common\bootstrap;

/** profile 拒绝与普通业务输入错误分开，CLI 保留说明，HTTP 只返回稳定错误码。 */
final class RuntimeCapabilityException extends \InvalidArgumentException
{
    private string $errorCode;

    /** 仅由能力边界创建；细节不得包含连接串或凭据。 */
    public function __construct(string $errorCode, string $detail)
    {
        $this->errorCode = $errorCode;
        parent::__construct($errorCode . ': ' . $detail);
    }

    /** 返回与界面语言无关的公开失败标识。 */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
