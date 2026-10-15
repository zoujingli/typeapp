<?php

declare(strict_types=1);

namespace TypeTests\ReferenceCapture {
    /** 显式跨越局部 lambda 优化边界，仍执行同一个真实闭包。 */
    function execute(\Closure $operation): array
    {
        return $operation();
    }

    /** 调用期间直接修改原引用；异常不能回滚已经写入的模型状态。 */
    function mutate(array &$device, bool $fail, bool $replace = false): array
    {
        $operation = static function () use (&$device, $fail, $replace): array {
            if ($replace) {
                $device = ['scope_rev' => 3, 'replaced' => true];
            } else {
                $device['scope_rev'] = 3;
            }
            if ($fail) {
                throw new \RuntimeException('save failed');
            }
            return $device;
        };
        $saved = execute($operation);
        if ($saved !== $device) {
            throw new \RuntimeException('参数读取没有观察到闭包写入');
        }
        return $saved;
    }

    /** 同一数组的两个形参必须保持引用身份，独立值副本保持写时复制。 */
    function alias(array &$first, array &$second, array $snapshot): array
    {
        $operation = function () use (&$first, &$second, $snapshot): array {
            $first['first'] = 1;
            if (($second['first'] ?? 0) !== 1) {
                throw new \RuntimeException('同一实参被拆成独立引用');
            }
            $second['second'] = 2;
            return $snapshot;
        };
        return execute($operation);
    }

    /** 多层捕获复用一个引用，内层赋值后外层与具名调用者立即可见。 */
    function nested(array &$device): array
    {
        $outer = function () use (&$device): array {
            $inner = function () use (&$device): array {
                $device['nested'] = 4;
                return $device;
            };
            return execute($inner);
        };
        return execute($outer);
    }

    /** 已有默认引用 ABI 继续适用，不因为新适配复制参数。 */
    function defaulted(array &$device = []): array
    {
        $operation = function () use (&$device): array {
            $device['defaulted'] = true;
            return $device;
        };
        return execute($operation);
    }

    /** 按值参数被捕获只修改函数本地值，不能污染调用者。 */
    function valueCopy(array $device): array
    {
        $operation = function () use (&$device): array {
            $device['local'] = true;
            return $device;
        };
        return execute($operation);
    }

    /** 非逃逸闭包仍须保持引用与异常写回语义。 */
    function local(array &$device): void
    {
        $operation = function () use (&$device): void {
            $device['local_ref'] = true;
        };
        $operation();
    }

    /** 只有事务成功才替换调用方变量的行为继续由业务明确控制。 */
    class SaveService
    {
        public function save(array &$device, bool $atomic, bool $fail): array
        {
            if ($atomic) {
                $candidate = $device;
                $saved = $this->persist($candidate, $fail);
                $device = $saved;
                return $saved;
            }
            return $this->persist($device, $fail);
        }

        private function persist(array &$device, bool $fail): array
        {
            $operation = function () use (&$device, $fail): array {
                $device = ['scope_rev' => 3];
                if ($fail) {
                    throw new \RuntimeException('save failed');
                }
                return $device;
            };
            return execute($operation);
        }
    }

    /** 继承调用仍应使用父类私有方法中的引用参数。 */
    final class InheritedSave extends SaveService
    {
    }

    /** 带类型来源的属性实参继续受 Zend 约束。 */
    final class DeviceState
    {
        public array $value = ['scope_rev' => 1];
    }

    /** PHP 引用参数的数组约束在入口检查，赋值类型约束来自实参的属性来源。 */
    function replaceWithString(array &$device): void
    {
        $operation = function () use (&$device): array {
            $device = 'changed';
            return [];
        };
        execute($operation);
    }

    /** 上游动态引用可被返回闭包持有；调用方须使用允许逃逸的动态存储。 */
    function retain(array &$device): \Closure
    {
        return function () use (&$device): array {
            $device['retained'] = 5;
            return $device;
        };
    }

    /** mixed 参数保持动态存储，允许跨函数返回后的闭包仍持有引用。 */
    function retainedCaller(mixed $device): array
    {
        $operation = retain($device);
        $device['outside'] = 6;
        execute($operation);
        return $device;
    }

    /** 按 PHP 原行为核对可观察值，失败必须指出对应公开场景。 */
    function record(array &$checks, string $name, bool $passed): void
    {
        if (!$passed) {
            throw new \RuntimeException('引用参数捕获不一致：' . $name);
        }
        $checks[] = $name;
    }
}

