# 有界原生 UDP 接入

UDP 客户端与服务端使用统一公共契约。各目标平台须分别验证真实数据报、超时、取消与资源释放。

## 复用与职责

核心此前没有 UDP 功能源码或生产调用者，不能以清理名义删除 HTTP/MQTT 的不同协议实现。本次在 `type-core` 增加一个 `UdpSocket`，由独立消费者和 echo 示例实际调用；不新增通用 Client/Server 管理器、后台收包队列或私有分包协议。

资源由 `start()` 打开，调用方用作用域或 `finally` 显式关闭。`stop()` 后禁止重启原实例。被原生取消唤醒的业务可以捕获失败，但在控制方取消调用栈尚未返回时不能抢先归还该端点额度；若其他平台的实际完成迟到，端点继续保留 `stopping/allocated`，由原收发 `finally` 开始关闭。角色退出必须按既有作用域、任务收尾、事件循环排空和线程 join 契约进行。

## 报文、预算与失败

默认单报文上限 8192 字节，可配置至 65507 字节；普通 IPv4 和 IPv6 采用同一上限，不接收 IPv6 jumbogram。零长度数据报不是 EOF。发送返回完整长度只表示本地系统接受了提交，不表示远端收到或业务持久化；没有可靠重传、自动排序或广播/组播策略。UDP 不能辨认网络中属于旧业务代次的迟到报文；需要此语义的业务必须在载荷中携带自身协议标识，不能靠端口重用推定代次隔离。

固定原生 `recvfrom()` 为每个在途接收分配 65536 字节，普通 UDP 报文不会因较小的应用上限被静默截断。超过应用上限的接收被完整消费后抛出 `udp_datagram_too_large`，下一次接收从新报文开始；同码的发送拒绝发生在原生提交之前。原生 `EMSGSIZE` 单独报告 `udp_message_size`，包括平台报告的超长或截断错误，不返回成功片段。原生无法提供完整来源时报告 `udp_peer_unavailable`。

`socket_buffer_size` 支持 64 KiB–1 MiB，默认 64 KiB。原生同名设置没有完整传播两次 `setsockopt` 的失败，本入口直接逐项调用 `setOption(SO_RCVBUF/SO_SNDBUF)` 并检查结果，再读取实际值。Linux 可能双倍报告配置值；每个方向预留至多配置的两倍，超出即拒绝启动。IPv6 使用 `IPV6_V6ONLY=1`，没有每个平台独立实现的网络循环。

同一端点一次只有一个收发，应用排队报文数为 0。在途接收分配 65536 字节；原生 Socket 初始化启用 zero-copy，固定 POSIX UDP 发送直接持有调用载荷。跨后端的容量计划保守预留原生两个方向各 65536 字节及至多一个配置上限的载荷；`statistics()` 中两个 native 字节字段是这个静态预算，不是当前分配量。按每个端点 `4 × socket_buffer_size + 2 × 65536 + maxDatagramBytes` 计算网络缓冲和在途载荷上界，再乘最大线程/进程/副本/滚动代次的端点总额。该公式不声称包括内核结构、分片重组缓存、PHP 对象元数据及业务自己保留的已返回报文；这些继续受 OS 与应用各自预算约束。内核接收队列由实际 socket 缓冲限制，饱和允许丢包，项目不增加缓存来掩盖丢包。

接收期限和 `write_timeout` 都使用 `(0,60]` 秒，等待由原生调度；不提供零值代表无限等待的隐式约定。选项缺失使用默认值，显式 `null` 按无效配置拒绝。`udp_timeout`、`udp_cancelled`、`udp_stopped`、`udp_send_failed`、`udp_receive_failed` 保留各自原因和原生 errno。发送失败或停止不能证明远端没有收到；业务确认仍属于调用协议。

## 空报文原生修复

固定上游为 Swoole `0f3bee2f0ed8704ce33a336e7feabb0115411dd7`（版本字符串 6.2.1）。`ext-src/swoole_socket_coro.cc` 原文 SHA-256 为 `0bf446e5d66184507c13e664c165eb9d83e296a105cf5a1e80cf8b994c1a8b4c`。同进程中使用独立的 ext-sockets 原生发送入口复现：IPv4、IPv6 的非空报文正确，空报文返回空串但地址引用仍保留调用前的哨兵。

`getpeername()` 查询的是已连接对端，不能取得未连接 UDP 空报文来源。sockets hook 最终仍调用上述 Socket 方法。协程 Client 的 socket 懒创建且没有直接绑定入口，sendto 又拒绝空载荷；stream UDP hook 在固定源码中还有 IPv6/Windows 路径缺口，不能用假发送、解释库或同步路径绕过。

`Type\Build\SwooleSocketSource::apply($isolatedSourceDirectory)` 只在原文摘要和唯一替换均匹配时修改一个方法：所有成功接收先写回 peer，再返回空串或非空串。原生收发、等待和协议保持不变，没有私有 ABI。与独立的 `SwooleThreadSource` 分别显式应用，不把线程构建绑定到文件 I/O 候选或无关网络特性；未自动安装或替换共享 SDK。

当采用的上游版本原生返回 IPv4/IPv6 空报文地址，并通过同一公共接口消费者后，撤除这一源适配；不能只改变固定摘要后继续打补丁。macOS 构建沿[已验证的标准 TLS 链接配置](mqtt-native-client.md#macos-原生-tls-链接)，不另加 TLS 补丁。

## 协程启动时的内置库缺口

以下保留当时关闭库的故障与修正证据；`compiled-udp.php` 的三处未加载检查服务于该显式关闭场景，不是新标准的通用要求。 已批准固定官方库正常加载，当前开启模式的线程与协程实证见[编译业务线程](compiled-business-threads.md)；本页 UDP 组合不能沿用旧结果宣称新模式已验收。

最终复验曾在双业务线程启动时挂起，原生采样显示 `_compile_string` 反复递归。固定上游 `PHPCoroutine::activate()` 在首次激活时不检查 `swoole.enable_library`，即使关闭该选项仍调用 `php_swoole_load_library()`。无需 TypeApp 或 UDP 的最小程序也能稳定复现：关闭选项、创建一个空协程后，`SWOOLE_LIBRARY` 和 PHP 实现的 `Swoole\ConnectionPool` 都出现。内置 `_eval()` 还会交换进程共享的 `zend_compile_string` 指针；并发首次加载存在把旧钩子保存成自身的竞态，与挂起采样一致。

## 验证入口

准备匹配锁文件的 PHP ZTS/TypePHP/PHPX 与已修模块，将 `PHP_HOME`、`PHPX_HOME` 指向受控工具链。在主仓根以显式绝对路径传入尚不存在的 `build/` 子目录：

```sh
php tests/udp-consumer.php "$PWD/build/udp-consumer-local"
```

该入口复制安装六个生产包，连同消费者与示例完整 AOT，随后对同一产物运行两轮双业务线程、主线程协程和示例客户端。对端使用 Node 标准 `dgram`，不加载 TypeApp；控制器停止并等待所有进程。macOS 内核禁止原产物读取主仓和消费者的生产/生成源码及 Composer 入口。`--verify` 仅复验给定产物，不重新构建。

场景覆盖 IPv4/IPv6、空报文、二进制、应用边界、65507 字节报文、超限后的继续收发、绑定冲突、端点额度饱和、独立协程进度、取消、取消栈内额度、幂等停止、端口重用与线程重建。256 报文突发记录实际收到的完整报文，允许内核丢包；这不是吞吐或延迟基准。
