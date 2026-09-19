# MQTT WebSocket 接入与证书身份

MQTT 支持 TCP/TLS 与 WS/WSS 接入，传输方式不改变协议会话语义。证书由外部 CA 签发，平台登记并绑定稳定接入主体，Broker 终止 TLS 并验证证书；专用 mTLS 入口允许有效登记证书单独认证，同时提供账号凭据时须属于同一主体。身份来源直接来自 Broker 的 TLS 校验，以明确证书轮换与吊销责任，避免把 Client ID 当作授权身份；具体配置见[MQTT mTLS](../development/mqtt-mtls.md)。
