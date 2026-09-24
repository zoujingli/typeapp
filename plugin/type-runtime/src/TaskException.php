<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 执行上下文、预算和任务生命周期失败的稳定错误载体。 */
final class TaskException extends \RuntimeException
{
    private string $errorCode;
    /** 分别保存供程序处理的错误码与供诊断阅读的说明。 */
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
    /** 返回稳定错误码；调用方无需解析异常文本判断失败类别。 */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
