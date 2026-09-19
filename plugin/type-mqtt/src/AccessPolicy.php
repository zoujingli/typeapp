<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 独立组件的认证与 Topic 授权边界；需要稳定主体及凭据代次时可实现兼容的 IdentityAccessPolicy。 */
interface AccessPolicy
{
    /** 每次 CONNECT 重新认证，peer 是实际远端地址，secure 仅在真实 TLS 完成后为 true。 */
    public function authenticate(ConnectPacket $connect, string $peer, bool $secure): bool;

    /**
     * action 为 publish/subscribe；共享订阅去除一次共享前缀后授权实际过滤器，交付前另外检查实际 Topic，组名不授予 Topic 权限。
     * 遗嘱恢复按已认证的 version/clientId/username 重新授权，password 不保存；授权不能依赖连接存活或密码。
     */
    public function authorize(ConnectPacket $connect, string $topic, string $action, int $qos): bool;
}
