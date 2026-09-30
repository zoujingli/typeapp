# 数据库 profile 与发布体积验收

本次实现基于 `ba18cd7753740712dcfcb03a167d417d71f4b42f` 后的主仓修改。历史 RC10 的标签、附件与验收记录不变；以下本机结果不能替代新 RC 的四平台十二组合发布验收。

## 本机静态程序

macOS ARM64 的 `sqlite` profile 保留 HTTP、MQTT、物联管理、告警、导出、队列与调度，Redis 在功能闭包内；通用缓存默认关闭。真实 embed 扩展表只包含 `pdo_sqlite`，没有 `pdo_mysql`、`pdo_pgsql`、Phar、DOM/XML/intl/zip。静态输入共 12 个归档，没有 libpq、libxml2 或 ICU。PHP 8.5 必需的 OPcache 核心仍在，运行时关闭 OPcache，构建时关闭未使用的 JIT。

PHP/Swoole 的实际 make 命令追加 `-O2 -g0 -ffunction-sections -fdata-sections`，避免上游 configure 重设编译参数。归档去除调试信息；最终程序执行 `strip -x` 后再封存身份、签名与摘要。链接采用 `dead_strip`；本次没有启用 LTO。

最终程序 SHA-256 为 `192b758e28087e9455e1cf9c817b8f10d20081adc75d322933ad7c6cf4306231`，构建 ID 为 `0c07da0dff4fd0f125c07bfea9d9f9386f9c1e29cb46fd0727504faeeb98919a`。TypePHP 全量编译 281 个单元，没有源码回退。

| 项目 | 字节 |
| --- | ---: |
| 最终主程序 | 48,029,208 |
| 代码区 | 31,961,024 |
| 其余数据及文件结构 | 16,068,184 |
| 必需外部符号表 | 1,203,968 |
| 内嵌前端原件 | 2,805,419 |
| 链接前静态归档输入合计 | 36,303,136 |

只有代码区和其余数据之和等于程序体积；符号、前端属于文件内部统计，静态归档是构建输入，不能将这些数字全部相加。程序没有 DWARF 或本地调试符号；仅保留 Mach-O 加载需要的外部符号及 Apple 兼容标记。

| 同一文件验证 | 结果 | 用时 |
| --- | --- | ---: |
| 无源码单程序部署、安装、前端摘要、双端登录及 CRUD | 通过 | 5.012 秒 |
| 服务生命周期与正常停止 | 通过 | 7.498 秒 |
| 真实 MQTT 授权 | 通过 | 6.668 秒 |
| 告警通知与可靠 Redis 存储 | 通过 | 28.455 秒 |
| 队列导出闭环 | 通过 | 31.597 秒 |
| 调度执行、历史及 Redis 中断恢复 | 通过 | 19.904 秒 |

部署验证使用只读程序目录、含空格路径和不同工作目录，禁止读取源码、SDK 以及执行 PHP/Composer/Node；其他业务扩展回归单独留证，不将它们混称为相同强度的隔离。未选择的数据库和关闭的缓存通过稳定错误拒绝，普通启动没有释放原生运行库。

本机归档受现有依赖最低版本约束，最低 macOS 为 26.0；它不证明 CI 的 macOS 15 基线。RC10 的 macOS 附件为 69,408,888 字节，本机文件约小 31%，但源码和系统依赖基线不同，仅作体积参考，不是严格性能对照。

## 无 Redis 自定义 profile

另用仅含 `web`、`mqtt`、`iot` 的 SQLite 配置重新制备 SDK，并全量编译相同的 281 个生产单元。真实 embed 与最终程序扩展表均不含 Redis，程序为 47,464,680 字节，SHA-256 为 `4aea883fc2af545016ca4a56b6066e00674673e68dbe64608058c676462b1a5f`，构建 ID 为 `d3e6f59c10566986712a89a33269df7b39b8c242e36494b0230095ce8956142c`。

该程序通过无源码部署、首次安装、页面校验、双端登录、CRUD 和真实 MQTT 授权；开启缓存、调用已关闭的告警通知、导出和调度命令均返回 `feature_unavailable`，未选择的数据库返回 `runtime_profile_database_mismatch`。默认发布 profile 仍保留这些业务能力及 Redis，此自定义程序只验证关闭能力的边界，不作为默认候选。

制备时修复了 macOS Bash 3 在 `set -u` 下展开空可选参数数组的失败：必需的 Swoole 参数与可选 Redis 参数共用非空数组。失败日志及输入已归档为 `.cache/profile-release-evidence-20260930/no-redis-empty-array-failure.tar.gz`，SHA-256 为 `31aa2e8413bde8568b23c28f30223e9ec40d6cc4d439e272bbd23aff78c3bfa5`，回读核对后回收失败源码树 170,576,872 逻辑字节。

## 检查与证据保全

