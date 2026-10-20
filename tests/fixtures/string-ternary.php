<?php

declare(strict_types=1);

/** 属性使用 Zend 存储，字符串三元分支仍须产生确定的字符串值。 */
final class StringSelection
{
    public int $conditions = 0;
    public int $reads = 0;

    public function __construct(public readonly string $value)
    {
    }

    /** 覆盖空串在两侧、属性与局部量、嵌套表达式及带构造临时量的分支。 */
    public function select(bool $empty): array
    {
        $local = 'local';
        return [
            $empty ? '' : $this->value,
            $empty ? $this->value : '',
            $empty ? $local : $this->value,
            $empty ? ($empty ? '' : $this->value) : $this->value,
            $empty ? $this->from(new StringSelection('created')) : $this->value,
            $this->condition($empty) ? '' : $this->read(),
        ];
    }

    /** 未选择的失败分支必须保持惰性。 */
    public function failure(bool $fail): string
    {
        return $fail ? $this->fail() : '';
    }

    /** 构造实参引入的临时对象沿上游调用路径清理。 */
    private function from(StringSelection $value): string
    {
        return $value->value;
    }

    /** 选择此分支时保留原异常。 */
    private function fail(): string
    {
        throw new RuntimeException('selected-failure');
    }

    /** 可观察条件的求值次数。 */
    private function condition(bool $empty): bool
    {
        ++$this->conditions;
        return $empty;
    }

    /** 可观察分支的求值次数。 */
    private function read(): string
    {
        ++$this->reads;
        return $this->value;
    }
}

/** 同一输入在 PHP/AOT 下对照值、类型、惰性、求值次数和异常后的后续运行。 */
function main(): void
{
    $selection = new StringSelection('topic');
    $result = [];
    foreach ([true, false] as $empty) {
        $values = $selection->select($empty);
        $types = [];
        foreach ($values as $value) {
            $types[] = get_debug_type($value);
        }
        $result[] = [$values, $types, $selection->conditions, $selection->reads];
    }
    $result[] = $selection->failure(false);
    try {
        $selection->failure(true);
        $result[] = 'missing-error';
    } catch (RuntimeException $error) {
        $result[] = [get_class($error), $error->getMessage()];
    }
    $result[] = $selection->select(false);
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
}
