# 内置 Swoole 模块实施与验证

2026-09-24，主仓首次在 `bin/swoole` 入仓四份真实预编译模块（现迁入[构建组件资源](../../plugin/type-build/resources/swoole/README.md)），合计 18,502,232 字节。固定 Swoole 6.2.1、PHP 8.5.10 ZTS 非 debug 64 位 ABI；准确来源、源码适配与文件摘要以[清单](../../plugin/type-build/resources/swoole/manifest.json)为准。

## 初次入仓时的构建行为

`RuntimeProfile` 在真实 embed 未内置 Swoole、调用者未明确指定模块时，从项目内置清单选择并校验文件。清单也纳入构建身份。已有清单但没有匹配 ABI、文件被篡改或适配源码已变更时失败；没有清单的独立应用继续使用 SDK。

Unix 准备入口与 Windows SDK 脚本默认复用模块；三个 Unix 原生工作流删除 setup-php 中随后会被覆盖的 Swoole 下载/编译步骤。Linux 显式准备动态依赖。Windows 只在 `TYPE_SWOOLE_BUILD_FROM_SOURCE=1` 时准备 Swoole 源码和专用 phpize 工具。PHP SDK、PHPX 及其他依赖仍独立准备。

## 初次入仓的验证

| 范围 | 结果与边界 |
| --- | --- |
| 四份模块 | 文件格式、机器类型、SHA-256、补丁身份及 17 份原始许可材料摘要一致；有效选择、缺文件、篡改、过期适配、错误 ABI 和越界路径均有契约测试 |
| Linux ARM64 / x64 | 真实 PHP 加载、原生线程入口 ABI 2、协程 Channel、SQLite hook 通过；默认准备入口在禁网容器中通过；x64 在 ARM64 主机模拟运行，未完成本批模块的 Linux 全量应用验收 |
| macOS ARM64 | 部署目标 15.0，实际运行环境 27.0；禁网默认选择、真实 embed、覆盖优先级、PostgreSQL 连接拒绝、WebSocket 与 WSS/TLS 1.2/1.3、错误 CA/主机名拒绝均通过 |
| Windows x64 | 复用 run 35967392758 的 DLL，既有 SDK 模块检查通过；该工作流整体失败，本轮没有执行新版 Windows 准备脚本或 PHPX 2.9.2 Windows 全量验收 |

PHPUnit 契约套件通过 87 个测试、2961 个断言；最终模块的选择与文件专项复核通过 2 个测试、23 个断言。格式检查通过 744 个文件，基础检查通过 763 个 PHP 文件及 3 个分发拒绝场景。三个修改后的工作流通过 actionlint（未启用 ShellCheck）；CI 本身未运行。

macOS 最终应用使用 TypePHP 0.9.3、PHPX 2.9.2，全量编译 255 个生产输入、21 个生产依赖包及 271 个编译单元。未设置 `TYPE_SWOOLE_MODULE`，运行清单实际选择仓库中的模块。

- 构建身份：`804bb86d8db8b30030dd750f9c07d5ce1b0b4d64f27b4243843cf93fb7d4eab6`。
- 程序 SHA-256：`3d48f6e0e81bf11393e1450d3081d822c6469b37f86675ebe880d8149ac09aec`。
- 同一程序的无源码身份 HTTP 验收：MySQL 508 项、PostgreSQL 507 项、SQLite 508 项检查通过；HTTP 进程正常退出，两个独立数据库实例均正常关闭。

## 验收中修复的链接问题

最初 macOS 扩展漏链 `libpgcommon_shlib.a` 和 `libpgport_shlib.a`。扩展加载能够成功，但连接 PostgreSQL 时，延迟绑定的 `pg_strcasecmp` 为空，进程发生崩溃。最小 PDO 复现、调试器栈及失败产物身份已保留；补齐同版本归档后，连接拒绝回归及完整三库验收通过。`tests/runtime-profile.php` 保留实际 PDO 失败路径检查，防止仅用扩展加载成功判断可用。

最终 macOS 模块隐藏所有静态子依赖符号，无未解析 PostgreSQL 私有符号，动态依赖仅为系统 libz、libSystem、libc++。PHP API 仍由宿主提供。

## 保留边界

