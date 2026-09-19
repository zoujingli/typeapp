# MQTT 专用 mTLS 入口

Swoole TLS 只开 1.2/1.3 并 `ssl_prefer_server_ciphers`，TLS 1.2 仅 ECDHE AEAD（`ssl_ciphers`），ECDH 限 `X25519:P-256`（`ssl_ecdh_curve`），同时提供两版本的客户端协商到 1.3，TLS 1.1、仅静态 RSA 或仅 P-384 握手失败；不调用 0-RTT API。原生监听开启 `open_tcp_nodelay`；IPv6 地址用 `SWOOLE_SOCK_TCP6`。mTLS 会话 Keep Alive 仍按完整 MQTT 控制报文的 1.5 倍超时。服务端证书文件第一份为叶证书，其后可跟中间 CA，客户端只信任根即可完成链校验。Swoole 仍可能用过期、尚未生效或 clientAuth 叶证书监听，Broker 启动前按有效期和 serverAuth 拒绝；已启动后服务端叶证书到期则断开 TLS 会话（DISCONNECT 0x8b）。Swoole 6.2 原生 Server 不接线 `ssl_passphrase`，加密私钥由 Broker 解密为临时钥再监听；口令错误或证书与私钥不匹配时拒绝启动。配置 SNI 主机时 `ssl_sni_certs` 按名字选用第二套证书链，过期或非 serverAuth 的 SNI 叶证书同样拒绝启动。Broker 另用 `openssl_x509_checkpurpose` 拒绝过期、尚未生效和 `serverAuth` 客户端证书；配置 PEM CRL 时核对其 CA 签名与有效期，并在文件更新后断开已列入的已连接会话。已连接会话在记下的证书 notAfter 到期后同样断开。管理员配置的 HTTPS CRL 源由 `Swoole\Process` 按间隔拉取并写入本地文件，失败保留旧列表。配置了 CRL 后文件缺失或过期则拒绝该 CA 新接入并断开已连会话，文件恢复且仍有效后重新接受。平台吊销名单不经 CA 签名，本进程内已接纳序列号只增不减。换证重叠由指纹窗口文件给出，默认及最长 24 小时，清零或到期后断开旧证书会话；吊销与到期无重叠宽限。客户端 CA 文件可为含根与签发中间 CA 的 PEM 包，CRL 用包内能核验签名的那份证书；未列入包的另一中间 CA 签发则拒绝。管理端登记 CA 与证书绑定见 [Broker 管理](broker-management.md)，换证重叠与旧连接退出见 [Broker 管理](broker-management.md)，管理端签名 CRL 与平台吊销见 [Broker 管理](broker-management.md)。目标平台和完整组合仍需分别验收。

## 复用与边界

| 能力 | 上游机制 | 本切片职责 |
| --- | --- | --- |
| TLS 版本 | `ssl_protocols` / `ssl_prefer_server_ciphers` / `ssl_compress=false` / `ssl_ciphers` / `ssl_ecdh_curve` | 只接受 TLS 1.2 与 1.3；TLS 1.2 仅 ECDHE AEAD；ECDH 限 X25519 与 P-256；双版本客户端协商到 1.3；拒绝 1.1、静态 RSA 与仅 P-384；不调用 early data API |
| TCP_NODELAY | `open_tcp_nodelay` | 主端口与附加 TLS/mTLS 监听都关闭 Nagle，避免小控制报文被合并 |
| IPv6 | `SWOOLE_SOCK_TCP6` | `--host` 为 IPv6 时主端口与附加监听都用 TCP6；IPv4 的 TCP 套接字不能绑定 `::1` |
| 服务端证书链 | `ssl_cert_file` / `SSL_CTX_use_certificate_chain_file` | 叶证书在前，其后中间 CA；客户端只配置根 CA 即可校验 |
| SNI | `ssl_sni_certs` | 可选一台小写域名对应另一套叶加中间 CA；缺省仍用主证书；过期或非 serverAuth 的 SNI 叶证书拒绝启动 |
| 服务端用途与有效期 | 启动期解析叶证书 notBefore/notAfter 与 EKU | Swoole 仍可能用过期、尚未生效或 clientAuth 证书监听，Broker 拒绝启动 |
| 加密私钥 | `openssl_pkey_export` 临时 0600 文件 | Swoole 6.2 `Server::set` 不接线 `ssl_passphrase`；Broker 启动期解密后交给 `ssl_key_file`，退出删除。错误口令或证书与私钥不匹配拒绝启动，口令不进日志 |
| 客户端证书链、有效期 | `ssl_verify_peer` / `ssl_client_cert_file` | 专用 `mtlsPort` 或配置了 `clientCa` 的 WSS 主端口；CA 文件可为含根与中间 CA 的 PEM 包；附加监听不能可靠启用校验 |
| 用途与有效期 | `openssl_x509_checkpurpose(X509_PURPOSE_SSL_CLIENT)` | Swoole 仍可能放行过期、尚未生效或 `serverAuth` 证书，Broker 在取指纹前拒绝 |
| 握手 CA 文件 | 同一 `Timer::tick(50)` 按文件 mtime/`size` 调用监听 `Port::set` | Swoole `Server::set` 在启动后拒绝。`Port::set` 重建 `SSL_CTX`（`SSL_CTX_load_verify_locations`），只影响新连接。节点心跳把当前受信 CA 公钥写入 `clientCa`。管理端登记见 [Broker 管理](broker-management.md) |
| 签名 CRL | 启动导入；Swoole `Timer::tick(50)` 按文件更新重载 | 已列入序列号的新连接不能取指纹；较新列表接纳后断开已连接的对应会话；thisUpdate 回退忽略；已接纳序列号只增不减；文件缺失或过期拒绝该 CA 并断开，恢复后可再接入；CA 包内任一份证书可核验 CRL 签名 |
| HTTPS CRL | `Swoole\Process` 阻塞 GET，写入 `clientCrl` | 启动期 https URL，默认 300 秒；不跟随跳转；失败不覆盖旧文件。客户端不能指定地址。不证明集群 5 秒 |
| 平台吊销 | 同一 `Timer::tick(50)` 按文件 mtime 重载 | 无签名序列号名单；已接纳只增不减；新连接拒绝，已连接断开。不必等待 CA CRL |
| 换证重叠 | 同一 `Timer::tick(50)` 按文件 mtime 重载 | 指纹加截止时间；缺截止默认 24 小时，上限 24 小时；清零或到期断开。吊销与到期无宽限 |
| 叶证书到期 | 握手写入 `validTo_time_t`；同一 `Timer::tick(50)` 每秒核一次 | 已连接 mTLS 会话在客户端 notAfter 到达后断开；不保存 PEM，不重读磁盘证书 |
| 服务端证书到期 | 启动记下主证书与 SNI 叶证书较早的 `validTo_time_t`；同一 `Timer::tick(50)` | Swoole 到期后仍可能握手；Broker 对已连 TLS 发 0x8b，并拒绝新的 TLS 接入 |
| 证书事实 | `onConnect` 的 `ssl_client_cert`，WSS 在 `onOpen` 套到连接 | SHA-256 指纹、序列号与 notAfter 写入连接，不保存 PEM |
| 认证 | `CertificateAccessPolicy` | 无 CONNECT 凭据可单独认证；同时提供凭据必须同一主体，失败不降级 |

