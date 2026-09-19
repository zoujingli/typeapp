# 平台与工具链

正式目标为 Linux x64/ARM64、macOS ARM64、Windows x64 的原生开发、构建与运行。各目标分别配置匹配的 PHP ZTS/embed SDK、PHPX、Swoole 及实际生产扩展，版本与源码摘要取自 `composer.lock` 和 `toolchain.lock.json`，语言规则见[TypePHP 规范](../standards/typephp.md)。

Swoole 是通信和基础并发的必需依赖。按构建能力选择官方进程、线程或协程；进程不可用时使用线程/协程并重新验证隔离、退出和资源归属。上游提供某平台能力不能直接证明 TypeApp 的全部角色已通过该平台验收。

## 当前结果与证据

面向使用者的状态统一维护在[平台与验收](../guide/platforms.md)。该页进入 Docsify 公开站点；本文保留执行入口和可复核的产物身份。

| 平台 | 已保存结果与身份 | 范围 |
| --- | --- | --- |
| Linux x64 | [基础命令运行](https://github.com/zoujingli/typeapp/actions/runs/35452574738)，源码 `fed4efae5826bdca69a613743e1c6c459addd565`；产物 SHA-256 `ea87c232b80b9498d8083f3b2f1e4ed18d4a99c762e9fa69dc983253e845ddc2` | 26 个源码输入、29 个编译单元、9 项原生命令；断网只读 AOT 与真实运行 |
| Linux ARM64 | 本地 ARM64 Linux 虚拟机，源码 `be3052b0cb082aac509633cb3f7d11a4e41198a0` | 四组件 SQLite、身份/缓存、命令及 HTTP AOT；WebSocket PHP 单独记录运行库身份；完整应用受线程 SDK 阻塞 |
| macOS ARM64 | 本机组件与 HTTP 原生验收；HTTP 产物 SHA-256 `e8bf3f31fa9830c2dadb950aee41283a39958dd35dedc1fe22a65f4e5dea8ed2` | HTTP 审计 251 个源码输入、202 个编译单元；另有四组件 SQLite、身份/缓存和 WebSocket PHP，完整应用受线程 SDK 阻塞 |
| Windows x64 | [完整工作流运行](https://github.com/zoujingli/typeapp/actions/runs/35455624828)，源码 `25e1cf4a4f2160a845f105f3f003cae2d3e6f7d7`；四组件产物 SHA-256 `aa8ad29a97af018253d4988ea8ecc9fd37bfae58c7a0419d49296b490019e3f4` | 73 项测试/2247 断言、进程与平台行为、身份/部署审计/缓存、89 个源码输入的四组件 AOT 与真实 SQLite；标准应用 PHP 因缺少 Swoole 模块而拒绝，工作流整体未通过 |

Windows 身份消费者产物 SHA-256 为 `c00b44ecb68dc7211fa3c77e553d987c46d7f2c084fb82db19c1e66526a0ab5a`。部署审计退出码为 0，二次构建缓存命中且产物摘要不变；相同字节但未实际加载的路径、等长篡改均被拒绝。Windows 成功/失败原始产物与构建报告已回读核对摘要。macOS、Linux ARM64 的记录来自本地验收，不能称为对应 GitHub runner 结果。

上述结果按实际源码和产物成立。Linux 验收入口已统一选择 Swoole，macOS/Linux ARM64 工作流启用官方内置库；入口修改后的完整平台矩阵仍需实际执行，配置检查不计为原生通过。

## 继续验收的前置条件

- Windows：准备匹配 PHP 8.5.10 ZTS x64/embed 的 Swoole 模块，核对扩展依赖、SDK 布局与实际加载；当前应用 PHP 装置默认查找 Unix 模块名，需同时对齐 Windows 模块定位和子进程启动。
- 已编译线程：`NativeBuilder` 要求已适配并重编译的 PHPX、Swoole `startNative`/`NATIVE_ENTRY_ABI=2` 与 fiber 通知配置；官方 Swoole 6.2.2 的普通 Thread 不提供这些项目标识。具体接入见[已编译业务线程](compiled-business-threads.md)。
- 完整应用：满足 SDK 前置后重新执行应用、五种通信、三库、发布搬迁与无源码部署。Windows 组件审计成功不能替代 `NativePackage` 的完整应用发布验收。

## 验收条件

每个目标需要全量生产源码 AOT、同一产物的 HTTP/TCP/UDP/MQTT/WebSocket 行为、实际数据库驱动、依赖身份与无源码部署验证。性能、容量和故障测试使用相同平台和负载，独立记录吞吐、延迟、CPU/RSS 与资源回收。

CI 定义见[原生 CI](native-ci.md)，当前差距见[实现规划](../guide/roadmap.md)。WASI、移动端及其他架构需另行确定组件范围与验收条件。
