# 单程序原生 CI 验收

本记录保留 2026-09-28 原生 runner 生成的静态候选及实际部署结果。候选内部版本为 `1.0.0-rc.8`，不代表对应 tag 或 Release 已经发布；不同提交、平台和程序分别记录，不能拼接成同一源码的四平台发布凭据。

## Linux x64

[运行 36382136464](https://github.com/zoujingli/typeapp/actions/runs/36382136464) 的 x64 任务成功。候选和证据已下载回读，程序字节数、SHA-256、三个数据库报告的产物摘要及原始日志摘要一致。

| 字段 | 实际值 |
| --- | --- |
| 源码提交 | `5e59b712d36c6c3d75085e151a56198f9ea24925` |
| 环境 | GitHub Actions Ubuntu 24.04 x64 原生 runner |
| 文件 | `typeapp-iot-1.0.0-rc.8-linux-x64`，单个 ELF 可执行文件 |
| 字节数 | 148,730,708 |
| SHA-256 | `f42fe390d8f07c58e81fa64ad2478ad0ce5a7964278978b078023f8b8fe1cfb6` |
| 构建 ID | `c5ef7724890349a8f362a0ba446c54cd0c82af7f3052579ec63113f3b667c47b` |
| 系统加载项 | `libstdc++.so.6`、`libm.so.6`、`libgcc_s.so.1`、`libc.so.6`、`ld-linux-x86-64.so.2` |

PHP、PHPX、Swoole 和非系统依赖采用已登记的静态归档。MySQL、PostgreSQL、SQLite 的同一文件验收均通过，空库初始化分别为 1.945、0.865、0.639 秒，均在 30 秒预算内；这些时间仅记录功能验收，不构成绩效或性能比较。

实际入口为 `tests/native-single-program.php`。测试先移除构建端 `web/dist`，在拒绝读取源码和 SDK 的环境中，从含空格的只读程序目录、不同工作目录运行同一个程序。通过项包括普通启动不写出文件、外部 PHP INI 无效、64 份内嵌许可原文核对、前端安装与重复/预演/强制更新、上传文件保留、页面 GET/HEAD 与缓存、平台及客户登录、站点默认值、角色 CRUD 和正常停止。

原始附件为 `release-candidate-linux-x64-1` 与 `static-linux-x64-evidence`。本地保全于 `.cache/retained-evidence/static-ci-20260928/linux-36382136464/`，包含程序、SDK 清单、源码适配、应用构建身份、三库报告及构建日志。重建文件不能替换这份候选的验收身份。

## Linux ARM64

同一运行的 ARM64 原生任务也已成功，源码提交同为 `5e59b712d36c6c3d75085e151a56198f9ea24925`。附件 `release-candidate-linux-arm64-1` 与 `static-linux-arm64-evidence` 已保全至同一缓存目录；回读程序及三库日志摘要均一致。

| 字段 | 实际值 |
| --- | --- |
| 环境 | GitHub Actions Ubuntu 24.04 ARM64 原生 runner |
| 文件 | `typeapp-iot-1.0.0-rc.8-linux-arm64`，单个 ELF ARM64 可执行文件 |
| 字节数 | 138,654,404 |
| SHA-256 | `f7ceecf930470b1f6e1afa3dba7f23be4e1c58a4e70e8cefdf80d8f6ca27b0e7` |
| 构建 ID | `74c510625b4c1641e959d09b7c6960afe72a7b19e2d7ce496eac0c71a8f4dcd2` |
| 系统加载项 | `libstdc++.so.6`、`libm.so.6`、`libgcc_s.so.1`、`libc.so.6`、`ld-linux-aarch64.so.1` |

隔离、前端、64 份许可和业务检查范围与 x64 一致。MySQL、PostgreSQL、SQLite 的空库初始化分别为 1.764、0.789、0.579 秒，均在 30 秒预算内且正常退出。三个报告的程序摘要均为上述 ARM64 摘要；此前本机 ARM64 容器产物仍保留独立身份。

两个架构共保全 48 个文件、306,639,263 字节，逐文件复制后回读摘要一致，原下载目录已回收。`retention.json` 记录原相对路径、来源提交、运行编号与全部文件摘要；需要恢复时按记录复制原始文件，不重新编译冒充旧证据。

## 发布边界

Windows 的静态 PHP 核心及第三方依赖探针已经分别通过，完整扩展与 PHPX 尚在验证，尚未形成 Windows 完整应用候选。最终发布必须以最终同一源码重新完成四平台验收，不能用本记录跳过剩余门禁。RC7 的历史目录包和此前本机结果保持原身份。

## Windows x64，静态运行库阶段

[运行 36387006296](https://github.com/zoujingli/typeapp/actions/runs/36387006296) 已完成第三方依赖的真实链接、PE 导入审计和独立运行。该运行源码为 `64203e2`，固定 vcpkg 提交为 `07f4812200df3d3c931c0c8a6081d3b21fe2bf9f`，使用 `x64-typeapp-static` 和静态 CRT；17 个包生成的 22 份归档逐项回读摘要一致。后续新增的 libiconv 不在这份历史结果中。

探针 SHA-256 为 `30c26671f40553164ba99d8e5254df45468ce9544aace48a56dfb64712d0d3f9`，实际调用了加密、压缩、数据库、XML 和数值计算依赖的公开接口。导入项只有 `crypt32`、`bcrypt`、`ws2_32`、`advapi32`、`iphlpapi`、`secur32`、`kernel32`、`user32` 系统 DLL。仅保留系统 PATH 的独立程序目录可以正常运行；这不代表 PHP、PHPX、Swoole 或应用已经验收。

[运行 36387786915](https://github.com/zoujingli/typeapp/actions/runs/36387786915) 在源码 `eae67954df4eedaa4fbde9b97b48d7e5be9f0357` 上完成了 PHP 8.5.10 ZTS 核心静态归档及 embed 程序链接。该运行**失败**：PE 审计未识别 Windows 自带的 `api-ms-win-core-path-l1-1-0.dll`，因此没有执行后续独立启动，不能计为运行通过。线程缓存重复符号和公共随机数种子函数缺失已不再出现；系统 API 契约名称已加入后续探针的精确清单，仍拒绝非系统 DLL。

上述日志、原始附件及依赖归档已保全于 `.cache/retained-evidence/static-ci-20260928/windows-stages/`。`retention.json` 记录原相对路径、用途和回读摘要。完整扩展、PHPX、全量应用 AOT 与三库单程序部署须继续逐项验证，不能以归档构建成功替代。

[运行 36389336409](https://github.com/zoujingli/typeapp/actions/runs/36389336409) 在源码 `1f78f10e932cb40aa9584ffdfd9af12b742a371a` 上通过了 PHP 核心完整探针：生成静态核心、链接 embed、检查 PE 导入、在只含程序和测试配置的目录中使用系统 PATH 启动，确认 PHP 核心地址属于主程序本身。程序为 7,825,408 字节，SHA-256 为 `638fb4cf7349d1a8d1f0c3dc1857a02b03e8fd9bbb6624c05ed14352b277c1ca`。原始程序、报告和日志回读一致，保全于 `.cache/retained-evidence/static-ci-20260928/windows-core-36389336409/`。

该成功修复了两项不同问题：系统 API-set 识别遗漏，以及静态 CRT 启动时仍查找 `vcruntime140.dll`。固定源码适配仅在动态 CRT 的 `_DLL` 条件成立时保留外部 CRT 版本检查；静态目标仍通过真实 PE 审计拒绝外置非系统库。此核心只包含 PHP 默认扩展，不包含 Swoole、三库驱动或 PHPX，不能作为 Windows 应用交付。

[运行 36388669418](https://github.com/zoujingli/typeapp/actions/runs/36388669418) 的依赖任务在源码 `e6050d211836c4ea2d51be012886c2d4ff31b7b6` 上加入了 libiconv，24 份归档的实际链接及独立启动通过，探针 SHA-256 为 `8478ec03cd482bd457c5081b3c4f508c56ddb74511b65fd67f8f55dca5dc1c9f`。该运行的完整扩展任务因 MySQL 配置开关失败，整次运行仍为失败；后续任务修正了 Windows PHP 的 `--with-mysqlnd`，并用 `--with-openssl=yes` 明确请求静态扩展。

## macOS ARM64，最低系统 15

[运行 36383075960](https://github.com/zoujingli/typeapp/actions/runs/36383075960) 已成功，源码提交为 `2fd944fc55d40aee108432b03ec708ad9e07c9d0`。候选与原始证据均已下载回读；三库报告及日志摘要绑定同一个文件。

| 字段 | 实际值 |
| --- | --- |
| 环境 | GitHub Actions macOS 15 ARM64 原生 runner |
| 文件 | `typeapp-iot-1.0.0-rc.8-macos-arm64`，单个 Mach-O ARM64 可执行文件 |
| 字节数 | 69,408,472 |
| SHA-256 | `c129ff59722c707bae0979015db02e37145ae336115ad67a687dd7916c967980` |
| 构建 ID | `fea1104527bc3662e6fbefb4449f44cdf77ef0add096cd751fbc62576e3dad4a` |
| 最低系统 | 所有非系统静态归档及最终程序均声明 macOS 15.0；最终链接 SDK 为 15.5 |

最终加载项仅来自 `/usr/lib/` 和系统框架：resolver、libSystem、XML、zlib、curl、iconv、CoreFoundation、Security、libc++。PHP、PHPX、Swoole、OpenSSL、数据库客户端及其他非系统运行库静态链接；不携带 Homebrew 动态库。

同一程序在 MySQL、PostgreSQL、SQLite 下通过完整安装与页面/API 业务闭环，空库初始化分别为 1.749、1.057、0.916 秒，均正常退出。源码/SDK 隔离、只读程序目录、不同工作目录、普通启动不释放文件、恶意外部 PHP INI 无效及 54 份内嵌许可原文均已验证。

附件 `release-candidate-macos-arm64-1` 和 `macos-arm64-single-program` 已保全至 `.cache/retained-evidence/static-ci-20260928/macos-36383075960/`。31 个文件共 153,766,889 字节回读一致；相同内容使用硬链接保留原路径，实际唯一内容为 84,329,219 字节，原下载目录已回收。`retention.json` 登记全部原路径及摘要。

先前 PostgreSQL 配置找不到 keg-only OpenSSL 的失败日志仍单独保留；本轮通过显式传递 OpenSSL 头文件和库搜索目录完成重跑。此前最低系统为 26 的本机产物不被改写为此 macOS 15 候选。
