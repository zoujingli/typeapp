<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 运行产物与外部数据协议不兼容，调用方必须停止对应操作。 */
final class CompatibilityException extends \RuntimeException
{
    private string $reason;
    /** 保存机器可判定的兼容性原因和可读说明。 */
    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
    /** 返回 schema_incompatible 或 message_incompatible 等稳定原因。 */
    public function reason(): string
    {
        return $this->reason;
    }
}
