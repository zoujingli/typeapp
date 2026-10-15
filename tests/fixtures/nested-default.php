<?php

declare(strict_types=1);

/** 观察默认构造的顺序、次数及异常；不依赖生成的 C++ 文本。 */
final class DefaultConstructionProbe
{
    public static array $events = [];
    public static array $references = [];
    public static bool $fail = false;
}

/** 构造会留下可观察事件，以发现重复求值或默认对象复用。 */
final class DefaultLeaf
{
    /** 记录构造尝试；异常发生在外层构造之前。 */
    public function __construct(public readonly string $name = 'plain')
    {
        DefaultConstructionProbe::$events[] = $name;
        if (DefaultConstructionProbe::$fail) {
            throw new RuntimeException('default_construction_failed');
        }
    }
}

/** 两个嵌套实参必须按 PHP 的顺序求值，保留只读属性的真实对象身份。 */
final class DefaultPair
{
    /** 保留左右子对象身份并记录外层构造。 */
    public function __construct(public readonly DefaultLeaf $first, public readonly DefaultLeaf $second)
    {
        DefaultConstructionProbe::$events[] = 'pair';
    }
}

/** 构造默认值覆盖私有类常量、嵌套 new 和多个临时实参。 */
final class DefaultFacade
{
    private const FIRST = 'first';

    /** 省略参数时每次创建新的对象树。 */
    public function __construct(public readonly DefaultPair $value = new DefaultPair(new DefaultLeaf(self::FIRST), new DefaultLeaf('second')))
    {
    }

    /** 静态方法默认值使用同一语义，返回的是本次调用创建的对象。 */
    public static function create(DefaultPair $value = new DefaultPair(new DefaultLeaf('static-first'), new DefaultLeaf('static-second'))): DefaultPair
    {
        return $value;
    }
}

/** 独立函数的默认对象与普通参数无共享的临时存储。 */
function defaultPair(DefaultPair $value = new DefaultPair(new DefaultLeaf('function-first'), new DefaultLeaf('function-second'))): DefaultPair
{
    return $value;
}

/** 保留非对象默认参数和既有数组默认值路径。 */
function unchangedDefaults(string $text = 'ok', array $values = [new DefaultLeaf('array')]): string
{
    return $text . ':' . $values[0]->name;
}

/** 命名参数跳位与显式 null 在 Zend 包装入口必须保持不同语义。 */
function namedDefaults(?DefaultPair $value = new DefaultPair(new DefaultLeaf('named-first'), new DefaultLeaf('named-second')), string $tail = 'tail'): string
{
    return ($value === null ? 'null' : $value->first->name) . ':' . $tail;
}

/** 已支持的标量引用默认值仍须保持原生引用绑定与写回。 */
function referenceDefault(string &$value = 'ref-default'): string
{
    $previous = $value;
    $value = 'ref-replacement';
    return $previous;
}

/** PHP 与原生程序共用公开行为断言；任一语义差异均以非零退出。 */
function main(): void
{
    $first = new DefaultFacade();
    $second = new DefaultFacade();
    if ($first->value === $second->value || $first->value->first === $second->value->first
        || DefaultConstructionProbe::$events !== ['first', 'second', 'pair', 'first', 'second', 'pair']) {
        throw new RuntimeException('默认对象未逐次创建或构造顺序错误');
    }
    $count = count(DefaultConstructionProbe::$events);
    $explicit = new DefaultFacade($first->value);
    if ($explicit->value !== $first->value || count(DefaultConstructionProbe::$events) !== $count) {
        throw new RuntimeException('显式参数仍执行默认构造');
    }
    DefaultConstructionProbe::$events = [];
    $static = DefaultFacade::create();
    $function = defaultPair();
    if ($static->first->name !== 'static-first' || $function->second->name !== 'function-second'
        || DefaultConstructionProbe::$events !== ['static-first', 'static-second', 'pair', 'function-first', 'function-second', 'pair']) {
        throw new RuntimeException('方法或函数默认参数未完整求值');
    }
    DefaultConstructionProbe::$events = [];
    DefaultConstructionProbe::$fail = true;
    $failed = false;
    try {
        new DefaultFacade();
    } catch (RuntimeException $error) {
        $failed = $error->getMessage() === 'default_construction_failed';
    } finally {
        DefaultConstructionProbe::$fail = false;
    }
    if (!$failed || DefaultConstructionProbe::$events !== ['first'] || unchangedDefaults() !== 'ok:array') {
        throw new RuntimeException('默认构造异常或非对象默认参数发生退化');
    }
    DefaultConstructionProbe::$events = [];
    $named = call_user_func_array('namedDefaults', ['tail' => 'provided']);
    if ($named !== 'named-first:provided' || DefaultConstructionProbe::$events !== ['named-first', 'named-second', 'pair']) {
        throw new RuntimeException('命名参数跳位未求值默认对象');
    }
    DefaultConstructionProbe::$events = [];
    DefaultConstructionProbe::$fail = true;
    $nullable = call_user_func_array('namedDefaults', ['value' => null, 'tail' => 'provided']);
    $explicitMethod = call_user_func_array([DefaultFacade::class, 'create'], [$first->value]);
    $explicitFunction = call_user_func_array('defaultPair', [$first->value]);
    $explicitArray = call_user_func_array('unchangedDefaults', ['values' => [$first->value->first]]);
    DefaultConstructionProbe::$fail = false;
    if ($nullable !== 'null:provided' || $explicitMethod !== $first->value || $explicitFunction !== $first->value
        || $explicitArray !== 'ok:first' || DefaultConstructionProbe::$events !== []) {
        throw new RuntimeException('显式参数触发默认构造或错误替换 null');
    }
    DefaultConstructionProbe::$events = [];
    DefaultConstructionProbe::$references = ['ref-explicit'];
    $referenceArguments = [&DefaultConstructionProbe::$references[0]];
    $previous = call_user_func_array('referenceDefault', $referenceArguments);
    $referenceMissing = call_user_func_array('referenceDefault', []);
    if ($previous !== 'ref-explicit' || DefaultConstructionProbe::$references[0] !== 'ref-replacement' || $referenceMissing !== 'ref-default'
        || DefaultConstructionProbe::$events !== []) {
        throw new RuntimeException('引用参数默认构造或写回发生退化');
    }
    echo json_encode(['ok' => true, 'checks' => ['fresh_objects', 'left_to_right_once', 'explicit_argument', 'class_constant', 'method_and_function', 'constructor_exception', 'array_and_scalar_defaults', 'named_argument_gap', 'explicit_null', 'reference_binding']], JSON_THROW_ON_ERROR), "\n";
}
