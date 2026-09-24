<?php

declare(strict_types=1);

namespace Type\Redis;

/** Redis 会话用途；普通命令、阻塞、批次、事务与脚本分别占用独立容量。 */
final class Purpose
{
    public const COMMAND = 'command';
    public const BLOCKING = 'blocking';
    public const PIPELINE = 'pipeline';
    public const TRANSACTION = 'transaction';
    public const SCRIPT = 'script';

    /**
     * 列出管理器允许声明容量的用途，名称也用于隔离连接池。
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::COMMAND, self::BLOCKING, self::PIPELINE, self::TRANSACTION, self::SCRIPT];
    }
}
