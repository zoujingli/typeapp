<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 协议错误只暴露标准原因码；不携带报文、密码或认证实现异常。 */
final class ProtocolError extends \RuntimeException
{
    /** MQTT 5 原因码；MQTT 3.1.1 由连接阶段决定是否可发送 CONNACK。 */
    public function __construct(public readonly int $reason = 0x81)
    {
        parent::__construct('MQTT 控制报文不符合协议', $reason);
    }
}
