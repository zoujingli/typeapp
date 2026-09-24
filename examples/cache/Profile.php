<?php

declare(strict_types=1);

namespace TypeApp\CacheExample;

use InvalidArgumentException;

/** 缓存 DTO 示例，只保存明确字段，不依赖运行时任意对象恢复。 */
final class Profile
{
    private int $id;
    private string $name;

    /** 建立明确 ID 与姓名的值对象，字段校验由解码边界完成。 */
    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }
    /** 返回缓存对象的稳定业务 ID。 */
    public function id(): int
    {
        return $this->id;
    }
    /** 返回构造时保存的姓名文本。 */
    public function name(): string
    {
        return $this->name;
    }
    /**
     * 导出显式字段供 JSON 编码，不暴露额外对象状态。
     *
     * @return array{id: int, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
    /**
     * 从缓存数据恢复 DTO，字段形状或类型不符时拒绝。
     *
     * @throws InvalidArgumentException 缓存数据没有整数 ID 与文本姓名。
     */
    public static function fromData(mixed $value): Profile
    {
        if (!is_array($value) || !is_int($value['id'] ?? null) || !is_string($value['name'] ?? null)) {
            throw new InvalidArgumentException('个人资料缓存格式无效');
        }
        return new Profile($value['id'], $value['name']);
    }
}
