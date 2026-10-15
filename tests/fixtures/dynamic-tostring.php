<?php

declare(strict_types=1);

/** 类型被擦除后，真实方法与字符串转换仍是不同的公开操作。 */
class DynamicPlainText
{
    public function toString(): string
    {
        return 'user-method';
    }

    public function __toString(): string
    {
        return 'cast';
    }
}

/** 实际类继承的普通方法同样参与运行时查找。 */
final class DynamicInheritedText extends DynamicPlainText
{
}

/** 模拟 builder 经循环返回 object 后丢失具体类的调用路径。 */
final class DynamicTextBuilder
{
    public function withClaim(string $name, string $value): object
    {
        return $this;
    }

    public function getToken(): object
    {
        return new DynamicPlainText();
    }
}

/** 不得把用户方法的非字符串返回值隐式转换成字符串。 */
final class DynamicIntegerText
{
    public function toString(): int
    {
        return 42;
    }
}

/** 真实方法的异常保留对象身份。 */
final class DynamicThrowingText
{
    public function __construct(private RuntimeException $error)
    {
    }

    public function toString(): string
    {
        throw $this->error;
    }
}

/** 私有方法的调用范围由词法类决定，不能受对象类型擦除影响。 */
final class DynamicPrivateText
{
    private function toString(): string
    {
        return 'private';
    }

    public function render(object $text): string
    {
        return $text->toString();
    }

    public function outside(): string
    {
        return renderDynamicText($this);
    }
}

/** 受保护方法允许子类作用域调用，拒绝全局调用。 */
class DynamicProtectedText
{
    protected function toString(): string
    {
        return 'protected';
    }
}

/** 子类中的未知接收者仍保留原有可访问范围。 */
final class DynamicProtectedChild extends DynamicProtectedText
{
    public function render(object $text): string
    {
        return $text->toString();
    }
}

/** PHP 对照通过魔术调用表达 TypePHP 已有的无真实方法转换规则。 */
final class DynamicKeywordText
{
    public function __toString(): string
    {
        return 'keyword';
    }

    public function __call(string $name, array $arguments): string
    {
        if ($name !== 'toString' || $arguments !== []) {
            throw new LogicException('意外的魔术调用');
        }
        return (string) $this;
    }
}

/** object 参数不可被编译器当作已知具体实现。 */
function renderDynamicText(object $text): string
{
    return $text->toString();
}

/** mixed 参数必须保留实际用户方法返回的值与类型。 */
function dynamicTextValue(mixed $text): mixed
{
    $value = $text->toString();
    return $value;
}

/** 引用接收者通过相同的真实方法入口调用。 */
function dynamicTextReference(mixed &$text): mixed
{
    return $text->toString();
}

/** mixed 参数拥有可引用存储，避免把固定 object 局部强制改为引用。 */
function renderDynamicReference(mixed $text): mixed
{
    return dynamicTextReference($text);
}

/** 创建表达式只求值一次。 */
function createDynamicText(int &$calls): object
{
    $calls++;
    return new DynamicPlainText();
}

/** 空安全方法调用只在非空分支进行方法查找。 */
function nullableDynamicText(?object $text): ?string
{
    return $text?->toString();
}

/** 显式转换与插值使用 __toString，方法 callable 则绑定真实方法。 */
function dynamicConversions(object $text): array
{
    $callback = $text->toString(...);
    return [(string) $text, "value-{$text}", $callback()];
}

/** 对照动态分发、返回值、访问范围与异常；累计候选复用同一夹具。 */
function main(): void
{
    $text = new DynamicPlainText();
    $builder = new DynamicTextBuilder();
    foreach (['iss' => 'issuer', 'aud' => 'audience'] as $name => $value) {
        $builder = $builder->withClaim($name, $value);
    }
    $calls = 0;
    $private = new DynamicPrivateText();
    $protected = new DynamicProtectedChild();
    $results = [renderDynamicText($text), dynamicTextValue($text), renderDynamicReference($text),
        $builder->getToken()->toString(), renderDynamicText(new DynamicInheritedText()),
        createDynamicText($calls)->toString(), $calls, nullableDynamicText($text), nullableDynamicText(null),
        dynamicTextValue(new DynamicIntegerText()), $private->render($private), $protected->render($protected),
        renderDynamicText(new DynamicKeywordText()), (string) $text, 'prefix-' . $text,
        dynamicConversions($text), (new DynamicIntegerText())->toString()];
    if ($results !== ['user-method', 'user-method', 'user-method', 'user-method', 'user-method',
        'user-method', 1, 'user-method', null, 42, 'private', 'protected', 'keyword', 'cast', 'prefix-cast',
        ['cast', 'value-cast', 'user-method'], 42]) {
        throw new RuntimeException('动态 toString 的调用、返回值或转换不一致');
    }
    $error = new RuntimeException('same-error');
    try {
        renderDynamicText(new DynamicThrowingText($error));
        throw new LogicException('用户方法异常被吞掉');
    } catch (RuntimeException $caught) {
        if ($caught !== $error) {
            throw new LogicException('用户方法异常被替换');
        }
    }
    $denied = 0;
    try {
        renderDynamicText($private);
    } catch (Error) {
        $denied++;
    }
    try {
        renderDynamicText($protected);
    } catch (Error) {
        $denied++;
    }
    try {
        call_user_func([$private, 'outside']);
    } catch (Error) {
        $denied++;
    }
    if ($denied !== 3) {
        throw new LogicException('动态调用泄漏了类的访问权限');
    }
    echo json_encode(['ok' => true, 'checks' => ['object', 'mixed', 'reference', 'builder_loop', 'inherited',
        'receiver_expression', 'receiver_once', 'nullsafe_value', 'nullsafe_null', 'integer_result',
        'private_scope', 'protected_scope', 'keyword_fallback', 'cast', 'concat', 'exception_identity',
        'private_denied', 'protected_denied', 'global_scope_denied', 'dynamic_cast', 'interpolation',
        'method_callable', 'declared_integer_result']], JSON_THROW_ON_ERROR), "\n";
}
