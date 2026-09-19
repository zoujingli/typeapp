<?php

declare(strict_types=1);

namespace Type\Validate;

use Closure;
use InvalidArgumentException;

/** 保存Schema输出的有效字段（含显式默认值）；PATCH不为缺失字段制造默认值。 */
final class Data
{
    private array $values;

    /**
     * 保存调用方提供的数据，不重复校验；业务通常使用Schema生成此对象。
     *
     * @param array<string, mixed> $values 已通过校验的有效字段。
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /** 判断结果中是否存在字段，显式/默认null均为true；不判断原始请求是否提供。 */
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    /**
     * 读取有效值，null不与缺失混同。
     *
     * @throws InvalidArgumentException 结果中没有该字段。
     */
    public function get(string $field): mixed
    {
        if (!$this->has($field)) {
            throw new InvalidArgumentException('字段没有提供：' . $field);
        }
        return $this->values[$field];
    }

    /** @return array<string, mixed> 有效字段映射，不包含未知或不适用字段。 */
    public function toArray(): array
    {
        return $this->values;
    }

    /** @param Closure(Data): object $factory 将本次已校验数据映射为显式 DTO。 */
    public function map(Closure $factory): object
    {
        return $factory($this);
    }
}
