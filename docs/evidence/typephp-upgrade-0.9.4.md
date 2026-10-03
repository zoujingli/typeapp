# TypePHP 0.9.4 与 Swoole 开发快照升级

本记录从 2026-10-02 开始，目标版本为 `v1.0.0-rc.14`。RC13 的源码、产物与结论保持原身份；本次候选必须完成自己的四平台 × 三数据库静态程序验收，不能沿用旧版通过状态。四平台共享模块已重建并导入，Windows HTTP 退出故障已完成定向复验，当前继续定位 TCP 连接失败并汇合完整应用和静态 SDK 验收，尚未公开 RC14。下面按准确源码记录每轮结果，不把排队或局部成功当作完整通过。

## 固定输入

| 输入 | 版本及源码提交 |
| --- | --- |
| PHP | 8.5.10 ZTS，保持不变 |
| TypePHP | 0.9.4，`874b82e96a2383712e8faf8177c6c715587e3546` |
| PHPX | 2.9.3，`a0138bbdd6cbfda62225adc56c558d0742114c8a` |
| Swoole | `4aff74a9ac086458d1c5251e71ac6e080f68b390`，运行时字符串 `6.3.0RC1` |
| 对照版本 | RC13，源码 `aa95924a871f7f07c891cd38015d7182a8f7e616` |

Swoole 候选在 `v6.3.0-rc1` 之后包含 42 个提交，属于开发快照。上游正式版 6.2.3 与项目原 Windows 支持分支已经分叉，本次不将其直接替换进现有 SDK，也不把候选称为正式 6.3.0。固定源码归档 SHA-256 为 `63598eba7d2a36d8820b1501854161e5c326ab30a32a419e3aa0e4d5154936cd`。

## 适配审查

| 适配 | 上游变化与本次处理 | 撤除条件 |
| --- | --- | --- |
| Swoole 线程标准流 | 上游在线程请求关闭后回收标准流；删除项目的 `native_stdio_owned` 和请求关闭重复处理，调用官方注册与回收路径 | 已由上游承担，须持续验证描述符和输出 |
| 原生线程入口与共享控制 | 上游没有 TypeApp 的 AOT 入口、有限 join 和受控参数 ABI；继续保留 | 上游提供等价的已编译入口与生命周期契约 |
| HTTP 原始头与请求校验 | 上游增加分块完成、Upgrade 长度和上传失败处理；原始头与重复头校验仍有项目调用者 | 官方接口覆盖相同信息并通过协议回归 |
| Socket、TLS、Windows | 保留空 UDP 对端、IP SAN 校验、WSAPoll/IOCP 与 PDO 构建适配；上游新增 bcrypt 链接等变更未覆盖这些缺口 | 逐项达到行为等价后删除 |
| PHPX | 2.9.3 将 Args 实现移入 base.cc；异常传播、属性语义、线程/协程状态隔离尚未被等价替代 | 对应官方行为通过现有 AOT 消费者 |
| TypePHP 编译入口 | 复核 CompilerBase 与 Translator、常量数据结构和回调声明；更新受审源码摘要 | 上游修复已有兼容缺口并通过生成代码和业务验收 |
| Windows 静态 CRT | 上游 C 编译也默认 `/MD`；统一 C/C++ 为 `/MT`，使用官方 `section_gc`，拒绝冲突 CRT | 上游可直接声明同一静态 CRT 契约 |
| 文件 I/O 候选 | 迁移组合适配与源码校验；继续作为显式候选，不自动进入默认发布 | 专项线程、取消、容量及失败收尾完整通过后另行决定 |

适配仍绑定原文 SHA-256 和替换次数；不允许更换版本后只改预期摘要。Nano、FPM 或业务 opcode 路径不纳入本次升级，生产 PHP 继续全量 AOT。

## 四平台模块身份

