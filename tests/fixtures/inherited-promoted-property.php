<?php

declare(strict_types=1);

namespace TypeTests\Promoted {
    /** 接口属性的具体实现通过默认对象或显式实参提供。 */
    interface Conversion
    {
        public function convert(): string;
    }

    /** 非共享默认值的身份和内容均可观察。 */
    final class Converter implements Conversion
    {
        public function __construct(private readonly string $value = 'converted')
        {
        }

        public function convert(): string
        {
            return $this->value;
        }
    }

    /** 继承构造器须写入本声明类的私有只读槽，并在构造体执行前完成。 */
    abstract class Signer
    {
        public string $initialized;

        public function __construct(private readonly Conversion $converter = new Converter())
        {
            $this->initialized = $this->converter->convert();
        }

        public function sign(): string
        {
            return $this->converter->convert();
        }

        public function converter(): Conversion
        {
            return $this->converter;
        }
    }

    /** 不声明构造器的子类继承完整提升语义。 */
    class ChildSigner extends Signer
    {
    }

    /** 多层继承不改变父属性的声明者。 */
    final class GrandchildSigner extends ChildSigner
    {
    }

    /** 子构造器提升自身属性，再显式调用 parent 初始化父属性。 */
    final class ExplicitChildSigner extends Signer
    {
        public function __construct(private readonly string $label = 'child')
        {
            parent::__construct(new Converter('parent'));
        }

        public function own(): string
        {
            return $this->label;
        }
    }

    /** Trait 构造提升属性归属于组合它的类。 */
    trait PromotedTrait
    {
        public function __construct(private readonly Conversion $converter = new Converter('trait'))
        {
        }

        public function convert(): string
        {
            return $this->converter->convert();
        }
    }

    /** 使用类提供实际声明作用域。 */
    class TraitOwner
    {
        use PromotedTrait;
    }

    /** 组合后的构造器仍可被子类继承。 */
    final class TraitChild extends TraitOwner
    {
    }

    /** 可见性不同的提升属性仍由 Zend 检查类型与 readonly。 */
    class PublicProtected
    {
        public function __construct(public readonly string $label = 'public', protected int $count = 7)
        {
        }
    }

    /** 子类可读受保护属性，外部只读公共属性不能重写。 */
    final class PublicProtectedChild extends PublicProtected
    {
        public function count(): int
        {
            return $this->count;
        }
    }
}

namespace {
    /** 未知接收者的外部写入仍须经过原只读检查。 */
    function overwritePromotedReadonly(object $value): void
    {
        $value->label = 'changed';
    }

    /** 同一源码对照初始化、继承、权限、对象身份和异常后的后续构造。 */
    function main(): void
    {
        $first = new \TypeTests\Promoted\ChildSigner();
        $second = new \TypeTests\Promoted\ChildSigner();
        $converter = new \TypeTests\Promoted\Converter('explicit');
        $explicit = new \TypeTests\Promoted\ChildSigner($converter);
        $grandchild = new \TypeTests\Promoted\GrandchildSigner();
        $child = new \TypeTests\Promoted\ExplicitChildSigner();
        $trait = new \TypeTests\Promoted\TraitChild();
        $public = new \TypeTests\Promoted\PublicProtectedChild();
        if ([$first->sign(), $explicit->sign(), $grandchild->sign(), $child->sign(), $child->own(),
            $trait->convert(), $public->label, $public->count(), $first->initialized]
            !== ['converted', 'explicit', 'converted', 'parent', 'child', 'trait', 'public', 7, 'converted']) {
            throw new RuntimeException('继承的提升属性没有写入声明者存储');
        }
        if ($first->converter() === $second->converter() || $explicit->converter() !== $converter) {
            throw new RuntimeException('默认对象被共享或显式对象被替换');
        }
        $values = get_mangled_object_vars($child);
        if (!isset($values["\0TypeTests\\Promoted\\Signer\0converter"], $values["\0TypeTests\\Promoted\\ExplicitChildSigner\0label"])
            || isset($values['converter']) || get_object_vars($first) !== ['initialized' => 'converted']) {
            throw new RuntimeException('私有提升属性泄漏为动态或公开属性');
        }
        $readonly = 0;
        try {
            call_user_func([$first, '__construct'], new \TypeTests\Promoted\Converter('overwrite'));
        } catch (Error $error) {
            if (!str_contains($error->getMessage(), 'readonly')) {
                throw $error;
            }
            $readonly++;
        }
        try {
            overwritePromotedReadonly($public);
        } catch (Error $error) {
            if (!str_contains($error->getMessage(), 'readonly')) {
                throw $error;
            }
            $readonly++;
        }
        if ($readonly !== 2 || $first->sign() !== 'converted' || $public->label !== 'public') {
            throw new RuntimeException('readonly 拒绝或失败后的原值不正确');
        }
        $after = new \TypeTests\Promoted\ChildSigner(new \TypeTests\Promoted\Converter('after'));
        if ($after->sign() !== 'after') {
            throw new RuntimeException('属性异常泄漏了写入作用域');
        }
        echo json_encode(['ok' => true, 'checks' => ['inherited_default', 'explicit_value', 'deep_inheritance',
            'parent_constructor', 'child_promotion', 'trait_inheritance', 'public_promotion', 'protected_promotion',
            'before_constructor_body', 'fresh_default', 'explicit_identity', 'private_slots', 'no_dynamic_property',
            'readonly_reinitialize_denied', 'readonly_external_denied', 'after_exception']], JSON_THROW_ON_ERROR), "\n";
    }
}
