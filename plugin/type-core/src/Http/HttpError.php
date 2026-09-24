<?php

declare(strict_types=1);

namespace Type\Core\Http;

use RuntimeException;

/** HTTP 边界可转换为公开响应的状态与稳定错误码，不携带业务秘密。 */
final class HttpError extends RuntimeException
{
    private int $status;
    private string $errorCode;
    /** 保存响应状态和稳定错误码；调用者负责选择符合当前失败的状态。 */
    public function __construct(int $status, string $errorCode)
    {
        parent::__construct($errorCode);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
    /** 返回 HTTP 错误响应应使用的状态码。 */
    public function status(): int
    {
        return $this->status;
    }
    /** 返回机器可判定的业务边界错误码。 */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
