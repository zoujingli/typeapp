# 平台与验收

Linux x64 / ARM64、macOS ARM64、Windows x64 均有原生编译与运行记录，实际范围见下表。各平台使用 TypePHP 编译业务与生产组件，构建选择匹配的原生运行库；编译器存在平台实现、模块可以加载和应用完整验收分别判断。

本页是平台支持范围的统一入口。支持范围按操作系统、CPU 架构和实际场景判断，未列出的架构尚无已支持声明。源码公开、PHP 测试通过、原生编译成功和完整应用可部署是不同状态。

框架已集成 Swoole，生产单程序将其与其他非系统原生库一起静态链接。开发、构建和部署分别需要准备什么，先看[环境与依赖](environment.md)；SDK 和编译工具留在构建机。性能目标与实测依据见[性能与调优](performance.md)。

## 当前平台状态

每个版本重新执行四平台完整验收，并将最终待上传的单个程序复制到隔离环境，执行对应 profile 的数据库、页面安装和真实 API 检查。本轮目标候选为 `v1.0.0-rc.14`；它在四个平台分别提供 `sqlite`、`mysql`、`pgsql` 程序，只有对应 Actions 运行全部成功并完成公开回读后才算已发布。跨仓分发、公开消费与 Release 流程见[版本发布](releases.md)。

RC14 的验收使用当前主仓提交 **`d646837d445adb5543a5c71ae45b2a616f62a21b`**，工具链为 PHP 8.5.10 ZTS、TypePHP 0.9.4、PHPX 2.9.3，以及提交 `4aff74a9`、运行时字符串 `6.3.0RC1` 的 Swoole 开发快照。Linux x64、Windows x64、macOS ARM64 的运行 ID 分别为 `37076682879`、`37076682889`、`37076682999`；截至本页更新时仍在执行，不能把 RC13 的结果借记为 RC14 的通过。完成后应把同一运行轮次、12 个程序摘要和公开下载回读补入[升级证据](../evidence/typephp-upgrade-0.9.4.md)。

| 平台与实际环境 | 已通过的范围 | 部署验收边界 |
| --- | --- | --- |
| Linux x64，Ubuntu 24.04 | 完整默认矩阵：应用 AOT、三库应用、组件、TLS、恢复与回滚；另验静态 SDK 和单程序 | bubblewrap 禁止读取源码、SDK、Composer 及执行开发工具；三个 profile 的最终 ELF 分别完成对应数据库部署 |
| Linux ARM64，Ubuntu 24.04 原生 ARM runner | 独立 ORM、完整应用 AOT、三库应用、任务、恢复与回滚；另验静态 SDK 和单程序 | bubblewrap 隔离；三个 profile 的最终 ARM64 ELF 分别完成对应数据库部署 |
| macOS ARM64，macOS 15 原生 runner | HTTP、ORM、三库完整应用 AOT、部署、恢复、回滚与 TLS；另验静态单程序 | 系统沙箱禁止读取源码、SDK 和执行开发工具；三个 profile 的最终 Mach-O 分别完成对应数据库部署 |
| Windows x64，Windows 2022 原生 runner | SDK、三库独立 ORM、主应用 PHP/AOT、模板与搬迁；另验静态 SDK 和单程序 | 受限令牌与 ACL 禁止读取源码、SDK 及执行 PHP、MSVC、Node；三个 profile 的最终 EXE 分别完成对应数据库部署，并核对权限恢复 |

三库指 MySQL、PostgreSQL、SQLite。RC13 的 12 个程序已分别覆盖运行库审计、数据库 profile 不匹配拒绝、内嵌前端安装与摘要、GET/HEAD 和缓存、平台及客户登录、站点默认值、角色 CRUD 和正常停止，并完成 MQTT TLS 授权、告警通知、导出及调度回归。RC14 必须用新版最终程序重新执行这些范围。普通启动不写出运行库，页面通过显式安装命令生成。独立 ORM 和通用模板仍分别验证自己的入口与产物；Docker、WSL 中的 Linux 结果不计为 Windows 或 macOS 原生结果。

上述系统版本是实际构建与运行基线，不等于已测试所有更高或更低版本。Linux 程序仍依赖目标系统的 glibc，不适用于 Alpine/musl；开发用共享 Swoole 模块的 Debian 12 基线不能套用于这些 Ubuntu 24.04 静态程序。macOS 程序按最低系统版本与实际加载映像核验，只允许系统库；历史 RC7 目录包的 dyld 缓存摘要限制保留在[旧版验收记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/rc-release-20260926.md)。

