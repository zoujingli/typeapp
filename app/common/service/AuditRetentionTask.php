<?php

declare(strict_types=1);

namespace app\common\service;

use Type\Orm\Db;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

/** 调度既有的审计保留策略；每个计划只处理一批，保留期与手工清理命令一致。 */
final class AuditRetentionTask implements Task
{
    private string $realm;

    /** 注册时固定管理端或客户端，不接受外部表名。 */
    public function __construct(string $realm)
    {
        if (!in_array($realm, ['admin', 'customer'], true)) {
            throw new \InvalidArgumentException('审计调度身份域无效');
        }
        $this->realm = $realm;
    }

    /**
     * 删除满 180 天的至多 100 条审计；中断可重跑，不扩大删除范围。
     * 数据库事务属于本次任务作用域，Redis 游标不宣称与数据库跨资源原子提交。
     * @return array{deleted:int,has_more:bool,cutoff:int}
     */
    public function run(TaskContext $context): array
    {
        $context->assertActive();
        return AuditLog::prune(Db::connection('default', true), 100, $this->realm);
    }
}
