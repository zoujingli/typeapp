<?php

declare(strict_types=1);

namespace Type\Queue;

final class Message
{
    private string $id;
    private string $type;
    private int $version;
    private array $payload;
    private array $context;

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
    public function id(): string
    {
        return $this->id;
    }
    public function type(): string
    {
        return $this->type;
    }
    public function version(): int
    {
        return $this->version;
    }
    public function payload(): array
    {
        return $this->payload;
    }
    public function context(): array
    {
        return $this->context;
    }
    public function encode(): string
    {
        return json_encode(['protocol' => 1, 'id' => $this->id, 'type' => $this->type, 'version' => $this->version,
            'payload' => $this->payload, 'context' => $this->context], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }
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