RC13 的 15 个组件与应用模板已发布对应 tag、GitHub Release 和 Packagist 版本；RC14 的子仓 tag、Packagist 索引与 Release 必须在新版主仓验收通过后按同一批次执行。版本 tag 分发不移动子仓 `main`；`dev-main` 是独立更新的开发分支，不能用它替代固定版本。安装方式见[组件参考](components.md)。

完整源码、程序摘要、重建材料与隔离详情见[profile 发布验收记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/profile-release-20260930.md)；[开发分支基线](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/native-release-20260925.md)及[更早的平台记录](https://github.com/zoujingli/typeapp/blob/main/docs/development/platform-support.md#历史结果)保留原身份。MySQL、SQLite 仍采用安全关闭重建，完整三库物理连接复用未完成。本轮未运行可选性能基准，默认矩阵通过也不代表全部协议、容量和业务故障组合验收完成。

## 通信结果如何理解

HTTP、TCP、UDP、MQTT、WebSocket 的教程和接口平级，验收按协议、运行模式和平台分别判定：

- HTTP：验证请求响应、路由、TLS、停止和资源回收。
- WebSocket：验证 HTTP 共用服务与端口、握手、分片、控制帧、WSS、作用域和取消。
- TCP、UDP、MQTT：分别验证字节流、数据报、会话、QoS、保活、重连、持久确认和故障收尾。MQTT Broker 在经典 Server 不可用时接入官方 Coroutine Socket，支持 TCP/TLS 与账号授权；MQTT over WebSocket、客户端证书身份和服务端 SNI 仍需要经典 Server，配置不满足时明确拒绝。此边界不影响独立 HTTP/WebSocket 组件的协程入口。

每次更换 Swoole、PHPX、libphp 或目标架构都须重新生成并验证完整产物。HTTP 与 WebSocket 的共用监听方式见[WebSocket 教程](communications/websocket.md#http-与-websocket-共用服务)。

## SDK 与执行方式

开发环境需要 PHP `>=8.4 <8.6`、Composer、Swoole `>=6.2 <7` 和所选 PDO 驱动；原生构建还需要匹配目标 OS/架构的 PHP ZTS/embed SDK、PHPX 和编译工具。Linux/macOS 使用对应原生工具链，Windows x64 使用匹配的 ZTS SDK 与 MSVC；准备入口见 GitHub 上的[原生命令与 SDK](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-command.md)。

当前源码的原生构建基线为 PHP 8.5.10 ZTS、TypePHP 0.9.4、PHPX 2.9.3，准确引用以项目的工具链锁和 Composer 锁文件为准。Swoole 固定为 `4aff74a` 开发快照，运行时报告 `6.3.0RC1`，不是正式 6.3.0；源码、构建开关、模块摘要和官方内置库配置分别记录。CLI 加载成功还需要对应 embed 环境验证，不能只复制一个扩展文件就认定 ABI 匹配。

上表记录 RC13 原工具链的已发布结果；0.9.4 升级的模块重建、十二组合程序及性能对照单独记录在[本次升级验收](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/typephp-upgrade-0.9.4.md)。历史 macOS 性能对照按自己的源码和负载成立，不能扩展为新版或其他平台的性能结论。

构建组件另外携带四平台 Swoole 共享模块，供开发及共享库回归使用；具体 ABI、系统依赖和选择规则见[内置 Swoole](plugins/type-build.md#内置-swoole-与运行依赖)。新版模块已在四个平台重建并完成加载与 PDO hook 检查；独立消费及完整应用结果以升级记录为准。生产单程序使用静态 SDK 中的归档，不将这些 `.so` / `.dll` 嵌入后释放。

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

RC13 已完成四平台 × 三数据库 profile 单程序发布及本页列出的隔离范围；RC14 尚在新版工具链验收，`package-directory` 与 `archive` 仅维护旧目录包。其余协议、全部角色、容量与性能继续按场景验收，不能由单程序发布成功推导出全部框架能力完成。后续版本仍须以同一源码重新通过完整门禁。

[系统架构](architecture.md) · [构建与部署](deployment.md) · [实现规划](roadmap.md)
