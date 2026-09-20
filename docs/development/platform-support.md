# 平台与工具链

正式目标为 Linux x64/ARM64、macOS ARM64、Windows x64 的原生开发、构建与运行。各目标分别配置匹配的 PHP ZTS/embed SDK、PHPX、Swoole 及实际生产扩展，版本与源码摘要取自 `composer.lock` 和 `toolchain.lock.json`，语言规则见[TypePHP 规范](../standards/typephp.md)。

Swoole 是通信和基础并发的必需依赖。按构建能力选择官方进程、线程或协程；进程不可用时使用线程/协程并重新验证隔离、退出和资源归属。上游提供某平台能力不能直接证明 TypeApp 的全部角色已通过该平台验收。

## 当前结果与证据

面向使用者的状态统一维护在[平台与验收](../guide/platforms.md)。该页进入 Docsify 公开站点；本文保留执行入口和可复核的产物身份。

| 平台 | 已保存结果与身份 | 范围 |
| --- | --- | --- |
| Linux x64 | [基础命令运行](https://github.com/zoujingli/typeapp/actions/runs/35452574738)，源码 `fed4efae5826bdca69a613743e1c6c459addd565`；产物 SHA-256 `ea87c232b80b9498d8083f3b2f1e4ed18d4a99c762e9fa69dc983253e845ddc2` | 26 个源码输入、29 个编译单元、9 项原生命令；断网只读 AOT 与真实运行 |
| Linux ARM64 | 使用 ARM64 Linux Swoole 构建 | 组件、应用、五种通信、三库和无源码发布包按同一产物验收 |
| macOS ARM64 | 使用 macOS ARM64 Swoole 构建 | 组件、应用、五种通信、三库和无源码发布包按同一产物验收 |
| Windows x64 | 使用 Windows x64 Swoole 构建 | 组件、应用、五种通信、三库和无源码发布包按同一产物验收 |

每个平台的产物身份、源码摘要、扩展摘要和运行结果必须随本次构建重新记录；更换任一工具链或扩展都不能沿用旧产物结论。配置检查不计为原生通过。

## 继续验收的前置条件

- Windows：准备匹配 PHP 8.5.10 ZTS x64/embed 的 Swoole 模块，核对扩展依赖、SDK 布局与实际加载；当前应用 PHP 装置默认查找 Unix 模块名，需同时对齐 Windows 模块定位和子进程启动。
- 已编译线程：`NativeBuilder` 要求已适配并重编译的 PHPX、Swoole `startNative`/`NATIVE_ENTRY_ABI=2` 与 fiber 通知配置；官方 Swoole 6.2.2 的普通 Thread 不提供这些项目标识。具体接入见[已编译业务线程](compiled-business-threads.md)。
- 完整应用：满足 SDK 前置后重新执行应用、五种通信、三库、发布搬迁与无源码部署。Windows 组件审计成功不能替代 `NativePackage` 的完整应用发布验收。

## 验收条件

每个目标需要全量生产源码 AOT、同一产物的 HTTP/TCP/UDP/MQTT/WebSocket 行为、实际数据库驱动、依赖身份与无源码部署验证。性能、容量和故障测试使用相同平台和负载，独立记录吞吐、延迟、CPU/RSS 与资源回收。

CI 定义见[原生 CI](native-ci.md)，当前差距见[实现规划](../guide/roadmap.md)。WASI、移动端及其他架构需另行确定组件范围与验收条件。
