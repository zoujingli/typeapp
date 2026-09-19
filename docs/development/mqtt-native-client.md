# MQTT 客户端的原生协程 I/O

## 复用与清理

| 需要的行为 | 直接复用 | 保留的项目职责与旧路径处置 |
| --- | --- | --- |
| 连接与 TLS | 原生 Socket `connect()`、`setProtocol()`、`sslHandshake()`；沿用 `open_ssl`、`ssl_verify_peer`、`ssl_host_name`、`ssl_cafile`、`ssl_protocols`。 | 明确 IP 与证书身份，TLS 1.2/1.3，连接和握手共用总截止。握手分段读之外以单次原生 Timer 取消到期等待。 |
| 接收与发送 | `recv()`、`sendAll()` 及原生超时。 | 最多一个报文的输入预算、Receive Maximum、MQTT 保活和原始半包期限。协程路径不再经过旧 select 查询或手写部分发送循环；同步兼容分支继续保留。 |
| 归属、取消与关闭 | 既有 `ExecutionOwner`、Socket 原生取消和关闭。 | 实例固定在首次建连的协程，同线程控制方可停止；先撤销本地持有再关闭，防止被唤醒的失败清理重复关闭。 |
| 协议结果 | 现有 Message、PacketReader 与 Client 状态机。 | 手动 PUBACK、旧 receipt 拒绝、接收窗口、未知发布与显式重连保持原有语义。没有新增 Client/Socket 管理层或原生补丁。 |

`coroutine: true` 不会替应用建立事件循环或创建业务协程；没有协程上下文时返回 `mqtt_client_coroutine_required`。基础能力检查沿用 `CoroutineRuntime::assertAvailable()`，不在业务线程重新修改 hook。`stop()` 返回后，被唤醒的原等待报告 `mqtt_client_stopped`；直接原生取消报告 `mqtt_client_cancelled`，超时报告 `mqtt_client_timeout`。这些错误均不能证明远端发布未发生。

## 原生分包的暂缓条件

固定源码为 Swoole `0f3bee2f0ed8704ce33a336e7feabb0115411dd7`（版本字符串 6.2.1）。[原生 recv_packet](https://github.com/swoole/swoole-src/blob/0f3bee2f0ed8704ce33a336e7feabb0115411dd7/src/coroutine/socket.cc) 在返回值小于等于零时清空读缓冲；[MQTT 长度解析](https://github.com/swoole/swoole-src/blob/0f3bee2f0ed8704ce33a336e7feabb0115411dd7/src/protocol/mqtt.cc)也不拒绝非最短编码。

本地原生复现发送 `30 03 61`，第一次 `recvPacket(0.04)` 超时，再发 `62 63`，下一次字节读取只得到 `62 63`，先前半包已经丢弃。现有公共契约要求短 `receive()` 超时返回 null 并允许继续接收同一报文；完整缓冲帧也不应因业务处理超过五秒而丢失。因此不能直接删掉 `Client::frame()`，也不为了替换方法名新增后台读循环、另一套完成通道或原生补丁。

后续若原生接口能保留半包并暴露实际接收期限，或已确认的连接生命周期能以有界方式等价表达上述行为，再替换网络累积缓冲，继续保留固定头、最短编码、属性及 QoS 校验。当前只有一份应用输入缓冲，未同时启用原生 MQTT 分包。

## 验证入口

复用独立消费者和测试端原始 TCP/TLS 对端，不借用 Broker 的编解码证明 Client 正确。使用已配置的锁定 PHP SDK 与受控 PHPX，运行：

```sh
php tests/mqtt-consumer.php --native --client --client-only --client-peer-only --client-coroutine --client-thread
```

该入口完整编译安装后的四个生产包、业务测试入口及线程登记，随后在真实业务线程执行；去掉 `--client-thread` 验证主线程协程，去掉协程选项验证同步兼容。macOS 沿现有内核策略拒绝生产源码与生成源码读取。辅助 PHP 运行不代替 AOT；原始进程输出分别保存在消费者的独立 `client-evidence-*` 目录。

场景覆盖协议拒绝、入站显式确认、重复交付、窗口上限、未知发布恢复、短等待半包续收、半包总期限、完整帧延迟处理、保活、TLS 成功与身份拒绝、慢握手截止、取消、跨协程误用及独立连接进度。整个 Broker、物联网持久链路、DNS、通用监听分配与其他平台仍须后续验收；吞吐与端到端延迟收益须独立测量。

## macOS 原生 TLS 链接

固定上游的 `config.m4` 在 `--with-openssl-dir` 分支没有将 ssl/crypto 加入 `SWOOLE_SHARED_LIBADD`；此外，该 PHP SDK 的 libtool 在缺少 `MACOSX_DEPLOYMENT_TARGET` 时选择 `-flat_namespace -undefined suppress`。仅补库依赖仍会复现混绑。采用以下已有构建机制即可修复，不需要增加 TLS 原生补丁：

1. 使用与 PHP embed 库匹配的 OpenSSL，将其 pkg-config 目录加入 `PKG_CONFIG_PATH`，采用上游默认的 pkg-config 检测分支。
2. 显式设置与目标 PHP SDK 一致的 `MACOSX_DEPLOYMENT_TARGET`，使 libtool 使用动态查找未解析的 PHP 符号，同时保持已链接库的双层命名空间。
3. 检查 `otool -L` 中的 ssl/crypto 依赖、`otool -hv` 的 `TWOLEVEL`，以及 `nm -m` 中 `SSL_CTX_new`、`TLS_method`、`SSL_new` 均显示 `from libssl`。随后运行实际 TLS 用例；静态链接检查不能代替握手验收。
