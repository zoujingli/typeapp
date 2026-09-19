<?php

declare(strict_types=1);

namespace Type\Mqtt;

/**
 * 可选的连接事实观察方；只接收无秘密的身份及 UTC Unix 秒，不参与协议会话提交。
 * 回调必须限制外部 I/O；异常使 Broker 停止，不能继续刷新不完整的连接观察。
 */
interface ConnectionObserver
{
    /** 完整成功 CONNACK 已写入且 Broker 仍持有开放连接；ownerId 每条网络连接唯一。 */
    public function connected(string $clientId, string $username, string $ownerId, int $observedAt): void;

    /** 仅通知此前已报告 connected 的连接；迟到关闭必须按 ownerId 隔离。 */
    public function disconnected(string $clientId, string $username, string $ownerId, int $observedAt, int $reason): void;

    /** 循环启动及每五秒一次活性；不是对每台设备的重新认证或遥测观察。 */
    public function heartbeat(int $observedAt): void;

    /** 所有本进程连接已关闭；进程崩溃没有此通知，观察方须让活性过期。 */
    public function stopped(int $observedAt): void;
}
