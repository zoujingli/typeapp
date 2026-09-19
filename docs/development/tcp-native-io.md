# 工作线程内的 TCP/TLS 原生通信

本文描述 TCP 的原生 I/O 接入与验证入口。公共入口属于 `type-core`，直接使用 `Swoole\Coroutine\Socket`；线程入口、作用域和部署额度来自 `type-runtime`。不新增网络引擎、应用消息队列、通用 Driver 或协议解析器。Linux 与 Windows 原生 IOCP 按本批后续矩阵集中验收，不能由本地结果替代。

## 复用与责任

| 生产调用入口 | 固定原生机制 | 项目必须保留的职责 |
| --- | --- | --- |
| `TcpSocket::client()` 与 `start()` | Socket `connect()` 内的 DNS、TCP 和 TLS；`Timer::after()`、`Coroutine::cancel()`。 | 同线程归属，连接额度，DNS/连接/握手合计截止，安全 TLS 默认值。 |
| `TcpSocket::listener()` 与 `accept()` | `bind()`、`listen()`、`accept()`，原生 TLS 上下文继承。 | 接入前预留额度；交接失败仍持有关闭责任。监听实例与已交接连接分别由所属作用域停止。 |
| 接入连接的 `start()` | 原生 `sslHandshake()`，既有执行作用域的子任务。 | TLS 握手移到本连接协程，监听继续接入其他连接；不跨线程搬运 Socket 或活动 TLS 对象。 |
| `receive()`、`send()`、`shutdownWrite()` | `recv()`、`sendAll()`、原生读写期限与 `shutdown(SHUT_WR)`。 | 片段上限，一读一写准入，EOF 与未知部分写入语义；业务自行限制总消息大小。 |
| `stop()`、`awaitClosed()` | `Coroutine::cancel()`、Socket 析构、`Event::defer()`、原生 Channel 通知。 | 取消重入保护；原生 FD 关闭后归还额度；等待超时不提前释放。 |

固定上游为 `0f3bee2f0ed8704ce33a336e7feabb0115411dd7`，版本字符串 6.2.1。其 `src/coroutine/socket.cc` 的 `accept()` 只创建接入 Socket、继承 TLS 上下文，不执行握手，因此无需修改原生接入循环。客户端 `connect()` 已串联解析、连接与握手，应用只加一次总截止 Timer；分段握手不能延长总预算。SSL 握手的 PHP 方法不保证同步 `errCode`，失败仍按连接或握手阶段报告，超时和取消由当前执行状态区别。

