<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 成功认证形成的无秘密身份；稳定主体不随凭据轮换改变，凭据代次用于精确撤权。 */
final class AccessIdentity
{
    /**
     * 标识由认证策略解析，不由 Client ID 或用户名展示文字推定；同一凭据标识的版本只能递增。
     * authenticationMethod 是消费者的稳定认证方法名，不表示 Broker 已实现对应认证。
     * @throws \InvalidArgumentException 身份缺失、超界或包含不能持久传递的文字。
     */
    public function __construct(
        public readonly string $principalId,
        public readonly string $credentialId,
        public readonly int $credentialVersion,
        public readonly string $authenticationMethod = 'connect'
    ) {
        foreach ([$principalId, $credentialId] as $identifier) {
            if ($identifier === '' || strlen($identifier) > 256 || preg_match('//u', $identifier) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $identifier) === 1) {
                throw new \InvalidArgumentException('MQTT接入身份无效');
            }
        }
        if ($credentialVersion < 1 || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $authenticationMethod) !== 1) {
            throw new \InvalidArgumentException('MQTT凭据代次或认证方法无效');
        }
    }

    /** @return array{principal_id:string,credential_id:string,credential_version:int,authentication_method:string} 可持久化但不授予权限的认证快照。 */
    public function data(): array
    {
        return ['principal_id' => $this->principalId, 'credential_id' => $this->credentialId,
            'credential_version' => $this->credentialVersion, 'authentication_method' => $this->authenticationMethod];
    }

    /**
     * 仅用于受控工作请求或持久状态恢复；读取快照不等于重新认证。
     * @param array<string,mixed> $data 精确的无秘密身份字段。
     * @throws \InvalidArgumentException 快照结构或身份非法。
     */
    public static function fromData(array $data): self
    {
        if (count($data) !== 4 || !is_string($data['principal_id'] ?? null) || !is_string($data['credential_id'] ?? null)
            || !is_int($data['credential_version'] ?? null) || !is_string($data['authentication_method'] ?? null)) {
            throw new \InvalidArgumentException('MQTT接入身份快照无效');
        }
        return new self($data['principal_id'], $data['credential_id'], $data['credential_version'], $data['authentication_method']);
    }

    /** 撤权同时匹配主体、凭据、方法和代次，不能把旧凭据清理扩大到同主体新凭据。 */
    public function matches(self $other): bool
    {
        return $this->principalId === $other->principalId && $this->credentialId === $other->credentialId
            && $this->credentialVersion === $other->credentialVersion && $this->authenticationMethod === $other->authenticationMethod;
    }
}
