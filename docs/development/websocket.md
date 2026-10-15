# 有界 WebSocket 会话

状态：通用 `Type\Core\WebSocket` 直接复用 Swoole 的握手、双向消息、分片重组、控制帧隔离、单条上限、HTTP 共存、子协议/Origin 与 WSS 能力。各平台的 PHP、AOT、TLS 和资源回收结果按当前构建产物单独记录。

## 实现边界

- `Type\Core\WebSocket\Server`：包装 `Swoole\WebSocket\Server`，原生负责 `Sec-WebSocket-Accept`、分片重组与关闭握手。
- `Type\Core\WebSocket\Client`：包装 `Swoole\Coroutine\Http\Client::upgrade()`，原生负责掩码、帧解析与 WSS 校验。

项目只持有升级授权、连接会话、每条消息的 `ExecutionScope`、发送上限和关闭结算。业务代码不按帧边界对齐应用层报文。[MQTT over WebSocket](mqtt-websocket.md) 不依赖本组件。

服务端当前使用经典 Swoole WebSocket Server；单连接同一时刻保持一个消息所有者，回调顺序和资源归属由服务装配明确。Windows 在 `start()` 返回明确的不支持错误，协程 HTTP 升级尚未接入，不能将它写成已实现路径。

## 有界之处

| 项目 | 约束 |
| --- | --- |
| 服务端单条完整消息 | 1 B–64 MiB，默认 1 MiB；超限拒绝并关闭该连接 |
| 服务端排队 | 每连接累计字节上限 1 B–64 MiB，默认 1 MiB |
| 连接数 | 1–4096，默认 64 |
| 包上限 | `package_max_length` 必须介于消息上限与两倍之间，默认 2 MiB；预留帧头字节 |
| 消息作用域 | `message_seconds` 为 (0,60] 秒，默认 30 |
| 期限 | 所有等待均为 (0,60] 秒 |

控制帧（PING/PONG/CLOSE）不进入业务消息路径，也不延长业务超时。启用 Ping 回调后，框架原样回复一次 Pong，再将 Ping/Pong 交给受管 `onControl()`。连接会话保存有界握手快照；每条消息在独立 `ExecutionScope` 中投递，回调返回后作用域关闭，数据库租约不能活到连接断开。具体接口、兼容键、原生心跳及关闭参数以[WebSocket 指南](../guide/communications/websocket.md)为准；新增接口不属于官方 RC14 原版。

## WSS / TLS

WSS 通过 `open_ssl=true` 与成对 `ssl_cert_file`/`ssl_key_file` 启用，仅允许 TLS 1.2/1.3，`ssl_compress=false`，不调用 0-RTT/early data API。本进程终止 TLS；四层透传同样走这一套证书；七层反代终止 TLS 后转到本进程时保持 `open_ssl=false`。不提供 mTLS。客户端 `tls=true` 时强制校验证书链和主机名。

## 子协议与 Origin 策略

Swoole 原生的 `websocket_subprotocol` **会无条件回显配置值**：客户端请求 `chat` 或完全不发 `Sec-WebSocket-Protocol` 时，服务端仍回配置值并返回 101。因此必须在握手完成时核对客户端是否真的提供该子协议，未提供按 1002 关闭且不调用 `onOpen`。Origin 存在时必须与白名单精确匹配，不匹配按 1008 关闭。Origin 缺失允许连接，`onOpen` 第三参数为 `false`。

## 已验收

`composer test:websocket`（macOS ARM64，PHP 协程环境）：

- 握手 101，`Sec-WebSocket-Accept` 与标准计算一致。
- 两帧分片由原生重组后回显；二进制含空字节保真；普通文本回显。
- 超过上限拒绝且不提交；同一监听 HTTP/HTTPS 共存。
- 子协议与 Origin 策略。
- WSS：TLS 1.2/1.3，拒绝 TLS 1.1、缺 CA 与主机名不匹配；消息作用域在连接仍存活时关闭租约；取消接收返回 `null`；`/stop` 后进程退出。

可编译示例：[examples/websocket/main.php](../../examples/websocket/main.php)。

`composer test:websocket-compat` 使用独立协议客户端发送真实 Ping/Pong、分片及 50 MiB 双向载荷，覆盖原始查询 `ssid=0`、主动关闭、异常、对端断开、停止、逐连接一次结算、原生空闲关闭、默认 Pong 与容量拒绝。共享夹具 `tests/fixtures/websocket-compat.php` 有显式 `main()`，可独立安装组件后完整编译；通过 `TYPE_WS_NATIVE_COMMAND` 传入原生命令的 JSON 参数数组，以同一断言验证同一程序。PHP 与 AOT 结果分别记录，编译阶段不能排除生产依赖。

## 2026-10-15 本地 RC14 补丁验收

基线为 RC14 `683f6d4d30e1e99b6a93f6a6fb45625fbd87138a`，Composer ZIP 仅替换 core 的 `src/WebSocket/Server.php`，其 SHA-256 为 `37145e7df281f33e696f54e56715c4826db49f89eb356ed54bf8c787341ac217`。runtime/build 固定安装官方 RC14；本地版本 `dev-dmstype-websocket-37145e7df281` 通过显式 RC14 别名消费，没有移动官方 tag 或发布新版本。

macOS ARM64、PHP 8.5.10 ZTS、TypePHP 0.9.4、PHPX 2.9.3 与固定 Swoole `4aff74a9ac086458d1c5251e71ac6e080f68b390` 下，独立安装的 89 个生产源码文件完整 AOT。最终程序 SHA-256 为 `6e25f08661544255e95418f9b45dd723dd117b5f211c7ef954fc804fcdd2d1d4`；删除消费者的 2398 个 PHP 文件并核对沙箱拒绝读取源码、执行 PHP 后，同一程序通过上述兼容回归，包括控制帧后的多条消息、50 MiB 双向载荷和逐连接关闭配对。

原生首轮发现锁定 TypePHP 在含提前返回的 `finally` 中复用空合并临时值，导致正常消息收尾未归还 Channel 令牌；独立私有收尾方法消除了这一生成路径。失败程序、最小复现及修复后身份分别保留，未修改共享 SDK、vendor 或编译器。此结论针对 RC14 补丁消费者；本轮没有重验完整物联中心、Linux 或 Windows，WSS/TLS 的本轮证据来自既有 PHP 回归，不能算作该补丁的原生 WSS 验收。
