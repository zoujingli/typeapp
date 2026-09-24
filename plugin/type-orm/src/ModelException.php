<?php

declare(strict_types=1);

namespace Type\Orm;

/** 模型映射、状态、归属或乐观并发约束失败。 */
final class ModelException extends DatabaseException
{
    private string $errorCode;

    /** 保留模型契约错误码与可选底层原因，调用者应按错误码处理。 */
    public function __construct(string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    /** 返回稳定模型错误码，如 field_not_loaded、optimistic_conflict。 */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
