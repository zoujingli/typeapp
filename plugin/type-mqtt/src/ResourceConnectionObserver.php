<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 可选的真实连接及订阅观察；不读取协议载荷，也不代替持久会话。 */
interface ResourceConnectionObserver extends ConnectionObserver
{
    /**
     * 完整成功 CONNACK 写出后代替 connected 调用一次；观察方负责沿用原连接业务语义。
     * durable 表示此连接由持久 worker 管理，不表示 expiry 必然非零。
     * node_run_id 为空、node_generation 为零表示单节点运行；session_generation 为零表示纯实时连接。
     * @param array{owner_id:string,client_id:string,username:string,session_id:string,session_generation:int,node_id:string,node_run_id:string,node_generation:int,resource_scope:?string,access_identity:?array,protocol:int,transport:string,durable:bool} $resource 无秘密资源事实。
     */
    public function connectedResource(array $resource, int $observedAt): void;

    /**
     * 仅已观察连接的真实订阅变化；首次连接逐条发送恢复后订阅，之后仅发送增删改。
     * null 表示移除；关闭仍使用 disconnected，观察方按 ownerId 清理实时订阅。
     * @param array{options:int,identifier:int}|null $subscription 已授权且已生效的选项，不伪造空订阅快照。
     */
    public function subscriptionResource(string $ownerId, string $filter, ?array $subscription, int $observedAt): void;
}
