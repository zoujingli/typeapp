<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use RuntimeException;
use Type\Orm\Model;
use Type\Orm\ModelObserver;

/** 记录文档生命周期事件，并以受控标题触发取消或失败路径。 */
final class DocumentObserver implements ModelObserver
{
    private array $events = [];

    /** 记录调用顺序；CANCEL 拒绝创建，FAIL 在更新事件中抛错以验证事务。 */
    public function onEvent(string $event, Model $model): bool
    {
        $this->events[] = $event;
        if ($event === 'creating' && $model->get('title') === 'CANCEL') {
            return false;
        }
        if ($event === 'updated' && $model->get('title') === 'FAIL') {
            throw new RuntimeException('后置事件失败');
        }
        return true;
    }

    /**
     * 返回本实例按执行顺序记录的事件。
     *
     * @return list<string>
     */
    public function events(): array
    {
        return $this->events;
    }
    /** 清空观察记录，隔开同一演练中的各个操作阶段。 */
    public function clear(): void
    {
        $this->events = [];
    }
}
