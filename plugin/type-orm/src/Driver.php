<?php

declare(strict_types=1);

namespace Type\Orm;

use PDO;

interface Driver
{
    public function name(): string;
    public function identity(): array;

    public function connect(): PDO;

    /**
     * @internal 归还租约时恢复完整会话基线；游标与事务已由 PdoSession 关闭。
     * 返回 true 才允许保留同一物理连接；无法证明干净时返回 false 或抛出异常。
     */
    public function reset(PDO $pdo): bool;
}
