<?php

declare(strict_types=1);

namespace Type\Core\Http;

use InvalidArgumentException;

/** 认证所有者建立的主体、角色与属性快照，授权仍须检查当前业务事实。 */
final class Identity
{
    private string $subject;
    private array $roles = [];
    /** @param array<string, mixed> $attributes 认证所有者提供的可信上下文，不从请求字段直接构造。 */
    public function __construct(string $subject, array $roles = [], private array $attributes = [])
    {
        if ($subject === '') {
            throw new InvalidArgumentException('身份必须有主体');
        }
        foreach ($roles as $role) {
            if (!is_string($role) || $role === '') {
                throw new InvalidArgumentException('角色无效');
            } $this->roles[] = $role;
        }
        $this->subject = $subject;
    }
    /** 返回已认证的非空主体标识。 */
    public function subject(): string
    {
        return $this->subject;
    }
    /**
     * 返回认证时的角色列表，不在读取时重新查询权限。
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }
    /** @return array<string, mixed> 本次认证上下文的值副本；授权仍由调用者检查当前事实。 */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
