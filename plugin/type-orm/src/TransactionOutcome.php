<?php

declare(strict_types=1);

namespace Type\Orm;

final class TransactionOutcome
{
    public const NOT_STARTED = 'NOT_STARTED';
    public const ACTIVE = 'ACTIVE';
    public const COMMITTED = 'COMMITTED';
    public const ROLLED_BACK = 'ROLLED_BACK';
    public const UNKNOWN = 'UNKNOWN';
}
