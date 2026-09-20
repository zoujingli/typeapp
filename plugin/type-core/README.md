# type-core

提供字符串与嵌套配置快照、命令执行、同步事件，以及 HTTP、WebSocket、TCP、UDP 四项基础通信。各协议保持自己的公开入口与数据语义，通过 type-runtime 的执行作用域和资源预算管理生命周期。

| 能力 | 服务端 | 客户端 | 数据语义 |
| --- | --- | --- | --- |
| [HTTP](#http) | `Http\SwooleServer`；共用服务时为 `WebSocket\Server::onRequest()` | 当前尚无独立通用 HTTP 客户端封装 | 请求、响应；PSR 处理链按入口装配 |
| [WebSocket](#websocket) | `WebSocket\Server` | `WebSocket\Client` | 持续双向消息 |
| [TCP](#tcp) | `TcpSocket::listener()` | `TcpSocket::client()` | 有序字节流，由业务定义消息边界 |
| [UDP](#udp) | `UdpSocket` 绑定本地端点 | 同一 `UdpSocket` 接口 | 独立数据报及来源地址 |

使用选择、共用监听、作用域和平台边界集中见[基础通信指南](https://github.com/zoujingli/typeapp/blob/main/docs/guide/communications.md)。

## 安装与版本

本组件通过公开 Git 分发子仓安装，不假设已发布到 Packagist。先在应用的 Composer 根配置登记下列组件及传递依赖仓库；HTTPS 读取不需要 SSH 密钥，依赖包自己的 repositories 不会自动传递给消费应用。

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer config repositories.type-core vcs https://github.com/zoujingli/type-core.git
composer require zoujingli/type-core:dev-main
```

`dev-main` 的分支别名为 `1.0.x-dev`；本仓组件间使用 `~1.0.0@dev` 约束。开发分支不等于已发布稳定 1.0 版本。提交应用的 `composer.lock` 固定实际分发提交；构建工具只放 `require-dev`。详细依赖与公开分发规则见[组件组织与安装](https://github.com/zoujingli/typeapp/blob/main/docs/development/component-structure.md)。

- `Configuration` 保存不可变的字符串快照，环境变量在应用启动时读取，合法空字符串和 `0` 不被默认值覆盖。
- `Command::run` 接收配置快照与命令参数，返回进程退出码；业务失败可抛异常。
- `Events` 按注册顺序同步通知 `Listener`；异常中止当前通知并向调用者传播。
- `Application` 按声明顺序启动 `ManagedResource`，执行命令后逆序停止；启动、命令或监听器失败同样清理。

资源构造函数只接收依赖、准备状态，实际外部资源在 `start` 中打开。`stop` 必须允许 `start` 部分失败后的收尾；一个 `stop` 失败不会阻止其余资源关闭。只有一个失败时保留原异常，业务与清理同时失败时合并说明并保留原业务异常作为原因。

命令装配由 type-build 生成直接构造调用。`help`、`check` 不构造业务服务，也不连接外部资源；安装库本身不会启动模块钩子。

## HTTP

HTTP 使用 PSR-7/15/17 接口。`Http\Message\Factory` 创建消息和流，`Http\Router` 接收静态路由或生成路由，以及每次执行的处理器与中间件工厂。HTTP 传输固定复用 `Http\SwooleServer`，网络与基础并发必须使用 Swoole 原生 Server、协程和 hook。进程不可用时按目标构建能力使用官方线程或协程，并分别记录原生验收结果。

`SwooleServer::handleNative(Request, Response)` 是内部协作入口，让当前线程的原生 HTTP 回调复用上述 PSR 转换、错误映射、响应发送及逐请求清理；经典 `serve()` 同样调用它。监听方仍负责连接额度、就绪、排空和线程监督，不能只注册这个回调就视为完整线程服务。显式候选及实际验收见[HTTP 线程接入](https://github.com/zoujingli/typeapp/blob/main/docs/development/http-native-threads.md)。

显式 `SwooleServer::serveThread($listener, $state)` 在编译业务线程中复用原生协程 HTTP 的配置、监听、停止及连接回收，沿用同一 PSR 链。监听副本与事件循环由该入口拥有；`HttpControl` 接收部署入口已分配的请求/连接份额，满额连接不占用受管子任务的预留。`$state` 是 `ThreadSupervisor` 提供的原生控制 Map，主控独立负责停止期限及最终 join。正常排空后执行已有 `onWorkerStop` 同步回调；无法完整收尾则报告失败，不能提前复用资源。主仓物联中心的生产 HTTP 已使用该入口，开发 HTTP、Broker 管理 HTTP 和通用模板 HTTP 使用经典 `serve()`；各平台与输入/流边界分别验收。

处理器、中间件和生成路由的控制器工厂均为零参数闭包；`ActionHandler` 的动作接收一个 `ServerRequestInterface` 并返回 `ResponseInterface`。`Authentication` 的认证器签名为 `(string $token): ?Identity`，授权器为 `(Identity $identity, CanonicalRequest $request, string $method): bool`；`TenantResolver` 的授权器为 `(Identity $identity, Tenant $tenant): bool`。TypePHP 严格检查实参数量，未使用的上下文参数仍需显式声明。

认证器可用 `new Identity($subject, $roles, $attributes)` 传递已验证的身份元数据，`attributes(): array` 返回值副本，供应用记录会话来源和审计。组件不解释业务字段，不从客户端自报数据构造可信来源；元数据不代替每次请求的当前授权检查，禁止存放口令和可认证令牌。

控制器注解与 `config/route.php` 支持参数约束、嵌套分组、资源、命名路由和反向 URL，统一生成 `Http\RouteDefinition`。构建阶段检查名称与参数歧义，生产请求没有 Attribute 扫描；公开行为、真实 PHP HTTP 与原生验收的当前边界见[统一路由说明](https://github.com/zoujingli/typeapp/blob/main/docs/development/routing.md)。

独立安装会同时获得 PSR 接口和准确版本的编译适配；全量编译仍审计每个生产包，不能通过遗漏自动加载源码完成构建。

本包第一方源码按 Apache-2.0 提供；仓库可见性、分发批次和稳定版本仍由维护者按发布门槛管理。第三方 PSR 接口和 Swoole 依赖保留各自许可证。

## 声明式使用示例

以下代码可保存为应用的声明式入口；不在全局加载业务文件或自动启动服务，编译时与生产依赖一起纳入 AOT。

```php
<?php

declare(strict_types=1);

use Type\Core\Config\Environment;
use Type\Core\Config\Repository;

/**
 * 只读取启动配置，示例不会连接外部服务或加载配置 PHP。
 */
function main(): void
{
    $environment = Environment::load(null);
    $configuration = new Repository([
        'app' => [
            'name' => $environment->get('APP_NAME', 'type-example'),
            'port' => $environment->get('APP_PORT', 9501),
        ],
    ]);
    echo $configuration->text('app.name') . ':' . $configuration->integer('app.port') . "\n";
}
```

## 接口与源码组织

`src/` 根是既有 `Application/Command/Configuration/Events/Listener` 公共入口及原生 `TcpSocket/UdpSocket`；`Config/` 承担嵌套配置与环境数据；`Http/` 负责路由、接入安全、上传、背压和 HTTP 生命周期；`Http/Message/` 是 PSR-7/17 消息与流；`Http/Attribute/` 是构建期路由声明；`WebSocket/` 提供消息与连接会话双端。旧 `Type\Core\Configuration` 不改名，新的配置接口不偷换其字符串快照语义。

`Config\Environment::load(?string $file)` 明确选择外部 dotenv 文件，`null` 不自动搜索。`get()` 的进程环境优先于文件，按标量默认值执行受限类型转换，不 `putenv()`。`Config\Repository` 以点路径读取不可变快照，`has()` 区分不存在与已声明 null，`text/integer/boolean/array` 严格检查类型；范围约束仍由应用决定。配置文件编译和秘密不进入产物的约束见下方配置说明。`HttpControl` 的就绪/排空不是业务数据库结构已迁移的证明。

文件所有者已取得一致快照时可用 `Environment::parse($contents)` 复用同一有界dotenv解析；第二参数false仅检查文件自身，不更改全局环境。调用编译配置的 `get()` 后，`describe()` 返回所消费声明的类型、来源、文件是否声明、进程覆盖只读原因及未声明键，不返回值或默认值。文件路径、私有副本、并发锁、原子保存及实际依赖验证由应用配置所有者承担，组件不引入进程管理或业务配置仓库。

## AOT 与运行要求

Composer 安装核心必须满足 `ext-swoole >=6.2 <7`，Swoole 是通信和基础并发的硬依赖。HTTP、WebSocket、TCP、UDP 网络入口复用 Swoole 原生能力；允许固定官方内置 PHP 库。各协议按目标平台实际构建能力选择 Server、协程 Server 或 Socket，分别完成原生验收。AOT 仍需匹配的 PHPX/libphp 与实际使用的原生扩展，PSR 接口及整个核心源码一起编译。

当前 `HttpServerInterface` 只管理 HTTP 请求与响应。`SwooleServer` 对带 `Upgrade` 的请求返回 `501 / upgrade_not_supported` 并关闭连接。需要 HTTP 与 WebSocket 共用服务和端口时，由一个 `WebSocket\Server` 实例持有监听，通过 `onRequest()` 显式处理普通 HTTP 请求。

Swoole 的请求在实际 worker 中执行，该进程不会返回主进程的 `serve()` 调用。进程级连接池和日志可通过 `SwooleServer(..., onWorkerStop: $cleanup)` 注册零参数关闭回调；回调只在请求排空后的 worker 同步停止阶段运行，不应再启动异步工作。主进程自己的资源仍由调用方在 `finally` 关闭；SIGKILL或未排空的强制退出不能保证执行回调。两个进程的资源所有权不能互相代替。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## WebSocket

`WebSocket\Server::create($budget, $ip, $port, $options)` 创建监听，`WebSocket\Client::create($budget, $host, $port, $path, $tls, $headers, $maxMessageBytes, $connectTimeout, $tlsOptions)` 创建客户端。握手、掩码、分帧重组、控制帧、关闭握手与 TLS **全部由 Swoole 原生持有**，本组件只负责升级授权、连接会话、每条消息的 `ExecutionScope`、发送排队上限和关闭结算，不实现 RFC 6455 的帧解析或状态机。

同一服务实例用 `onRequest()` 处理普通 HTTP 请求，用 `onOpen()`、`onMessage()`、`onClose()` 处理 WebSocket 连接与消息，共用监听地址、端口、原生连接上限和生命周期。登记回调后调用一次 `start()`；`stop()` 停止整个共用服务，`disconnect()` 仅关闭指定 WebSocket 连接。明文监听提供 HTTP/WS，启用 TLS 后同一监听提供 HTTPS/WSS。

`onRequest()` 接收 Swoole 原生请求和响应，未登记时普通请求返回 `404 / not_found`。PSR 消息转换、路由、HTTP 策略与请求资源清理需要应用显式装配；Upgrade 由 Swoole 握手入口处理，不自动经过普通 HTTP 回调和中间件。`onOpen()` 在握手完成后提供连接标识、请求头与 Origin 核对结果，没有 URI 路径或声明式 WebSocket 路由。共用监听不会自动获得 `SwooleServer::serveThread()` 的业务线程模型，见[共用服务指南](https://github.com/zoujingli/typeapp/blob/main/docs/guide/communications/websocket.md#http-与-websocket-共用服务)。

`onMessage` 登记的回调以第四个参数接收本条消息的作用域：数据库连接必须挂在其上，回调返回后立即关闭，即使对端仍连接或尚未读取回显，事务也不会继续占用。当前服务端使用单 worker、顺序回调，消息作用域不自动创建协程，慢回调会影响共用服务内的 HTTP 和 WebSocket 处理；客户端的业务处理作用域由调用方管理。每条连接同一时刻只有一个消息所有者；`send($fd, $data, $binary, $seconds)` 对单条消息与排队字节双重设限，超限即关闭该连接而不静默丢弃。控制帧（PING/PONG/CLOSE）不进入业务消息路径，也不延长任何业务超时。

WSS 通过 `open_ssl=true` 以及成对的 `ssl_cert_file`/`ssl_key_file` 启用，仅允许 TLS 1.2/1.3，不启用压缩或 0-RTT；本进程终止 TLS，四层透传同样走这一套证书，七层反代终止 TLS 后转到本进程时保持 `open_ssl=false`。客户端 `tls=true` 时强制校验证书链和主机名，可用 `ssl_cafile`/`ssl_host_name`。目标平台按实际构建能力选择 Swoole WebSocket Server 或协程升级入口。

边界与默认值：帧上限与排队上限各为 1 B–1 MiB（默认 1 MiB），连接数 1–4096（默认 64），`package_max_bytes` 必须介于帧上限与两倍之间，`message_seconds` 为 (0,60] 秒（默认 30）。期限均为 (0,60] 秒。客户端 `receive($seconds)` 返回原生已重组的一条完整消息，`null` 表示关闭或超时，不代表收到空消息。

服务端可对 `subprotocol` 与 `allowed_origins` 设策略：**Swoole 原生的 `websocket_subprotocol` 会无条件回显配置值**（客户端不发或发其他值时也回显），因此本组件在握手完成时另行核对客户端是否真的提供该子协议，未提供按 1002 关闭；Origin 存在时必须与白名单精确匹配，不匹配按 1008 关闭。**Origin 缺失不直接拒绝**——那是非浏览器标准工具的常见情形，`onOpen` 第三参数为 `false` 交由业务鉴权。

可编译示例如 [examples/websocket/main.php](https://github.com/zoujingli/typeapp/blob/main/examples/websocket/main.php)。握手、分片、二进制、控制帧、上限、HTTP 共存、子协议/Origin、WSS 与消息作用域均由 Swoole 原生入口承载；各平台的 PHP、AOT、TLS 和资源回收结果按实际构建产物分别记录。MQTT over WS 由 `type-mqtt` 自行复用 Swoole，不依赖本组件。

## TCP

`TcpSocket::client($budget, $host, $port, $maxChunkBytes, $options)` 创建客户端；`TcpSocket::listener($budget, $ip, $port, $maxChunkBytes, $options)` 创建监听。两者在所属线程的已有协程中用 `ExecutionScope::open()` 启动；也可以显式 `start($seconds)` 并在 finally 中停止。默认片段上限为 65536 字节，启动合计截止为 5 秒，覆盖 DNS、连接和 TLS。数字 IPv6 直接使用 AF_INET6，域名沿原生 AF_INET DNS 解析；监听仅接受数字 IP，端口 0 由系统分配。

监听调用 `accept($seconds)` 前预留连接额度，返回尚未 `start()` 的接入连接。同线程子任务可将其登记到自己的作用域，TLS 握手随后在该连接协程完成，监听可继续接入。交接失败必须停止该连接；同线程监听、等待接入的槽及已接入连接共享同一部署分额，不跨线程传递 PHP/TLS 对象。

`receive($seconds)` 返回至多一个配置片段的字节，空字符串仅表示 EOF。`send($data, $seconds)` 使用原生 `sendAll()`，超限在提交前拒绝；短写、超时或取消会停止连接，远端结果可能部分到达，不能自动重发。相同线程内允许一读一写并行，重复同方向操作明确拒绝。`shutdownWrite()` 保留读方向；业务协议自行限制总消息大小，不把一次读取视为一条消息。

TLS 通过 `open_ssl=true` 启用，沿用 `ssl_cert_file/ssl_key_file/ssl_cafile/ssl_host_name/ssl_verify_peer/ssl_protocols`，客户端默认验证证书链和主机名，仅允许 TLS 1.2/1.3。监听需要证书与私钥。`socket_buffer_size` 为 65536–1048576 字节，`backlog` 为 1–4096；它们不替代连接预算。网络等待、DNS、TLS、部分发送、取消和原生 FD 关闭均复用 Swoole，不另建 I/O 循环。

`stop()` 请求关闭并取消在途操作，原生 FD 关闭后才归还额度；同线程 `awaitClosed($seconds)` 等待真实完成，等待超时仍保留额度。状态、在途数量及字节预算通过 `statistics()` 观察。线程启动、示例及逐平台验证边界见[TCP/TLS 原生接入](https://github.com/zoujingli/typeapp/blob/main/docs/development/tcp-native-io.md)与[可编译示例](https://github.com/zoujingli/typeapp/blob/main/examples/tcp/main.php)。HTTP/WebSocket 和 MQTT 角色迁移仍按各自任务验收。

## UDP

`UdpSocket` 是客户端和服务端共用的原生协程入口。在已有协程中用 `ExecutionScope::open()` 启动，调用 `sendTo($ip, $port, $data)` 和 `receive($seconds)` 收发完整报文，退出时关闭作用域。`receive()` 返回 `['data' => string, 'address' => string, 'port' => int]`，空字符串仍是携带来源的有效报文；发送成功只表示本地提交。绑定和目标只接受数字 IP，IPv6 端点明确只接收 IPv6，不自动解析主机名。

构造参数为 `UdpSocket(ResourceBudget $budget, string $address, int $port = 0, int $maxDatagramBytes = 8192, array $options = [])`。相同线程和退役代次共享一份 `DeploymentBudget::poolBudget()`；收发固定在启动协程，其他协程只能观察统计或停止。原生选项沿用 `socket_buffer_size`（默认 65536 字节）和 `write_timeout`（默认 1 秒）；每个端点只有一个在途收发、零应用排队报文。单报文上限为 1–65507 字节，超长发送在提交前拒绝，超长接收完整消费后拒绝；不提供分包、可靠重试或顺序保证。

原生等待到期报告 `udp_timeout`，取消为 `udp_cancelled`，停止中的原等待为 `udp_stopped`；原生消息大小错误为 `udp_message_size`，不包装成截断成功。`stop()` 先禁止新操作，调用原生取消，等收发退出后释放 Socket；额度结算排在原生事件循环真正关闭 FD 之后。期间 `statistics()` 保持 `stopping` 和 `allocated=true`，直到状态为 `closed`，不能将停止意图当作已释放。已经关闭的实例不能重启，新一代端点重新构造。

固定 Swoole 上游的 Socket `recvfrom()` 没有为空报文填写来源。本仓构建工具的 `SwooleSocketSource` 只修正该分支，并验证原文摘要；未修模块在该路径明确报告 `udp_peer_unavailable`。复现、原生预算与构建说明见[UDP 原生接入](https://github.com/zoujingli/typeapp/blob/main/docs/development/udp-native-io.md)，可执行例子见[UDP 示例](https://github.com/zoujingli/typeapp/blob/main/examples/udp/main.php)。

`write_timeout` 通过原生 `SO_SNDTIMEO` 设置，`statistics()['write_timeout']` 返回原生回读的实际秒数。Socket 的 `setProtocol()` 不接收发送期限，不能用它的 true 返回值判断超时配置有效。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

```sh
composer check:assembly
composer build:commands
composer test:commands
composer test:websocket
composer test:websocket-native
composer build:http
composer test:http
composer test:routing
composer build:routing
composer test:routing-native
php tests/configuration.php
```

- [嵌套配置与 dotenv](https://github.com/zoujingli/typeapp/blob/main/docs/development/configuration.md)
- [TypeApp 标准物联中心项目](https://github.com/zoujingli/typeapp/blob/main/docs/development/typeapp.md)
- [HTTP 接入安全](https://github.com/zoujingli/typeapp/blob/main/docs/development/http-trust.md)
