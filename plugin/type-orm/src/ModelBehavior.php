<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use InvalidArgumentException;

/** 不可变行为声明；每个观察器的作用域由应用显式注入。 */
final class ModelBehavior
{
    private array $observers = [];
    private array $getters = [];
    private array $setters = [];

    public function observe(ModelObserver $observer): ModelBehavior
    {
        $copy = clone $this;
        $copy->observers[] = $observer;
        return $copy;
    }

    /** @param Closure(mixed): mixed $transform 接收字段原值并返回读取值。 */
    public function getter(string $field, Closure $transform): ModelBehavior
    {
        $this->field($field);
        $copy = clone $this;
        $copy->getters[$field] = $transform;
        return $copy;
    }

    /** @param Closure(mixed): mixed $transform 接收输入值并返回待存值。 */
    public function setter(string $field, Closure $transform): ModelBehavior
    {
        $this->field($field);
        $copy = clone $this;
        $copy->setters[$field] = $transform;
        return $copy;
    }

    public function read(string $field, mixed $value): mixed
    {
        return isset($this->getters[$field]) ? ($this->getters[$field])($value) : $value;
    }

    public function write(string $field, mixed $value): mixed
    {
        return isset($this->setters[$field]) ? ($this->setters[$field])($value) : $value;
    }

    public function dispatch(string $event, Model $model, bool $cancellable): bool
    {
        foreach ($this->observers as $observer) {
            if (!$observer->onEvent($event, $model) && $cancellable) {
                return false;
            }
        }
        return true;
    }

    private function field(string $field): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field)) {
            throw new InvalidArgumentException('行为字段名无效');
        }
    }
}
