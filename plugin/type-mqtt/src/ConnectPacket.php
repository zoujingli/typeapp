<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 已验证的 CONNECT 事实；认证方只使用显式字段，不记录完整对象以免泄漏密码。 */
final class ConnectPacket
{
    public int $version = 0;
    public string $clientId = '';
    public bool $assignedIdentifier = false;
    public bool $cleanStart = true;
    public int $keepAlive = 0;
    public ?string $username = null;
    public ?string $password = null;
    public bool $will = false;
    public int $willQos = 0;
    public bool $willRetain = false;
    public string $willTopic = '';
    public string $willPayload = '';
    /** @var array<int, mixed> MQTT 5 CONNECT 属性，缺失与零值分开。 */
    public array $properties = [];
    /** @var array<int, mixed> MQTT 5 遗嘱属性。 */
    public array $willProperties = [];
    /** 可作为 PUBLISH 转发的原始属性，不含 Will Delay。 */
    public string $willPublicationProperties = '';

    /**
     * CONNECT 是每条网络连接的首个报文；调用方先验证固定头及完整长度。
     * @throws ProtocolError 格式、字段或协议版本不合法。
     */
    public function decode(string $payload): void
    {
        $reader = new PacketReader($payload);
        $protocol = $reader->text();
        $level = $reader->integer(1);
        if ($protocol !== 'MQTT') {
            throw new ProtocolError(0x84);
        }
        $this->version = $level >= 5 ? 5 : 4;
        if (!in_array($level, [4, 5], true)) {
            throw new ProtocolError(0x84);
        }
        $flags = $reader->integer(1);
        $this->cleanStart = ($flags & 2) !== 0;
        $this->will = ($flags & 4) !== 0;
        $this->willQos = ($flags >> 3) & 3;
        $this->willRetain = ($flags & 32) !== 0;
        if (($flags & 1) !== 0 || $this->willQos === 3) {
            throw new ProtocolError(0x81);
        }
        if ((!$this->will && ($this->willQos !== 0 || $this->willRetain))
            || ($this->version === 4 && ($flags & 64) !== 0 && ($flags & 128) === 0)) {
            throw new ProtocolError(0x82);
        }
        $this->keepAlive = $reader->integer(2);
        $this->properties = $this->version === 5 ? $reader->properties('connect') : [];
        $this->clientId = $reader->text();
        if ($this->clientId === '') {
            if (!$this->cleanStart && $this->version === 4) {
                throw new ProtocolError(0x85);
            }
            $this->clientId = bin2hex(random_bytes(12));
            $this->assignedIdentifier = true;
        }
        if ($this->will) {
            $this->willProperties = $this->version === 5 ? $reader->properties('will') : [];
            $this->willPublicationProperties = $this->version === 5 ? $reader->forwardedProperties() : '';
            $this->willTopic = $reader->text();
            if ($this->willTopic === '' || strpbrk($this->willTopic, '+#') !== false) {
                throw new ProtocolError(0x90);
            }
            $this->willPayload = $reader->binary();
            if (($this->willProperties[0x01] ?? 0) === 1 && preg_match('//u', $this->willPayload) !== 1) {
                throw new ProtocolError(0x99);
            }
        }
        $this->username = ($flags & 128) !== 0 ? $reader->text() : null;
        $this->password = ($flags & 64) !== 0 ? $reader->binary() : null;
        if ($reader->remaining() !== 0) {
            throw new ProtocolError();
        }
        if (isset($this->properties[0x15])) {
            throw new ProtocolError(0x8c);
        }
    }
}
