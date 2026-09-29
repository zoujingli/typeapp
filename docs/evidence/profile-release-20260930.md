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

四平台 × 三数据库的 12 个最终候选、Windows 专用 Redis 测试实例、16 个分发子仓与 Packagist 消费，以及公开下载摘要仍须由新 RC 的真实 Actions 运行完成。发布门禁要求全部组合成功；任何失败都阻止主仓 Release 公开。当前记录不宣称这些远端验收已经完成。
