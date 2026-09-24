<?php

declare(strict_types=1);

namespace Type\Runtime;

use InvalidArgumentException;

/** 携带字段、稳定原因和建议 HTTP 状态的输入异常，不回显原始参数值。 */
final class QueryStringException extends InvalidArgumentException
{
    private string $field;
    private string $reason;
    private int $status;

    /** 保存公开错误定位信息；status 由 HTTP 边界决定是否用于响应。 */
    public function __construct(string $field, string $reason, int $status = 400)
    {
        parent::__construct('查询输入不明确：' . $reason);
        $this->field = $field;
        $this->reason = $reason;
        $this->status = $status;
    }
    /** 返回失败字段路径，不包含该字段的原始输入值。 */
    public function field(): string
    {
        return $this->field;
    }
    /** 返回供调用方分支处理的稳定错误原因。 */
    public function reason(): string
    {
        return $this->reason;
    }
    /** 返回输入边界建议使用的 HTTP 状态码。 */
    public function status(): int
    {
        return $this->status;
    }
}
