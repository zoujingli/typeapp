<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use RuntimeException;
use Type\Orm\Model;
use Type\Orm\ModelObserver;

final class DocumentObserver implements ModelObserver
{
    private array $events = [];

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

    public function events(): array
    {
        return $this->events;
    }
    public function clear(): void
    {
        $this->events = [];
    }
}
