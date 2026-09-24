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

    /** 返回追加观察器的新行为声明，原声明保持不变。 */
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

    /** @internal 运行已登记的字段获取器，未配置时保留原值。 */
    public function read(string $field, mixed $value): mixed
    {
        return isset($this->getters[$field]) ? ($this->getters[$field])($value) : $value;
    }

    /** @internal 在类型规范化之前运行修改器，未配置时保留输入值。 */
    public function write(string $field, mixed $value): mixed
    {
        return isset($this->setters[$field]) ? ($this->setters[$field])($value) : $value;
    }

    /** @internal 依声明顺序通知观察器；只允许可取消的前置事件以 false 中止。 */
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
