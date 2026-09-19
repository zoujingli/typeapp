<?php

declare(strict_types=1);

namespace Type\Mqtt;

/**
 * 可选的版本化配额来源；Broker 只消费当前快照，不读取人员表。
 * 降低额度不得删除已确认积压，也不得据此终止可靠会话。
 */
interface QuotaUpdates
{
    /**
     * 返回管理库当前最新配额快照；无版本时返回 null。
     *
     * @return array{version:int,limits:array<string,int>}|null version 从 1 起；limits 为已校验的有界整数。
     */
    public function nextQuota(): ?array;
}
