# TypePHP 0.9.4 与 Swoole 开发快照升级

本记录从 2026-10-02 开始，目标版本为 `v1.0.0-rc.14`。RC13 的源码、产物与结论保持原身份；本次候选必须完成自己的四平台 × 三数据库静态程序验收，不能沿用旧版通过状态。四平台共享模块已重建并导入，Windows HTTP 退出、IPv6、TLS EOF 与 IOCP 句柄复用修复已通过定向回归，完整 TCP/UDP AOT 对照通过。新增原生 curl 的连接生命周期修复已通过模块回归并导入，正在重建对应静态 SDK；最终源码的完整验收与 RC14 公开发布尚未完成。下面按准确源码记录每轮结果，不把排队或局部成功当作完整通过。

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

[c-ares 模块重建 37117428360](https://github.com/zoujingli/typeapp/actions/runs/37117428360) 已按 `fe0a3cd034b08e1923fcb7912203a6261e5836c0` 通过。新 DLL 为 2,567,168 字节、SHA-256 `841e4a30bd63634159aee09bcab79542e0846a0730c87b5f6132388a1d5fffa7`，Artifact `11272206764`、归档摘要 `97768d74a6650184ef6d3acec43ae6f2c924bb575616ae0b95c775628a62d2fc`。同一个最小探针在主线程和两次重建的工作线程均返回 `127.0.0.1`、错误码 0，DNS 对端收到三次查询，线程退出为零且协程清空；DLL 导入表没有外置 c-ares，实际静态库摘要为 `d105c510fcb2e59b58c04376344f1e8ff86555bafec46f83d19023e582fedbec`。这确认构建开关缺失是该解析故障的原因，新模块替代前述 Windows DLL；完整 TCP/TLS 与应用回归另行验收。

IPv6/独占绑定适配后的 Windows 静态 SDK 已按 `3c583020f5fb5813256feaa8eabd469fab3775e8` 分别重建：[SQLite 37116141545](https://github.com/zoujingli/typeapp/actions/runs/37116141545)、[MySQL 37116144369](https://github.com/zoujingli/typeapp/actions/runs/37116144369)、[PostgreSQL 37116146811](https://github.com/zoujingli/typeapp/actions/runs/37116146811) 均成功。回读三份原生探针报告，实际数据库扩展各自仅包含所选驱动，PHP 8.5.10 ZTS、Swoole `6.3.0RC1` 与系统库清单均匹配；探针范围为 PHP 和扩展 embed，不能替代下文应用程序验收。SDK Artifact 依次为 `11272016209`、`11271896210`、`11270749874`。

### Windows TLS EOF 定位

源码 `53193c921efe0f35d1d24dd600b1cbb8fdce2b73` 的 [TCP/UDP 回归 37117985063](https://github.com/zoujingli/typeapp/actions/runs/37117985063) 中，UDP 通过，TCP 两个业务线程各完成 130 项断言后在首个 TLS 回声场景返回 `tcp_receive_failed`、`errno=0`。同源码的 [完整回归 37117987099](https://github.com/zoujingli/typeapp/actions/runs/37117987099) 通过线程、初始化、资源、HTTP 和全部退出监督模式后，也在 TCP 失败；两次失败均保留，未通过项继续阻止 RC14 发布。

将复现缩为 `tests/swoole-tls.php` 的真实 Swoole Socket 与独立 Node TLS 对端：[37118983742](https://github.com/zoujingli/typeapp/actions/runs/37118983742)，源码 `0127b13961d9ee080037dbccba22d2f3ce381927`，主线程和两次重建工作线程均完成握手与二进制回声，但写半关闭后两次读取均返回 `false`、错误码 0；移除前置超时仍失败。原始逐步报告保存在 Artifact `11273130289`（摘要 `8b87617546133d133b819fe365862ec34aefb8a0a8e626fab1661eeba417f130`），相同探针在 macOS 通过。

固定上游 `src/coroutine/iocp_socket.cc` 原文摘要为 `f38615b8c967e70429ba29ff317c98f62126f709e73fd575c5e0c6cae22114bf`。其 `ssl_recv()` 对非正返回统一进入 BIO 错误分支，正常的 `SSL_ERROR_ZERO_RETURN` 也转成失败；适配仅在读取后立即确认这个 OpenSSL 状态时返回零，其他状态继续交给原错误处理。调用者仍为 `TcpSocket::receive()` 的原生接口，无可替代此分支的公开配置，不在 PHP 层将 `false/errno=0` 猜成 EOF。适配归入 `SwooleWindowsSource`，旧 Windows 模块与 SDK 身份应被拒绝，必须重新构建；上游提供等价 EOF 语义且原生回归通过后撤除。

[TLS 模块重建 37119324620](https://github.com/zoujingli/typeapp/actions/runs/37119324620)，源码 `ca6eac1d13b5c808893b978321ac11c06933f2bb`，使用同一个探针通过主线程及两次重建线程的六个 TLS 场景：无前置超时及超时后均读到两次空字符串 EOF，错误码为 0，线程与协程正常退出。DNS、模块加载、SQLite 和 PostgreSQL 拒绝探针也通过。新 DLL 为 2,567,168 字节、SHA-256 `33de10a64d6191c01da0c855be1d6698eda09b6744748c584f3d3bb80c291397`；IOCP Socket 适配后摘要为 `ca34170efb2b6aa4e161ab76f32ca0ff4364b1872bba160f602ed0b938756a22`，与本地固定源码适配结果一致。Artifact `11271819553` 的摘要为 `573c070c7a7e4895504be3cb05a80e41a10aaaf50fec708e3ffb9ef713cb82fe`，原始许可字节不变。模块已导入，真实 RST 拒绝、完整 TCP 与三套静态 SDK 另行复验，尚不能据此宣布完整 Windows 通过。

同适配的三个 Windows 静态 SDK 已重建通过：[SQLite 37119655745](https://github.com/zoujingli/typeapp/actions/runs/37119655745)、[MySQL 37119657684](https://github.com/zoujingli/typeapp/actions/runs/37119657684)、[PostgreSQL 37119659932](https://github.com/zoujingli/typeapp/actions/runs/37119659932)。回读 PHP/embed 和 PHPX 探针，三个 SDK 各自只注册对应 PDO 驱动，运行时版本与系统动态库匹配；SDK Artifact 分别为 `11272722691`、`11273201854`、`11272124784`。这只证明重建与探针结果，应用静态程序须另行验收。

源码 `010da9d9492c9360399050e0c7a90a2f04a00a62` 的 [通信复验 37119866075](https://github.com/zoujingli/typeapp/actions/runs/37119866075) 中，UDP 与扩展后的 TLS 探针通过。探针新增握手后的真实 TCP RST，主线程及两次重建线程均保持错误返回和非零 errno，没有将复位误判为空串 EOF。完整 TCP 消费者仍在首轮双线程的 40 秒预算内未返回，控制端终止后记录 `exit=-1073741510`、`timed_out=true`，没有异常报告，不能把终止状态当作原生崩溃或通过。原程序保存在 Artifact `11272977668`（摘要 `26ea0bbb97d93cf2f1bcf7ef1fea414b9ec6ac57b17ad3235ab42c4868614470`）；新增场景边界检查点继续定位，原超时与断言不变。

同源码新增的 TLS RST 场景已在本机 macOS 完成完整 TCP AOT：六个生产包、91 份源码，源码目录由系统沙箱拒绝，连续两轮双线程及主线程协程全部通过，工作线程每轮 414 项、主线程 413 项断言，线程退出零且协程清空。程序 SHA-256 为 `8017ecd3f6fcae0314791eeed47f9675e42c187d61fe0e93f492e28dd3cc7063`，构建身份为 `139349c8a7c9fcd27b0990b80f3661dd07e98fbda4d09e99d863add5ea21bb94`。这不能代替 Windows 的未通过场景。

### Windows TCP 挂起的对照

[完整 Windows 运行 37119914590](https://github.com/zoujingli/typeapp/actions/runs/37119914590) 使用同一 `010da9d` 源码，通过线程、初始化、资源、HTTP 与退出监督后，同样在 TCP 的 40 秒预算内未返回。原程序保存在 Artifact `11273033868`（摘要 `59036b4ee6e9e6d00fdd2e35ef6b170e7351b48286c432c746aca84f1fb40d93`）。随后 [37120824423](https://github.com/zoujingli/typeapp/actions/runs/37120824423)，源码 `75ea8206f77ced4f36ce90a4374641be3c547745`，增加场景检查点，两个业务线程均完成 226 项断言，最后记录为并行普通回声的 `echo-close`。该场景与慢 TLS 握手同时执行；这个检查点尚不能区分关闭未返回或另一协程的握手截止失效。UDP 与基础 TLS 探针通过，完整 TCP 仍失败；Artifact `11273510901` 的摘要为 `868b336dbc7fb2daacd7a87714fb9f75c04229ffe74018a521bb8312c5bade32`。

独立 PHP 探针将慢握手与此前的 DNS、证书拒绝和并行回声隔离。原生接口的 [37121683446](https://github.com/zoujingli/typeapp/actions/runs/37121683446)，源码 `70d03d2f749c514d6ae18740db46bff385551a58`，通过主线程和工作线程的读写截止及协程取消。框架接口的 [37122053033](https://github.com/zoujingli/typeapp/actions/runs/37122053033)，源码 `a5fea1b04e2e8eed8e219eb66cf84b6e68b4aa7e`，进一步通过 `TcpSocket::start(0.15)` 与定时 `stop()`：自然截止约 0.166/0.161 秒返回 `tcp_timeout`，停止约 0.026/0.037 秒返回 `tcp_stopped`，额度归还、协程清空。原始报告保存在 Artifact `11273686411`（摘要 `019c34c094d8a658f28fee29e689c33129b6bfc67c464bbd804c406e47b217b4`）。这些是 PHP 调用路径的结果，不能替代 AOT。

只保留上述握手生命周期的消费者已在 macOS 全量 AOT 并通过两轮双线程及主线程协程；本机在 `a5fea1b` 上加入夹具修改后执行，修改随后以 `37970d20e481b87edf5ca462233a47e39335829e` 提交。程序摘要 `344f59028f9e35405ffcd9227a67da84a9b26952c6cfde88a7f346a8258c4244`，构建身份 `7c73d56a43d9986b695f4eebdb63f827eeb426868fe5755fd23e23f00fde3d77`。报告明确为 `handshake-only`，完整套件原有断言与预算保持不变。

同一已提交夹具的 [Windows 最小 AOT 对照 37122626515](https://github.com/zoujingli/typeapp/actions/runs/37122626515) 通过两轮双线程及主线程协程，分别为每个工作线程 13 项、主线程 12 项断言，协程清空。程序摘要为 `db689affe106106c82ade3a4ac0c7e9faa92c3f6137719a2d232dd287549ec65`，构建身份 `524d37797c06bdb257dc36619dc3ea7fb3e2f71b5ebb1e49cb6dbcdd74f116f8`，Artifact `11274097122`（摘要 `fd36f500c9df83e1a7af0a98b02471640135d9551e3ada58d970d014cee1ad86`）。这排除了仅包含两个握手生命周期的失败，不代表完整场景已解决。

同源码的 [完整通信对照 37122750438](https://github.com/zoujingli/typeapp/actions/runs/37122750438) 中，UDP 通过，TCP 仍失败。增加关闭前后检查点后，本轮两个线程都越过慢握手、取消和背压，进入服务端场景；报告保留 `tcp_not_stopped`，最终仍由 40 秒外层截止终止。该异常来自清理尚未进入作用域的控制连接，可能覆盖更早的失败，因此不能沿用“慢握手卡住”作为本轮结论。Artifact `11274501585`（摘要 `be6ceeae8660c45557ead9de9fcd56eeac6d2136bf80060f81f843367d79779c`）保留原程序和两个线程的失败。后续先修正测试清理并独立验证重复监听；此前未返回的原记录继续保留。

[并行握手与监听对照 37123480987](https://github.com/zoujingli/typeapp/actions/runs/37123480987)，源码 `29e7c97f17a6e075c6f7c08a953c40d2bcdb5079`，通过只恢复并行回声的 AOT 场景，程序摘要 `1f054c404f65994e1e6e8c77ecad34a0aab4894be90edb7e28dc9f99d52a5ebf`，构建身份 `41a7de583595654241d8ad7a2aa75469a4b1b4f19a752935ce621651be88a78c`。随后 `tests/tcp-binding.php` 独立复现重复监听未被拒绝：IPv4、IPv6 的第二个同端口监听均为 `accepted`；显式清理后额度与协程均为零，进程正常返回测试失败。这条最小路径不含 TLS、DNS、并行回声或 AOT；原生程序与报告保存在 Artifact `11273479089`（摘要 `5a5f8e3fd578a7ff9d0c3bc94097665100574b51c7bc37ddaaf00f9200b49d81`）。

候选修正仅调整 `TcpSocket` 监听前的官方选项：Windows 从 `SO_REUSEADDR` 改为 `SO_EXCLUSIVEADDRUSE`，Unix 沿用既有选项。复用 UDP 已有的固定 Swoole 绑定适配，不修改原生调度或追加 ABI；重复监听仍须返回 `tcp_listen_failed`。该阶段尚待最小回归及完整 AOT，后续结果分别记录如下。

[独占监听复验 37124385204](https://github.com/zoujingli/typeapp/actions/runs/37124385204)，源码 `1c733ae9fb62f7902597184d523582d014f81c77`，已通过 IPv4/IPv6 的最小重复监听拒绝与清理，原生 TLS 探针也通过。完整 TCP AOT 仍在 40 秒外层截止被终止：一线程进入服务端场景，记录子任务清理超时，另一线程最后位于回声关闭后；不能把最小修复通过写成完整通信通过。原程序和检查点保存在 Artifact `11274204892`，归档摘要 `5fe76cbe79d32c67c279937a12a3f4f75677867bd3442bcab83054f7289b3692`。本轮 UDP 也失败，另存其原始报告，尚未确认原因。

UDP 失败进一步收窄为突发测试的对端同步：原夹具发送控制消息后固定等待 40 毫秒，再以接收超时结束统计；Windows 的 IPv6 本轮收到零条。让本机独立 Node 对端延后 120 毫秒发送，同一旧 AOT 程序的两个线程均可复现“突发没有收到有界完整报文”，记录在 `build/toolchain-upgrade-followup.lVbKwh/udp-exclusive-runtime/run-f5ecfda26f/0-thread/execution.json`。夹具现通过独立控制端点确认全部 256 次发送回调完成，再排空被测端点；仍允许内核丢包，逐条核对长度、内容和唯一编号，生产收发期限不变。

修正后的六个生产包、91 项源码在 macOS ARM64 全量 AOT，同一程序分别在延迟 120 毫秒及无延迟条件下完成两轮双线程和主线程协程，每个工作线程 365 项、主线程 364 项断言，源码禁读通过。程序 SHA-256 为 `48c3706a87537297af7a09f4ab64dc54f5b023c0d084c8027b21cfa285f0639d`，构建身份 `de64f5adc7b8f607735e2b6b4e3018f491a561b83b7babfefaeb205bbfd433f6`。报告分别为 `udp-burst-ready-macos/run-157ae4ff2e/verification.json` 和 `udp-burst-ready-macos/run-eee39f0a61/verification.json`，均在上述任务目录内；报告摘要分别为 `9fdf5492c5ac5f43e335b7c9891e5685584f3c784931ecd4454ad58ae5916168`、`ff814aa7a9d4740dbae08255d318616a15cb47381742bc21ad524af52f28a50c`。Windows 须在相同修正后另行复验，不能沿用本机结论。

### 静态程序预验收

以下运行采用源码 `11a73078df41a9f8116384705251d37603f24666`，用于验证新版工具链与静态依赖。它们不是最终 RC14 标签的候选附件，后续发布仍须从最终固定源码重新构建并验收同一程序。

| 平台 | SQLite | MySQL | PostgreSQL |
| --- | --- | --- | --- |
| Linux x64 / ARM64 | [37110396184](https://github.com/zoujingli/typeapp/actions/runs/37110396184)，均通过 | [37110398545](https://github.com/zoujingli/typeapp/actions/runs/37110398545)，均通过 | [37110400048](https://github.com/zoujingli/typeapp/actions/runs/37110400048)，均通过 |
| macOS ARM64 | [37110399992](https://github.com/zoujingli/typeapp/actions/runs/37110399992)，通过 | [37110400955](https://github.com/zoujingli/typeapp/actions/runs/37110400955)，通过 | [37110402374](https://github.com/zoujingli/typeapp/actions/runs/37110402374)，第 2 次执行通过；首次程序验收通过后因重建材料的 GNU 下载超时失败，保留原结果 |
| Windows x64 | [37110714410](https://github.com/zoujingli/typeapp/actions/runs/37110714410)，通过 | [37110715499](https://github.com/zoujingli/typeapp/actions/runs/37110715499)，通过 | [37110716660](https://github.com/zoujingli/typeapp/actions/runs/37110716660)，通过 |

Linux x64 的完整原生功能运行 [37110360053](https://github.com/zoujingli/typeapp/actions/runs/37110360053) 同样通过二十组及汇总门禁。Linux ARM64 的 [37110376883](https://github.com/zoujingli/typeapp/actions/runs/37110376883) 十组功能通过，但附加性能任务在旧程序安装阶段失败，故整个工作流仍记为失败；使用各自产物 INI 的性能复验另行记录，不改写原结果。[macOS 完整原生功能运行 37110360051](https://github.com/zoujingli/typeapp/actions/runs/37110360051) 已完成九组及汇总门禁，全部成功；工具链证据保存在 Artifact `11272424364`（摘要 `0cb541a44c7d2a29f8e72bffff2c873b9acd573ea5818d99611c46b691057ccc`）。这些均保持原源码 `11a73078df41a9f8116384705251d37603f24666` 的身份，不冒充后续 Windows 修复后的完整通过。

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

Windows 后续使用源码 `53193c921efe0f35d1d24dd600b1cbb8fdce2b73` 及 IPv6/独占绑定适配重建三种单程序，[SQLite 37117989474](https://github.com/zoujingli/typeapp/actions/runs/37117989474)、[MySQL 37117991539](https://github.com/zoujingli/typeapp/actions/runs/37117991539)、[PostgreSQL 37117994094](https://github.com/zoujingli/typeapp/actions/runs/37117994094) 均通过。逐份回读程序摘要、安装、只读程序目录、源码/SDK 禁读、普通启动无写入和同摘要业务报告；此批尚未包含 TLS EOF 修复，不替代修复后的程序验收。

| Windows profile | 字节数 | 程序 SHA-256 |
| --- | ---: | --- |
| SQLite | 50,730,852 | `9b2ad6604b4b0b1aa605d04959b4384390af6e22d093ec95abe5d4688ba77bc0` |
| MySQL | 49,800,587 | `4d975f29da60d3f53b700df18a80772732a8efe0edca352ba34dcd9dd10fc44b` |
| PostgreSQL | 49,975,605 | `47d5adb50294dcfd855ea1230220a2a4e61d1e650e5587565c1daba114f3daea` |

包含 TLS EOF 修复的源码 `010da9d9492c9360399050e0c7a90a2f04a00a62` 使用上述新 SDK，再次完成 [SQLite 37120210968](https://github.com/zoujingli/typeapp/actions/runs/37120210968)、[MySQL 37120213209](https://github.com/zoujingli/typeapp/actions/runs/37120213209)、[PostgreSQL 37120217423](https://github.com/zoujingli/typeapp/actions/runs/37120217423) 的全量 AOT 和同程序部署。回读三份 EXE、构建报告、单程序报告及业务报告，摘要一致；源码与 SDK 禁读、只读程序目录、不同工作目录、普通启动无文件写入、profile 拒绝、前端安装及摘要、登录与 CRUD、MQTT、告警、导出、调度和正常停止均通过。它们不覆盖完整 TCP 套件的未通过项，也不是最终标签附件。

| Windows profile | 字节数 | 程序 SHA-256 | 应用证据 Artifact |
| --- | ---: | --- | --- |
| SQLite | 50,733,084 | `ea3dafa7d3dd1fae0d0a265a225e591817b59961c8a6f17a105032ff0242bcd1` | `11273104522` |
| MySQL | 49,805,891 | `5c8cc4d4d778d011c70990b26e31120876ddc35a99e8f48a1fcba457ebfae530` | `11273069374` |
| PostgreSQL | 49,976,813 | `47de7c581a66df1eaf5253323a95513193c8095f084903ed605fd90e85b2a9cb` | `11274500314` |

三份应用证据 Artifact 摘要分别为 `bdc5ffd816bf8552b4d6eddbfcf7df10e076948326a383ca4f4e9dfefa72cf1e`、`5b18aac791f32fa3d437609eca0a89480c5133ba0bd2575a9e8b297b86b45c02`、`f86b07bb03d94887ebde89b3dd9683261cec7b1a4f5b24be212381600f86433f`，本地保留于 `build/toolchain-upgrade-followup.lVbKwh/windows-tls-programs/`。下载中断后的重试仅恢复同一 Artifact 并回读摘要，没有重新编译或覆盖原始验收身份。

### 服务端最小对照与剩余 TCP 故障

[Windows PHP 服务端对照 37125205272](https://github.com/zoujingli/typeapp/actions/runs/37125205272) 在主线程和单工作线程均完成 4096 字节回声、作用域任务等待和额度归还，Artifact `11274825570` 的摘要为 `74fe1a983b6b5e338db86b8630bbf31f6a6b0b4665856ae22cf5d077bd5709b4`。该 PHP 路径使用默认 65536 字节片段，不能视为与双线程、1024 字节片段的 AOT 完全等价。

[Windows AOT 对照 37125507154](https://github.com/zoujingli/typeapp/actions/runs/37125507154)，源码 `35f2372f3f97f90bd8ceae2edb1f52e7d55ddabb`，最小服务端通过双线程两轮及主线程协程，分别每线程 24 项、主线程 23 项断言。六个生产包及 91 项源码完整编译；程序摘要为 `bdd2172df9f4b44735c8bae0de3d7f7eeaaae48b07171539e27568a153087b01`，构建身份 `e98e7e6e25a0898733a919d449bddc500eec1bb712c1d2211822103d78942bda`。随后同一程序的完整套件失败：一线程记录背压期间的并行任务未完成，另一线程到达监听退役阶段，关闭端口后在 0.2 秒内收到 `tcp_timeout`，而断言要求 `tcp_connect_failed`。最终由 40 秒外层截止终止，不是原生崩溃。Artifact `11275710867` 的摘要为 `367856108b74a01614016f91752ae88a4b49f8fc84ad0fb961a99077934b48fc`；最小通过与完整失败均保留，后续分别测量拒绝时间和隔离背压场景。

[Windows 原生拒绝对照 37126584031](https://github.com/zoujingli/typeapp/actions/runs/37126584031)，源码 `843f7db06fc45bbc586a05ad217c9e664c8052bd`，直接使用 Swoole Socket 连接已关闭的监听端口：0.2 秒预算实际约 0.222 秒返回 `false`、errno 10060；五秒预算实际约 2.054 秒返回 `false`、errno 107。额度及协程归零，Artifact `11275741975` 摘要为 `c5aa70a658e1c93ea8d96e2fb98a48cfe52dca4aa9011593bbd9f130c2cd26ab`。这证明原 0.2 秒断言早于该平台原生失败完成；完整夹具改用公开入口既有的五秒默认预算，继续要求明确 `tcp_connect_failed`，不接受超时，也不修改生产超时。

[UDP 延迟发送复验 37126419401](https://github.com/zoujingli/typeapp/actions/runs/37126419401)，源码 `13972b766647fca95876b697d49651c79398af70`，在 Windows x64 使用 120 毫秒对端延迟通过全部 UDP 场景：两轮双线程各 377 项、主线程 376 项断言，协程清空，六个生产包和 91 项源码全量 AOT。程序摘要 `5b4e98e2960ab71f7b1b9c618b0288e999ab8e0fdb7d62a2a5db55f022ed117a`，构建身份 `f0b69a4d01c19ef4dd10259be3b20da02291aab82572d797a53569df2674e59a`；Artifact `11274942367` 摘要 `2aebfecf18b01d5d403208cd052fc5f5b00d8a0e9c16524fc8a212956893a458` 已回读核对。该消费者在 Windows 的源码禁读仍为 `not-verified`。同轮 TCP 两个线程均到达监听退役后触发原 0.2 秒拒绝断言，整体运行仍失败；Artifact `11275273189` 摘要 `184942ed46cd8ba15a40c096e7abe82d0997da857c43c1e30bca73138b69b28d` 保留原程序和失败报告。

[Windows 背压与完整套件对照 37126934735](https://github.com/zoujingli/typeapp/actions/runs/37126934735)，源码 `608601f7a786f7d6ddb4c95fee3c91b4290d5557`，独立背压场景完成双线程两轮及主线程验收。随后对同一程序执行完整 TCP 套件，两个线程均停在慢 TLS 握手与并行回声的组合：最后检查点为 `echo-closed`、228 项断言，尚未记录慢握手完成耗时，也未进入背压场景。没有线程异常文件，40 秒后由控制器终止，不能归为原生崩溃或背压断言失败。

该程序 SHA-256 为 `c4f162e0131b266c69715474ec582adcae47471b233da18ec1093f28a8504e6f`，构建身份 `2d0cbde44be36babf56fe7992f6df55ffc8b602b745125698ae9703a73748bee`；Artifact `11274693109` 摘要 `1ea884bebe179a11dd2b5abf36abf4306ddd7ebaf8c9b3df50a5d98b10969d67` 已回读核对。本地保留在 `build/toolchain-upgrade-followup.lVbKwh/tcp-backpressure-windows/`。后续隔离 trace 构建只用于区分 IOCP 取消、完成通知及线程收尾，不替代默认模块的完整验收。

### DNS 后 IOCP 句柄复用的最小复现

[原生对照 37128447975](https://github.com/zoujingli/typeapp/actions/runs/37128447975)，源码 `64fdddd`，使用原内置 Windows 模块 `33de10a64d6191c01da0c855be1d6698eda09b6744748c584f3d3bb80c291397`。不执行 DNS 的 16 次 UDP 请求全部通过，协程归零；加入 `Swoole\Coroutine\System::gethostbyname()` 后，第一轮停在 Socket 716 的 `sendto()`，12 秒后由控制器终止。独立 DNS 对端共收到 18 次查询，说明 DNS 和后续 UDP 请求均已发送；缺少的是被测进程的完成通知。该测试直接调用原生扩展，不经过 TypePHP 或框架 Socket 类。macOS 使用同一测试，两组各 16 次通过。

固定上游的 `Iocp::associate_socket()` 将 Reactor 与 curl 借入的句柄放入 `associated_sockets`，而缓存只在 `Iocp::close()` 清除。c-ares 自行关闭 Socket，并在事件删除时把包装对象的 FD 置为无效，不经过该关闭入口；Windows 复用数值句柄后会命中过期缓存，跳过 `CreateIoCompletionPort()`。候选修复只调整借入句柄的关联入口，每次由 Windows 核对真实关联；自有 Socket 的缓存、取消等待及完成回收继续采用原实现。原文件 `include/swoole_iocp.h` 摘要为 `5c7a094064a71b43c6698dff60a6d9054c79bfe271fd429928b5e4b6fb3f6f41`，适配后为 `1d2aa2dea8211dda70d003cf1f1c51af1caa36cd62ca9cd3424d724f5fbfdeed`。Windows API 的关联生命周期见 [CreateIoCompletionPort](https://learn.microsoft.com/en-us/windows/win32/api/ioapiset/nf-ioapiset-createiocompletionport)。

[模块重建及原复现回归 37128837651](https://github.com/zoujingli/typeapp/actions/runs/37128837651)，源码 `eaf1a3da2cc191f39dd3885b23e3e9c1298e941f`，两组各 16 次原生请求全部通过，协程归零；DNS 组反复复用数值句柄 716 和 724，未再挂起。指定 DNS、线程重建、TLS EOF/重置及十组超时、取消、收尾探针也通过。新 DLL 为 2,567,168 字节，SHA-256 `dea622d3e2b86d834c8843f339b8db5b126fc54be0dbc6182c2082e607aa8b0c`；Artifact `11276211906` 的归档摘要为 `5e52d17d34b2042fe48064bfa86a7cfa4b266bb5933513845aff5c57352fc8fb`。回读 ZIP、模块、源码适配、依赖与许可证摘要后导入；其他平台模块保持原身份。完整 TCP、HTTP/curl 和三个 Windows 静态 SDK 仍须使用此适配复验，不能由最小场景推断通过。

此前的 [官方 IOCP trace 37128088888](https://github.com/zoujingli/typeapp/actions/runs/37128088888) 在完整 TCP 双线程场景仍于 40 秒截止终止，两个线程最后均为 228 项断言、`echo-closed`；日志显示慢握手取消后继续等待完成通知。它使用隔离 trace DLL `ddb3fd4934c34e084fcf4650dee2645e99a217f1a30971ecd5b944e4f2c6d0f0`，原程序摘要 `45033b86e460cb131011ef5620f9de105bd4177743b33bb19bc482d239aedcad`。Artifact `11275991967`（摘要 `c15bd8fef7e09b5737d94c5bf068bced6a782f429d2d411f8903920c158ecc18`）保留原字节和日志，不计为默认模块的通过证据。

失败 Artifact `11274919315` 的摘要为 `9b9b6fdd0f312488afd3f676d013929b7d8622dbaf2b4ffb4db95891966a4d28`，已回读保存在 `build/toolchain-upgrade-followup.lVbKwh/dns-reuse-windows-red/`。上面的重建运行已通过原最小对照；后续完整 TCP/UDP 与静态 SDK 结果分别记录如下。原静态 SDK 因 Windows 适配摘要变化不能继续冒充新输入，固定上游等价修复并通过原始回归后撤除本项适配。

### IOCP 修复后的完整通信与 SDK

[Windows 完整 TCP/UDP 对照 37129534352](https://github.com/zoujingli/typeapp/actions/runs/37129534352) 使用源码 `dded0dbe9a80e48451930dd02e80839d74ec9f63`、默认模块且 `iocp_trace=false`。TCP 最小背压与同一 EXE 的完整套件均通过，两轮双工作线程各 414 项断言，主线程协程也通过，涵盖慢 TLS 与并行回声、背压、IPv4/IPv6 服务端和关闭后拒绝。TCP 程序 SHA-256 为 `abbba99f4f080f9e4c4f19a8aa5b5d8d5d545b8be24c5d5fe551d9c33f593138`，构建身份为 `66434b20d65c64e2a9d2ea196fa56799b6cdd45ee8826f74b24f3c54786687e2`。

TCP Artifact `11276845882` 的摘要为 `95583914090ca91d9fa447d2a3452c76b702da2dcef8f94c5792f015fd1a4051`；UDP Artifact `11276581467` 的摘要为 `17ed777e7241b2e7dd68e2403997658e9645221264dcbf6b1062b0807aad1528`，均回读核验。UDP 程序 SHA-256 为 `f07feaa90d4449c94b2269dec87210bc9106d3a374c8517ccb7b040f77bb5ce0`，构建身份为 `de910122f5313d4bef677bea9c7332658b4cefd66eb7ad540866ed24b01c7412`；独立对端延后 120 毫秒发送仍通过。两套消费者均全量编译六个生产包、91 份源码，但 Windows 的源码禁读字段仍为 `not-verified`，不将其写成无源码隔离通过。

同源码的 Windows 静态 SDK 在 [SQLite 37129541240](https://github.com/zoujingli/typeapp/actions/runs/37129541240)、[MySQL 37129543409](https://github.com/zoujingli/typeapp/actions/runs/37129543409)、[PostgreSQL 37129546583](https://github.com/zoujingli/typeapp/actions/runs/37129546583) 重建成功，SDK Artifact 分别为 `11276586230`、`11276193252`、`11276223238`。这只记录 SDK 重建，不替代同输入的最终应用验收。

### Windows 原生 curl 构建能力

[原共享模块的 curl 对照 37130108803](https://github.com/zoujingli/typeapp/actions/runs/37130108803) 使用源码 `e6959a6`。两个线程均完成 16 次快速请求，但慢响应期间 Swoole 计时器没有执行，未通过协程让出断言。固定上游只在 `SW_USE_CURL` 下接管原生函数；Windows 共享模块缺少 `--enable-swoole-curl`，而静态 SDK 已启用该开关。读取 hook 标志不能证明原生实现已编入。原报告 Artifact `11275829554` 的摘要为 `9fa82b14a9fbe93a071b4e791f255efb025d080977f7d36d644e60882a4a5661`。

构建入口随后固定官方 libcurl 8.22.0 与 libssh2 1.11.1，校验原始许可和共享 PHP 对应的 `/MD` CRT，并要求配置实际产生 `SW_USE_CURL`。[重建 37131456197](https://github.com/zoujingli/typeapp/actions/runs/37131456197) 使用源码 `b538d3e0ae9877959b905e462b3bd34abf2f5835`，DLL 编译、DNS 与 TLS 通过，但 curl 首个请求失败；该模块没有导入默认清单。原始模块和报告保存在 Artifact `11276578568`，摘要 `9482670dac7337153aaef81db9af7a08a5aa3906c6d997def517e939f18e7874`。后续加入独立主线程对照和准确 curl 错误，线程失败后先回收本轮全部线程再报告；仍保留真实让出、超时及恢复断言。

[独立主线程对照 37132307555](https://github.com/zoujingli/typeapp/actions/runs/37132307555) 使用源码 `4f8817164304f982e7d61b33c2f43636c6b109bb`，两个协程的首个请求均在约 2017 毫秒返回 curl 错误 28，HTTP 状态为 0、未收到正文。该结果排除故障仅发生在线程之间的判断；不能据此确定是连接建立还是事件通知故障。Artifact `11277487587` 的摘要为 `cc0e12f1d8a2ae6caa886679b3895a55fc1ba4bdca97d24ef703ec2362a122a3`，已下载、核验并保留原始报告。失败模块仍未导入；重建入口可显式设置 `curl_trace=true`，仅在该回归中开启上游 `SWOOLE_CURL_IOCP_DEBUG`，正式验收仍须关闭诊断。

[IOCP 定向诊断 37133199027](https://github.com/zoujingli/typeapp/actions/runs/37133199027) 使用源码 `3178f58af676b349b0dcbc9c9b2aabe223f61f59`。两个请求的初次关联、写事件提交和完成均成功，随后从写事件切换为读事件时再次关联同一 socket 失败，最终由 curl 的两秒截止返回错误 28。Artifact `11277663371` 的摘要为 `a705059555bf319509e3e7fe2d4605717bcfccad5a3b54bafc96bba2fdffd92b`。此前绕过全局关联缓存的修复解决了 DNS 关闭后的句柄复用，却没有覆盖同一存活连接的重复事件登记；该历史通过范围保持原样，不能推广到 curl。

后续适配恢复上游关联缓存，并由真实 socket 关闭生命周期清除记录：固定 c-ares 1.34.8 在 `ares_close_connection` 的全零状态通知中清除外部关联；libcurl 使用原生关闭回调复用 `Iocp::close()`，句柄重置后重新安装回调。`CURL_POLL_REMOVE` 只表示撤销事件关注，不能作为连接关闭依据。新增长连接及句柄重置回归已在 macOS 通过，Windows 模块、静态 SDK 及完整应用须重新验收，成功前不更新默认模块摘要。

[无诊断重建 37134319268](https://github.com/zoujingli/typeapp/actions/runs/37134319268) 使用源码 `5c409678213ebd12da3c3d80de4213f1896da7a3`，模块加载、指定 DNS、16 次句柄复用对照、TLS 及增强 curl 回归全部通过。独立主线程、两轮双工作线程和最后主线程共六个角色各完成 36 次成功响应，计时器在慢响应期间继续运行；同一连接连续请求、真实超时、超时后恢复及 `curl_reset()` 后请求均成功，退出时协程归零。Artifact `11278136059` 摘要为 `fbfb252131f26d56a4a236c3e12ac12487c21ae010e50211b75257dc59d28b1d`；已回读并核对源码适配、依赖、许可及报告，再导入 3,471,360 字节的 x64 DLL，SHA-256 为 `c0f4ee13c7c6bb59fa724cd0596f0c2f15273185e830a4dc7bbeda42f1cfce20`。PHP curl 报告自身版本 `8.21.0`，Swoole 构建输入为固定 libcurl `8.22.0`，两者分别记录。此结果不替代使用新适配的三个静态 SDK 和最终应用验收。

### 清除诊断写盘后的 macOS TCP 复验

移除 TCP/TLS 的逐步同步检查点写盘后，同一完整 TCP 程序在第二轮接入时被 `tcp_buffer_limit` 拒绝：声明 65536 字节，实际接收缓冲为 326848 字节。独立 Swoole Socket 的 500 次对照有 25 次读到相同越界值；Python 原生 Socket 对照也复现，排除了 TypePHP 或框架独有行为。框架最小路径在第 46 次失败，复用既有连接后有界重设机制覆盖已接入连接后，连续 500 次通过；没有放宽缓冲上限或启动截止。

完整消费者新增独立客户端连续 128 次接入、立即发送与半关闭，逐次核对缓冲、4096 字节回声和额度回收。macOS 的六个生产包、91 份源码全量 AOT 后，同一程序在源码禁读条件下通过两轮双线程及主线程协程。程序 SHA-256 为 `d58c5f058dc829c5ee5663df4171a6ce4d9159d7d1487f13acd590f1ff55965c`，构建身份为 `66f5dab4b565c84c4b0761fc5957472cb58fe0ef0bffd18500f366d7246db32f`；原报告为 `build/toolchain-upgrade-followup.lVbKwh/tcp-buffers-macos/run-0e44d60f08/verification.json`。其他平台须在相同修改后复验，原 Windows 结果保持之前的源码身份。

### 性能测量的运行环境

macOS 的新旧程序分别从 RC13 源码与 `4631be08002695625477229d2552e10b7834a242` 完整编译，使用相同 PHP SDK 和 83 个相同前端资源。首轮测量在旧程序安装阶段被 `运行扩展身份不一致：swoole` 拒绝：成对控制器错误地继承了当前新版模块的 INI。仅改为该旧程序构建报告中的运行 INI 后，同一程序完成空库与前端安装。成对测量入口现逐版本定位和记录已探测 INI，失败轮次保留，不计入吞吐或延迟结果；原始基准程序未因这个控制器修复重新编译。

随后完成旧版在先、新版在先两轮测量；每个顺序分别执行三库、两个版本、三次独立重复，负载和程序保持相同。旧版在先的 SQLite/MySQL CRUD 吞吐中位数分别变化 -1.12%/-0.87%，触发复验；新版在先时分别为 -0.14%/+0.32%，未再次触发。逆序的 SQLite/PostgreSQL 慢依赖负载出现单轮信号，对应首轮未触发。两轮没有同一负载重复触发，但这不证明性能等价或改善；保留全部单轮信号，不以较好的一轮替换原结果。

原始成对报告为 `build/benchmark-pairs-7c8afe62a9de/verification.json` 与 `build/benchmark-pairs-a4ffe8a3bf9f/verification.json`，比较结果分别为同目录的 `comparison-935c77356901.json` 和 `comparison-a0de776d9769.json`。报告包含每个样本的吞吐、p50/p95/p99、CPU 与采样 RSS；两轮各负载的 RSS 中位数变化约 -0.03% 至 +1.18%。共享 embed 基准的编译耗时为旧版 322.909 秒、新版 328.581 秒，程序分别为 32,014,952 和 32,067,528 字节；这些数值不能代替发布静态程序体积，也不能推断其他平台性能。

文件流对照复用 `examples/file-http-command.php` 与 `examples/files`，以相同源码分别编译两版独立消费者。每轮预热 10 次，再下载 100 次 1 MiB 文件，逐次校验状态、长度和摘要；每种顺序执行三组新旧配对。首轮吞吐中位数为旧版 396.12、新版 392.36 次/秒（-0.95%），逆序复验为 392.48、395.30 次/秒（+0.72%），没有重复出现下降。首轮 p50/p95/p99 为旧版 0.723/1.031/1.114、新版 0.727/1.046/1.106 毫秒；逆序为旧版 0.722/1.072/1.155、新版 0.730/1.044/1.103 毫秒。两轮 CPU 中位数均为 0.07 秒，RSS 中位数变化分别为 +0.47% 和 +0.26%，全部服务正常停止。

文件消费者的旧/新编译耗时分别为 136.448/129.356 秒，程序分别为 3,278,456/3,314,424 字节。准备身份、原始样本和两轮比较位于 `build/toolchain-upgrade-followup.lVbKwh/file-benchmark-*.json`；旧/新程序 SHA-256 分别为 `5ab734e8c3e9d059ecab6deffe745f9f661a4cd7d330cc9573a55895a1346d9d`、`73b73f54b201fc6c8a41196ecbaa1093e310e1d4bb2a05d928c568607c230196`。该顺序负载未确认持续回退，也不支持宣传吞吐改善或推广到其他平台、并发量。

[Linux ARM64 首轮完整测量 37112212467](https://github.com/zoujingli/typeapp/actions/runs/37112212467) 比较 RC13 与 `fe3c22f79fdaabc607012cd13b72d4fda1623906`，三库均完成三次重复；比较器返回 `regression-signal-needs-repeat`，整个运行记为失败。PostgreSQL 短 JSON 吞吐中位数变化 -6.42%、p50/p95 分别 +7.15%/+5.95%；SQLite 慢依赖、PostgreSQL CRUD 和慢依赖也触发复验信号。原始测量与比较保存在 Artifact `11270479905`。逆序复验继续固定这两个源码提交；新的运行会重新编译，须分别记录程序摘要，不能称为同一原生字节的复测。

[Linux ARM64 逆序复验 37115494900](https://github.com/zoujingli/typeapp/actions/runs/37115494900) 使用工作流源码 `7909ee115cc20de33b8bfbf9de0c54ec0f4bcb75`，新旧应用仍固定为前述两个提交，实际按新版在先执行。三库各三次重复全部完成，六份内嵌测量与原始报告摘要一致；比较器返回 `no-consistent-latency-regression-detected`，该轮工作流成功。首轮信号对应负载的吞吐中位数变化为：PostgreSQL 短 JSON +1.54%、CRUD +0.07%、慢依赖 -0.84%，SQLite 慢依赖 -0.83%；本轮均未触发区间分离条件。单项中位数仍可下降，不能把结果写成性能等价或提升，也不覆盖首轮失败。采样 RSS 中位数变化 +0.28%～+0.72%；SQLite 慢依赖 CPU 中位数由 1 秒变为 2 秒，原始区间为旧版 `[1, 1]`、新版 `[1, 2]`，首轮同负载为旧版 `[1, 2]`、新版 `[1, 2]`，保留采样粒度和波动边界。

逆序运行的旧/新程序 SHA-256 分别为 `a8d3f02c500f9b35ee13410facc96653a476ff7a4694aadd76c7ee74f7d5f842`、`db62f6df01623c85adabaf857580b8805334a989a52a52d9c4e5a9edf1bc84c9`，共享 embed 程序大小分别为 29,051,681 / 29,118,286 字节，编译耗时为 1,016.919 / 990.875 秒；前端文件清单完全相同。准备、原始样本和比较保存在 Artifact `11272128682`（摘要 `aba5c8c9be66c60984b97018cb0ce8a50355f6706b5a5d690df06a16dc18205d`），本地回读位置为 `build/toolchain-upgrade-followup.lVbKwh/linux-arm64-benchmark-reverse/`。该轮重建了程序，仅作为固定源码的顺序复验；不能推断发布静态程序的极限吞吐。

本次升级尚未完成 Windows 的同条件性能对照，也未完成非 macOS 平台的文件流对照。各平台功能验收与程序体积核验不能替代这些测量。

[Linux x64 首轮 37123039925](https://github.com/zoujingli/typeapp/actions/runs/37123039925) 固定 RC13 与 `010da9d9492c9360399050e0c7a90a2f04a00a62`，实际旧版在先。两版 SQLite/MySQL 各三次测量完成；RC13 的 PostgreSQL 前两次完成，第三次在服务启动时返回 `swoole_hook_startup_required`，尚未执行新版 PostgreSQL，故整轮失败，不能用于完整三库性能结论。Artifact `11273584344`（摘要 `93d3e88468c626eb2b3b30689d6ee6591fa9d4681f200e43399a7839a12c5bed`）已回读保存。逆序复验保持两个源码提交不变，不修改 RC13 的实现以消除基线故障。

[Linux x64 逆序复验 37124844478](https://github.com/zoujingli/typeapp/actions/runs/37124844478) 使用工作流源码 `1c733ae9fb62f7902597184d523582d014f81c77`，两版业务源码仍固定为前述提交。新版在先的三库、两版、各三次测量全部完成；逐份回读六份原始报告，其摘要和成对报告内嵌结果一致，比较器返回 `no-consistent-latency-regression-detected`。九组负载均未触发区间分离条件；吞吐中位数变化约 -0.77%～+2.15%，采样 RSS 中位数变化 +0.03%～+3.06%。这是一轮完整三库测量，不能与前轮未完成的 PostgreSQL 合称两轮通过，也不证明性能等价或改善。

本轮旧/新共享 embed 程序分别为 36,807,050 / 36,879,694 字节，编译耗时 668.449 / 633.174 秒；SHA-256 分别为 `1eacacd0375130bb8dd93bc2f06218c5d5e220928a1f6a24435174f26b902a9c`、`dba14c96dbcd066dcc7298d1ab077728985f5da126a6a518938b95dac4b62427`。它们是本轮重建的基准程序，不冒充首轮字节或最终静态发布附件。准备、样本和比较报告保存在 Artifact `11275602989`（摘要 `d60ef8b36d2d2501a73d758cef9274d179ef2648a481db279b62f761bad3095b`），本地回读位置为 `build/toolchain-upgrade-followup.lVbKwh/linux-x64-benchmark-reverse/`。

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