完整 PHPUnit 套件通过 179 项测试、3353 个断言；后续定向构建与发布复核通过 68 项测试、171 个断言。格式检查覆盖 798 个文件，无需修正；基础检查、文档引用和站点分发边界、工作流语法、shell 语法及重建材料契约通过。Git 分发回归覆盖固定标签、冲突、部分失败和幂等重试。后续新增设备端 SQLite 边界的原生拒绝验收仍由对应非 SQLite 候选执行。

原始报告位于任务专用 `build/profile-release-validation/`，六项最终程序回执为 `same-program-final.json`，构建报告为 `program/type-app.build.json`。阶段一原件已保全到 `.cache/profile-release-evidence-20260930/phase-one.tar.gz`，归档 SHA-256 为 `ed887cf2e778e430418d65db85682b661325d145a09919b979c5e3d2af04a3a5`；逐文件回读通过后回收 14 个已结束测试目录，释放 751,553,991 逻辑字节。该归档中的中间程序保留原身份，不能用作最终程序的验收证明。

两个最终本机程序、对应构建身份和原始报告另保全到 `.cache/profile-release-evidence-20260930/local-final.tar.gz`，归档 SHA-256 为 `97f4916e958068dd21956214d2085fa22fd9b7bca0367676b61c71e05c9150fb`。逐文件回读摘要通过后回收 9 个已结束测试目录，释放 358,029,910 逻辑字节；各归档旁的 JSON 清单保存原相对路径与单文件摘要，可按清单恢复原件。本次累计回收 1,280,160,773 逻辑字节。任务 SDK 暂留供发布故障复核，等待远端矩阵完成后再回收。

Docsify 发布页已用本地浏览器核对标题、下载表格和 Mermaid 流程图；备案名称“物联开源分享”保留。检查后关闭任务预览标签与临时监听服务，没有替换本机业务站点。

服务生命周期原始报告另封存为 `.cache/profile-release-evidence-20260930/service-final.tar.gz`，SHA-256 为 `4c7cf05ebf2feb56bb18e98662411902314e8621d19cf4eb9086a4e9adc6236f`。确认所属进程退出、端口释放并回读归档后，回收服务测试目录和两个最终程序的可重建编译中间目录，释放 1,611,387,363 逻辑字节；累计回收 2,891,548,136 逻辑字节。最终程序及原始构建报告仍保留。

## RC11 首轮门禁

