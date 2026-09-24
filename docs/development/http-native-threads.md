# HTTP 原生线程接入

本页承接 [Swoole 复用标准](../standards/swoole-reuse.md)，说明编译业务线程如何复用 Swoole 协程 HTTP、原生连接准入及既有 PSR 处理链。当前主仓物联中心的生产 HTTP 已由 `ThreadSupervisor` 管理：Unix 按配置线程数复制同一原生监听，线程调用 `serveThread()`；Windows 分支使用一个业务线程自行监听，调用 `serveThreadOwned()`，不依赖尚未验收的 IOCP 共享监听。主控负责停止和真实 join，请求及连接额度由各线程自己的宿主管理。

主仓开发入口、Broker 管理 HTTP 和通用模板仍调用 `serve()`；该方法在 Unix 使用经典 worker，在 Windows 使用协程 HTTP 与控制事件桥。以下保留初始接缝设计与独立消费者的专项记录，其中候选状态和后续接入项对应当时的验证范围；不能以接缝结果推定当前完整应用或所有平台通过。当前工具链、应用产物和未完成项统一见[平台与验收](../guide/platforms.md)。

## 复用与最小缺口

| 调用者 | 固定上游机制 | 项目接缝与保留责任 |
| --- | --- | --- |
| `CoroutineRuntime::startThread()` | `Swoole\Thread` 的 `ZendArray` 已可复制 Coroutine Socket；Unix 使用 dup，Windows 使用 WSADuplicateSocket/WSASocket。 | 原生 AOT 入口新增一个可选 Socket 参数，保持原来的登记与消息上限。仅传 Socket 时检查私有能力；原两参数入口不依赖 HTTP 或 FILE 补丁。 |
| 线程 HTTP 监听 | `Swoole\Coroutine\Http\Server` 的原生 accept、onAccept、HTTP parser、Request/Response、shutdown。 | 固定版只有自行 bind/listen 的构造器，没有公开 Socket 导入；增加受限的 `fromSocket()`，不访问私有 onAccept，不自写解析或接收循环。 |
| PSR 请求 | `SwooleServer` 原有转换、错误、发送、`HttpControl` 与 `ExecutionScope`。 | 原私有响应方法成为内部 `handleNative()`，经典和协程 HTTP 共用一份实现；每次响应完成前清理请求，不依赖 keep-alive 连接协程退出。 |
| HTTP 连接额度 | 原生 `zclients` 连接表、Coroutine Channel 与 Socket 的 Event defer 关闭。 | 显式连接上限在 accept 前挂起；登记包含 TLS 握手，FD 关闭完成后再删除表项。没有另建连接表、调度器或扫描回收器。 |

固定上游为 Swoole 6.2.1 / `0f3bee2f0ed8704ce33a336e7feabb0115411dd7`。macOS 诊断中两个 `reuse_port=true` 的原生服务均绑定成功，但三轮每轮 100 个请求全部进入后绑定线程；调换顺序仍跟随后绑定者，不能作为当前单端口分配方案。改用同一原生监听的线程副本后，共享唤醒存在 EAGAIN 竞争；上游 HTTP accept 循环把它当作失败退出，候选将其并入既有重试分支。TCP 共享监听和纯原生 HTTP 诊断分别保留，不充当 AOT 或 PSR 验收。

