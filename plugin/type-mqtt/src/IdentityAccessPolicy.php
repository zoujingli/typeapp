<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 可选的明确身份契约；保留 AccessPolicy 的旧调用，Broker 对本契约只认证一次。 */
interface IdentityAccessPolicy extends AccessPolicy
{
    /**
     * 每次 CONNECT 真实验证凭据后返回稳定身份，拒绝返回 null；peer/secure 含义沿用 AccessPolicy。
     * 不把仅解析或查到用户名当作认证成功，不在身份快照中返回密码、验证值或证书私钥。
     */
    public function authenticateIdentity(ConnectPacket $connect, string $peer, bool $secure): ?AccessIdentity;

    /**
     * identity 是该连接成功认证或该遗嘱持久保存的身份，connect 仅为协议事实。
     * 每次按当前凭据状态、版本和权限重新授权；遗嘱恢复没有密码和活跃连接。
     */
    public function authorizeIdentity(AccessIdentity $identity, ConnectPacket $connect, string $topic, string $action, int $qos): bool;
}
