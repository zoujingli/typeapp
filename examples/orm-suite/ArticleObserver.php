<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Model;
use Type\Orm\ModelObserver;

/** 记录文章模型事件，用可控标题观察取消创建与事件顺序。 */
final class ArticleObserver implements ModelObserver
{
    private array $events = [];
    /** 记录事件并拒绝指定标题的创建，其他操作继续执行。 */
    public function onEvent(string $event, Model $model): bool
    {
        $this->events[] = $event;
        return !($event === 'creating' && $model->get('title') === '取消发布');
    }
    /**
     * 返回当前阶段的事件顺序，供业务套件断言。
     *
     * @return list<string>
     */
    public function events(): array
    {
        return $this->events;
    }
    /** 仅重置观察日志，不修改任何模型或数据库状态。 */
    public function clear(): void
    {
        $this->events = [];
    }
}
