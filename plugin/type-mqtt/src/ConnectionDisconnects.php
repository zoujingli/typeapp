<?php

declare(strict_types=1);

namespace Type\Mqtt;

/**
 * 可选的精确连接管理来源，与撤权和连接观察分离；由消费者保存断开、终止或清保留意图。
 * 每次只提供一项，回调必须限制外部 I/O。完成前不能把受理当作网络已关闭、会话已终止或原件已清除。
 */
interface ConnectionDisconnects
{
    /**
     * 返回尚未完成的管理断开；重启或未知提交后重复返回同一项，不能仅因已领取就移除。
     * 只按 owner_id 与会话代次匹配当前网络连接，不能用 Client ID 代替精确所有者。
     * @return array{id:string,owner_id:string,session_id:string,session_generation:int,actor:string}|null id 为 32 位小写十六进制。
     */
    public function nextDisconnect(): ?array;

    /**
     * 仅在匹配网络已关闭、相关关闭工作已结束，且实时观察行已消失后保存完成事实。
     * 不终止仍有效的持久会话，也不伪造客户端正常 DISCONNECT。
     * @param string $outcome disconnected 表示曾匹配并关闭；missing 表示本节点已无该所有者。
     * @return bool 完成事实已保存为真；观察仍在或关闭未结束时为假，Broker 下轮重试。
     */
    public function disconnectCompleted(string $id, string $outcome): bool;

    /**
     * 返回尚未完成的精确会话终止；按 session_id 与会话代次匹配，不能用 Client ID 代替。
     * @return array{id:string,owner_id:string,session_id:string,session_generation:int,actor:string}|null id 为 32 位小写十六进制。
     */
    public function nextTermination(): ?array;

    /**
     * 仅在匹配网络已关闭、该会话已从持久存储删除或确认缺失，且实时观察行已消失后保存完成事实。
     * 不伪造客户端正常 DISCONNECT；未完成交付只放弃本会话副本。
     * @param string $outcome terminated 表示已删除匹配会话；missing 表示本节点已无该代次会话。
     * @return bool 完成事实已保存为真；观察仍在、关闭未结束或存储证明未到时为假，Broker 下轮重试。
     */
    public function terminationCompleted(string $id, string $outcome): bool;

    /**
     * 返回尚未完成的保留原件清除；owner_id/session_id 均为 resource_id，session_generation 为原件代次。
     * @return array{id:string,owner_id:string,session_id:string,session_generation:int,actor:string}|null id 为 32 位小写十六进制。
     */
    public function nextClearance(): ?array;

    /**
     * 仅在当前原件已按代次删除或确认不再是该代次后保存完成事实。不制造普通发布，不撤回已有交付。
     * @param string $outcome cleared 表示已删除匹配原件；missing 表示该代次已不是当前原件。
     * @return bool 完成事实已保存为真；存储证明未到时为假，Broker 下轮重试。
     */
    public function clearanceCompleted(string $id, string $outcome): bool;
}
