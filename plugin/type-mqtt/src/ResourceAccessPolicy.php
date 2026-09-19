<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 可选的管理资源归属；协议 ClientID、Topic 及 AccessIdentity 保持原有语义。 */
interface ResourceAccessPolicy extends IdentityAccessPolicy
{
    /**
     * 成功认证后、替换旧连接前取得不透明归属；1..128 UTF-8 字节且不含控制字符。
     * null 表示尚未归属，仅全局元数据权限可见；不能根据客户端声明猜测租户。
     * 已有非空归属不可经重连改变；旧 null 会话在恢复订阅重新授权并同步保存后绑定。
     */
    public function resourceScope(AccessIdentity $identity, ConnectPacket $connect): ?string;
}
