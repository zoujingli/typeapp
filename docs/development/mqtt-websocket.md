# MQTT over WebSocket

## 复用与边界

`type-mqtt` 不依赖 `type-core`，以免独立 Broker 安装拖入 PSR HTTP 栈。握手、掩码、分片和控制帧交给 `Swoole\WebSocket\Server`；MQTT 切帧仍是原有 `Broker::packets()` 字节流，不假设 WebSocket 帧对齐 MQTT 包。

TCP、TLS、mTLS 与 WebSocket 统一由同一个 Swoole Server 管理生命周期；启用 WebSocket 端口时通过同一服务的 `addListener` 复用监听能力。WebSocket 负责 HTTP Upgrade、帧解析和 MQTT 字节流承载，MQTT Broker 负责协议状态与授权。明文 WS 与 WSS 使用各自监听配置，证书校验沿用 Swoole 原生 TLS 能力。

Swoole 的 `websocket_subprotocol=mqtt` 会无条件回显；`onOpen` 再核对客户端是否真的提供 `mqtt`，未提供按 1002 关闭。Origin 存在时必须与预先小写的白名单精确匹配（1008）；缺失 Origin 放行，仍走 MQTT 认证。文本帧按 1003 关闭。

## 调用者

- `BrokerOptions::$wsPort` / `$wssPort` / `$allowedOrigins`
- 示例 `examples/mqtt/main.php`：`--ws-port`、`--wss-port`、`--allowed-origins`
- 应用 `broker:` 与物联 MQTT 角色读取 `BROKER_*` / `IOT_MQTT_*` 对应配置；物联角色默认 TLS，明文 WS 端口保持 0

## 已验证

`composer test:mqtt-websocket`（当前解释器若无 `pdo_pgsql`，QoS 1/2 记为跳过）与 `php tests/mqtt-websocket.php --durable-only`（需 `pdo_pgsql`）：

- 未提供或提供 `chat` 子协议：原生仍 101，随后 1002 关闭
- 不匹配 Origin：101 后 1008；无 Origin 的独立客户端可升级
- 二进制 MQTT；文本帧 1003；跨 WebSocket 消息的 CONNECT 半包与一帧多包
- WebSocket PING 不延长 MQTT Keep Alive
- 同进程：WS 发布者 ↔ TCP 订阅者及反向（明文）；WSS 发布者 ↔ TLS 订阅者
- 独立 `mqtt@5.15.0`：`ws://` / `wss://`，子协议 `mqtt`；3.1.1/5.0 QoS 0；在同步 PostgreSQL worker 上另跑 3.1.1 QoS 1 与 5.0 QoS 2 各一条

## 验收边界

协议行为、授权、关闭和资源回收由 `type-mqtt` 验证；平台支持和原生 AOT 结果按对应构建产物单独记录，不能把 PHP 场景结果外推到其他平台。
