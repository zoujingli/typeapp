# 平台与工具链

正式目标为 Linux x64/ARM64、macOS ARM64、Windows x64 的原生开发、构建与运行。各目标分别配置匹配的 PHP ZTS/embed SDK、PHPX、Swoole 及实际生产扩展，版本与源码摘要取自 `composer.lock` 和 `toolchain.lock.json`，语言规则见[TypePHP 规范](../standards/typephp.md)。

Swoole 是通信和基础并发的必需依赖。按构建能力选择官方进程、线程或协程；进程不可用时使用线程/协程并重新验证隔离、退出和资源归属。上游提供某平台能力不能直接证明 TypeApp 的全部角色已通过该平台验收。

## 当前结果与证据

面向使用者的状态统一维护在[平台与验收](../guide/platforms.md)。该页进入 Docsify 公开站点；本文保留执行入口和可复核的产物身份。

2026-09-25，固定源码 `bf28c8bd276f7d0f25535e82ec3ca4a59c4f241a` 使用 TypePHP 0.9.3／PHPX 2.9.2、PHP 8.5.10 ZTS、内置 Swoole 6.2.1 完成四平台默认矩阵。Linux x64 的 19 个分组及汇总、Linux ARM64 的 9 个分组、macOS ARM64 的 8 个分组和 Windows x64 完整流程均成功。原生 runner、Actions 链接、应用/模板隔离区别、发布与产物摘要见[四平台发布验收](../evidence/native-release-20260925.md)。本轮未运行可选性能基准，也不声明完整静态单程序已完成。

四平台 Swoole 模块随 Composer 构建组件分发，匹配 SDK 后默认复用。macOS 当前矩阵在原生 macOS 15 runner 运行，Linux ARM64 在原生 ARM64 runner 运行，Windows 已完成 SDK 准备、完整应用 AOT 和模板搬迁。每种产物按报告解释，不能把主应用 `no_source=false` 的检查与独立模板隔离结果混为一项。

## 历史结果

