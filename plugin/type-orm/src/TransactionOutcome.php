<?php

declare(strict_types=1);

namespace Type\Orm;

/** 事务结果词汇；UNKNOWN 保留不确定性，不允许自动重试业务写入。 */
final class TransactionOutcome
{
    public const NOT_STARTED = 'NOT_STARTED';
    public const ACTIVE = 'ACTIVE';
    public const COMMITTED = 'COMMITTED';
    public const ROLLED_BACK = 'ROLLED_BACK';
    public const UNKNOWN = 'UNKNOWN';
}
