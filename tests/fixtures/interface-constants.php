<?php

declare(strict_types=1);

namespace TypeTests\Claims {
    /** 聚合常量在标量声明之前，保持接口自身的词法作用域。 */
    interface Registered
    {
        public const ALL = [self::AUDIENCE, self::EXPIRATION, self::ID];
        public const MAP = ['name' => self::class, 'audience' => self::AUDIENCE, 'nested' => [self::EXPIRATION]];
        public const AUDIENCE = 'aud';
        public const EXPIRATION = 'exp';
        public const ID = 'jti';
    }

    /** 子接口通过 self 查找继承的标量，同时保留自身的类名。 */
    interface Extended extends Registered
    {
        public const OWN = [self::AUDIENCE, self::class];
    }

    /** 实现类覆盖常量不能改变接口默认聚合值的词法绑定。 */
    final class Consumer implements Extended
    {
        public const AUDIENCE = 'application-aud';
        public const LOCAL = [self::AUDIENCE, Registered::AUDIENCE];
    }
}

namespace TypeTests\OtherClaims {
    /** 同名接口来自不同命名空间，不能复用前一声明的解析状态。 */
    interface Registered
    {
        public const ALL = [self::AUDIENCE, self::class];
        public const AUDIENCE = 'other';
    }
}

namespace {
    /** 全局接口没有命名空间前缀，也必须保留完整声明名称。 */
    interface GlobalClaims
    {
        public const ALL = [self::KEY, self::class];
        public const KEY = 'global';
    }

    /** 原生程序必须先成功初始化，再逐项核对可观察的常量值。 */
    function main(): void
    {
        if (\TypeTests\Claims\Registered::ALL !== ['aud', 'exp', 'jti']
            || \TypeTests\Claims\Registered::MAP !== ['name' => 'TypeTests\\Claims\\Registered', 'audience' => 'aud', 'nested' => ['exp']]) {
            throw new RuntimeException('接口前向 self 常量或嵌套数组初始化错误');
        }
        if (\TypeTests\Claims\Extended::OWN !== ['aud', 'TypeTests\\Claims\\Extended']
            || \TypeTests\Claims\Consumer::ALL !== ['aud', 'exp', 'jti']
            || \TypeTests\Claims\Consumer::LOCAL !== ['application-aud', 'aud']) {
            throw new RuntimeException('接口继承或实现类覆盖改变了词法绑定');
        }
        if (\TypeTests\OtherClaims\Registered::ALL !== ['other', 'TypeTests\\OtherClaims\\Registered']
            || GlobalClaims::ALL !== ['global', 'GlobalClaims']) {
            throw new RuntimeException('接口解析状态泄漏到其他命名空间或全局接口');
        }
        echo json_encode(['ok' => true, 'checks' => ['startup', 'forward_self', 'nested_array', 'self_class', 'interface_inheritance', 'implementation_override', 'namespace_isolation', 'global_interface']], JSON_THROW_ON_ERROR), "\n";
    }
}
