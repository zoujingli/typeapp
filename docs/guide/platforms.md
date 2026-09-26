# 平台与验收

Linux x64 / ARM64、macOS ARM64、Windows x64 均有原生编译与运行记录，实际范围见下表。各平台使用 TypePHP 编译业务与生产组件，构建选择匹配的原生运行库；编译器存在平台实现、模块可以加载和应用完整验收分别判断。

本页是平台支持范围的统一入口。支持范围按操作系统、CPU 架构和实际场景判断，未列出的架构尚无已支持声明。源码公开、PHP 测试通过、原生编译成功和完整应用可部署是不同状态。

框架已集成 Swoole，构建组件已内置下列四平台模块。开发、构建和部署分别需要准备什么，先看[环境与依赖](environment.md)；部署机使用经过验证的完整运行包，SDK 和编译工具在构建机准备。性能目标与实测依据见[性能与调优](performance.md)。

## 当前平台状态

新版本发布会重新运行四平台完整验收，并对最终下载归档解包执行三库、页面安装和真实 API 检查。下表记录 RC4 已通过的原生范围；跨仓分发与公开 Release 是后续独立门禁，见[版本发布](releases.md)。

截至 2026-09-26，`v1.0.0-rc.4` 对应源码 **`fedc731`** 的[四平台完整原生矩阵](https://github.com/zoujingli/typeapp/actions/runs/36236424786)及汇总门禁全部成功，使用 PHP 8.5.10 ZTS、TypePHP 0.9.3、PHPX 2.9.2 与内置 Swoole 6.2.1。每个平台的最终归档另用同一程序完成三库部署检查；该结果不表示后续提交自动获得相同结论。

| 平台与实际环境 | 已通过的范围 | 部署验收边界 |
| --- | --- | --- |
| Linux x64，Ubuntu 24.04 | 19 个分组及汇总：完整应用 AOT、三库应用、组件、TLS、恢复与回滚 | 最终归档三库部署禁止读取源码、SDK、Composer 及执行 PHP/编译器；另有 scratch 镜像与备份恢复验收 |
| Linux ARM64，Ubuntu 24.04 原生 ARM runner | 9 个分组及汇总：独立 ORM、完整应用 AOT、三库应用、恢复与回滚 | 最终归档三库部署禁止读取源码、SDK、Composer 及执行 PHP/编译器 |
| macOS ARM64，macOS 15 原生 runner | 8 个分组及汇总：HTTP、ORM、三库完整应用 AOT、部署、恢复、回滚与 TLS | 最终归档三库部署禁止读取源码、SDK、Composer 及执行 PHP/编译器；系统缓存审计限制见下文 |
| Windows x64，Windows 2022 原生 runner | 完整流程：SDK、三库独立 ORM、主应用 PHP/AOT、模板与搬迁包 | 最终 ZIP 不含 PHP 源码、Composer 或 SDK，三库部署通过；未强制禁止访问构建机原项目/SDK 或执行编译器 |

三库指 MySQL、PostgreSQL、SQLite。四份最终归档的十二份报告均覆盖运行库审计、83 个内嵌前端文件的安装与摘要、GET/HEAD 和缓存、平台及客户登录、站点默认值、角色 CRUD 和正常停止。独立 ORM、主应用和通用模板分别验证自己的入口与产物，不能合并为“全部平台同一产物通过最严格无源码隔离”。Docker、WSL 中的 Linux 结果不计为 Windows 或 macOS 原生结果。

macOS 发布包的 `verify-runtime` 目前核对构建机整套 dyld 系统缓存。macOS 15 候选包在 macOS 27 / ARM64 上的补验中，普通安装、服务和页面可运行，但完整部署审计因缓存摘要不同而拒绝。这不构成 macOS 27 支持；系统更新也可能触发同类限制。当前验收范围以实际构建和运行基线为准，详情见[RC 验收记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/rc-release-20260926.md)。

此前 `bf28c8b` 的 [15 组件批次](https://github.com/zoujingli/typeapp/actions/runs/36144180719)与[应用模板分发](https://github.com/zoujingli/typeapp/actions/runs/36146310707)已完成公开安装和三库原生集成。Packagist 的 16 个 `dev-main` 引用与该分发提交一致，自动同步已启用。版本 tag 分发不移动子仓 `main`；安装方式见[组件参考](components.md)。

完整源码、归档摘要及隔离详情见[RC 验收记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/rc-release-20260926.md)；[开发分支基线](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/native-release-20260925.md)及[更早的平台记录](https://github.com/zoujingli/typeapp/blob/main/docs/development/platform-support.md#历史结果)保留原身份。MySQL、SQLite 仍采用安全关闭重建，完整三库物理连接复用未完成。本轮未运行可选性能基准，默认矩阵通过也不代表全部协议、容量和业务故障组合验收完成。

## 通信结果如何理解

HTTP、TCP、UDP、MQTT、WebSocket 的教程和接口平级，验收按协议、运行模式和平台分别判定：

- HTTP：验证请求响应、路由、TLS、停止和资源回收。
- WebSocket：验证 HTTP 共用服务与端口、握手、分片、控制帧、WSS、作用域和取消。
- TCP、UDP、MQTT：分别验证字节流、数据报、会话、QoS、保活、重连、持久确认和故障收尾。

每次更换 Swoole、PHPX、libphp 或目标架构都须重新生成并验证完整产物。HTTP 与 WebSocket 的共用监听方式见[WebSocket 教程](communications/websocket.md#http-与-websocket-共用服务)。

## SDK 与执行方式

开发环境需要 PHP `>=8.4 <8.6`、Composer、Swoole `>=6.2 <7` 和所选 PDO 驱动；原生构建还需要匹配目标 OS/架构的 PHP ZTS/embed SDK、PHPX 和编译工具。Linux/macOS 使用对应原生工具链，Windows x64 使用匹配的 ZTS SDK 与 MSVC；准备入口见 GitHub 上的[原生命令与 SDK](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-command.md)。

原生构建基线为 PHP 8.5.10 ZTS、TypePHP 0.9.3、PHPX 2.9.2，准确引用以项目的工具链锁和 Composer 锁文件为准。Swoole 另外固定版本、源码、构建开关、模块摘要和官方内置库配置；CLI 加载成功还需要对应 embed 环境验证。不能只复制一个扩展文件就认定 ABI 匹配。

四平台默认矩阵已在上述工具链上通过。工具链升级时的 macOS 性能对照仍按自己的源码和负载成立，见[升级验收](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/typephp-upgrade-0.9.3.md)，不能扩展为本轮四平台性能结论。

构建组件携带四平台 Swoole 6.2.1 共享模块，具体 ABI、系统依赖和选择规则见[内置 Swoole](plugins/type-build.md#内置-swoole-与运行依赖)。独立 Composer 安装的禁网选择、空格路径与不同工作目录已验证；模块迁移时的原始结果见[迁移验收](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/swoole-bundle.md#迁入构建组件后的验证)。本轮进一步完成了四平台匹配 SDK 和应用回归，模块仍是动态构建输入，不等于静态链接完成。

不同服务入口具有各自的执行方式，不能把某一入口的限制套用到整个框架：

| 入口 | 当前实现 | 平台边界 |
| --- | --- | --- |
| HTTP `serve()`，Unix | 经典 Swoole 单 worker，在协程中处理请求 | 停止沿用原生 worker 生命周期 |
| HTTP `serve()`，Windows | Swoole 协程 HTTP，`ProcessSignals` 接入控制台停止事件 | CLI 使用 PHP 控制台处理器；embed 需要编译的控制事件桥与可用控制台 |
| HTTP `serveThread()` / `serveThreadOwned()` | 编译业务线程使用共享监听副本或自行监听，主控监督停止与 join | 需要匹配项目线程 ABI；两种监听方式和各平台单独验收 |
| `WebSocket\Server::start()` | 经典 Swoole WebSocket Server | 当前 Windows 原生入口明确拒绝，协程升级尚未接入本组件 |

Windows 主应用与模板已通过本轮 HTTP、正常停止和发布包用例；控制台异常退出、全部故障组合仍按对应场景分别验收。主仓物联中心生产 HTTP 使用编译业务线程，通用模板使用 `serve()`，见[快速开始](quickstart.md#启动服务)和[type-core](plugins/type-core.md#启动-http-服务)。

进程不可用时采用官方线程或协程是框架要求；HTTP 已按平台选择入口，其他角色仍需逐项接入和验证。经典 Server/Process、线程和协程的实际业务按所选构建分别核验，上游提供某项能力不能替代应用验收。

当前已编译线程入口还依赖受控的 PHPX 与 Swoole 接入。Linux ARM64 实测中，官方 Swoole 6.2.2 启用 Thread 后并不提供 TypeApp 当前要求的 `startNative` 和 `NATIVE_ENTRY_ABI=2`；这些是项目编译适配标识，不是官方标准 API。普通 Thread 可用不等于已编译业务线程可用。需要让固定上游版本、必要适配和 SDK 构建流程一致，再完成生命周期与全量 AOT 验收。

## 完整交付条件

每个平台都需要用自己的同一份产物完成以下验收：

1. 全部生产业务、Plugins、生成代码和实际 PHP 依赖进入 TypePHP 编译；源码、工具链、扩展与产物身份完整对应。
2. HTTP/TCP/UDP/MQTT/WebSocket 的实际业务、异常、取消、停止和资源释放通过；MySQL、PostgreSQL、SQLite 按各自真实语义验证。
3. 在无业务源码、无 Composer 和无编译 SDK 的目标环境验证启动、迁移、运行库校验、搬迁、升级和恢复。
4. 完成一个程序文件加外置配置的交付，非系统原生库静态链接、启动不释放运行库，并验证干净环境、权限及数据保留。

当前 `package` 提供目录包，`archive` 提供归档，单程序封装仍待完成。容量与性能需要同平台、同负载和可复现基线，不能用测试数量或模拟环境结果代替。

[系统架构](architecture.md) · [构建与部署](deployment.md) · [实现规划](roadmap.md)
