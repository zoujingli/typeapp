# 有界 WebSocket 会话

状态：通用 `Type\Core\WebSocket` 直接复用 Swoole 的握手、双向消息、分片重组、控制帧隔离、单条上限、HTTP 共存、子协议/Origin 与 WSS 能力。各平台的 PHP、AOT、TLS 和资源回收结果按当前构建产物单独记录。

## 实现边界

- `Type\Core\WebSocket\Server`：包装 `Swoole\WebSocket\Server`，原生负责 `Sec-WebSocket-Accept`、分片重组与关闭握手。
- `Type\Core\WebSocket\Client`：包装 `Swoole\Coroutine\Http\Client::upgrade()`，原生负责掩码、帧解析与 WSS 校验。

项目只持有升级授权、连接会话、每条消息的 `ExecutionScope`、发送上限和关闭结算。业务代码不按帧边界对齐应用层报文。[MQTT over WebSocket](mqtt-websocket.md) 不依赖本组件。

服务端直接使用 Swoole WebSocket Server 或官方协程升级入口；单连接同一时刻保持一个消息所有者，回调顺序和资源归属由服务装配明确。

## 有界之处

| 项目 | 约束 |
| --- | --- |
| 单条消息 / 单帧 | 1 B–1 MiB，默认 1 MiB；超限在提交前拒绝并关闭该连接 |
| 排队发送 | 每连接累计排队字节上限 1 B–1 MiB，默认 1 MiB |
| 连接数 | 1–4096，默认 64 |
| 包上限 | `package_max_bytes` 必须介于帧上限与两倍之间，默认两倍 |
| 消息作用域 | `message_seconds` 为 (0,60] 秒，默认 30 |
| 期限 | 所有等待均为 (0,60] 秒 |

控制帧（PING/PONG/CLOSE）不进入业务消息路径，也不延长任何业务超时。连接会话只保存协议状态；每条消息在独立 `ExecutionScope` 中投递，回调返回后作用域关闭，数据库租约不能活到连接断开。慢对端不读回显、`receive` 超时返回 `null`、或 `GET /stop` 退役时，租约均已释放。

## WSS / TLS

WSS 通过 `open_ssl=true` 与成对 `ssl_cert_file`/`ssl_key_file` 启用，仅允许 TLS 1.2/1.3，`ssl_compress=false`，不调用 0-RTT/early data API。本进程终止 TLS；四层透传同样走这一套证书；七层反代终止 TLS 后转到本进程时保持 `open_ssl=false`。不提供 mTLS。客户端 `tls=true` 时强制校验证书链和主机名。

## 子协议与 Origin 策略

Swoole 原生的 `websocket_subprotocol` **会无条件回显配置值**：客户端请求 `chat` 或完全不发 `Sec-WebSocket-Protocol` 时，服务端仍回配置值并返回 101。因此必须在握手完成时核对客户端是否真的提供该子协议，未提供按 1002 关闭且不调用 `onOpen`。Origin 存在时必须与白名单精确匹配，不匹配按 1008 关闭。Origin 缺失允许连接，`onOpen` 第三参数为 `false`。

## 已验收

`composer test:websocket`（macOS ARM64，PHP 协程环境）：

- 握手 101，`Sec-WebSocket-Accept` 与标准计算一致。
- 两帧分片由原生重组后回显；二进制含空字节保真；控制帧隔离后业务消息不丢失。
- 超过上限拒绝且不提交；同一监听 HTTP/HTTPS 共存。
- 子协议与 Origin 策略。
- WSS：TLS 1.2/1.3，拒绝 TLS 1.1、缺 CA 与主机名不匹配；消息作用域在连接仍存活时关闭租约；取消接收返回 `null`；`/stop` 后进程退出。

可编译示例：[examples/websocket/main.php](../../examples/websocket/main.php)。
