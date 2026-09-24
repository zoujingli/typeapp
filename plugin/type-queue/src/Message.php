<?php

declare(strict_types=1);

namespace Type\Queue;

/** 受限 JSON 消息信封；保留稳定业务 ID，不携带可执行类名或动态对象。 */
final class Message
{
    private string $id;
    private string $type;
    private int $version;
    private array $payload;
    private array $context;

    /**
     * 校验并复制载荷与关联；载荷和关联编码后合计不超过 60000 字节。
     *
     * @param array<array-key, mixed> $payload 由有限标量、null 和数组组成的数据树。
     * @param array<string, string> $context 追踪关联，不自动成为可信身份。
     * @throws QueueException 消息身份、字段类型、深度或容量不符合协议。
     */
    public function __construct(string $id, string $type, int $version, array $payload, array $context = [])
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $id) || !preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $type) || $version < 1) {
            throw new QueueException('invalid_message', '任务 ID、类型或版本无效');
        }
        foreach ($context as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new QueueException('invalid_context', '任务上下文只接受显式字符串');
            }
        }
        self::data($payload, 0);
        $encoded = json_encode([$payload, $context], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > 60000) {
            throw new QueueException('payload_too_large', '任务载荷与上下文超限');
        }
        [$this->payload, $this->context] = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $this->id = $id;
        $this->type = $type;
        $this->version = $version;
    }
    /** 返回跨重试保持不变的业务消息 ID，供实际写入目标实施幂等。 */
    public function id(): string
    {
        return $this->id;
    }
    /** 返回已编译注册使用的任务类型键，不表示 PHP 类名。 */
    public function type(): string
    {
        return $this->type;
    }
    /** 返回处理协议版本，与类型共同定位 Registry 工厂。 */
    public function version(): int
    {
        return $this->version;
    }
    /**
     * 读取构造时已复制的消息数据；具体业务字段由处理器校验。
     *
     * @return array<array-key, mixed> 受限 JSON 数据。
     */
    public function payload(): array
    {
        return $this->payload;
    }
    /**
     * 读取仅用于追踪的关联字段；不能直接作为认证或租户授权依据。
     *
     * @return array<string, string> 消息提供的显式字符串关联。
     */
    public function context(): array
    {
        return $this->context;
    }
    /** 生成版本化 JSON 信封，保留整数与浮点数的差异。 */
    public function encode(): string
    {
        return json_encode(['protocol' => 1, 'id' => $this->id, 'type' => $this->type, 'version' => $this->version,
            'payload' => $this->payload, 'context' => $this->context], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }
    /**
     * 解析最多 64 KiB 的协议消息，拒绝未知协议和非法载荷。
     *
     * @throws QueueException JSON、协议、身份、载荷或预算无效。
     */
    public static function decode(string $encoded): Message
    {
        if (strlen($encoded) > 65536) {
            throw new QueueException('payload_too_large', '任务消息超限');
        }
        try {
            $value = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            throw new QueueException('invalid_payload', '任务载荷不是有效 JSON');
        }
        if (!is_array($value) || ($value['protocol'] ?? null) !== 1 || !is_string($value['id'] ?? null) || !is_string($value['type'] ?? null)
            || !is_int($value['version'] ?? null) || !is_array($value['payload'] ?? null) || !is_array($value['context'] ?? null)) {
            throw new QueueException('unknown_payload', '任务载荷格式不兼容');
        }
        return new Message($value['id'], $value['type'], $value['version'], $value['payload'], $value['context']);
    }
    private static function data(mixed $value, int $depth): void
    {
        if ($depth > 24) {
            throw new QueueException('payload_too_deep', '任务载荷深度超限');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::data($item, $depth + 1);
            } return;
        }
        if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value) && (!is_float($value) || !is_finite($value))) {
            throw new QueueException('invalid_payload', '任务只接受 JSON 数据，不接受动态对象或可执行代码');
        }
    }
}
