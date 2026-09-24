<?php

declare(strict_types=1);

namespace Type\Orm;

use PDO;

/** 数据库端点协议；建立会话与证明会话可复用由具体驱动负责。 */
interface Driver
{
    /** 返回 SQL 方言使用的驱动名：mysql、pgsql 或 sqlite。 */
    public function name(): string;
    /**
     * 声明不可混用的连接身份，凭据轮换需要递增代次。
     *
     * @return array<string, mixed> 包含驱动、端点、逻辑库、角色与凭据代次，不包含密码。
     */
    public function identity(): array;

    /**
     * 建立并初始化真实 PDO 会话；受管调用由 PdoSession 负责释放。
     *
     * @throws DatabaseException 缺少扩展、连接失败或会话基线未满足。
     */
    public function connect(): PDO;

    /**
     * @internal 归还租约时恢复完整会话基线；游标与事务已由 PdoSession 关闭。
     * 返回 true 才允许保留同一物理连接；无法证明干净时返回 false 或抛出异常。
     */
    public function reset(PDO $pdo): bool;
}
