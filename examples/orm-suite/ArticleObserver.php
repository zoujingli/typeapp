<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Model;
use Type\Orm\ModelObserver;

final class ArticleObserver implements ModelObserver
{
    private array $events = [];
    public function onEvent(string $event, Model $model): bool
    {
        $this->events[] = $event;
        return !($event === 'creating' && $model->get('title') === '取消发布');
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