沿用已经核验的六文件线程入口适配及内嵌库关闭修复；TCP 不依赖 UDP 空报文修正或文件 I/O 候选补丁。macOS TLS 链接要求沿用[已有修正](mqtt-native-client.md#macos-原生-tls-链接)。IP 证书身份有下面的单方法原生修正，不改调度、握手或协议处理。

## 数字 IP 的 TLS 校验修正

固定 `src/network/socket.cc` 的 SHA-256 为 `e6919bae549f08932e6922c118b830d1f9fe6e5318efb874a7909ccd2a3814bd`。原 `Socket::ssl_check_host()` 无论输入是域名还是数字 IP，都调用 `X509_check_host()`。同一份含 `DNS:localhost,IP:127.0.0.1,IP:::1` 的证书，独立 Node TLS 对端下的原生最小复现得到：localhost 成功、127.0.0.1 失败、错误域名失败。该缺陷阻止普通 IP SAN 证书的合法连接。

`ssl_host_name` 只能传入待验证身份，没有切换 IP SAN 校验的配置；传空值或关闭验证会丢失身份约束，PHP 解析证书再自己匹配则重复实现 TLS 规则。现由已有 `SwooleSocketSource::applyTls()` 在固定原文唯一位置调整分支：数字 IPv4/IPv6 使用 OpenSSL `X509_check_ip_asc()`，域名保持 `X509_check_host()`。证书链、协议版本和全部成功/失败关闭路径继续由原生实现。本地对照修正后得到成功、成功、失败；公共 AOT 消费者进一步验证 IPv4/IPv6 TLS。

补丁只修改这一原生方法，不增加 ABI 或自定义 TLS 选项；独立于既有 UDP `apply()`，维护归属为 / `type-build`。匹配 IP SAN 的行为影响所有使用该方法的原生 TLS 调用者，升级时须重验正确 IP、错误身份、链拒绝、取消和退役。上游等价修复并通过同一公共消费者时撤除此补丁。其他平台必须分别构建和实际验收。

## 公共契约

在所属业务线程中取得共享的 `DeploymentBudget::poolBudget()`，再创建尚未启动的实例：

```php
$client = TcpSocket::client($budget, 'localhost', 9443, 65536, [
    'open_ssl' => true,
    'ssl_cafile' => $caFile,
]);
$listener = TcpSocket::listener($budget, '127.0.0.1', 9443, 65536, [
    'open_ssl' => true,
    'ssl_cert_file' => $certificateFile,
    'ssl_key_file' => $privateKeyFile,
]);
```

用 `ExecutionScope::open($resource)` 启动并登记资源，缺省启动总截止为 5 秒。需要更短握手预算时可显式 `start($seconds)`，但必须用 `finally` 调用 `stop()`；之后登记到作用域不会重复连接。`accept($seconds)` 返回已占额度、状态为 `accepted` 的实例，调用者负责在同线程启动、交给子任务或立即停止，不能遗弃。已退役实例禁止重启和序列化，线程请求代次由 `ExecutionOwner` 检查。

`receive($seconds)` 返回最多一个配置片段的字节，空字符串是读 EOF。它不承诺与一次发送或一条业务消息对应，既不复制 MQTT 分帧，也不与 HTTP Stream 合并。短接收超时或取消抛出 `tcp_timeout` / `tcp_cancelled`，后续可以继续读取；已经返回的数据由业务持有。`send($data, $seconds)` 使用原生 `sendAll()`；长度超限在提交前拒绝，短写或发送失败会停止连接，不能推定远端未收到，更不能自动重发。

同一线程内允许一个接收协程和一个发送协程并行，重复同方向操作在进入 Swoole 前返回 `tcp_operation_active`。一旦进入停止状态便拒绝新 I/O。读 EOF 后仍可发送；`shutdownWrite()` 后仍可接收。TLS 采用原生 close_notify 和半关闭语义，不增加自己的 TLS 状态机，也不将本地 shutdown 成功当成对端确认。

`stop()` 从同线程控制方发起，取消所有在途方向；它可以在取消唤醒的调用中重入。全部原操作退出、取消调用栈结束后才释放原生引用，项目结算回调在同一原生 defer 队列中排在 FD 关闭之后。`statistics()['state'] === 'closed'` 才代表完成。`awaitClosed($seconds)` 用原生 Channel 等待实际完成，超时或被取消返回 `tcp_close_pending`，仍保留未完成额度。

## 配置与预算

- `maxChunkBytes` 范围为 1–1048576，默认 65536；每连接最多一个接收片段和一个待发片段，应用排队字节为零。业务累计多个片段时须另行限制总消息大小。
- `socket_buffer_size` 沿用原生名称，范围 65536–1048576。通过 `SO_RCVBUF/SO_SNDBUF` 设置并回读；原生报告不得超过预留的两倍，兼容 Linux 双倍报告惯例。TLS/内核元数据另有成本，不能把片段字节数宣传为整个进程的 RSS 硬上限。
- `backlog` 为原生监听等待队列参数，范围 1–4096，默认 128；它不是应用连接额度。监听占一份额度，每个等待中的 accept 在接入前再预留一份；接入后的连接继承该额度，不重复计数、不再排应用队列。
- 所有等待显式为 `(0,60]` 秒，Socket 读写期限直接通过对应方法参数传入；Socket `setProtocol()` 不处理 `read_timeout/write_timeout`，不把 Client 专属设置误传给它。
- TLS 保留 `open_ssl`、`ssl_cert_file`、`ssl_key_file`、`ssl_cafile`、`ssl_host_name`、`ssl_verify_peer`、`ssl_protocols` 名称。客户端强制链和主机名校验，默认 TLS 1.2/1.3，不接受自签名绕过。监听可启用客户端证书校验，具体证书身份与授权仍属于业务。
- 域名客户端当前沿原生 AF_INET 解析 A 记录；数字 IPv6 使用 AF_INET6。监听只接受数字 IP，IPv6 显式关闭 IPv4 映射。不提供 Happy Eyeballs 或应用 DNS 缓存。

证书文件加载与 TLS 计算仍包含原生同步阶段；总截止在启动完成时再次检查，不能把计时器当成可抢占任意同步系统调用的硬截止。其他线程和系统监督职责仍沿现有运行时任务落实。

## 验证与历史清理

`tests/tcp-consumer.php` 复用从 UDP 消费者提取的 `tests/socket-consumer.php`，保留原 `tests/udp-consumer.php` 命令。公共安装、完整 AOT、原产物摘要检查、无源码隔离和进程收尾只有一份实现；两个协议仍有各自独立的标准 Node 对端与行为用例。

```sh
php tests/tcp-consumer.php "$TCP_CONSUMER_DIR"
php tests/udp-consumer.php "$UDP_CONSUMER_DIR"
```
