<?php

declare(strict_types=1);

namespace Type\Validate;

use Type\Runtime\PublicFailureInterface;

/**
 * 用稳定字段路径、错误码及HTTP状态表达失败；框架不将原始输入写入消息。
 */
final class ValidationException extends \RuntimeException implements PublicFailureInterface
{
    private array $errors;

    private int $status;

    private string $errorCode;

    /** @var array<string, list<string>> */
    private array $messages;

    /**
     * 保存调用方提供的结构化错误；自定义调用方同样不能将秘密放入错误码。
     *
     * @param array<string, list<string>> $errors 完整字段路径到稳定错误码列表
     * @param array<string, list<string>> $messages 与错误码对齐的开发者说明，可为空字符串
     */
    public function __construct(array $errors, int $status = 422, string $errorCode = 'validation_failed', array $messages = [])
    {
        parent::__construct('输入未通过校验');
        $this->errors = $errors;
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->messages = $messages;
    }

    /**
     * @return array<string, list<string>> 各字段已收集的错误，不包含原始字段值
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 返回调用方指定的HTTP状态；解析400/413、字段校验默认422。
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * 返回顶层稳定错误码，字段明细使用errors()。
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, list<string>> 与 errors() 对齐的说明；没有编写说明时为空字符串
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * 公开响应体。fields 保持错误码，有说明时再附上 messages。
     *
     * @return array{error: string, fields: array<string, list<string>>, messages?: array<string, list<string>>}
     */
    public function body(): array
    {
        $body = ['error' => $this->errorCode, 'fields' => $this->errors];
        $visible = [];
        foreach ($this->messages as $path => $texts) {
            $kept = array_values(array_filter($texts, static fn (string $text): bool => $text !== ''));
            if ($kept !== []) {
                $visible[$path] = $kept;
            }
        }
        if ($visible !== []) {
            $body['messages'] = $visible;
        }
        return $body;
    }
}
