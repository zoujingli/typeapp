<?php

declare(strict_types=1);

namespace Type\Redis;

use RuntimeException;
use Throwable;

/** Redis 失败事实，区分明确拒绝与可能已经产生效果的情况。 */
final class RedisException extends RuntimeException
{
    private string $errorCode;
    private string $outcome;

    /** 保留稳定错误码及操作结果，不以异常本身推断写入尚未发生。 */
    public function __construct(string $errorCode, string $outcome, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->outcome = $outcome;
    }

    /** 返回可供应用分支处理的稳定错误码，不依赖异常文本。 */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
    /** 返回 NOT_STARTED、REJECTED、MAY_HAVE_APPLIED 或 UNKNOWN；未知结果须先对账。 */
    public function outcome(): string
    {
        return $this->outcome;
    }
}
