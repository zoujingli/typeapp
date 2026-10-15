<?php

declare(strict_types=1);

/** 接口声明的普通方法不能被转换关键字抢占。 */
interface TextContract
{
    public function toString(): string;
}

/** 子接口沿用父接口方法。 */
interface ChildTextContract extends TextContract
{
}

/** 普通方法与隐式字符串转换拥有各自的可观察结果。 */
class ExplicitText implements ChildTextContract
{
    public function toString(): string
    {
        return 'method';
    }

    public function __toString(): string
    {
        return 'cast';
    }
}

/** 继承的真实方法也应被识别。 */
final class InheritedText extends ExplicitText
{
}

/** 接口属性和返回类型均能提供方法声明。 */
final class TextHolder
{
    public function __construct(public TextContract $text)
    {
    }
}

/** 无真实 toString 时，PHP 对照通过魔术方法表达相同的转换结果。 */
final class KeywordText
{
    public function __toString(): string
    {
        return 'keyword';
    }

    public function __call(string $name, array $arguments): string
    {
        if ($name !== 'toString' || $arguments !== []) {
            throw new RuntimeException('意外的魔术调用');
        }
        return (string) $this;
    }
}

/** 用户方法抛出的异常必须保持原对象。 */
final class ThrowingText implements TextContract
{
    public function __construct(private RuntimeException $error)
    {
    }

    public function toString(): string
    {
        throw $this->error;
    }
}

/** 接口类型的调用使用运行时实际方法。 */
function renderDeclaredText(ChildTextContract $text): string
{
    return $text->toString();
}

/** 带副作用的接收者只允许求值一次。 */
function createDeclaredText(int &$count): TextContract
{
    $count++;
    return new ExplicitText();
}

/** 空安全调用保留空值分支。 */
function renderNullableText(?TextContract $text): ?string
{
    return $text?->toString();
}

/** PHP 与 AOT 执行同一组方法、转换、异常及求值断言。 */
function main(): void
{
    $text = new ExplicitText();
    $inherited = new InheritedText();
    $holder = new TextHolder($text);
    $calls = 0;
    $results = [$text->toString(), renderDeclaredText($text), $inherited->toString(),
        $holder->text->toString(), createDeclaredText($calls)->toString(),
        renderNullableText($text), renderNullableText(null), (string) $text, 'prefix-' . $text,
        (new KeywordText())->toString(), $calls];
    if ($results !== ['method', 'method', 'method', 'method', 'method', 'method', null, 'cast', 'prefix-cast', 'keyword', 1]) {
        throw new RuntimeException('已声明 toString 的调用、转换或求值次数错误');
    }
    $error = new RuntimeException('same-error');
    try {
        $throwing = new ThrowingText($error);
        $throwing->toString();
        throw new LogicException('用户方法异常被吞掉');
    } catch (RuntimeException $caught) {
        if ($caught !== $error) {
            throw new LogicException('用户方法异常对象被替换');
        }
    }
    echo json_encode(['ok' => true, 'checks' => ['declared', 'interface', 'inherited', 'property', 'return_type', 'nullsafe', 'cast', 'concat', 'keyword_fallback', 'receiver_once', 'exception_identity']], JSON_THROW_ON_ERROR), "\n";
}
