<?php

declare(strict_types=1);

namespace TypeApp\Rollout;

/** 发布兼容演练的版本化 JSON 编码器，使新旧缓存格式明确隔离。 */
final class Codec implements \Type\Cache\Codec
{
    private int $version;
    /** 固定本实例编码版本，不自动接受其他版本的字段。 */
    public function __construct(int $version)
    {
        $this->version = $version;
    }
    /** 返回带发布版本的格式身份，防止不同结构共享缓存内容。 */
    public function format(): string
    {
        return 'rollout.user.v' . $this->version;
    }
    /** 只接受姓名文本，按版本选择 name 或 nickname 字段。 */
    public function encode(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('演练缓存只接受用户名');
        }
        return json_encode([$this->version === 1 ? 'name' : 'nickname' => $value], JSON_THROW_ON_ERROR);
    }
    /** 严格验证当前版本的单字段结构，不将旧格式误认为新值。 */
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