以下记录保留原源码、工具链和产物。TypePHP 升级对照见[升级验收](../evidence/typephp-upgrade-0.9.3.md)；模块首次迁移的独立消费与 macOS 回归见[迁移验收](../evidence/swoole-bundle.md#迁入构建组件后的验证)。其中的待验收项描述当时状态，当前进展以上节为准。

| 平台 | 已保存结果与身份 | 范围 |
| --- | --- | --- |
| Linux x64 | [基础命令运行](https://github.com/zoujingli/typeapp/actions/runs/35452574738)，源码 `fed4efae5826bdca69a613743e1c6c459addd565`；产物 SHA-256 `ea87c232b80b9498d8083f3b2f1e4ed18d4a99c762e9fa69dc983253e845ddc2` | 26 个源码输入、29 个编译单元、9 项原生命令；断网只读 AOT 与真实运行 |
| Linux ARM64 | Colima ARM64 虚拟机，PHP 8.5.10 ZTS、TypePHP 0.9.0、Swoole 6.2.2 | 同提交三库独立 ORM 的 PHP、AOT 与移除源码运行通过；同一产物的 QEMU 三库运行通过；完整应用和五种通信待完成 |
| macOS ARM64 | PHP 8.5.10 ZTS、TypePHP 0.9.0；三库独立 ORM 与完整应用使用的受控 Swoole 模块实际为 6.2.1 | 同提交三库独立 ORM 的 PHP、AOT、无源码运行及真实数据库锁等待通过；主从专项 PHP/AOT 通过；完整应用身份 HTTP 已有三库原生结果，完整协议与故障待完成 |
| Windows x64 | PHP 8.5.10 ZTS x64/embed、TypePHP 0.9.0、固定源码构建的 Swoole 6.2.1 与重编译的 PHPX | SDK 构建和加载、完整契约及同提交三库独立 ORM 的 PHP、AOT、移除源码运行通过；PostgreSQL 物理复用与污染隔离通过，MySQL/SQLite 复用及完整应用待完成 |

三平台独立 ORM 矩阵对应干净源码 `5abdb5e53ea9ca67054f69d17113b91eb3402d69`，每个平台均完成三库 PHP 与原生消费者共六项。MySQL 为 8.4.11，PostgreSQL 为 17.11；SQLite 在 macOS、Linux、Windows 分别为 3.53.3、3.40.1、3.53.4。消费者通过 Composer 复制安装框架组件，不依赖主仓生产源码；原生消费者移除生产 PHP 后运行，覆盖十项作用域检查、双进程乐观锁、原子更新、模型隔离及会话退役。PostgreSQL 验证物理复用；MySQL、SQLite 验证安全关闭，不能计为物理复用完成。

[Windows 三库消费者验收](https://github.com/zoujingli/typeapp/actions/runs/35567372098)的三个运行包各有 32 个文件，均回读核对大小和 SHA-256；每库移除 100 个 PHP 文件后执行相同公开行为，专属数据库正常清理。该结果使用[Windows IOCP 名称限定适配](native-ci.md)。Linux ARM64 在 Colima 中构建与运行后，使用 QEMU 用户态指令模拟、ARM64 loader 和同一无源码产物，再次完成三库公开 ORM、上下文和双进程行为。macOS 另以真实主库与只读端点完成三库 PHP/AOT 主从专项；单库消费者不代替独立主从端点验收。

每个平台的产物身份、源码摘要、扩展摘要和运行结果必须随本次构建重新记录；更换任一工具链或扩展都不能沿用旧产物结论。配置检查不计为原生通过。

Windows ORM 验收由原生数据库装置另外创建两个独立端口和数据目录，复用主从公共场景验证 PHP 与 AOT 的普通读取、显式主读、关联路由、事务和从库故障拒绝。两端使用受控的数据差异，不冒充数据库复制集群；SQLite 按单库语义执行。运行配置取自该产物的构建报告，日志保全后关闭本轮实例并回收私有目录。

## 继续验收的前置条件

- Windows：SDK 准备入口默认复用 `plugin/type-build/resources/swoole` 中匹配 PHP 8.5.10 ZTS x64/embed 的 DLL；仅在 `TYPE_SWOOLE_BUILD_FROM_SOURCE=1` 时下载固定 Swoole 源码及专用 phpize 工具。本轮默认入口与完整应用已通过；每次更换输入仍须核对扩展依赖、SDK 布局、模块版本和官方内置库。应用 PHP 装置先探测子进程已有扩展，避免重复加载；模块可通过 `TYPE_SWOOLE_MODULE` 显式定位。
- 已编译线程：`NativeBuilder` 要求已适配并重编译的 PHPX、Swoole `startNative`/`NATIVE_ENTRY_ABI=2` 与 fiber 通知配置；官方 Swoole 6.2.2 的普通 Thread 不提供这些项目标识。具体接入见[已编译业务线程](compiled-business-threads.md)。
- macOS 扩展构建：设置与所选 PHP SDK 相符的 `MACOSX_DEPLOYMENT_TARGET`，通过 `pkg-config` 定位匹配的 OpenSSL。实际模块须使用两级符号绑定，TLS 符号明确链接到所选 OpenSSL；不能依赖平面命名空间从已加载的系统库中猜测同名实现。用真实 TCP/TLS 与 WSS 行为验证链接结果。
- 完整应用：SDK 或生产输入变化后重新执行受影响的应用、通信、三库、发布搬迁与无源码部署。现有 Windows 搬迁包验收未禁止读取外部源码/SDK；更强隔离及全部协议组合仍需补充，不能由组件审计代替。

## 验收条件

每个目标需要全量生产源码 AOT、同一产物的 HTTP/TCP/UDP/MQTT/WebSocket 行为、实际数据库驱动、依赖身份与无源码部署验证。性能、容量和故障测试使用相同平台和负载，独立记录吞吐、延迟、CPU/RSS 与资源回收。

CI 定义见[原生 CI](native-ci.md)，当前差距见[实现规划](../guide/roadmap.md)。WASI、移动端及其他架构需另行确定组件范围与验收条件。
