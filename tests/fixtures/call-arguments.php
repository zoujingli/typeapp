<?php

declare(strict_types=1);

namespace TypeTests\Arguments {
    use function func_get_args as currentArguments;

    /** 事件处理器保留旧合同传入的额外位置参数。 */
    class Handler
    {
        public function event(array $param): array
        {
            return ['declared' => $param, 'count' => func_num_args(), 'args' => func_get_args()];
        }

        public function optional(string $first = 'first', string $second = 'second'): array
        {
            return [func_num_args(), func_get_args()];
        }

        public function changed(string $first, string $second = 'second'): array
        {
            $first = 'changed';
            $position = func_num_args() - 1;
            return [func_num_args(), func_get_args(), func_get_arg($position)];
        }

        public function recursive(int $depth): array
        {
            $before = func_get_args();
            $child = $depth > 0 ? $this->recursive($depth - 1, 'inner') : [];
            return [$before, $child, func_get_args()];
        }

        public function ownCalls(): array
        {
            return [self::optional(), static::optional(), self::captureStatic(), static::captureStatic(), self::required('first', 'extra')];
        }

        public static function captureStatic(): array
        {
            return [static::class, func_num_args(), func_get_args()];
        }

        public function at(int $position): mixed
        {
            return func_get_arg($position);
        }

        public function namedAt(int $position): mixed
        {
            return func_get_arg(position: $position);
        }

        public function aliased(string $first): array
        {
            $callback = static fn (string $inner): array => currentArguments();
            return [currentArguments(), $callback('inner', 'extra')];
        }

        public function required(string $first): string
        {
            return $first;
        }
    }

    /** 继承与晚绑定类不因引入 Zend 回调而改变。 */
    final class ChildHandler extends Handler
    {
    }

    /** 魔术入口的实参是方法名和完整参数数组。 */
    final class MagicHandler
    {
        public function __call(string $method, array $arguments): array
        {
            return [func_num_args(), func_get_args()];
        }
    }

    /** 普通函数与方法使用相同的实参规则。 */
    function ordinary(string $first = 'first'): array
    {
        return [func_num_args(), func_get_args()];
    }

    /** 按引用修改可从参数内省及调用者同时观察。 */
    function changeReference(string &$first): array
    {
        $first = 'changed-reference';
        return [func_num_args(), func_get_args()];
    }

    /** 可变参数的本地数组修改不应改写 Zend 保存的原始额外位置参数。 */
    function variadic(string $first = 'first', string ...$rest): array
    {
        $first = 'changed';
        $rest[0] = 'local';
        return [func_num_args(), func_get_args(), $rest];
    }

    /** 闭包有自己的参数，不能使用创建它的函数参数。 */
    function createCallback(string $outer): \Closure
    {
        return static function (string $first): array {
            $first = 'closure-local';
            return [func_num_args(), func_get_args(), func_get_arg(2)];
        };
    }
}

namespace {
    /** 同一夹具输出真实结果，由控制器逐字节对照 PHP 与 AOT。 */
    function main(): void
    {
        $handler = new \TypeTests\Arguments\Handler();
        $event = 'event';
        $optional = 'optional';
        $required = 'required';
        $at = 'at';
        $results = [];
        $results['event'] = $handler->{$event}(['id' => 7], ['token' => 'synthetic'], null);
        $results['array_callback'] = call_user_func_array([$handler, 'event'], [['id' => 8], 'token', ['frame' => 1]]);
        $results['ignored_extras'] = $handler->{$required}('first', 'second', null);
        $results['direct_extras'] = $handler->required('first', 'second');
        $results['optional_empty'] = $handler->optional();
        $results['optional_one'] = $handler->{$optional}('one');
        $results['optional_extra'] = $handler->optional('one', 'two', 'three');
        $results['named_hole'] = $handler->optional(second: 'two');
        $results['local_change'] = $handler->changed('one', 'two', 'three');
        $results['recursion'] = $handler->recursive(2, 'outer', null);
        $results['own_calls'] = $handler->ownCalls();
        $child = new \TypeTests\Arguments\ChildHandler();
        $results['inheritance'] = $child->ownCalls();
        $results['static_extra'] = \TypeTests\Arguments\ChildHandler::captureStatic('extra');
        $magic = new \TypeTests\Arguments\MagicHandler();
        $results['magic'] = $magic->unknown('first', 'second');
        $results['ordinary'] = \TypeTests\Arguments\ordinary('one', 'two');
        $function = 'TypeTests\\Arguments\\ordinary';
        $results['dynamic_function'] = $function('one', 'two', 'three');
        $referenced = 'original';
        $results['reference'] = \TypeTests\Arguments\changeReference($referenced, 'extra');
        $results['reference_caller'] = $referenced;
        $results['variadic'] = \TypeTests\Arguments\variadic('one', 'two', 'three');
        $results['variadic_named'] = \TypeTests\Arguments\variadic(first: 'one', extra: 'named');
        $callback = \TypeTests\Arguments\createCallback('outer');
        $results['closure'] = $callback('one', 'two', 'three');
        $local = static fn (string $first): array => [func_num_args(), func_get_args()];
        $results['arrow'] = $local('one', 'two');
        $results['extra_at'] = $handler->{$at}(2, 'middle', ['last' => true]);
        $results['named_position'] = $handler->namedAt(2, 'middle', 'last');
        $results['function_alias'] = $handler->aliased('one', 'two');
        try {
            $handler->{$event}();
            throw new \LogicException('缺参没有被拒绝');
        } catch (\ArgumentCountError $error) {
            $results['missing'] = get_class($error);
        }
        try {
            $handler->{$event}('wrong', null, null);
            throw new \LogicException('错误类型没有被拒绝');
        } catch (\TypeError $error) {
            $results['type'] = get_class($error);
        }
        try {
            call_user_func_array([$handler, 'event'], ['param' => [], 'unknown' => null]);
            throw new \LogicException('未知命名参数没有被拒绝');
        } catch (\Error $error) {
            $results['unknown_named'] = get_class($error);
        }
        try {
            $handler->{$at}(9, 'middle', 'last');
            throw new \LogicException('参数位置越界没有被拒绝');
        } catch (\ValueError $error) {
            $results['out_of_range'] = $error->getMessage();
        }
        try {
            $handler->{$at}(-1, 'middle', 'last');
            throw new \LogicException('负位置没有被拒绝');
        } catch (\ValueError $error) {
            $results['negative_position'] = $error->getMessage();
        }
        $builtin = 'strlen';
        try {
            $builtin('one', 'two');
            throw new \LogicException('内部函数数量检查被放宽');
        } catch (\ArgumentCountError $error) {
            $results['builtin_count'] = get_class($error);
        }
        $results['after_exceptions'] = $handler->{$event}(['id' => 9], 'token', 'frame');
        echo json_encode(['ok' => true, 'results' => $results], JSON_THROW_ON_ERROR), "\n";
    }
}