标签 `v1.0.0-rc.11` 固定在 `65c5149a6ea6090f74439844eff9783706dcb0ab`，原发布运行 [36621347358](https://github.com/zoujingli/typeapp/actions/runs/36621347358) 的 Windows 契约测试发现用例间 profile 环境泄漏：设置 `mysql` 后仅用变量名调用 `putenv()` 清理，下一用例仍读取到 `mysql`，报“未知构建 profile”。该主仓 Release 未公开，原标签不移动。

测试改为每个用例先隔离 profile、结束后恢复原值，并断言环境清理结果；Windows 使用 CRT 支持的空值删除形式。带外部 `TYPEAPP_BUILD_PROFILE=mysql` 的本机定向执行通过 13 个测试、84 个断言。修复提交 `bbec8deea3429ce94bc68300c83cc58ca5030967` 的 Windows 运行 [36624117644](https://github.com/zoujingli/typeapp/actions/runs/36624117644) 已通过“检查完整公开契约”步骤；该步骤结果不代替后续新 RC 的完整平台门禁。

Linux x64 与 macOS ARM64 的 PostgreSQL 候选通过单程序隔离部署后，在 MQTT 授权扩展回归中触发 `TYPE_MIGRATION_NOT_EMPTY`。最小复现确认：同一授权脚本先安装独立 Broker，再将物联中心安装到同一服务器数据库；SQLite 原来使用两个不同文件。脚本现为 Broker 和物联中心分别创建专用库，关闭进程后逐项删除并核对结果。真实 PostgreSQL/MySQL 的 PHP 控制回归分别通过 53/54 项 HTTP 检查，两个数据库均确认清理；此结果不替代修复后的原生候选复跑。

Linux SQLite 候选通过隔离部署及 MQTT 后，告警回归误启动 Ubuntu 的 `redis-check-rdb`。发行版 `redis-server` 链接到同一个按 `argv[0]` 区分用途的程序，测试夹具解析实际路径后改变了用途。静态发布工作流复用既有 ARM64 准备方式，将相同字节复制为任务目录下的 `redis-server`，保持服务器名称；工作流语法检查通过，实际业务仍须在下一批候选执行。

RC11 的 Linux x64 PostgreSQL 原候选为 56,839,923 字节，SHA-256 为 `3ca918c7ba69247fcda8fb379be23b4d7bf18ef2d03712d392bd9ddec6746f21`；原报告确认 strip 已执行、符号统计为零、扩展只保留所选 PDO 和默认 Redis。与 RC10 同平台 148,731,221 字节相比约减少 61.8%，但 RC11 业务验收未完成，不能将该文件作为已发布结果。原日志、SDK/程序身份和隔离部署回执保留在 `build/profile-release-validation/rc11/`。

本机 Broker 故障复现和两库修复结果已封存为 `.cache/profile-release-evidence-20260930/rc11-broker-probes.tar.gz`，SHA-256 为 `08fcb8e4293676d271d433057ae7945ff72a4f126f305017af11742866053630`。回读全部文件并核对所属数据库 PID 退出、端口关闭后，回收 8 个测试目录共 328,266,610 逻辑字节；本任务累计回收 3,219,814,746 逻辑字节。此后修改不改变 RC11 的源码、原程序或标签身份。

Windows SQLite/PostgreSQL 的 AOT 链接完成后被 PE 体积门禁拒绝。原失败最终 EXE 未进入封存目录，因此不能据此声称已核对它的完整区段。下载同一 SDK 运行的真实 embed 探针（SHA-256 `1b8cd2c73d8d398947306878f604587e6b0974310c0160722e7577d0605398ed`）与 PHPX 探针（`bb27fcf6d8893276d56aedc545a375ee1e8d82cf2378187bc8beb9b3fe9298e9`）可复现同一拒绝：两者均没有 COFF 符号表或 CodeView/PDB，只有类型 13 的 POGO 区段布局记录。

体积门禁现解析目录类型、RVA、文件边界和布局载荷，只接受合法 POGO 记录，继续拒绝 PDB、未知记录、调试区段和非必要符号。两个原探针通过新解析；对应单元测试通过 4 项、24 个断言。Windows 入口增加真实 MSVC 发布程序可执行、带 PDB 调试程序被拒绝的前置测试，最终应用仍须重新编译并完成同一 EXE 的部署与业务验收。

RC11 的 macOS ARM64 SQLite 候选已通过完整静态程序验收，原文件为 47,745,960 字节，SHA-256 `2226942fad846eb0a9ceaca884f4d9dacb02b7e79090496c13f37b3710240013`。相较 RC10 同平台 69,408,888 字节减少约 31.2%；实际 PDO 为 SQLite，保留默认 Redis 闭环，无 MySQL/PostgreSQL 驱动和 XML/ICU 归档。它仍属于 RC11 候选证据，不能替代下一标签的验收。

## 候选复验发现的配置边界

修复提交 `33cd3d0ab8e81591b05877144852a927529184fb` 的 Windows 运行 [36627912097](https://github.com/zoujingli/typeapp/actions/runs/36627912097) 已通过真实 MSVC 前置回归：108,032 字节的发布程序可执行且通过 PE 检查，使用 `/DEBUG:FULL` 的调试程序被拒绝。该普通运行随后主动取消，避免与完整静态候选重复编译；其前置成功不等于整个运行成功。

同一 RC11 macOS MySQL 原程序通过修复后的 Broker 授权回归后，管理端保存配置返回 422。独立配置回归确认：原实现忽略进程环境，只校验 `.env`，因此将进程提供的 MySQL/PostgreSQL 错当成缺省 SQLite。现分别校验文件声明类型和实际生效配置，沿用启动优先级；进程覆盖字段仍只读，数据库不匹配仍在写文件前拒绝，HTTP 保留稳定能力错误码。

定向单元测试通过 15 项、104 个断言，包含 MySQL 与 PostgreSQL 的进程覆盖保存、只读拒绝、错误 profile 拒绝和失败不改文件。两库真实 PHP 控制回归均通过告警、导出、调度的 755/749/510 项 HTTP 检查，所属服务器退出及测试库清理均通过。此处 PHP 结果只证明配置与业务行为，不能代替外部数据库 profile 的原生验收。

配置修复后的本机 SQLite 程序全量编译 281 个生产单元，最终 48,029,208 字节，SHA-256 为 `f8cfcec436fc6e51fd130a5aa615e6acc1e66baa13445a97e23bdae15db6175e`，构建 ID 为 `3200ec4a295d084423ce8c97826dbf845784dda66291294783574f574eee10e1`。同一文件通过单程序隔离部署与管理 API 的 510 项 HTTP 检查，覆盖配置保存、站点信息、账号权限和正常停止；原报告分别为 `build/single program-43d07417dc49/verification.json` 与 `build/iot-identity-08f6b8a7229a/verification.json`。本机结果仍受 macOS 26 SDK 基线限制，下一 RC 须验证四平台及三个数据库 profile。

## 尚待实际发布验证

Linux SQLite 定向运行 [36628322727](https://github.com/zoujingli/typeapp/actions/runs/36628322727) 的 x64 原程序已通过无源码部署及 MQTT、告警、导出、调度；程序 SHA-256 为 `f019f1563b98810abaf6d831a2ddcfbe784d3cd2206b1039baeba68c858e80ef`。systemd 控制脚本随后误用数据库夹具 PATH 中的系统 PHP，加载 ZTS 扩展产生 ABI 警告并污染摘要输出。脚本改为明确调用 `PHP_HOME/bin/php`，不改变部署程序或服务配置，后续仍须完成真实 systemd 复验。

Linux ARM64 的同次定向运行也已完成部署及四项业务检查，随后在相同的 systemd 控制脚本失败；两平台的服务复验均不能以业务成功替代。

Windows 原 EXE 的定向诊断 [36630937370](https://github.com/zoujingli/typeapp/actions/runs/36630937370) 没有重新编译，复用 [36627922661](https://github.com/zoujingli/typeapp/actions/runs/36627922661) 保存的 50,758,048 字节程序，SHA-256 为 `7a2492dd71e4627ca4f7f7cca65d764dc46754fbd6b2c21831817fa4b13ab895`。失败原因是 Broker 无条件使用当前 Windows 运行库未提供的 `SWOOLE_BASE`，并非裁剪掉数据库或 TLS。原 EXE 已通过严格无源码部署，但不能计为 MQTT 或 Redis 业务通过。

修复在 Broker 既有所有者中补充官方 Coroutine Socket 接入；协议、授权、持久操作、全局事件门和额度继续复用。经典 Server 不可用时自动选择，显式协程入口只支持 TCP/TLS；MQTT WebSocket、客户端证书身份及 SNI 在监听前明确拒绝。Windows 新程序和完整矩阵仍须实测。

本机最小对比确认：经典与协程入口均在约 15.04 秒拒绝半包 CONNECT，原用例的 12 秒等待早于配置的 15 秒，现按真实配置保留 18 秒测试预算。活跃读取时从信号回调同步关闭监听会形成嵌套恢复等待，改用官方 `Event::defer` 后正常退出；接纳循环的临时 Socket 引用也在进入下一轮等待前释放，避免 TLS 关闭延迟到下个连接。原始拒绝、停止和文件描述符记录保留在 `build/profile-release-validation/mqtt-probe/`。

随后完整组件 AOT 暴露两项仅看 PHP 结果无法确认的问题。正常退出的生成代码复用了提前返回分支中初始化的 `finally` 临时值，导致读取登记未清除；收尾现归入独立方法，每次读取当前连接代次。接入循环的编译临时引用还会延长 Socket 生命周期，标准客户端在 DISCONNECT 后一直等待，下一次接入才解除；接纳处理现独立于下一次 `accept` 等待。原失败程序、生成代码摘要与客户端事件对照分别记录，不修改历史身份。

修复后的独立组件程序 SHA-256 为 `f6bf94d085605a20e86f5132d0983c5e7268f5a0c3e2fea11483455075be20bf`，构建 ID 为 `680e5d5d6f311a50d015d4f3bfb08cb0acf4efbd35accd5a2f4adbef520546eb`。同一程序通过源码及生成实现禁读的 TCP/TLS 验收：各包含 45 项 CONNECT 字段、182 项线协议、72 项消息、14 项文本、14 项别名检查，以及 MQTT.js 的双版本四组合交互、TLS 1.2/1.3、活跃连接停止、端口重绑定和三个不支持配置的明确拒绝。共享组件 SDK 的选定动态模块只在测试准备时搬迁并核对摘要，不属于最终静态 Release 的部署方式。该结果不代替 Windows 或完整应用的验收。

本轮完整单元套件通过 182 项测试、3431 个断言；基础检查覆盖 819 个文件，文档检查核对 1771 处引用、204 条路由、317 个命令和 250 个 Composer 脚本。源码收尾方法的后续调整另经完整组件 AOT、行为与格式检查。

经典 Server 的 SQLite 应用授权控制回归通过 54 项 HTTP 检查及真实 TLS 连接、凭据轮换和旧凭据拒绝，测试进程和临时私钥均已回收。控制端已加载 Swoole 时不再重复声明扩展，避免启动警告混入命令 JSON。该 PHP 回归与上面的组件原生证据分别记录。

MQTT 诊断与组件验收资料封存为 `.cache/profile-release-evidence-20260930/mqtt-coroutine-validation.tar.gz`，SHA-256 为 `0cd33f11c44f3bbe1d075e9cfd8ca2575683845e04f71e72b669dc91086d196b`。原程序、构建报告、所需源码、失败生成片段和原始日志均已逐文件回读校验；可重建的重复安装及编译中间文件不长期保留。确认相关进程退出后回收 11 个独立消费者目录，释放 1,183,568,140 逻辑字节，累计回收 5,938,242,448 逻辑字节。

提交 `f54c7743ac8d31b6c64082ea7b0c31db864cbea3` 的完整物联中心再次全量编译 281 个单元。本机 SQLite 静态程序为 48,097,560 字节，SHA-256 为 `b848b61699100937fe50d00b8976745d35b599fe7dee03cc410d31eef20bea2f`，构建 ID 为 `16706f3fb43547b6abb733fa88e45529b17f689c04d566831f722fee1d9d4c95`。同一程序通过严格单文件无源码部署、53 项 MQTT TLS 授权 HTTP 检查、755 项告警通知检查、743 项导出检查、510 项调度及管理检查，并通过真实 launchd 启停、崩溃恢复和外部数据保留。测试 Redis 均为专用实例，回收后日常实例保持原状。该程序仍使用本机 macOS 26 SDK，不替代 CI 的 macOS 15 基线。

这轮完整应用证据封存为 `.cache/profile-release-evidence-20260930/mqtt-application-validation.tar.gz`，SHA-256 为 `f6996bb171a69355c2a47313f5b073d6ab1842b8991ed60a00191b8071dd325d`。逐文件回读后回收 8 个测试目录及本轮可重建中间文件，释放 673,096,410 逻辑字节，累计回收 6,611,338,858 逻辑字节。最终程序和对应报告继续保留。

RC11 已确定不能通过发布门禁，停止其剩余重复验收任务后，原运行终态为 cancelled；此前成功和失败的任务结果均保留，标签未移动、主仓 Release 未公开。

Windows 定向运行 [36637152747](https://github.com/zoujingli/typeapp/actions/runs/36637152747) 复用已验证 SDK，重新全量编译上述修复源码。最终 EXE 为 50,811,808 字节，SHA-256 为 `0762343509449915a156ed206ecc91a30f81469a6f92cc250a6642a6488df86e`。同一文件通过严格无源码部署、54 项 MQTT TLS 授权 HTTP 检查和 755 项告警检查；导出初始 100 行分块成功，Redis 重启后恢复执行并计算最终 CSV 摘要时出现非 JSON 命令输出，运行最终失败，调度与材料封存未执行。原 EXE、构建身份及报告保存于 `build/profile-release-validation/rc12/windows-mqtt-evidence/`，不能将其诊断运行等同于 RC12 正式发布。

原 EXE 复验 [36640003777](https://github.com/zoujingli/typeapp/actions/runs/36640003777) 通过部署与 MQTT 后，被测试入口对旧构建 INI 路径的要求阻止；这一结果未到达导出故障点。测试入口现读取程序封存清单和真实摘要，静态程序直接执行，共享库程序继续使用自己的运行配置。未改写原 EXE 或构建报告。命令输出异常另保留非 JSON 诊断和摘要，去掉 JSON 凭据正文及环境秘密。

本机上述 SQLite 静态程序在编译目录已移除后，以新入口通过 744 项导出 HTTP 检查，涵盖分块、队列重启、内容摘要、撤权和清理。原报告 `build/iot-identity-6f52ff86dd9d/verification.json` 与资料已逐文件回读封存到 `.cache/profile-release-evidence-20260930/exports-relocation-validation.tar.gz`，SHA-256 为 `4a201c538522a4d93efb48e1f3f40521784a354ead912e143d43d429c6c9836a`。确认 HTTP、测试 Redis 退出及端口释放后，回收 298,338,001 逻辑字节，本任务累计回收 6,909,676,859 逻辑字节。

配置修复阶段的原始资料已封存为 `.cache/profile-release-evidence-20260930/settings-validation.tar.gz`，SHA-256 为 `e07e4b039e077aa88513a43ce4b15def59eeaec3af669f8fa8886cfe490433b6`。逐文件回读并确认所属进程退出、端口释放后，回收 19 个测试目录共 1,534,859,562 逻辑字节；本任务累计回收 4,754,674,308 逻辑字节。共享 SDK 和后续所需程序仍保留。

Windows 原程序复验 [36640808535](https://github.com/zoujingli/typeapp/actions/runs/36640808535) 明确捕获 `hash_file()` 的 `errno=13 Permission denied`。最小对照 [36641734334](https://github.com/zoujingli/typeapp/actions/runs/36641734334) 在普通文件模式下确认：9216 字节文件在加锁前及解锁后可读，独占锁内另开句柄被拒绝，原持锁句柄完整读取且摘要一致。独立启用 FILE hook 的实验出现不同读锁语义，不能作为生产修复依据；当前 `CoroutineRuntime::enableIo()` 未启用该 hook。

导出完成阶段改用原持锁句柄计算 SHA-256，并校验读取长度等于已经落盘的字节数。独占锁、`fflush`/`fsync`、数据库提交顺序与任务截止继续生效。真实 PHP 控制回归通过 745 项 HTTP 检查，包含 Redis 重启后分块恢复，以及同字节数 CSV 损坏被拒绝、恢复原字节后可下载；原生程序须重新全量编译并验收，不能沿用旧 EXE 的身份。最小诊断代码与报告保存在 `build/profile-release-validation/rc12/`，临时诊断步骤从工作流移除。

本机修复版本完成 281 个编译单元，SQLite 程序为 48,097,544 字节，SHA-256 为 `dc27c2687a89122b0c37f49e4b88827e6a278f4fade6b342ab4e51091feb31cd`，构建 ID 为 `0c572165234403ba4a0441f650973b14a25b6944a16f7204103ac856f72d3411`。同一文件通过严格单程序部署及 749 项导出 HTTP 检查，原报告为 `build/single program-73558d40c1d3/verification.json` 与 `build/iot-identity-e7083cf53529/verification.json`。此处证明 macOS 本机行为，Windows 的原故障仍以新 EXE 的实际复验为准。

修复后的完整单元套件通过 182 项测试、3431 个断言，基础检查覆盖 819 个文件，文档检查通过。原程序、构建身份、PHP/原生回归报告及文件锁对照封存为 `.cache/profile-release-evidence-20260930/export-lock-validation.tar.gz`，SHA-256 为 `da64ddb5eb926a0f279d46aa0bfb5b4f779fce274eab82a4db0f7dd99e48504d`。逐文件回读通过，确认测试 Redis 退出和端口释放后回收 900,025,607 逻辑字节；累计回收 7,809,702,466 逻辑字节。对应程序和共享 SDK 仍保留供后续核验。

## 发布前复核基础扩展

复核生产调用者、固定 Swoole 的 PDO 配置及 phpredis 源码后，进一步去除 profile SDK 中未使用的独立 `sqlite3`、PHP `session` 和 `tokenizer` 扩展，并关闭 phpredis 的 PHP 会话适配。账号会话仍由业务数据库持久化，所选 PDO 与 SQLite 原生库保留；默认 Redis 队列、通知、导出和调度不变。历史 `all` SDK 保留原接口，实际 embed 检查会拒绝将其作为裁剪后的 profile SDK。

本机重新制备 SDK 后，真实 embed 扩展表确认三项扩展已移除，运行库加载仅包含系统库。SDK 首次封存因本机 GMP 归档要求 macOS 26 而拒绝声明 macOS 15；随后按实际 macOS 26 基线登记完整输入。这里不将本机结果计作 CI 的 macOS 15 兼容验收。SDK 准备期间的配置对照已回读封存到 `.cache/profile-release-evidence-20260930/extra-trim-sdk-inputs.tar.gz`，SHA-256 为 `5448c6cbd999d9bed20b396d2f4c85b0120b58856ac1b300c898e3f692d7d9c9`，回收已停止的中间目录 199,351,138 逻辑字节。

完整物联中心重新编译 281 个单元，程序为 47,963,128 字节，SHA-256 为 `75c3fef8585af9c9747260557bf6469784d27ae5891f159c7dfe12b9edad248c`，构建 ID 为 `337b49892f25035209d6f08c7cabbaad3a2424b8c962edd8ac4c7e8862648535`。与同机刚完成导出修复的程序相比再减少 134,416 字节；内嵌前端仍为 2,805,419 原文字节。

同一程序通过严格隔离部署、53 项 MQTT TLS 授权 HTTP 检查、752 项告警检查、745 项导出检查及 506 项调度/管理检查。完整单元套件通过 182 项测试、3436 个断言，基础检查覆盖 819 个文件，文档与分发边界检查通过。Windows 原故障的定向复验见下文，新的完整 SDK 矩阵仍须由新 RC 核验。

本机原始程序、完整身份、SDK 清单及各项报告已逐文件回读封存到 `.cache/profile-release-evidence-20260930/extra-trim-validation.tar.gz`，SHA-256 为 `d741b2dd3b501b2bb0e0fd1923563d69cd8756d2e57b6a592c32b6a497b4a7cb`。确认所属进程退出后回收 6 个测试目录和可重建编译中间文件，共 636,048,538 逻辑字节；本任务累计回收 8,645,102,142 逻辑字节。程序和当前 SDK 继续保留，日常 Redis 实例未被替换或停止。

Windows 定向运行 [36643523483](https://github.com/zoujingli/typeapp/actions/runs/36643523483) 全部成功。它使用提交 `cc365fc6b6dac809081f49e7e67be184dd61ae0b` 重新编译完整应用，复用此前验证的静态 SDK；最终 EXE 为 50,812,320 字节，SHA-256 为 `2df85e6fd1011353e271ef02687c1c17759712356a48b6164c474751efd648b5`。同一文件通过严格无源码部署、54 项 MQTT TLS 授权检查、755 项告警检查、748 项导出检查和 510 项调度/管理检查，随后完成重建材料验收与封存。导出锁内摘要读取的原故障不再出现。原报告及程序保存于 `build/profile-release-validation/rc12/windows-export-fixed-evidence/`；此运行早于额外三项扩展裁剪，不能代替新 SDK 的正式矩阵。

Windows 的五轮原始诊断与修复证据已封存为 `.cache/profile-release-evidence-20260930/windows-export-lock-validation.tar.gz`，SHA-256 为 `b3e934e70b8e96f5ac0c6aeeaa4697d1dac4c35dbf41e7b8c2abd59e8da8eb7e`。归档包含上述原目录、程序、构建报告、日志及最小探针；逐文件回读并按各自报告核对 EXE 摘要后，回收下载副本 290,571,196 逻辑字节。累计回收 8,935,673,338 逻辑字节，历史程序身份保持不变，原目录现从该归档恢复。

四平台 × 三数据库的 12 个最终候选、Windows 专用 Redis 测试实例、16 个分发子仓与 Packagist 消费，以及公开下载摘要仍须由新 RC 的真实 Actions 运行完成。`v1.0.0-rc.12` 固定提交 `2dac4969237e8aff1ddcdcd2f7fa6125b674f651`，正式运行是 [36646304824](https://github.com/zoujingli/typeapp/actions/runs/36646304824)，与前述同名诊断参数的运行分别记录。发布门禁要求全部组合成功；任何失败都阻止主仓 Release 公开。当前记录不宣称这些远端验收已经完成。

## RC12 容量请求与节点退出诊断

RC12 的 macOS PostgreSQL 候选在创建合法十万行快照时被测试客户端的 3 秒预算中断，实际等待 3.0035 秒；当时 PostgreSQL 查询仍在执行，没有锁阻塞者。程序为 46,154,408 字节，SHA-256 为 `260be31e954432cbaaeb2c0c3dba5053ceddac50a10c4682a5b849f433e274cf`。原字节在本机完整导出复验通过 750 项 HTTP 检查，快照创建约 0.8954 秒；此结果不替代 CI 验收。

为区分测试截止与业务失败，在隔离 PostgreSQL 中仅对最大快照写入添加一次 3.2 秒语句级延迟。原测试稳定在 3.0003 秒失败；同一程序、相同装置下，仅为该请求使用生产既定的 30 秒预算后，快照在 4.2535 秒返回 202，746 项 HTTP 检查和资源清理通过。移除延迟装置后的正常回归通过 750 项检查，快照约 0.9917 秒。保留十万行完整计数、超量拒绝、事务、后续有界清理和其他请求的原预算；没有修改生产代码或性能承诺。

上述原始报告分别为 `build/iot-identity-f3e013edf8f2/`、`build/iot-identity-99c6ff75ebe7/`、`build/iot-identity-03236b114d4f/` 和 `build/iot-identity-9fdf42fe37b8/`。新增节点退出观测在本机 SQLite 原生 TLS 授权回归通过 53 项 HTTP 检查，节点约 0.0104 秒正常退出；其报告为 `build/broker-access-96e6c91c73bd/`。格式、819 文件基础检查和文档检查通过。

报告、最小延迟装置和数据库关闭记录已逐文件回读封存到 `.cache/profile-release-evidence-20260930/snapshot-budget-validation.tar.gz`，SHA-256 为 `9ec2b2b3bc3b708b0d33bf042a5a8988e24efd78a9c12fbab9ae016d58a9ce42`。回收已停止的隔离数据库及测试目录共 2,976,495,054 逻辑字节；累计回收 11,912,168,392 逻辑字节。原候选文件继续保留，日常 Redis 不受影响。

Windows PostgreSQL 原候选已通过编译与严格无源码部署，但 MQTT 授权节点停止断言失败。原 EXE 为 49,910,006 字节，SHA-256 为 `58077f13675bc3d408ee107e839eeee61d6b2d53b4831f23b9718fc207bebf04`，下载后已回读核对。原报告未保存退出码，因此增加脱敏日志及退出状态，再以原 EXE 运行 [36650422087](https://github.com/zoujingli/typeapp/actions/runs/36650422087) 诊断；未取得完整结果前不认定原因或通过。以上故障均阻止 RC12 公开，成功组合不能替代失败组合。

Windows MySQL 原候选为 49,739,596 字节，SHA-256 为 `b3bec7e405630c8961e7f4b5c48ca2b6bf95d2a5438d0b7b7bfc9b46a8121db4`。运行 [36654596688](https://github.com/zoujingli/typeapp/actions/runs/36654596688) 只对本轮测试库的导出插入增加 6 秒服务器等待，使用同一 EXE 和 205 行数据。POST 在约 6.0418 秒返回 202，随后 GET 连接被拒绝，HTTP 角色退出码为 1，日志为 `thread_progress_timeout`。该结果排除了“只有十万行结果处理才触发”的解释，数据库等待期间的协程进度仍须修复。延迟触发器在清理时删除；没有放宽生产线程的进度预算。原日志保留在 `build/profile-release-validation/rc12/windows-mysql-wait-evidence/`。

Windows PostgreSQL 的最小停止对照 [36653826674](https://github.com/zoujingli/typeapp/actions/runs/36653826674) 在节点就绪后立即发送 CTRL_BREAK，同一原 EXE 在约 3.5955 秒正常退出，退出码为 0；数据库采样未发现锁阻塞者。该诊断主动终止后续测试，不能计为完整 MQTT 业务通过，完整业务后的等待和停止问题仍未关闭。报告保留在 `build/profile-release-validation/rc12/windows-pgsql-minimal-stop-evidence/`。

独立接缝测量 [36654843594](https://github.com/zoujingli/typeapp/actions/runs/36654843594) 使用锁定的 Windows 宿主 PHP/Swoole，分别执行 20 次同步、仅 PostgreSQL hook 和运行组件 hook 的参数点查询。同步执行及释放通常低于 1 毫秒；两种 hook 模式执行通常约 31 毫秒，释放语句约 16 毫秒。此证据属于宿主接缝，不能冒充静态程序的结果。原日志保留在 `build/profile-release-validation/rc12/windows-pgsql-latency-evidence/`，后续比较官方原生参数绑定模式，保持真实查询及事务语义。

官方 `Pdo\Pgsql::ATTR_DISABLE_PREPARES` 对照 [36655832891](https://github.com/zoujingli/typeapp/actions/runs/36655832891) 在同一连接上分别执行 20 次命名和未命名参数查询。运行组件 hook 下，查询及释放的合计中位数由 46.8855 毫秒降至 15.6271 毫秒；同步对照由 0.2368 毫秒降至 0.1019 毫秒。采用 `PQexecParams` 的路径保留原生参数绑定及事务，不使用 SQL 字符串模拟参数；锁定 PHP 与 Swoole 源码均支持该路径。此结果不等于应用吞吐或完整 MQTT 已通过。

ORM 据此启用该官方配置，新增真实 `pg_prepared_statements` 回归，修改前失败、修改后通过完整独立 PHP 消费。macOS 原生消费者全量编译 93 个单元，程序 SHA-256 为 `0088f45bf80e807f7c51519910c060d6b4dd86db76dfbee49501d4a816b4e913`，构建 ID 为 `64d167837dd1e2ef3ba1bc3ad46a249ac38fee7d683cb75871578de93f201fd0`，移除 103 个业务及依赖 PHP 文件后通过相同 ORM 套件、双进程更新竞争、事务、真实行锁等待和会话重置。该消费者使用共享运行库，是组件行为证据，不作为单文件发布候选。报告为 `build/orm-suite-pgsql-da9531d5b5/verification.json`；本轮 PostgreSQL 专用实例已正常关闭。

上述 PostgreSQL 参数绑定验证已逐文件回读封存为 `.cache/profile-release-evidence-20260930/pgsql-parameter-validation.tar.gz`，SHA-256 为 `db5efcecdf61b2e624867a530733102889fb90bd892263e3a4f6edb2b0376b59`。归档为 17,408,369 字节，回收对应已停止实例和独立消费者共 338,451,021 逻辑字节；累计回收 12,317,374,565 逻辑字节。原报告从归档恢复，不改写原身份。

Windows 独立 PDO 消费者在运行 [36656249048](https://github.com/zoujingli/typeapp/actions/runs/36656249048) 中完成静态编译，程序 SHA-256 为 `5f5b95dcacbe994e9d6949b238a9bcac1fd8f354e5cf59701449a9b193ef9880`。同一文件的主线程与业务线程各执行一次 `SELECT SLEEP(0.5)`，耗时约 0.5036/0.4913 秒，10 毫秒定时器分别推进 32/31 次。随后原 RC12 应用仍在 6 秒导出等待后报告 `thread_progress_timeout`；探针通过不能替代应用验收。证据保存于 `build/profile-release-validation/rc12/windows-pdo-progress-retry-evidence/`。下一对照增加应用实际使用的“主线程先完成一次 Scheduler，再启动业务线程”顺序，检查启动期 hook 生命周期。

MySQL 的 6 秒数据库等待已转为正式导出回归，等待后必须继续通过 HTTP 读取完整任务并完成原有导出检查。macOS PHP 开发路径通过 751 项 HTTP 检查，报告为 `build/iot-identity-48525df95b24/verification.json`；该结果不作为 Windows 原生故障已修复的证据。

本机进一步对照复现了启动顺序的影响：业务线程中同一 `SELECT SLEEP(0.5)` 直接运行时定时器推进 50 次；主线程先完成一次 Scheduler、再调用 `enableIo()` 启动线程时，定时器推进 0 次。锁定 Swoole 的 `PHPCoroutine::deactivate()` 撤销实际 hook，而 `getHookFlags()` 仍返回配置值；原 `enableIo()` 的提前返回跳过了重新安装。修复限定在无其他业务线程的主线程，通过官方 `enableCoroutine()` 重新应用原配置和必要能力；已有业务线程时不修改进程级 hook，不调整监督期限或原生 SDK。

修复后 PHP 对照两项均推进 50 次。独立消费者全量 AOT 后的程序 SHA-256 为 `d89e40a88ab128626127c3572a5b8d0ca9130d412cf934ca01656e0a37ca6867`，构建 ID 为 `389d308989064fe388cf9337d2a7c8a6e01adec8cdfd662fac74e5143127cad2`。使用编译器生成并核验的运行配置，主线程、直接业务线程和先检查再启动业务线程三项均推进 50 次，耗时约 0.5023–0.5056 秒；报告为 `build/profile-release-validation/pdo-preflight-18561005a21b/verification.json`。初次错误使用宿主配置时因未启用 fiber mock 而拒绝业务线程，原失败日志单独保留；程序字节未变。这是 macOS 共享 embed 组件证据，Windows 静态应用尚须独立复验。