namespace {
    /** 同一夹具在 PHP 与 AOT 中执行，覆盖异常写入、别名、类型与调用边界。 */
    function main(): void
    {
        $checks = [];
        foreach ([false, true] as $replace) {
            $device = ['scope_rev' => 1];
            $caught = false;
            try {
                \TypeTests\ReferenceCapture\mutate($device, true, $replace);
            } catch (RuntimeException $error) {
                $caught = $error->getMessage() === 'save failed';
            }
            \TypeTests\ReferenceCapture\record($checks, $replace ? 'replacement_before_exception' : 'item_before_exception', $caught && $device['scope_rev'] === 3 && isset($device['replaced']) === $replace);
            $success = \TypeTests\ReferenceCapture\mutate($device, false, $replace);
            \TypeTests\ReferenceCapture\record($checks, $replace ? 'replacement_return' : 'item_return', $success === $device);
        }
        $aliased = ['original' => true];
        $snapshot = \TypeTests\ReferenceCapture\alias($aliased, $aliased, $aliased);
        \TypeTests\ReferenceCapture\record($checks, 'shared_alias', $aliased === ['original' => true, 'first' => 1, 'second' => 2]);
        \TypeTests\ReferenceCapture\record($checks, 'value_snapshot', $snapshot === ['original' => true]);
        $nested = [];
        \TypeTests\ReferenceCapture\record($checks, 'nested_capture', \TypeTests\ReferenceCapture\nested($nested) === ['nested' => 4] && $nested === ['nested' => 4]);
        $defaults = [];
        \TypeTests\ReferenceCapture\record($checks, 'default_explicit', \TypeTests\ReferenceCapture\defaulted($defaults) === ['defaulted' => true] && $defaults === ['defaulted' => true]);
        \TypeTests\ReferenceCapture\record($checks, 'default_omitted', \TypeTests\ReferenceCapture\defaulted() === ['defaulted' => true]);
        $copy = ['original' => true];
        \TypeTests\ReferenceCapture\record($checks, 'by_value_isolation', \TypeTests\ReferenceCapture\valueCopy($copy) === ['original' => true, 'local' => true] && $copy === ['original' => true]);
        \TypeTests\ReferenceCapture\local($copy);
        \TypeTests\ReferenceCapture\record($checks, 'local_closure', $copy === ['original' => true, 'local_ref' => true]);
        $service = new \TypeTests\ReferenceCapture\InheritedSave();
        foreach ([false, true] as $atomic) {
            $model = ['scope_rev' => 1];
            $failed = false;
            try {
                $service->save($model, $atomic, true);
            } catch (RuntimeException $error) {
                $failed = $error->getMessage() === 'save failed';
            }
            \TypeTests\ReferenceCapture\record($checks, $atomic ? 'atomic_failure_keeps_original' : 'private_method_failure_visible', $failed && $model === ['scope_rev' => $atomic ? 1 : 3]);
            $result = $service->save($model, $atomic, false);
            \TypeTests\ReferenceCapture\record($checks, $atomic ? 'atomic_success_replaces' : 'private_method_success', $result === ['scope_rev' => 3] && $model === $result);
        }
        $property = new \TypeTests\ReferenceCapture\DeviceState();
        $failure = false;
        try {
            \TypeTests\ReferenceCapture\mutate($property->value, true);
        } catch (RuntimeException $error) {
            $failure = $error->getMessage() === 'save failed';
        }
        \TypeTests\ReferenceCapture\record($checks, 'typed_property_exception', $failure && $property->value === ['scope_rev' => 3]);
        $typeRejected = false;
        try {
            \TypeTests\ReferenceCapture\replaceWithString($property->value);
        } catch (TypeError $error) {
            $typeRejected = true;
        }
        \TypeTests\ReferenceCapture\record($checks, 'typed_property_source_preserved', $typeRejected && $property->value === ['scope_rev' => 3]);
        $dynamic = json_decode('"invalid"', true);
        $invalid = false;
        try {
            \TypeTests\ReferenceCapture\mutate($dynamic, false);
        } catch (TypeError $error) {
            $invalid = true;
        }
        \TypeTests\ReferenceCapture\record($checks, 'array_parameter_type_checked', $invalid && $dynamic === 'invalid');
        \TypeTests\ReferenceCapture\record($checks, 'retained_dynamic_reference', \TypeTests\ReferenceCapture\retainedCaller([]) === ['outside' => 6, 'retained' => 5]);
        $last = [];
        \TypeTests\ReferenceCapture\record($checks, 'after_exception', \TypeTests\ReferenceCapture\mutate($last, false) === ['scope_rev' => 3] && $last === ['scope_rev' => 3]);
        echo json_encode(['ok' => true, 'checks' => $checks], JSON_THROW_ON_ERROR), "\n";
    }
}