未配置 `mtlsPort` 时行为不变。配置后要求 `ioDriver=swoole`，该进程改用 `Swoole\Server`（若同时开 WS 则仍是 `WebSocket\Server`）。Windows 启动期拒绝。

## 已验证

`composer test:mqtt-mtls`：

- 普通 TLS 端口：账号密码 CONNECT 成功，不要求客户端证书
- 服务端下发叶证书加中间 CA，客户端只信任根即可建立会话
- SNI `mqtt.sni.test` 拿到对应叶证书并可 CONNECT；`-servername 127.0.0.1` 仍用主证书；过期 SNI 叶证书无法启动
- 过期、尚未生效或仅 clientAuth 的服务端叶证书无法启动
- 已启动后服务端叶证书到期：已连 TLS 收到 DISCONNECT 0x8b，新会话不能再建立
- 加密私钥：错误口令或证书与私钥不匹配无法启动；正确口令可在 mTLS 入口建立会话，口令不出现在进程输出
- IPv6 回环 `::1`：凭据 TLS 与证书 mTLS 均可建立会话；服务端 SAN 含 `IP:::1`
- 同时提供 TLS 1.2/1.3 的客户端协商到 1.3；仅 1.2 协商到 ECDHE AEAD；仅 1.3 可建立会话；TLS 1.1、仅静态 RSA AES-GCM 或仅 P-384 握手失败；X25519 与 P-256 可完成密钥交换
- mTLS 端口：无证书或未登记 CA 签发的证书在握手失败
- 列入 CA 包的中间 CA 签发的客户端证书可建立会话；未列入包的另一中间 CA 签发则拒绝
- 已登记指纹但过期、尚未生效、`serverAuth` 或启动期 CRL 已列入：不能建立 MQTT 会话
- 已连接的有效证书：CRL 文件更新列入其后，5 秒内会话结束
- 配置了 CRL 后文件被移走：已连接会话断开，新连接拒绝；文件恢复且仍有效后重新接受
- 已连接的短时证书：notAfter 到期后会话结束
- 已连接的有效证书：HTTPS CRL 源更新列入其后，会话结束
- 平台吊销名单中的已登记证书不能建立会话；文件增列后已连接会话断开；缩短文件不能复活本进程已接纳序列号
- 未登记指纹在重叠窗口内可建立会话；清零窗口后已连接旧证书断开，新连接拒绝；吊销证书写入重叠窗口仍被拒绝；窗口可再次打开
- 已登记指纹：无用户名密码 CONNECT 成功
- mTLS Keep Alive=1 在约 1.5 倍后收到 DISCONNECT `0x8d`；半包 PINGREQ 不能延长该截止
- 正确证书加正确密码成功；正确证书加错误密码返回 `0x87`，不降级为仅证书
- 同进程 mTLS 发布到达 TLS 订阅者
- 配置 `MQTT_CLIENT_CA` 的 WSS：无客户端证书、过期、`serverAuth`、CRL 已列入或平台吊销名单已列入不能建立 MQTT 会话；证书单独认证后发布到达同进程 TLS 订阅者

## 未验收

- 失联节点 5 秒集群完成证明
- Linux x64、Linux ARM64、Windows
- 本切片未重跑独立消费者原生产物
- 管理端导入/展示 CRL 与平台吊销见 [Broker 管理](broker-management.md)
- 管理端握手 CA 热加载与监听滚动见 [Broker 管理](broker-management.md)
