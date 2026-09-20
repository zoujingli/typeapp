# 平台与验收

TypeApp 的目标平台为 Linux x64、Linux ARM64、macOS ARM64 和 Windows x64。所有平台统一采用 TypePHP 编译生产 PHP、Swoole 承担通信与基础并发、Plugins 组合应用能力。各平台按同一源码、依赖、Swoole 构建和产物身份分别验收。

本页只描述当前验收范围和完成条件。不同平台、源码和运行库的结果分别成立，不能组合成同一版本全平台通过的声明。源码公开、PHP 测试通过、原生编译成功和完整应用可部署是不同状态。

## 当前平台状态

| 平台与实际环境 | 已通过的范围 | 完整应用边界 |
| --- | --- | --- |
| Linux x64 | 基础命令的全量 AOT 与实际运行 | 当前 ORM、完整应用及全部通信仍需同提交验收 |
| Linux ARM64（虚拟机） | 三库独立 ORM 的 PHP、AOT 与移除源码运行，包含锁等待、断连退役与凭据代次 | 完整应用、全部通信及最终同提交验收待完成 |
| macOS ARM64 | 三库独立 ORM 与真实锁等待、完整应用 AOT 产物的三库身份 HTTP 和无源码运行包；TCP/UDP 双线程和协程、WS/WSS 独立原生运行 | 完整协议、故障、单程序交付及最终同提交验收待完成 |
| Windows x64 | Swoole SDK 构建与加载、PHPUnit；已有四组件消费者、SQLite 原生行为与部署审计结果 | 三库独立 ORM、完整应用及最终同提交验收待完成 |

“四组件”指 `type-runtime`、`type-validate`、`type-orm` 和 `type-orm-sqlite` 的完整生产源码。SQLite 对照证明该场景的 PHP 与原生结果一致，不代表 MySQL、PostgreSQL 或物联中心全部业务已通过。Windows 原生部署审计验证的是组件消费者的实际程序和运行库，尚不包含完整应用的可搬迁发布包。

## 通信结果如何理解

HTTP、TCP、UDP、MQTT、WebSocket 的教程和接口平级，验收按协议、运行模式和平台分别判定：

- HTTP：验证请求响应、路由、TLS、停止和资源回收。
- WebSocket：验证 HTTP 共用服务与端口、握手、分片、控制帧、WSS、作用域和取消。
- TCP、UDP、MQTT：分别验证字节流、数据报、会话、QoS、保活、重连、持久确认和故障收尾。

每次更换 Swoole、PHPX、libphp 或目标架构都须重新生成并验证完整产物。HTTP 与 WebSocket 的共用监听方式见[WebSocket 教程](communications/websocket.md#http-与-websocket-共用服务)。

## SDK 与执行方式

原生构建基线为 PHP 8.5.10 ZTS、TypePHP 0.9.0、PHPX 2.9.0，准确引用以项目的工具链锁和 Composer 锁文件为准。Swoole 另外固定版本、源码、构建开关、模块摘要和官方内置库配置；CLI 加载成功还需要对应 embed 环境验证。不能只复制一个扩展文件就认定 ABI 匹配。

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
