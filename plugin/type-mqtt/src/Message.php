<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 标准 QoS 0/1/2 消息；拥有 Topic、二进制载荷、属性及单调过期预算，不解释业务数据。 */
final class Message
{
    /** @var array<int, mixed> 已校验的 MQTT 5 属性；User Property 保留顺序与重复项。 */
    public readonly array $attributes;
    private float $receivedAt;

    /**
     * @param string $properties MQTT 5 属性原始字节，不含 Property Length；MQTT 3.1.1 留空。
     * @param int $elapsedSeconds 恢复持久原件时已经等待的整秒数，不能重新获得原期限。
     * @param bool $retain 发布者请求替换保留事实；实际出站 RETAIN 由本次交付决定。
     * @throws ProtocolError Topic、属性或显式 UTF-8 载荷非法；连接级 Topic Alias 必须先由连接解析。
     */
    public function __construct(public readonly string $topic, public readonly string $payload, public readonly string $properties = '', public readonly int $qos = 0, int $elapsedSeconds = 0, public readonly bool $retain = false)
    {
        if (!in_array($qos, [0, 1, 2], true)) {
            throw new ProtocolError(0x9b);
        }
        $this->receivedAt = (float) hrtime(true) / 1000000000.0 - (float) max(0, $elapsedSeconds);
        if ($topic === '' || strlen($topic) > 65535 || strpbrk($topic, '+#') !== false) {
            throw new ProtocolError(0x90);
        }
        if (str_contains($topic, "\0") || preg_match('//u', $topic) !== 1) {
            throw new ProtocolError();
        }
        $reader = new PacketReader(PacketReader::encodeVariable(strlen($properties)) . $properties);
        $this->attributes = $reader->properties('publish');
        if (isset($this->attributes[0x23])) {
            // 公共消息持有真实 Topic，连接别名不进入持久原件或跨连接转发。
            throw new ProtocolError(0x94);
        }
        if (($this->attributes[0x01] ?? 0) === 1 && preg_match('//u', $payload) !== 1) {
            throw new ProtocolError(0x99);
        }
    }

    /** 返回剩余整秒数；null 表示未声明 Message Expiry Interval。 */
    public function remaining(): ?int
    {
        if (!isset($this->attributes[0x02])) {
            return null;
        }
        return max(0, (int) $this->attributes[0x02] - (int) floor((float) hrtime(true) / 1000000000.0 - $this->receivedAt));
    }

    /** 在创建持久请求时把剩余单调预算转换为 Unix 秒截止；null 表示无期限。 */
    public function expiresAt(): ?int
    {
        $remaining = $this->remaining();
        return $remaining === null ? null : time() + (int) $remaining;
    }

    /**
     * 编码完整 PUBLISH；QoS 1/2 必须提供仍被该会话占用的标识，过期消息返回空串。
     * duplicate 仅供已有会话的合法重传；调用者不能对 MQTT 5 活跃连接建立重传定时器。
     * alias/omitTopic 只用于 MQTT 5 已协商的当前连接；省略 Topic 前须已在该连接发送完整映射。
     * retain 表示此次交付的实际 RETAIN 标志；保留重放为 true，实时路由依据接收者 RAP 决定。
     * finishExchange 仅供持久证据表明已开始的协议交换；允许期限为零时继续收尾。
     * @param list<int> $subscriptionIdentifiers 接收者交付的标识；不写回发布者原件，3.1.1 不编码。
     */
    public function packet(int $version, int $identifier = 0, bool $duplicate = false, ?int $qos = null, int $alias = 0, bool $omitTopic = false, bool $retain = false, bool $finishExchange = false, array $subscriptionIdentifiers = []): string
    {
        $deliveryQos = $qos ?? $this->qos;
        if (!in_array($version, [4, 5], true) || !in_array($deliveryQos, [0, 1, 2], true)
            || ($deliveryQos > 0 && ($identifier < 1 || $identifier > 65535)) || ($deliveryQos === 0 && ($identifier !== 0 || $duplicate))
            || $alias < 0 || $alias > 65535 || ($version !== 5 && $alias !== 0) || ($omitTopic && $alias === 0)) {
            throw new \InvalidArgumentException('MQTT 消息版本、QoS 或 Packet Identifier 无效');
        }
        $remaining = $this->remaining();
        if ($remaining === 0 && !$finishExchange) {
            return '';
        }
        $propertyBytes = $this->properties;
        if ($version === 5 && $remaining !== null && $remaining !== $this->attributes[0x02]) {
            $reader = new PacketReader(PacketReader::encodeVariable(strlen($propertyBytes)) . $propertyBytes);
            $reader->properties('publish');
            $propertyBytes = $reader->forwardedProperties($remaining);
        }
        if ($alias > 0) {
            $propertyBytes .= "\x23" . pack('n', $alias);
        }
        if (!array_is_list($subscriptionIdentifiers) || count($subscriptionIdentifiers) > 100) {
            throw new \InvalidArgumentException('MQTT 交付订阅标识额度无效');
        }
        foreach ($subscriptionIdentifiers as $subscriptionIdentifier) {
            if (!is_int($subscriptionIdentifier) || $subscriptionIdentifier < 1 || $subscriptionIdentifier > 268435455) {
                throw new \InvalidArgumentException('MQTT 交付订阅标识无效');
            }
            if ($version === 5) {
                $propertyBytes .= "\x0b" . PacketReader::encodeVariable($subscriptionIdentifier);
            }
        }
        $wireTopic = $omitTopic ? '' : $this->topic;
        $body = pack('n', strlen($wireTopic)) . $wireTopic
            . ($deliveryQos > 0 ? pack('n', $identifier) : '')
            . ($version === 5 ? PacketReader::encodeVariable(strlen($propertyBytes)) . $propertyBytes : '') . $this->payload;
        return chr(0x30 | ($deliveryQos << 1) | ($duplicate ? 8 : 0) | ($retain ? 1 : 0)) . PacketReader::encodeVariable(strlen($body)) . $body;
    }
}
