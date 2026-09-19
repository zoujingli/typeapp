<?php

declare(strict_types=1);

namespace Type\Mqtt;

/**
 * 可选的持久授权失效来源，与连接观察分离；由消费者负责保存撤权意图及拒绝旧身份的后续认证。
 * 每次只提供一项，回调必须限制外部I/O。集群模式通过精确旧所有者的持久隔离意图等待全部节点关闭。
 */
interface AuthorizationInvalidations
{
    /**
     * 返回尚未完成的旧身份；重启或未知提交后重复返回同一项，不能仅因已领取就移除。
     * 新身份策略同时返回 access_identity，按完整凭据代次匹配；principal 仅匹配尚无快照的旧连接/会话，不替代新身份选择。
     * 旧来源省略 access_identity 时仍按原 username/principal 匹配，不提供同用户名多代凭据的精确撤权保证。
     * @return array{id:string,client_id:string,principal:string,actor:string,access_identity?:array{principal_id:string,credential_id:string,credential_version:int,authentication_method:string}}|null id为32位小写十六进制。
     */
    public function nextInvalidation(): ?array;

    /**
     * 仅在匹配网络已关闭、相关工作已结束，且精确旧持久会话已取得终止提交证明后调用。
     * 幂等保存完成事实；集群模式还须确认全部旧所有者的关闭或基础设施硬隔离。已交给网络的字节无法撤回。
     * 未释放后端或终止结果未知时不调用此方法；来源抛错后Broker丢弃未输出数据并停止。
     */
    public function invalidationCompleted(string $id): void;
}
