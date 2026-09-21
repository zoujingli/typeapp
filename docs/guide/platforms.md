# 平台与验收

**已验证平台：Linux x64 / ARM64、macOS ARM64、Windows x64。** 各平台已完成的原生编译与运行场景见下表。所有平台统一采用 TypePHP 编译生产 PHP、Swoole 承担通信与基础并发、Plugins 组合应用能力。

本页是平台支持范围的统一入口。支持范围按操作系统、CPU 架构和实际场景判断，未列出的架构尚无已支持声明。不同平台、源码和运行库的结果分别成立，不能组合成同一版本全平台通过的声明。源码公开、PHP 测试通过、原生编译成功和完整应用可部署是不同状态。

## 当前平台状态

| 平台与实际环境 | 已通过的范围 | 完整应用边界 |
| --- | --- | --- |
| Linux x64 | 基础命令的全量 AOT 与实际运行 | 当前 ORM、完整应用及全部通信仍需同提交验收 |
| Linux ARM64（虚拟机及 QEMU 指令模拟） | 同提交三库独立 ORM 的 PHP、AOT 与移除源码运行，包含锁等待、断连退役与凭据代次；同一无源码产物另通过 QEMU 三库上下文、乐观锁和原子更新验收 | 完整应用、全部通信及单程序交付待完成 |
| macOS ARM64 | 同提交三库独立 ORM、真实锁等待及主从专项；完整应用 AOT 产物的三库身份 HTTP 和无源码运行包；TCP/UDP 双线程和协程、WS/WSS 独立原生运行 | 完整协议、故障和单程序交付待完成 |
| Windows x64 | Swoole SDK 构建与加载、PHPUnit；同提交三库独立 ORM 的 PHP、AOT 与移除源码运行，包含上下文、双进程乐观锁与原子更新；PostgreSQL 另验证物理连接复用、污染清理及故障退役 | MySQL/SQLite 物理复用及完整应用待完成 |

三库指 MySQL、PostgreSQL、SQLite。独立 ORM 消费者安装标准框架与所选数据库驱动，验证模型和运行时的公开契约，不导入物联中心业务。每种数据库的结果只覆盖实际执行的场景。Windows 原生部署验收包含组件消费者的实际程序和运行库，尚不包含完整应用的可搬迁发布包。Docker、WSL 中的 Linux 结果不计为 Windows 或 macOS 原生结果。

Linux x64 基础命令结果对应源码 `fed4efae5826bdca69a613743e1c6c459addd565`；macOS ARM64、Linux ARM64、Windows x64 的三库独立 ORM 矩阵对应干净源码 `5abdb5e53ea9ca67054f69d17113b91eb3402d69`。工具链、产物身份与运行证据见 GitHub 上的[平台与工具链](https://github.com/zoujingli/typeapp/blob/main/docs/development/platform-support.md#当前结果与证据)。这些记录保留原提交归属，不表示后续每个提交都重新通过了同一矩阵。

消费者通过 Composer 复制安装对应提交的组件输入；远端分发子仓同步和安装另行验证。MySQL、SQLite 仍采用安全关闭，完整三库物理连接复用及全部协议入口作用域尚未收口，因此不声明 ORM 完整交付。

## 通信结果如何理解

HTTP、TCP、UDP、MQTT、WebSocket 的教程和接口平级，验收按协议、运行模式和平台分别判定：

- HTTP：验证请求响应、路由、TLS、停止和资源回收。
- WebSocket：验证 HTTP 共用服务与端口、握手、分片、控制帧、WSS、作用域和取消。
- TCP、UDP、MQTT：分别验证字节流、数据报、会话、QoS、保活、重连、持久确认和故障收尾。

每次更换 Swoole、PHPX、libphp 或目标架构都须重新生成并验证完整产物。HTTP 与 WebSocket 的共用监听方式见[WebSocket 教程](communications/websocket.md#http-与-websocket-共用服务)。

## SDK 与执行方式

开发环境需要 PHP `>=8.4 <8.6`、Composer、Swoole `>=6.2 <7` 和所选 PDO 驱动；原生构建还需要匹配目标 OS/架构的 PHP ZTS/embed SDK、PHPX 和编译工具。Linux/macOS 使用对应原生工具链，Windows x64 使用匹配的 ZTS SDK 与 MSVC；准备入口见 GitHub 上的[原生命令与 SDK](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-command.md)。

原生构建基线为 PHP 8.5.10 ZTS、TypePHP 0.9.0、PHPX 2.9.0，准确引用以项目的工具链锁和 Composer 锁文件为准。Swoole 另外固定版本、源码、构建开关、模块摘要和官方内置库配置；CLI 加载成功还需要对应 embed 环境验证。不能只复制一个扩展文件就认定 ABI 匹配。

通用模板的经典 HTTP `serve()` 入口仍要求 Unix worker 与信号能力，当前明确拒绝 Windows；Windows SDK 和 ORM 通过不代表该 HTTP 入口已适配。主仓物联中心生产 HTTP 使用编译业务线程内协程，具体入口与限制见[快速开始](quickstart.md#启动服务)和[type-core](plugins/type-core.md#启动-http-服务)。

进程不可用时采用官方线程或协程是框架要求，当前自动选择执行方式及部分角色接入仍待完成。Swoole 官方已有 Windows 原生能力，项目的 Windows 准备脚本已完成固定源码构建与扩展加载验证；经典 Server/Process、线程和协程的实际业务须按所选官方构建分别核验。

当前已编译线程入口还依赖受控的 PHPX 与 Swoole 接入。Linux ARM64 实测中，官方 Swoole 6.2.2 启用 Thread 后并不提供 TypeApp 当前要求的 `startNative` 和 `NATIVE_ENTRY_ABI=2`；这些是项目编译适配标识，不是官方标准 API。普通 Thread 可用不等于已编译业务线程可用。需要让固定上游版本、必要适配和 SDK 构建流程一致，再完成生命周期与全量 AOT 验收。

## 完整交付条件

每个平台都需要用自己的同一份产物完成以下验收：

1. 全部生产业务、Plugins、生成代码和实际 PHP 依赖进入 TypePHP 编译；源码、工具链、扩展与产物身份完整对应。
2. HTTP/TCP/UDP/MQTT/WebSocket 的实际业务、异常、取消、停止和资源释放通过；MySQL、PostgreSQL、SQLite 按各自真实语义验证。
3. 在无业务源码、无 Composer 和无编译 SDK 的目标环境验证启动、迁移、运行库校验、搬迁、升级和恢复。
4. 完成一个程序文件加外置配置的封装与自动运行库管理，并验证并发首次启动、中断恢复、权限及数据保留。

当前 `package` 提供目录包，`archive` 提供归档，单程序封装仍待完成。容量与性能需要同平台、同负载和可复现基线，不能用测试数量或模拟环境结果代替。

[系统架构](architecture.md) · [构建与部署](deployment.md) · [实现规划](roadmap.md)
