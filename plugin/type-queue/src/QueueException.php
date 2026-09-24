<?php

declare(strict_types=1);

namespace Type\Queue;

/** 以稳定错误码表达队列协议、租约与业务转移失败。 */
final class QueueException extends \RuntimeException
{
    private string $errorCode;
    /** 将稳定错误码保留为机器可读字段，显示消息仅用于诊断。 */
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
    /** 返回不依赖中文提示的错误码，供调用方分类处理。 */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
