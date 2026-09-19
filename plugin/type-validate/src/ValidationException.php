<?php

declare(strict_types=1);

namespace Type\Validate;

use RuntimeException;

/** 用稳定字段路径、错误码及HTTP状态表达失败；框架不将原始输入写入消息。 */
final class ValidationException extends RuntimeException
{
    private array $errors;
    private int $status;
    private string $errorCode;

    /**
     * 保存调用方提供的结构化错误；自定义调用方同样不能将秘密放入错误码。
     *
     * @param array<string, list<string>> $errors 完整字段路径到稳定错误码列表。
     */
    public function __construct(array $errors, int $status = 422, string $errorCode = 'validation_failed')
    {
        parent::__construct('输入未通过校验');
        $this->errors = $errors;
        $this->status = $status;
        $this->errorCode = $errorCode;
    }

    /** @return array<string, list<string>> 各字段已收集的错误，不包含原始字段值。 */
    public function errors(): array
    {
        return $this->errors;
    }

    /** 返回调用方指定的HTTP状态；解析400/413、字段校验默认422。 */
    public function status(): int
    {
        return $this->status;
    }

    /** 返回顶层稳定错误码，字段明细使用errors()。 */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
