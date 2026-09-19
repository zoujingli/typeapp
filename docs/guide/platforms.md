# 平台与验收

TypeApp 的目标平台为 Linux x64、Linux ARM64、macOS ARM64 和 Windows x64。所有平台统一采用 TypePHP 编译生产 PHP、Swoole 承担通信与基础并发、Plugins 组合应用能力。**当前已有组件和通信场景通过原生验收，四个平台的完整应用交付尚未全部完成。**

本页汇总截至 2026-09-20 已保存的验收结果。不同平台、源码和运行库的结果分别成立，不能组合成同一版本全平台通过的声明。源码公开、工作流存在、PHP 测试通过、原生编译成功和完整应用可部署是不同状态。

## 当前平台状态

| 平台与实际环境 | 已通过的范围 | 完整应用边界 |
| --- | --- | --- |
| Linux x64 · GitHub Ubuntu runner | 公开构建输入准备、断网只读环境中的基础命令 AOT、同一产物的 9 项命令行为 | 该次只覆盖运行组件；完整应用、通信和三库组合未在该次验收 |
| Linux ARM64 · macOS 上的 ARM64 Linux 虚拟机及 Debian 容器 | 四组件 AOT 与真实 SQLite 对照、运行库身份与缓存、命令装配、HTTP AOT；WebSocket 的 PHP 行为 | 完整应用在构建前被线程 SDK 检查拒绝；虚拟机结果不代表实机性能或 GitHub ARM64 runner 已通过 |
| macOS ARM64 · 本机原生运行 | 73 项契约测试、2247 个断言；原生身份、篡改拒绝与缓存；四组件 AOT、SQLite 对照、HTTP AOT 与实际响应；WebSocket 的 PHP 行为 | 验收环境缺少适配并重编译的 PHPX 线程 SDK，安装的 Swoole 6.2.2 未启用 Thread；完整应用未通过 |
| Windows x64 · GitHub Windows Server 2022 runner | 73 项契约测试、2247 个断言；进程参数、输出、截止与清理；原生部署审计、运行库身份与缓存；四组件 AOT 与真实 SQLite 对照 | 当前 SDK 准备流程没有提供匹配的 Swoole 模块，标准应用 PHP 验收在启动前拒绝；完整应用 AOT、发布搬迁和 MySQL/PostgreSQL 后续步骤未执行 |

“四组件”指 `type-runtime`、`type-validate`、`type-orm` 和 `type-orm-sqlite` 的完整生产源码。SQLite 对照证明该场景的 PHP 与原生结果一致，不代表 MySQL、PostgreSQL 或物联中心全部业务已通过。Windows 原生部署审计验证的是组件消费者的实际程序和运行库，尚不包含完整应用的可搬迁发布包。

## 通信结果如何理解

HTTP、TCP、UDP、MQTT、WebSocket 的教程和接口平级，验收按协议、运行模式和平台分别判定：

- HTTP：上述 Linux ARM64 和 macOS ARM64 已有独立场景的 AOT 与真实响应结果；不能据此认定主仓完整应用的线程 HTTP 已通过。
- WebSocket：上述 ARM64 环境的 PHP 用例覆盖 HTTP 共用服务与端口、握手、分片、控制帧、WSS、作用域和取消。本次平台验收没有把这些 PHP 结果计为 WebSocket AOT 通过。
- TCP、UDP、MQTT：本次平台验收未完成这些协议的完整原生业务矩阵；教程中的示例与局部结果不能外推为全部协议义务、容量或高可用通过。

Linux ARM64 的 HTTP AOT 与 WebSocket PHP 使用了不同构建的 Swoole 模块，各自按原产物身份记录。更换扩展后须重新验证对应原生产物。HTTP 与 WebSocket 的共用监听方式见[WebSocket 教程](communications/websocket.md#http-与-websocket-共用服务)。

## SDK 与执行方式

原生构建基线为 PHP 8.5.10 ZTS、TypePHP 0.9.0、PHPX 2.9.0，准确引用以项目的工具链锁和 Composer 锁文件为准。Swoole 另外固定版本、源码、构建开关、模块摘要和官方内置库配置；CLI 加载成功还需要对应 embed 环境验证。不能只复制一个扩展文件就认定 ABI 匹配。

进程不可用时采用官方线程或协程是框架要求，当前自动选择执行方式及部分角色接入仍待完成。Swoole 官方已有 Windows 原生能力，当前 Windows SDK 缺少模块属于本项目准备流程的缺口；经典 Server/Process、线程和协程须按所选官方构建分别核验。

当前已编译线程入口还依赖受控的 PHPX 与 Swoole 接入。Linux ARM64 实测中，官方 Swoole 6.2.2 启用 Thread 后并不提供 TypeApp 当前要求的 `startNative` 和 `NATIVE_ENTRY_ABI=2`；这些是项目编译适配标识，不是官方标准 API。普通 Thread 可用不等于已编译业务线程可用。需要让固定上游版本、必要适配和 SDK 构建流程一致，再完成生命周期与全量 AOT 验收。

## 完整交付条件

每个平台都需要用自己的同一份产物完成以下验收：

1. 全部生产业务、Plugins、生成代码和实际 PHP 依赖进入 TypePHP 编译；源码、工具链、扩展与产物身份完整对应。
2. HTTP/TCP/UDP/MQTT/WebSocket 的实际业务、异常、取消、停止和资源释放通过；MySQL、PostgreSQL、SQLite 按各自真实语义验证。
3. 在无业务源码、无 Composer 和无编译 SDK 的目标环境验证启动、迁移、运行库校验、搬迁、升级和恢复。
4. 完成一个程序文件加外置配置的封装与自动运行库管理，并验证并发首次启动、中断恢复、权限及数据保留。

当前 `package` 提供目录包，`archive` 提供归档，单程序封装仍待完成。容量与性能需要同平台、同负载和可复现基线，不能用测试数量或模拟环境结果代替。

[系统架构](architecture.md) · [构建与部署](deployment.md) · [实现规划](roadmap.md)
