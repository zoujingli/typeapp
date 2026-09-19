<?php

declare(strict_types=1);

namespace TypeApp\Rollout;

final class Codec implements \Type\Cache\Codec
{
    private int $version;
    public function __construct(int $version)
    {
        $this->version = $version;
    }
    public function format(): string
    {
        return 'rollout.user.v' . $this->version;
    }
    public function encode(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('演练缓存只接受用户名');
        }
        return json_encode([$this->version === 1 ? 'name' : 'nickname' => $value], JSON_THROW_ON_ERROR);
    }
    public function decode(string $payload): mixed
    {
        $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        $key = $this->version === 1 ? 'name' : 'nickname';
        if (!is_array($data) || array_keys($data) !== [$key] || !is_string($data[$key])) {
            throw new \InvalidArgumentException('缓存格式不属于当前版本');
        }
        return $data[$key];
    }
}