原生 [Socket 传递](https://github.com/swoole/swoole-src/blob/0f3bee2f0ed8704ce33a336e7feabb0115411dd7/ext-src/swoole_thread.cc)及 [HTTP 接收和关闭](https://github.com/swoole/swoole-src/blob/0f3bee2f0ed8704ce33a336e7feabb0115411dd7/ext-src/swoole_http_server_coro.cc)仍由上游维护。Windows 的描述符复制存在不代表多 IOCP 共享监听已经成立；该组合等待真实目标验收，不能提前写成支持或不支持。

## 显式适配与所有权

`Type\Build\SwooleHttpSource::apply($directory)` 只接受独立解压的固定原文，并核对文件摘要与唯一替换位置：

| 文件 | 原文 SHA-256 |
| --- | --- |
| `ext-src/swoole_http_server_coro.cc` | `711fa3732141b3b90bf098157936729003bcaebe74f7bc9fa0b8e130a98e47d2` |
| `ext-src/stubs/php_swoole_http_server_coro.stub.php` | `c656a32f9758ac831d2213ed6fe64aac9dffc3e1211f90f853786cfa2edc992a` |
| `ext-src/swoole_http_request.cc` | `33719a36f822333338d46a686a59de89db2f7872b2d73ca442e6a912d74cb9a2` |
| `ext-src/php_swoole_http.h` | `aa879b1cb2dcab56ee53956d4242cfdb65c4a7030d977db158ae21289d8a8b05` |

该适配与 `SwooleThreadSource` 分开应用，不组合进默认线程或 FILE 准备。监听传递的两项 TypeApp 私有能力是 `Thread::TYPEAPP_SOCKET_ARGUMENT_ABI=1` 与 `Coroutine\Http\Server::TYPEAPP_LISTENER_ABI=1`，不是官方 API；原 `NATIVE_ENTRY_ABI=2` 保持不变。准备后用显式模块和既有 `tools/configure-toolchain.php` 建立 SDK 视图，不覆盖原 SDK。

`Server::fromSocket(Socket $socket, int $backlog=0)` 要求当前线程没有协程读写绑定的、打开且已绑定非零本地端口的 TCP/TCP6 Socket，backlog 为 0 到 INT_MAX。它直接调用上游 `Socket::listen()`，沿用 backlog 默认值并恢复复制后本地 Socket 的监听及 TLS 服务端标记。曾使用 `SO_ACCEPTCONN` 核对状态，但 macOS 的原生 getsockopt 返回 ENOPROTOOPT；该不可移植探针已撤回，失败原件保留。

服务持有当前线程 Socket 的引用；调用者在 `start()` 结束后关闭并释放副本。原对象和其他线程副本各有自己的所有者，不能在同一线程为同一 Socket 同时启动多个读者。构造器不能对已初始化服务重复初始化。原构造器仍拥有自己创建的 Socket；借用的 Socket 不由 HTTP 对象直接 delete。上游 FD 释放使用 Event defer，因此端口回收检查须在最后引用释放和 `Swoole\Event::wait()` 之后执行。

适配维护责任归 `type-build`，运行入口归 `type-runtime`，PSR 边界归 `type-core`；升级固定上游时重验。上游提供等价导入方式并正确处理共享 accept 竞争后，撤除对应补丁及私有能力检查，重新运行相同行为。连接预算另按下节的独立缺口与撤除条件维护，不能借本候选扩建第二套协议引擎。

## 显式连接预算

固定上游协程 HTTP 不执行经典 `max_connection`。原生诊断分别将 `max_coroutine` 设为 5 和 9，在监听与提前建立的控制协程占用两个名额后，实际接入 3 和 7 条连接；两种配置均让满额时的受管子任务因协程额度不足失败。仅调整总协程上限无法同时保证独立连接总额和子任务预留。

候选新增 TypeApp 私有 `Coroutine\Http\Server::TYPEAPP_CONNECTION_LIMIT_ABI=1` 及 `typeapp_max_connections`。后者只接受 1 至 100000 的整数；显式 null、字符串、浮点和布尔均拒绝，未配置时保持原生无独立限额路径。Swoole 常用配置读取宏忽略 null，因此此键直接检查哈希中的存在性。它不是官方选项，也不将 `max_connection` 改名映射成另一种含义。

达到连接份额时，原有 accept 循环在原生容量为 1 的 Channel 上等待。后续连接仍受系统监听 backlog 约束，可能完成 TCP 握手但尚未被应用接收；这不等于收到 HTTP 503，也不计作已接入的应用连接。请求份额耗尽后的 HTTP 503 仍由 `HttpControl` 负责。

连接在 TLS 握手前进入原生表。连接协程退出后的第一次 Event defer 等待其参数释放，再丢弃表内的 Socket 引用，保留空槽占额；Socket 析构把实际 FD 关闭排入上游队列，后续 defer 才删除空槽并唤醒准入。事件回调不处于协程上下文，必须用原生 `Channel::push_data()`，不能用会检查当前协程的 `push()`。所有者对象在这两次回调间保持引用；没有提前复用尚未关闭的 FD 额度。

消费者由主控将请求总额 4、连接总额 16 分给最多两个同时存在的业务线程，连接分额复用 `DeploymentBudget`。每线程请求份额为 2、连接份额为 8；`max_coroutine` 为连接数加受管子任务预留，再加提前建立的监听与控制协程，当前为 14。退役线程 join 后才补位，因此新旧代不叠加额度。这是消费者中的真实预算分配，生产部署配置、独立监督及故障下的额度回收仍须接入。

上游提供覆盖 TLS 握手、真实 FD 完成、满额停止和子任务预留的等价连接限制后，撤除此准入补丁与私有配置，并在相同消费者重新验收。原生 TLS、解析器、WebSocket 与 HTTP 响应机制仍归 Swoole；本补丁不证明 HTTP 安全输入或全部协议已经完成。

## HTTP/1 输入与信任边界

固定上游的 `http_request_on_header_value()` 对 Host、Authorization、Content-Type 等常用头直接覆盖旧值。真实 TCP 诊断中，非法 Host/令牌在前、合法值在后的两行输入都进入业务并返回 200；解析后的 RequestPolicy 和 Authentication 已无法看到前值。`Request::getData()` 只提供原始 HTTP/1 字节，若用它重新识别头就会引入第二套解析；`Request::create()` 的解析选项也不保留这些重复值。

显式 `typeapp_http1_input=true` 在原生回调覆盖前拒绝重复 Host、Authorization、Content-Type，大小写不影响判定，错误沿用上游 400 与连接关闭。其他头使用上游已有的 `array_add_or_merge` 保存多值，不建立镜像表；Content-Length 重复以及 Content-Length/Transfer-Encoding 冲突仍由 llhttp 拒绝。此选项只接受布尔值，私有能力标识为 `Coroutine\Http\Server::TYPEAPP_HTTP1_INPUT_ABI=1`。开启时限定 HTTP/1.0 与 HTTP/1.1，HTTP/2 前言返回 505，避免另一条解析路径绕过本候选；未开启时不改变上游解析行为。它不代表 HTTP/2 已完成输入安全适配。

调用 `handleNative()` 的监听方须设置原生 `http_parse_cookie=false`，保留 Cookie 字符串或多行数组。Cookie 校验由既有 RequestBody 统一处理：重复名称返回 `invalid_cookie`，超过字段数量返回 `too_many_cookies`，继续使用原来的名称校验及 URL 解码语义。SwooleServer 的转换直接传递原生多值头和 `server_protocol`，不再把数组转成 `Array`；缺失 Host 明确拒绝，不能由 URI 构造器补出可信的 localhost。

原生修改归 type-build，PSR/输入策略仍归 type-core。经典 `serve()` 已复用 Cookie 与 PSR 转换，但没有获得本协程候选的关键重复头拒绝；默认切换仍待完整验收。上游提供等价的重复头保留/拒绝机制后，撤除输入补丁及私有配置，重跑相同 TCP 与源码禁读 AOT 回归。新 HTTP/2 或 WebSocket 路径须各自验证输入和升级授权，不能继承本节结论。

### 生产线程生命周期

`SwooleServer::serveThread(Socket $listener, Thread\Map $state)` 在已编译业务线程的同步入口执行一次，拥有该线程的事件循环与监听副本。它复用 `Server::fromSocket()`、原生选项、`handle()/start()/shutdown()` 及既有 `handleNative()`；原消费者中的监听配置、协程数量、停止和错误结算已迁入此处。`HttpControl` 的份额仍由部署入口明确分配。

原生 Timer 每 20ms 更新进度并检查主控停止标记及请求清理；停止先撤销就绪、缩短在途请求截止，再调用原生 shutdown。已进入的响应沿原请求作用域完成，事件循环退出后核对原生错误与请求清理，再调用已有 `onWorkerStop` 同步关闭回调。子线程不能重复安装进程 hook，也不能在入口内注册角色级信号或自行释放主控句柄。

`Type\Runtime\ThreadSupervisor` 独占角色主线程与事件循环，使用已有 `ProcessSignals` 接收停止，`CoroutineRuntime::startThread()` 创建有限组，原生 Timer 调用 `joinWithin(0)` 探测真正完成。每线程仅有一个原生 Map、固定四个标量键：主控写 `stop`，业务写 `ready/pulse/failed`；没有业务消息队列、另建线程池或调度器。线程句柄一直保留到 join 成功，部分启动失败也先停止并回收已经创建的线程。

启动、事件循环进度和停止各有有限期限；任一线程意外退出或报告清理失败就停止整组，全部回收后报告原错误。停止期限包括 PHP 请求关闭及 C++ TLS 析构；仍不可回收时，编译的进程控制入口调用标准 C++ `_Exit(75)` 结束该角色，跳过会再次阻塞的析构。没有自动重启；现有部署管理器决定进程恢复。主控不得执行业务 I/O；这不是硬实时保证，也不能覆盖主控本身阻塞或系统停止调度。

事件循环进度不证明其中每个异步 I/O 已完成，因此必须保留永久挂起保护。动态退役补位、完整应用/模板装配及各角色自己的操作截止仍须分别验证。测试中的控制文件仅连接外部驱动；生产控制状态不经文件轮询传递。

### 命令与观察范围

在主仓根使用匹配的 ZTS PHP、`PHP_HOME`、`PHPX_HOME`，`COMPOSER_BINARY` 指向实际 Composer PHP 脚本：

```sh
php tests/http-threads.php "$task_http_consumer"
php tests/http-threads.php "$task_http_consumer" --verify
```

第一次参数必须是 `build/` 下尚不存在的专用目录。消费者复制安装 `type-core`、`type-runtime` 与构建依赖，六个生产包全部 AOT；macOS 使用源码禁读策略并先证明策略有效。同产物三轮观察双线程请求、keep-alive 逐请求资源及子任务清理、PSR 字段、HEAD/POST、错误恢复、请求超量拒绝、退役、重建、join 及端口释放。控制文件仅用于外部验收协调，不作为生产线程监督机制。

当前消费者另外观察两线程各 8 条 keep-alive 连接、满额子任务、第 17 条连接等待及释放后的补位。共享监听不保证均匀分配或逐请求轮转，最终各 8 条来自明确的份额限制。TLS 链接核对、明文 HTTP 成功及真实 HTTPS 行为分别记录。

仍需生产预算接入、路由/授权/容器和完整输入边界、在途取消/退役、独立监督与应用/模板接入；TLS、上传、下载与 WebSocket 仍需完成对应入口的组合验证。其他平台、完整应用新路径和同负载性能对照属于后续验收，不能由本接缝通过推定完成。
