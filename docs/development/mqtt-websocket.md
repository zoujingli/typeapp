# MQTT over WebSocket

## 复用与边界

`type-mqtt` 不依赖 `type-core`，以免独立 Broker 安装拖入 PSR HTTP 栈。握手、掩码、分片和控制帧交给 `Swoole\WebSocket\Server`；MQTT 切帧仍是原有 `Broker::packets()` 字节流，不假设 WebSocket 帧对齐 MQTT 包。

未配置 `wsPort`/`wssPort` 时，TCP 仍走原来的 PHP `stream` / `Swoole\Event` 循环。配置后要求 `ioDriver=swoole`，该进程改用单个 `Swoole\WebSocket\Server`（`worker_num=1`，`enable_coroutine=false`），TCP/TLS 为 `addListener`。不为每条连接建协程。明文 WS 与 WSS 不能在同一进程同时开启，这与既有明文/证书互斥一致。Windows 在启动期拒绝。

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

## 未验收 / 明确不做