[重建运行 37075789749](https://github.com/zoujingli/typeapp/actions/runs/37075789749) 以主仓提交 `f6c092f436f2a341bb6d588d333d761c2ab64732` 构建并核验四个平台模块。四个平台均实际加载 PHP 8.5.10 ZTS 模块，核对原生线程 ABI 2、协程 SQLite CRUD 和 PostgreSQL 连接拒绝路径；该范围不代替完整应用静态验收。随后模块和清单以 `d646837d445adb5543a5c71ae45b2a616f62a21b` 写回组件。这是模块重建批次的历史身份；模块内容未因后续测试和 HTTP 入口修复而改变，该批次只证明所列模块范围。

| 平台 | 模块字节数 | SHA-256 |
| --- | ---: | --- |
| Linux x64 | 3,590,464 | `83a6af66a7588233ae8539fb060ec6afdb92cd1e8b93c4eb99f6b40a4bf62ee5` |
| Linux ARM64 | 3,439,800 | `d78a1507c65a4070faeedbf369a3830a352244d2daaca7cde0e0af7f7585b823` |
| macOS ARM64 | 9,350,528 | `a1389c3b451e309158152a1f8f82019e35b462c1f73c365f7cf0f7e6c41e82a2` |
| Windows x64 | 2,415,616 | `f5d7b69e573b508c2d92e17384f9b1457ca0e50b7e404e8f3d96cd616fa78fa6` |

本轮 Artifact ID 依次为 Linux x64 `11256621733`、Linux ARM64 `11256736708`、macOS ARM64 `11257001358`、Windows x64 `11256971455`；组件清单同时保留 Artifact 压缩包摘要、编译依赖和补丁前后摘要。macOS 的 SQLite 为本轮构建实际使用的 3.53.4，原始许可头文件随组件保留。独立分发检查剔除了误收的 Finder 元数据，并再次通过离线 Composer、含空格目录、不同工作目录、子树拆分、自动换行检出及所有模块和许可摘要核验。

静态 SDK 的 Swoole 来源由同一组件清单提供，分别记录源码提交、归档摘要、运行时版本和开发渠道；实际 embed 探针必须返回相同运行版本。旧来源、过期补丁和错误 ABI 均拒绝。Windows PHPX 原始归档重新下载核验为 `bae0d807610ddaade17b741e11c9397fd3e92f6bf7766cc61ca857fb53c5e0dd`。上游 TypePHP 0.9.4 没有 PHP 8.5.10 的 Windows SDK 载体，准备脚本只复用已核验的 `tpc_v0.9.0_windows_x64` PHP 8.5.10 ZTS 发行包；脚本现在先强制核对 `composer.lock` 中 TypePHP 0.9.4 的固定提交，再在任务目录重建 PHPX 与 Swoole，因此不能把 SDK 载体版本当作编译器版本。

## 已执行与待完成

### RC14 原生门禁运行轮次

截至 2026-10-03，以下轮次已有明确结论。它们采用新版工具链，但源码不同，不能合并成同一提交的四平台通过：

| 平台及运行 | 固定源码 | 实际结论 |
| --- | --- | --- |
| [Linux x64 37104164091](https://github.com/zoujingli/typeapp/actions/runs/37104164091) | `0c9b59cc0999406a923acf9fd01a50a56093ca80` | 二十组与 `native-complete` 成功；不包括后续 Windows HTTP 修复 |
| [Linux ARM64 37103146289](https://github.com/zoujingli/typeapp/actions/runs/37103146289) | `442a244a06bb138d1bba130a54336e124927dab7` | 完整默认工作流成功；仍须按最终候选源码重验 |
| [Windows x64 37108670317](https://github.com/zoujingli/typeapp/actions/runs/37108670317) | `2dfabab4e7f24f82247bb1999ad4317f28e1c343` | 线程、初始化失败与资源隔离通过，HTTP 消费者失败；完整工作流失败 |
| [Windows IOCP 诊断 37108902165](https://github.com/zoujingli/typeapp/actions/runs/37108902165) | `62bb2f1222c10739c8142e77204a5044e96758c2` | 官方 trace 记录 ACCEPT 提交后未完成，首个 HTTP 请求超时；保留原程序与日志 |

macOS 的早期轮次存在 Homebrew tap 网络失败和后续推送取消，尚无最终候选完整通过结论。基础设施失败与代码行为失败分别记录。Windows 修复后的专用 HTTP 诊断 [37109594948](https://github.com/zoujingli/typeapp/actions/runs/37109594948)，源码 `be5a0f7f3bcac945aa6b305d03d29a89f027094a`，已完成三轮 HTTP 输入、容量、清理与端口释放；随后在监督正常停止的测试断言失败：Windows 只有一个自绑定线程且返回 `[0]`，旧断言仍固定要求两个线程的 `[0, 0]`。现按本轮实际应 join 的线程数量逐个核对零退出码，继续完整回归，不把这轮诊断记为全部通过。

### Windows 回归诊断与修复

- 资源观察器：`37107861848` 的 PowerShell 子进程在原 3 秒预算内未完成，原报告为 `timedOut=true`、`exitCode=255`、双流为空。Windows 冷启动预算改为 15 秒，Unix 保持 3 秒；外层线程测试仍保留总截止和检查点。随后 `37108670317` 的三轮线程及资源观察通过。主程序被清理时的 `-1073741510` 不冒称为原生崩溃。
- 临时端口监听：固定 Swoole 的 `Socket::free()` 在存在 reactor 时延迟释放原生句柄。macOS 最小复现中，`close()` 和 `unset()` 后仍能连接，执行所属 `Swoole\Event::wait()` 后连接被拒绝。HTTP 夹具的两处临时监听先完成延迟回收，再启动自绑定业务线程；Windows 随后完成三轮 HTTP 请求，原 ACCEPT 等待故障不再出现。
- 入口契约：`serveThreadOwned()` 补齐与 `serveThread()` 一致的连接额度和原始 HTTP 输入 ABI 校验，传入 `typeapp_max_connections`、`typeapp_http1_input`。使用现有重复头、HTTP 版本、满额拒绝和恢复断言验证，不修改业务错误码或放宽容量。
- 诊断隔离：生产服务器删除临时阶段日志与 trace 环境变量处理。`scope=http-probe` 的官方 IOCP trace 仅由独立测试夹具显式启用，其源码重建模块不替换组件分发模块；诊断结果不代替完整 Windows 门禁。

[Windows 完整回归 37110360061](https://github.com/zoujingli/typeapp/actions/runs/37110360061)，源码 `11a73078df41a9f8116384705251d37603f24666`，再次通过线程重建、初始化失败、协程资源隔离和三轮 HTTP；正常停止、部分启动、业务阻塞的监督检查也已执行。该轮在最终 TLS 析构阻塞的 `exit-gate` 模式超时（`exit=255`、`timed_out=true`、双流为空），未通过完整门禁。原程序及报告保留在 Artifact `11269503645`。Windows 原生终止入口改用 `TerminateProcess`，避免 `_Exit` 进入 DLL 退出清理；相同析构故障与全部监督模式仍须在修复后的程序重验，不放宽原三秒上限。

修复后的 [Windows HTTP 定向回归 37112209968](https://github.com/zoujingli/typeapp/actions/runs/37112209968)，源码 `fe3c22f79fdaabc607012cd13b72d4fda1623906`，已完成三轮 HTTP 与全部生产监督模式。三次 `exit-gate` 均实际进入阻塞析构，健康线程已停止、故障门未释放，进程分别在 1.064、1.048、1.175 秒以 75 退出，未超时。证据保留在 Artifact `11270275821`，消费者 SHA-256 为 `57e737a3fddf3001d5416d61b5a3dd7002e784a6c06ca2d85089fa04cbf6583e`。这证明该退出故障已被定向回归覆盖；带官方 IOCP trace 的诊断模块仍不作为分发模块，也不代替完整 Windows 门禁。

同源码的 [Windows 完整回归 37112211610](https://github.com/zoujingli/typeapp/actions/runs/37112211610) 使用组件分发模块，通过线程、初始化、资源和 HTTP 后，在 TCP 消费者失败。两个业务线程均返回 `tcp_connect_failed`、`errno=1214`，进程退出 255，未超时；原程序和失败报告保存在 Artifact `11270572147`。随后仅在夹具中增加当前 host、TLS 和场景检查点，并启用分别运行 TCP/UDP 的 `scope=socket-probe`；诊断不跳过域名、IPv6、TLS 或错误收尾，也不作为完整验收通过。

[通信定向回归 37114447340](https://github.com/zoujingli/typeapp/actions/runs/37114447340)，源码 `4831d134dec3246edbc96970e77a16f7229f5588`，进一步定位：TCP 两个线程完成 39 项断言后均在 `::1` 的非 TLS 回声连接返回 1214；UDP 两个线程均未拒绝重复绑定。原产物分别保存在 Artifact `11270313238`、`11270519532`。固定上游的 `Address::assign()` 没有初始化 IPv6 flowinfo/scope_id；Windows 适配现清零地址存储，待相同原生场景复验。UDP 使用 [Winsock 独占地址选项](https://learn.microsoft.com/en-us/windows/win32/winsock/using-so-reuseaddr-and-so-exclusiveaddruse)：仅关闭本端 `SO_REUSEADDR` 不能阻止另一端抢占，因此框架在 bind 前设置 `SO_EXCLUSIVEADDRUSE`，固定 Swoole 适配保留调用者的独占设置。未请求独占的 Socket 沿用上游复用语义；错误码、端点归属和额度归还断言保持不变。

这两处原生适配归入 `SwooleWindowsSource`，绑定原文摘要与唯一替换；共享模块、静态 SDK 均需重建，旧 Windows 模块在适配身份变化后必须拒绝。上游初始化地址存储并尊重独占选项、且相同 IPv4/IPv6、TLS、双线程及收尾回归通过后撤除对应适配。此次修改的通信结果仍待验收，不能据源码审查宣布修复完成。

[Windows 模块重建 37115394185](https://github.com/zoujingli/typeapp/actions/runs/37115394185) 已按 `5b3fe64ae2e879d108b346b4716f852901898d59` 完成。新 DLL 为 2,416,128 字节、SHA-256 `c8a046a9b93297198eab0166fca8118d9111f9015563d76f83c5a9987a1f42fd`，Artifact `11270798483`；六个适配文件的前后摘要与本地固定源码复核一致，依赖和许可证未变化。此模块替代前文初始批次的 Windows DLL，仅完成加载、协程 SQLite 与 PostgreSQL 连接拒绝检查，后续通信及静态程序结果另行记录。

macOS UDP 复验先暴露独立消费者的运行目录问题：默认模块仍在受禁读的 Composer `vendor` 中，加载被沙箱拒绝。消费者现复用 `independentSwooleModule()` 将同字节模块放入独立运行目录，保持源码禁读范围。随后六个生产包、91 个源码单元全量 AOT 通过，双线程重建两轮及主线程协程均完成 IPv4/IPv6、重复绑定拒绝与资源归还；程序 SHA-256 为 `43fe4dad52ac68473964c48ddc47fef5d79fe2b5533e90e56908d2abf9b51aff`，报告位于 `build/toolchain-upgrade-followup.lVbKwh/udp-exclusive-runtime/run-8e80492992/verification.json`。首轮加载失败另行保留，不能据此推断 Windows 通信结果。

[Windows 通信复验 37116138110](https://github.com/zoujingli/typeapp/actions/runs/37116138110) 使用源码 `3c583020f5fb5813256feaa8eabd469fab3775e8`。UDP 全部通过：两轮双线程各完成 375 项断言，主线程协程完成 374 项，退出均为零且协程清空；六个生产包、91 项源码全量 AOT，程序 SHA-256 为 `8ad36c8a8ac7c8e048305be952c9646410874b1590ab782312d2bf80b2280510`，Artifact `11271511889`。该独立消费者在 Windows 的源码禁读字段仍为 `not-verified`，不能据此声称完成无源码隔离。

同轮 TCP 已通过非 TLS 的 IPv4、IPv6 与 localhost 回声，两个线程各完成 87 项断言后在 `tcp.typeapp.test` 返回 DNS 错误 711；进程退出 255、没有超时，尚未进入 TLS。Artifact `11272275702` 保留原程序与失败点。新增的最小 DNS 探针在 [37117228380](https://github.com/zoujingli/typeapp/actions/runs/37117228380)、源码 `2d4f1300743a912f12a90a32ed74e2c4b955bf09` 中复现主线程和两次工作线程重建均返回 711，独立 DNS 对端没有收到查询。固定 Swoole 在未启用 c-ares 时走系统解析器，原 Windows 共享模块的配置确实缺少 `--enable-cares`；静态 SDK 已启用，不能把共享模块的失败泛化为静态程序已经失败。

共享模块构建现加入固定 c-ares 1.34.8（源码 `c7a3138dcfe3bb0eaaf10c0c24c36dc66dc790ab`，归档 SHA-256 `c9ea1b3029b23b04376c229bd519489cee180874ec48cd863a5dcba628c0fe03`），使用与共享 PHP 一致的 `/MD` CRT，并静态链接到 Swoole DLL。生产静态 SDK 继续 `/MT`。原始许可证与组件保留材料的 SHA-256 均为 `460f5e768fda3752ca2169a95df062578a10fb126bfd65f3b9b1a1bed2f84807`。构建同时检查 `SW_USE_CARES`、实际静态库摘要及 DLL 导入表；模块重建和完整 TCP/TLS 结果需另行记录。

IPv6/独占绑定适配后的 Windows 静态 SDK 已按 `3c583020f5fb5813256feaa8eabd469fab3775e8` 分别重建：[SQLite 37116141545](https://github.com/zoujingli/typeapp/actions/runs/37116141545)、[MySQL 37116144369](https://github.com/zoujingli/typeapp/actions/runs/37116144369)、[PostgreSQL 37116146811](https://github.com/zoujingli/typeapp/actions/runs/37116146811) 均成功。回读三份原生探针报告，实际数据库扩展各自仅包含所选驱动，PHP 8.5.10 ZTS、Swoole `6.3.0RC1` 与系统库清单均匹配；探针范围为 PHP 和扩展 embed，不能替代下文应用程序验收。SDK Artifact 依次为 `11272016209`、`11271896210`、`11270749874`。

### 静态程序预验收

以下运行采用源码 `11a73078df41a9f8116384705251d37603f24666`，用于验证新版工具链与静态依赖。它们不是最终 RC14 标签的候选附件，后续发布仍须从最终固定源码重新构建并验收同一程序。

| 平台 | SQLite | MySQL | PostgreSQL |
| --- | --- | --- | --- |
| Linux x64 / ARM64 | [37110396184](https://github.com/zoujingli/typeapp/actions/runs/37110396184)，均通过 | [37110398545](https://github.com/zoujingli/typeapp/actions/runs/37110398545)，均通过 | [37110400048](https://github.com/zoujingli/typeapp/actions/runs/37110400048)，均通过 |
| macOS ARM64 | [37110399992](https://github.com/zoujingli/typeapp/actions/runs/37110399992)，通过 | [37110400955](https://github.com/zoujingli/typeapp/actions/runs/37110400955)，通过 | [37110402374](https://github.com/zoujingli/typeapp/actions/runs/37110402374)，第 2 次执行通过；首次程序验收通过后因重建材料的 GNU 下载超时失败，保留原结果 |
| Windows x64 | [37110714410](https://github.com/zoujingli/typeapp/actions/runs/37110714410)，通过 | [37110715499](https://github.com/zoujingli/typeapp/actions/runs/37110715499)，通过 | [37110716660](https://github.com/zoujingli/typeapp/actions/runs/37110716660)，通过 |

Linux x64 的完整原生功能运行 [37110360053](https://github.com/zoujingli/typeapp/actions/runs/37110360053) 同样通过二十组及汇总门禁。Linux ARM64 的 [37110376883](https://github.com/zoujingli/typeapp/actions/runs/37110376883) 十组功能通过，但附加性能任务在旧程序安装阶段失败，故整个工作流仍记为失败；使用各自产物 INI 的性能复验另行记录，不改写原结果。macOS 的完整原生功能运行仍在执行，不能用上表单程序结果替代全部组件回归。

回读上述十二个程序的构建及同产物业务报告，全部记录移除前端源码、仅单个可执行文件、profile 拒绝，以及同一摘要上的 MQTT、告警、导出和调度通过。相较 RC13 同平台同 profile 程序，体积增长为 0.00007%～0.21934%，均低于 5% 门槛。这里比较的是最终可执行字节数，不是输入静态归档的累计大小；macOS 保留运行所需符号，不能将其符号表等同于 DWARF 调试信息。

| 平台与 profile | 字节数 | 程序 SHA-256 |
| --- | ---: | --- |
| Linux x64 SQLite | 59,000,598 | `113beaf42c878f655e5f6199ed5c8cf8cd175fec0f263e02957b7d4315efe465` |
| Linux x64 MySQL | 56,724,839 | `dd4271b8d023ffc21addb12b4bf569aff0519f67794cd231a880ee597a5fbde1` |
| Linux x64 PostgreSQL | 56,821,616 | `2892edbf6b42e488adf922b260f15187b69bd2a12b7c6a220d9e0c7fe5159ec0` |
| Linux ARM64 SQLite | 50,307,350 | `f4d545c8966c41897840b18fa8bf46a1a821528793e77fdfb729e2c357207ad0` |
| Linux ARM64 MySQL | 50,033,687 | `0d80165529c6e78112891ed08a31d35d0bca7a7029e4ace6f046bccf44fb81bb` |
| Linux ARM64 PostgreSQL | 50,159,384 | `83c83e048960f95bc7a32068d974208b9dc4f212952d5b5087942e2283f6dd46` |
| macOS ARM64 SQLite | 47,748,056 | `505e81db1f323c70f9466849788b2cb052a5ad2b1db3af6f989ada46a3390731` |
| macOS ARM64 MySQL | 46,130,248 | `a82fca33ec20be6759d8654b793af1d5f877459460b80bae7f9547a112de6cdf` |
| macOS ARM64 PostgreSQL | 46,238,856 | `7cc471171c163eec5bae351ad7f09f21b807cb780a976602c211a6a5d593466d` |
| Windows x64 SQLite | 50,721,279 | `5c40b6f250f3acb94e5f35b2d16848a438f4ad0de9edf802795223bf7e2f5b80` |
| Windows x64 MySQL | 49,794,598 | `74cb09a6f0a72114707a547e6c191d05e1f0b70e78196b9685a82fbf06a1bfef` |
| Windows x64 PostgreSQL | 49,965,520 | `8517becbdf62cee94d5bfb86e812e7023c5177128e408fe27e8d5fa8ab610df1` |

### 性能测量的运行环境

macOS 的新旧程序分别从 RC13 源码与 `4631be08002695625477229d2552e10b7834a242` 完整编译，使用相同 PHP SDK 和 83 个相同前端资源。首轮测量在旧程序安装阶段被 `运行扩展身份不一致：swoole` 拒绝：成对控制器错误地继承了当前新版模块的 INI。仅改为该旧程序构建报告中的运行 INI 后，同一程序完成空库与前端安装。成对测量入口现逐版本定位和记录已探测 INI，失败轮次保留，不计入吞吐或延迟结果；原始基准程序未因这个控制器修复重新编译。

随后完成旧版在先、新版在先两轮测量；每个顺序分别执行三库、两个版本、三次独立重复，负载和程序保持相同。旧版在先的 SQLite/MySQL CRUD 吞吐中位数分别变化 -1.12%/-0.87%，触发复验；新版在先时分别为 -0.14%/+0.32%，未再次触发。逆序的 SQLite/PostgreSQL 慢依赖负载出现单轮信号，对应首轮未触发。两轮没有同一负载重复触发，但这不证明性能等价或改善；保留全部单轮信号，不以较好的一轮替换原结果。

原始成对报告为 `build/benchmark-pairs-7c8afe62a9de/verification.json` 与 `build/benchmark-pairs-a4ffe8a3bf9f/verification.json`，比较结果分别为同目录的 `comparison-935c77356901.json` 和 `comparison-a0de776d9769.json`。报告包含每个样本的吞吐、p50/p95/p99、CPU 与采样 RSS；两轮各负载的 RSS 中位数变化约 -0.03% 至 +1.18%。共享 embed 基准的编译耗时为旧版 322.909 秒、新版 328.581 秒，程序分别为 32,014,952 和 32,067,528 字节；这些数值不能代替发布静态程序体积，也不能推断其他平台性能。

文件流对照复用 `examples/file-http-command.php` 与 `examples/files`，以相同源码分别编译两版独立消费者。每轮预热 10 次，再下载 100 次 1 MiB 文件，逐次校验状态、长度和摘要；每种顺序执行三组新旧配对。首轮吞吐中位数为旧版 396.12、新版 392.36 次/秒（-0.95%），逆序复验为 392.48、395.30 次/秒（+0.72%），没有重复出现下降。首轮 p50/p95/p99 为旧版 0.723/1.031/1.114、新版 0.727/1.046/1.106 毫秒；逆序为旧版 0.722/1.072/1.155、新版 0.730/1.044/1.103 毫秒。两轮 CPU 中位数均为 0.07 秒，RSS 中位数变化分别为 +0.47% 和 +0.26%，全部服务正常停止。

文件消费者的旧/新编译耗时分别为 136.448/129.356 秒，程序分别为 3,278,456/3,314,424 字节。准备身份、原始样本和两轮比较位于 `build/toolchain-upgrade-followup.lVbKwh/file-benchmark-*.json`；旧/新程序 SHA-256 分别为 `5ab734e8c3e9d059ecab6deffe745f9f661a4cd7d330cc9573a55895a1346d9d`、`73b73f54b201fc6c8a41196ecbaa1093e310e1d4bb2a05d928c568607c230196`。该顺序负载未确认持续回退，也不支持宣传吞吐改善或推广到其他平台、并发量。

[Linux ARM64 首轮完整测量 37112212467](https://github.com/zoujingli/typeapp/actions/runs/37112212467) 比较 RC13 与 `fe3c22f79fdaabc607012cd13b72d4fda1623906`，三库均完成三次重复；比较器返回 `regression-signal-needs-repeat`，整个运行记为失败。PostgreSQL 短 JSON 吞吐中位数变化 -6.42%、p50/p95 分别 +7.15%/+5.95%；SQLite 慢依赖、PostgreSQL CRUD 和慢依赖也触发复验信号。原始测量与比较保存在 Artifact `11270479905`。逆序复验继续固定这两个源码提交；新的运行会重新编译，须分别记录程序摘要，不能称为同一原生字节的复测。

本次升级尚未完成 Linux x64 与 Windows 的同条件性能对照，也未完成非 macOS 平台的文件流对照。各平台功能验收与程序体积核验不能替代这些测量。

待平台任务完成后，必须追加每个平台的最终程序身份、12 个 profile 的摘要、实际未执行范围和公开下载回读；性能与体积对照没有原始报告时保持未验证。

- 本机 macOS ARM64 已编译新 Swoole 共享模块与 PHPX，真实 PHP 8.5.10 ZTS 加载、协程 SQLite 和 PostgreSQL 连接拒绝路径通过。该本机模块链接开发依赖，不能作为可移植分发模块。
- 独立线程消费者已全量 AOT；移走源码后连续三轮验证双线程、存活线程隔离、非零返回、exit、八次重建与标准流输出，资源报告记录句柄、线程及 RSS。
- 同一资源消费者完成三轮双线程 SQLite、预算、协程排队与清理。测试改为等待实际进入关闭路径，避免将 Channel 入队误当作 SQLite hook 已完成让出；生产所有权断言未放宽。
- HTTP 消费者完成无源码双线程、输入校验、分块正文包含结束标记、分块长度跨包、trailer 与流水线后续请求，以及超长 Upgrade 头检查。新增 WebSocket 输入保留、不完整帧拒绝、无符号关闭码与上传临时目录失效检查，共 34 项原生契约；完整消费者和生产监督均通过三轮。预期上传警告保存在独立原生日志，结果 JSON 保持明确。默认运行模块复制到消费者的独立运行目录，源码禁读范围保持原样。
- 当前本机格式检查 803 个文件、基础检查 824 个文件、文档与分发入口检查通过；单元测试 187 个、15,353 个断言通过。批次消费的工具版本改为读取固定提交的锁文件，已覆盖工作区存在未提交版本变更的情形。
- [Linux x64 原生回归运行 37058951633](https://github.com/zoujingli/typeapp/actions/runs/37058951633) 中，`threads`、`initialization`、`resources`、`http`、`tcp` 通过，`udp` 在两个业务线程中退出码为 1，因而整组未通过；失败批次的工具链 Artifact 保留在 [type-app-linux-x64-toolchain](https://github.com/zoujingli/typeapp/actions/runs/37058951633/artifacts/11250585207)。本次修复会重新核对每次原生线程创建时的 hook 状态，并上传线程内部 `.failure` 证据。TCP 组包含 macOS 连接后的内核缓冲自动调优修复；线程组覆盖新版 Swoole 的 `join()` 清理窗口，运行时只在主线程独占时有限重试，并在业务线程已经存在时返回稳定错误码。
- 早期失败记录仍保留在同一构建批次目录及原生日志中，包含 macOS TCP 缓冲误报、HTTP hook 重复安装失败和线程已创建后的 hook 重试失败；这些记录用于追溯修复，不能并入最终通过结论。
- 文件 I/O 候选已重新适配、编译并全量 AOT；大文件、metadata、锁、上传、缓冲和跨线程容量通过。强制停止场景当前得到退出码 75，而原专项契约要求 200，尚未完成全部专项验收，默认模块未启用此候选。
- 十二组合静态程序已完成上表所列源码的预验收；最终标签的全量编译、完整组件回归及 RC14 公开发布仍须完成。性能比较使用各版本自己的 PHPX 适配和 Swoole 模块，前端资源保持相同；共享 embed 负载对照与最终静态程序体积分别记录。

发布仍为对应平台与数据库 profile 的一个程序加外置配置，原生运行库启动不释放。数据库与按功能需要的 Redis 是外部服务；管理前端在显式安装命令中释放到 `public`。单文件边界不因本次工具链升级改变。