这些 `.so/.dll` 是共享扩展构建输入，不能转换成完整静态链接的证据。单程序加配置、启动不释放运行库的目标仍未完成；现有阻点见[完整静态链接可行性](../development/static-runtime-feasibility.md)。macOS 15 实机、其他未列架构及本轮 Windows/Linux 全量应用验收仍需对应平台验证。

## 证据与资源回收

回收本任务的源码副本、中间对象、重复下载和独立测试数据库，扣除保留证据后释放 2,482,233,344 字节。另删除两个已退出的专属 Linux 构建容器及本任务下载的 PHP 镜像。共享 SDK、缓存及其他项目服务保留。

最终程序、构建身份、资源与运行配置保留在 `build/swoole-bundle.d0S07W/app`；原始构建日志、三库报告、最初失败产物与复现证据等 143 个文件保存在 `.cache/swoole-bundle-d0S07W/evidence.tar.gz`，逐文件回读验证，索引及恢复方法在同目录。归档 SHA-256 为 `6b2d357b56d64ae7b11db4e8f7915ced8b92f7b1d7f30368a3304ace9da057f3`。这些是原位置的本地验收材料，不代表迁移后的验证。当前版本化来源与模块位于 `plugin/type-build/resources/swoole`。

## 迁入构建组件后的验证

四份模块、清单及 17 份原始许可材料迁入 `plugin/type-build/resources/swoole`，22 个 Git blob 与迁移前完全一致。`BundledSwoole::select()` 改为无参数，从组件自身安装位置选择；不依赖主仓或消费应用的目录布局。真实 embed 内置、显式声明和有效环境候选继续优先，默认内置清单缺失或不匹配则明确失败。当前行为见[运行依赖](../development/runtime-profiles.md#选择与失败语义)。

组件自己的 `.gitattributes` 保留源码 LF、模块及原始许可字节；分发白名单接受该根文件和带版本标记的许可目录。`composer test:bundled-swoole-consumer` 从真实组件形成 Git 子树，启用 `core.autocrlf=true` 检出后，再通过 Composer 独立安装；固定依赖从本地读取，关闭 Packagist 和 Composer 网络。macOS 系统禁网策略下再次运行通过，覆盖含空格路径、不同工作目录、四份模块及所有许可摘要。主仓准备脚本的系统禁网选择也通过。

本轮 PHPUnit 通过 91 项测试、3052 个断言；格式检查通过 745 个文件，基础检查通过 764 个 PHP 文件和 3 项分发拒绝用例，最终文档一致性核对 1540 处引用。真实 Git 分发批次回归通过；真实 embed 的默认选择、显式覆盖优先级、清单身份、ABI/函数/警告拒绝及 PostgreSQL 连接失败回归通过。

macOS ARM64 使用 TypePHP 0.9.3、PHPX 2.9.2，从新位置默认选择模块，未设置 `TYPE_SWOOLE_MODULE`；缓存未命中，完成 255 个生产输入、21 个生产依赖包和 271 个编译单元的完整构建。所选清单进入构建身份，应用资源未包含其他平台模块。

- 构建身份：`90dc751a469b84cb2fa781cbfef4848cbdbc39ebc928901ae6fde676b69251fe`。
- 程序 SHA-256：`ab73662861395bb6d6bd245c9948291bcdc36f5d16386767561c91c68671de35`。
- 同一程序的无源码身份 HTTP 验收：MySQL 508 项、PostgreSQL 507 项、SQLite 507 项，共 1522 项；HTTP 服务与两种数据库实例正常退出。

本轮没有执行 Linux/Windows 全量应用、Windows 准备脚本或 macOS 15 实机验收，原有平台限制继续保留。共享模块迁移不改变完整静态单程序目标的未完成状态。

最终程序、构建身份、应用资源和实际运行配置保留在 `build/swoole-component.qpnrdrzn/app`。原始日志、三库报告、源码差异与锁定输入等 92 个文件归档至 `.cache/swoole-component-qpnrdrzn/evidence.tar.gz`，逐文件回读校验；SHA-256 为 `85040b7f4a2584dee422cb767be7826e3423b1a3cbb8424096d1c4606ecffd02`，索引、清理范围及恢复说明见同目录。确认测试进程退出、数据库端口关闭、临时目录无打开文件后，回收本轮中间对象、探针副本、运行包副本与测试数据，扣除归档净释放 617,066,669 字节。现有 SDK、共享缓存、历史证据及其他服务保留。
