<?php

declare(strict_types=1);

namespace Type\Orm\Outbox;

/** 已领取的事务消息快照，稳定消息身份与本次领取 token 分开保存。 */
final class Record
{
    private array $values;
    private array $source;
    /**
     * @internal 保存 Store 已领取的记录，不持有数据库租约。
     *
     * @param array<string, mixed> $values 数据库行，包含消息身份、JSON 载荷及领取 token。
     * @param array<string, string> $source 原领取物理来源身份；不保留连接或凭据明文。
     */
    public function __construct(array $values, array $source = [])
    {
        $this->values = $values;
        $this->source = $source;
    }
    /**
     * @internal 领取凭据只用于原端点和物理数据库，凭据轮换不改变消息来源。
     * @param array<string, string> $identity 待登记连接的驱动身份。
     */
    public function belongsTo(array $identity): bool
    {
        foreach (['driver', 'endpoint', 'database', 'role'] as $key) {
            if (!isset($this->source[$key], $identity[$key]) || $this->source[$key] !== $identity[$key]) {
                return false;
            }
        }
        return true;
    }
    /** 返回稳定消息 ID，外部系统应使用该身份去重或对账。 */
    public function id(): string
    {
        return $this->values['id'];
    }
    /** 返回由业务声明的消息主题，不根据主题加载未知代码。 */
    public function topic(): string
    {
        return $this->values['topic'];
    }
    /** 返回载荷契约版本，供接收方选择兼容解码。 */
    public function version(): int
    {
        return (int) $this->values['version'];
    }
    /**
     * 解码明确 JSON 数据，不构造任意业务对象。
     *
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return json_decode($this->values['payload'], true, 32, JSON_THROW_ON_ERROR);
    }
    /**
     * 返回入队时显式保存的上下文，不读取当前请求全局状态。
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return json_decode($this->values['context'], true, 32, JSON_THROW_ON_ERROR);
    }
    /** 返回本次领取令牌，登记发布结果必须匹配该代且租约仍有效。 */
    public function token(): string
    {
        return $this->values['token'];
    }
    /** 返回累计领取次数，不等同于目标成功消费次数。 */
    public function attempts(): int
    {
        return (int) $this->values['attempts'];
    }
}
