# 基于 Swoole 的多协议基础通信

HTTP、TCP、UDP、MQTT、WebSocket 作为平级通信能力提供文档和应用入口。连接、TLS、等待、收发与关闭直接使用 Swoole；HTTP 与 WebSocket 可复用同一服务实例和端口，MQTT 由 Plugins 保留协议、QoS 和会话职责。各协议保持请求、消息、字节流或数据报自身的语义，避免为了统一配置另建传输管理层。
