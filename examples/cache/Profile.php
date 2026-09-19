<?php

declare(strict_types=1);

namespace TypeApp\CacheExample;

use InvalidArgumentException;

final class Profile
{
    private int $id;
    private string $name;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }
    public function id(): int
    {
        return $this->id;
    }
    public function name(): string
    {
        return $this->name;
    }
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
    public static function fromData(mixed $value): Profile
    {
        if (!is_array($value) || !is_int($value['id'] ?? null) || !is_string($value['name'] ?? null)) {
            throw new InvalidArgumentException('个人资料缓存格式无效');
        }
        return new Profile($value['id'], $value['name']);
    }
}
