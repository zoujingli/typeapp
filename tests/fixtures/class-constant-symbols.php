<?php

declare(strict_types=1);

namespace TypeTests\ConstantSymbols {
    /** 数组常量大小写敏感，方法名称大小写不敏感，二者不能共享原生存储名。 */
    class Example
    {
        private const UNFINISHED = ['pending'];
        public const STATUS = ['upper'];
        public const status = ['lower'];
        protected const PROTECTED_VALUES = ['protected'];
        public const SCALAR = 'scalar';

        public function unfinished(): array
        {
            return self::UNFINISHED;
        }

        public function status(): array
        {
            return [self::STATUS, self::status];
        }

        public function scalar(): string
        {
            return self::SCALAR;
        }
    }

    /** 继承仍通过声明者存储读取，覆盖不改变父类私有常量。 */
    final class ChildExample extends Example
    {
        public const STATUS = ['child'];

        public function inherited(): array
        {
            return [parent::STATUS, self::STATUS, parent::PROTECTED_VALUES];
        }
    }

    /** 接口常量和接口方法拥有相同拼写时仍分别注册。 */
    interface Contract
    {
        public const VALUES = ['interface'];

        public function values(): array;
    }

    /** Trait 常量被组合到使用类，不能误生成或访问 trait 自身的存储。 */
    trait SharedValues
    {
        private const ITEMS = ['trait'];

        public function items(): array
        {
            return self::ITEMS;
        }
    }

    /** 接口传播、trait 组合和类方法同时使用分拆的声明及初始化单元。 */
    final class Consumer implements Contract
    {
        use SharedValues;

        public function values(): array
        {
            return self::VALUES;
        }
    }
}

namespace TypeTests\OtherConstantSymbols {
    /** 同名类与方法在不同命名空间保留独立常量值。 */
    final class Example
    {
        private const UNFINISHED = ['other'];

        public function unfinished(): array
        {
            return self::UNFINISHED;
        }
    }
}

namespace {
    /** 从公开读取及方法返回证明符号隔离不改变 PHP 可观察语义。 */
    function main(): void
    {
        $example = new \TypeTests\ConstantSymbols\Example();
        $child = new \TypeTests\ConstantSymbols\ChildExample();
        $consumer = new \TypeTests\ConstantSymbols\Consumer();
        $other = new \TypeTests\OtherConstantSymbols\Example();
        $constant = 'TypeTests\\ConstantSymbols\\Example::status';
        $results = [
            $example->unfinished(), $example->UNFINISHED(), $example->status(),
            \TypeTests\ConstantSymbols\Example::STATUS, \TypeTests\ConstantSymbols\Example::status,
            $example->scalar(), $child->inherited(), $child->unfinished(),
            $consumer->values(), $consumer->items(), $other->unfinished(), constant($constant),
        ];
        if ($results !== [
            ['pending'], ['pending'], [['upper'], ['lower']], ['upper'], ['lower'],
            'scalar', [['upper'], ['child'], ['protected']], ['pending'],
            ['interface'], ['trait'], ['other'], ['lower'],
        ]) {
            throw new RuntimeException('类数组常量符号隔离改变了读取或方法调用');
        }
        $copy = $example->unfinished();
        $copy[] = 'changed';
        if ($example->unfinished() !== ['pending']) {
            throw new RuntimeException('数组常量返回值未保持写时复制');
        }
        echo json_encode(['ok' => true, 'checks' => ['private_constant', 'method_case', 'exact_name',
            'upper_constant', 'lower_constant', 'scalar', 'inheritance', 'private_inheritance',
            'interface', 'trait', 'namespace', 'dynamic_lookup', 'copy_on_write']], JSON_THROW_ON_ERROR), "\n";
    }
}
