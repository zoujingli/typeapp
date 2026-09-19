<?php

declare(strict_types=1);

namespace Type\Redis;

final class Purpose
{
    public const COMMAND = 'command';
    public const BLOCKING = 'blocking';
    public const PIPELINE = 'pipeline';
    public const TRANSACTION = 'transaction';
    public const SCRIPT = 'script';

    public static function all(): array
    {
        return [self::COMMAND, self::BLOCKING, self::PIPELINE, self::TRANSACTION, self::SCRIPT];
    }
}
