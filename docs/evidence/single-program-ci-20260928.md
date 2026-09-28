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

[运行 36403879176](https://github.com/zoujingli/typeapp/actions/runs/36403879176) 的 x64 后续候选在 `06a06d6` 上通过完整静态应用、同一文件三库隔离，以及发行版对应源码与重建材料的采集和回读。回执中的程序摘要为 `e228f7e2c20fc73d81f8661dd7f2393e805a59121facd6da061161e6a4407728`，重建材料摘要为 `a3dbdf4032fdb7ae0978e8b52e944e06ddaecc886b8e69d2c6198ec6aa645ce7`。任务仍失败：systemd 测试已完成启动、业务、崩溃恢复与正常停止，最后的程序摘要检查因清理循环覆盖路径变量而抛出 `TypeError`。没有生成完整服务通过回执，也没有上传最终候选，不能计为服务验收成功；原程序与材料未被旧失败上传规则保留，不能宣称本地回读过它们。原始证据 ZIP 已回读，摘要为 `26b136c5fc4ed81b81521aa294c7a2416d602934c8880025f7b1e7128c2e8d7a`。后续隔离清理变量，并保存失败时的原程序，重新执行服务验收。

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

四平台已有完整静态程序的三库隔离运行证据；Windows 原程序与修复后的隔离脚本分别记录身份，重建附件集成仍在验证。最终发布必须以最终同一源码重新完成四平台验收，不能用本记录跳过剩余门禁。RC7 的历史目录包和此前本机结果保持原身份。

### Windows 完整重编译与材料采集修复

[运行 36410921009](https://github.com/zoujingli/typeapp/actions/runs/36410921009) 在源码 `a0aeeefecd61eff2df75ec661dd53f72bb85127e` 重新完成全量 AOT，同一 EXE 的 SQLite、MySQL、PostgreSQL 隔离部署均通过。程序为 53,638,175 字节，SHA-256 为 `9d417c3f17164ae8d5cf19f11478f26ae83dcc5ed0a2f293c841dfd6a33d5374`，构建 ID 为 `9c060ce75aae74c32c7d2488bceeceded5bc6f3d3b1136d34d1b76eaab44f4f3`。原 EXE、三库回执和原始日志均已下载并回读摘要。

这次运行仍为**失败**：重建附件的许可检查发现 `zstd.lib` 使用 BSD/GPL 双许可证，而源码采集清单遗漏了 Zstd。用原 SDK 重现该错误后，补齐采集项，又在同批 SPDX 中复现 `git+https://github.com/facebook/zstd@v1.5.7` 未被识别的问题。修复提交 `95b1caf` 将该引用恢复为 GitHub 源码归档，继续逐字节核对 SPDX 的 SHA-512，并保留固定 vcpkg 源码、配方和许可原文；未知来源仍拒绝。

修复后的材料采集在本机读取原 Windows SDK 与同批 vcpkg 依赖完成，9,128 个归档成员全部回读通过，三个离线回归通过。Zstd 源码 SHA-512 与原配方一致，为 `26e441267305f6e58080460f96ab98645219a90d290a533410b1b0b1d2f870721c95f8384e342ee647c5e968385a5b7e30c2d04340c37f59b3e6d86762c3260c`。这是材料采集诊断，使用工作区修复脚本，内部主仓归档仍对应 `74f464f`；不能作为 Windows 原生运行或正式候选成功记录。正式发布仍须在同一最终源码的 Windows runner 完成全部步骤。

原始证据与回归身份保全于 `.cache/retained-evidence/static-ci-20260928/windows-materials-36410921009/`，包含失败运行附件、原 EXE、三库报告、同批依赖归档、修复前后日志及材料成员清单。SDK 继续引用此前 `windows-sdk-36398798810/` 的原归档，不复制或改写其身份。

### RC8 发布前置检查

`v1.0.0-rc.8` 标签固定在 `c7a788ed0ed670e58f864120853f2591efce2cc0`。[正式发布运行 36414110386](https://github.com/zoujingli/typeapp/actions/runs/36414110386) 在 `resolve` 的材料测试阶段失败：全新检出中没有 `build/`，两个归档测试创建临时目录时抛出 `FileNotFoundError`。四平台构建、子仓分发和 Release 均未执行，该标签不代表已公开可用版本。

在含中文、空格路径的隔离目录复制原测试与采集器，从不同工作目录执行，已重现相同错误。测试入口改为自行创建生成目录后，三个回归全部通过且临时文件全部回收。RC8 标签保留原身份，修复使用新版本继续发布，不移动既有标签。失败日志和干净目录修复前后结果保全于 `.cache/retained-evidence/static-ci-20260928/rc8-release-36414110386/`。

### RC9 完整矩阵的测试阻塞

`v1.0.0-rc.9` 固定源码为 `64b517f5a9013f4f22b4efa09e2b046224f66566`。[正式发布运行 36414753704](https://github.com/zoujingli/typeapp/actions/runs/36414753704) 已通过版本前置检查与统一前端构建，但两项默认回归及 Windows 单程序隔离失败，阻止发布：

- Windows `StaticRuntimeSdkTest` 的期望路径保留 `__DIR__` 反斜线，SDK 按公开契约返回斜线，两者直接字符串比较失败。修复 `b12415e` 统一夹具路径，保留 ABI、摘要、许可、越界和符号链接拒绝断言；本机 34 项断言通过。[Windows 复验 36416710031](https://github.com/zoujingli/typeapp/actions/runs/36416710031) 的完整公开契约步骤已通过，后续三库步骤因新提交的工作流并发规则取消，整次运行不计为通过。
- Linux ARM64 Redis 分组的基本命令、独立消费、租约与调度均通过；后续独立可靠性消费者的原生停止场景失败。原运行只保存了指向 `native/stop.log` 的异常，上传范围遗漏该内层日志，不能从现有证据认定具体根因。原消费者摘要为 `18eb82ceb9324bd5b23ffb4d64216772a6e4b2491820e778198a0ecd19eeb699`，PHP 对照成功、原生对照失败，身份保留。

诊断提交 `795b6be` 补齐受控子进程失败输出、原始日志上传和独立手动范围，不改变生产代码与验收断言。[定向运行 36416554886](https://github.com/zoujingli/typeapp/actions/runs/36416554886) 完成重新 AOT，PHP/原生的崩溃恢复、容量压力、合作与不合作任务停止均通过；原生程序摘要为 `0999f59b025d37506d1d065fda2a325c26b3ab9dc93f03d7b26779823c4f42b7`。这次未重现原错误，不宣称根因已修复，也不能代替完整版本矩阵。失败及诊断原始 ZIP、日志和回读清单保全于 `.cache/retained-evidence/static-ci-20260928/rc9-release-36414753704/`。

[第二次定向运行 36417136891](https://github.com/zoujingli/typeapp/actions/runs/36417136891) 使用 `b12415e`，同样完成全部 PHP/原生可靠性断言，程序摘要为 `6dcfeffa273450bd6dcdc61c2cf7f491380f769f5fd43769161c83457b2e45a3`。两轮内层停止日志均已回读；重复成功仍不抹去首次失败，也不扩大版本发布范围。

Windows 的最终 EXE 已完成全量 AOT，53,638,175 字节，SHA-256 为 `2be31f7e5a50eff5f7e25189e409f82aabb4c6a50dd40ca37464faea00df79c9`。SQLite 部署步骤在受限令牌的源码拒绝探针失败，尚未进入数据库业务；后续两库及材料封存未运行。原程序、封存身份和原始失败日志已回读保全。[独立隔离探针 36419571265](https://github.com/zoujingli/typeapp/actions/runs/36419571265) 在 `b12415e` 通过源码、编译器、目录元数据、只读程序、可写数据及 ACL 恢复断言，但它不包含完整构建环境的全部拒绝目标，不能代替最终程序部署。

增加系统预装 Node 后，[复现 36420519640](https://github.com/zoujingli/typeapp/actions/runs/36420519640) 明确显示 `node.exe` 的内容读取仍被允许。ACL 回读证实其显式允许项先于继承拒绝；正式发布复用前端成品，独立诊断重新构建前端并优先使用 `setup-node` 安装目录，因此原来的短探针没有覆盖此差异。夹具保留目录隔离，同时为被核对原文件添加本轮 SID 的显式拒绝，并检查 PATH 中全部 Node 程序；控制端权限不变，所有原 ACL 先记账再恢复。

恢复阶段另发现 Windows 会重排全部为继承允许项的 DACL。校验继续逐项核对 ACE 字节、数量及控制标记，仅对全为继承允许项的顺序变化等价比较；含拒绝、显式或特殊 ACE 时仍保留顺序。回归拒绝权限增加、缺项、重复项、继承及保护标志变化。系统 PowerShell 5.1 的枚举位运算另经显式整数转换修正，比较断言同时在 5.1 与 7 执行。[最终探针 36422162158](https://github.com/zoujingli/typeapp/actions/runs/36422162158) 在 `c76f9e4` 全部通过；原始失败、ACL 快照、成功回执和原程序仍保留各自身份。

[原 EXE 复验 36422163509](https://github.com/zoujingli/typeapp/actions/runs/36422163509) 使用测试源码 `c76f9e4`，对上述 RC9 原程序完成 MySQL、PostgreSQL、SQLite 无源码部署，整次诊断成功。受限进程不能读取源码、SDK、PHP、MSVC 或 PATH 中各个 Node；程序目录只读，数据目录可写，前端显式安装、真实页面/API、正常停止及 ACL 恢复均通过。诊断不重新编译，也不生成发布候选，仍不能代替新版本的完整矩阵。

已失败的 RC9 在保存上述结果后取消剩余 macOS HTTP、ORM 和可靠性任务，避免重复占用新候选所需的 runner；未结束的分组不记为通过。RC9 标签、三个 Unix 静态候选和 Windows 失败程序保留原身份，组件及主仓未发布此版本。

本轮本地格式检查覆盖 784 个 PHP 文件，基础检查覆盖 803 个文件，单元测试为 157 项、3267 个断言；Actions 配置、文档与 Docsify 发布边界、真实 Git 分发批次检查均通过。本机检查不替代 Windows 的真实隔离及新版本完整发布矩阵。

## Windows x64，静态运行库阶段

[运行 36387006296](https://github.com/zoujingli/typeapp/actions/runs/36387006296) 已完成第三方依赖的真实链接、PE 导入审计和独立运行。该运行源码为 `64203e2`，固定 vcpkg 提交为 `07f4812200df3d3c931c0c8a6081d3b21fe2bf9f`，使用 `x64-typeapp-static` 和静态 CRT；17 个包生成的 22 份归档逐项回读摘要一致。后续新增的 libiconv 不在这份历史结果中。

探针 SHA-256 为 `30c26671f40553164ba99d8e5254df45468ce9544aace48a56dfb64712d0d3f9`，实际调用了加密、压缩、数据库、XML 和数值计算依赖的公开接口。导入项只有 `crypt32`、`bcrypt`、`ws2_32`、`advapi32`、`iphlpapi`、`secur32`、`kernel32`、`user32` 系统 DLL。仅保留系统 PATH 的独立程序目录可以正常运行；这不代表 PHP、PHPX、Swoole 或应用已经验收。

[运行 36387786915](https://github.com/zoujingli/typeapp/actions/runs/36387786915) 在源码 `eae67954df4eedaa4fbde9b97b48d7e5be9f0357` 上完成了 PHP 8.5.10 ZTS 核心静态归档及 embed 程序链接。该运行**失败**：PE 审计未识别 Windows 自带的 `api-ms-win-core-path-l1-1-0.dll`，因此没有执行后续独立启动，不能计为运行通过。线程缓存重复符号和公共随机数种子函数缺失已不再出现；系统 API 契约名称已加入后续探针的精确清单，仍拒绝非系统 DLL。

上述日志、原始附件及依赖归档已保全于 `.cache/retained-evidence/static-ci-20260928/windows-stages/`。`retention.json` 记录原相对路径、用途和回读摘要。完整扩展、PHPX、全量应用 AOT 与三库单程序部署须继续逐项验证，不能以归档构建成功替代。

[运行 36389336409](https://github.com/zoujingli/typeapp/actions/runs/36389336409) 在源码 `1f78f10e932cb40aa9584ffdfd9af12b742a371a` 上通过了 PHP 核心完整探针：生成静态核心、链接 embed、检查 PE 导入、在只含程序和测试配置的目录中使用系统 PATH 启动，确认 PHP 核心地址属于主程序本身。程序为 7,825,408 字节，SHA-256 为 `638fb4cf7349d1a8d1f0c3dc1857a02b03e8fd9bbb6624c05ed14352b277c1ca`。原始程序、报告和日志回读一致，保全于 `.cache/retained-evidence/static-ci-20260928/windows-core-36389336409/`。

该成功修复了两项不同问题：系统 API-set 识别遗漏，以及静态 CRT 启动时仍查找 `vcruntime140.dll`。固定源码适配仅在动态 CRT 的 `_DLL` 条件成立时保留外部 CRT 版本检查；静态目标仍通过真实 PE 审计拒绝外置非系统库。此核心只包含 PHP 默认扩展，不包含 Swoole、三库驱动或 PHPX，不能作为 Windows 应用交付。

[运行 36388669418](https://github.com/zoujingli/typeapp/actions/runs/36388669418) 的依赖任务在源码 `e6050d211836c4ea2d51be012886c2d4ff31b7b6` 上加入了 libiconv，24 份归档的实际链接及独立启动通过，探针 SHA-256 为 `8478ec03cd482bd457c5081b3c4f508c56ddb74511b65fd67f8f55dca5dc1c9f`。该运行的完整扩展任务因 MySQL 配置开关失败，整次运行仍为失败；后续任务修正了 Windows PHP 的 `--with-mysqlnd`，并用 `--with-openssl=yes` 明确请求静态扩展。

完整扩展的后续构建仍记录为失败：[36389332950](https://github.com/zoujingli/typeapp/actions/runs/36389332950) 因全局 `NGHTTP2_NO_SSIZE_T` 隐藏了 Swoole 使用的兼容接口；[36390523167](https://github.com/zoujingli/typeapp/actions/runs/36390523167) 推进到 c-ares 回调，发现 Windows 的 `ares_socket_t` 与上游 `int` 签名不一致。后续适配让回调及索引保留完整套接字宽度，未禁用 DNS 协程能力。

[36391395572](https://github.com/zoujingli/typeapp/actions/runs/36391395572) 在源码 `cc0c24dede711adc9a1790ac283363bf962b208c` 上完成全部扩展的对象编译和静态归档，最终 embed 链接只剩 `swoole_module_entry` 未解析。Windows ABI 最小复现显示，C++ 声明会生成带修饰的全局符号，PHP 的 C 模块清单引用未修饰名称；后续修复为模块入口声明 C 链接。该运行未完成链接、独立启动或 PHPX 验证，不能记为完整运行库通过。

[运行 36392962515](https://github.com/zoujingli/typeapp/actions/runs/36392962515) 在 `70ccb474d936ff4ec57f03a0911187c5afb5d1d9` 上完成 PHP、Swoole 及完整扩展的静态链接。PE 导入仅有 13 个系统项；审计因名单漏列 `synchronization.lib` 对应的 `api-ms-win-core-synch-l1-2-0.dll` 而停止。独立启动和 PHPX 阶段未执行，后续只补入该准确系统名称。原始导入表、程序和日志保留于本轮运行附件，不把链接成功记为应用验收。

[Windows 隔离探针 36394884670](https://github.com/zoujingli/typeapp/actions/runs/36394884670) 在 `952c74c5777dbf56b4c36f42c1c0a4ab8bcf2cf3` 上通过原生受限令牌检查：同一策略允许读取程序、写入独立数据，拒绝程序目录写入及源码/编译器读取，控制端仍可读原文件，完成后恢复原 ACL。本轮尚未运行应用程序，不能代替完整三库部署；应用接入后还会实际阻断宿主 PHP、Composer、目标 SDK 和构建端 Node。

[运行 36394648959](https://github.com/zoujingli/typeapp/actions/runs/36394648959) 在 `84be866700033e9c785cc16d05c0534d85b5a347` 上通过静态链接、系统导入审计及独立 embed 启动，实际加载了 Swoole 6.2.1、Redis 6.3.0 和三库 PDO 驱动。该运行仍失败：Windows 配置忽略了 Unix 风格的 DOM、XML、SimpleXML 参数，运行时完整性检查准确拒绝漏编。后续改用固定 PHP 源码声明的 `--with-dom`、`--with-xml`、`--with-simplexml`，并在编译前检查无效参数与全部必需扩展的静态配置；真实启动检查继续保留。PHPX 尚未执行，本结果不是完整 SDK 或应用验收。

[运行 36396102521](https://github.com/zoujingli/typeapp/actions/runs/36396102521) 在 `498a405378bf6c494541e93604af923a1e17b9f7` 上通过完整静态运行库探针，包括先前漏编的 DOM、XML、SimpleXML。PHPX 的两个 mpdecimal 静态归档已生成，但 CMake 配置将 Windows 反斜杠路径重新解析为转义而失败；尚未构建 PHPX 或导出 SDK。后续只规范化 CMake 输入路径，保留含空格目录，不改变源码或依赖范围。

[运行 36397197563](https://github.com/zoujingli/typeapp/actions/runs/36397197563) 在 `32884ce788279a30634e0e50d5d005b5867acb30` 上通过 PHPX CMake 配置，完整运行库再次通过；PHPX 编译因 `_wchmod` 声明缺失而失败。头文件预处理确认 libmpdec 的内部 `io.h` 遮蔽了 Windows CRT 同名头。后续构建只向 PHPX 和探针公开 `mpdecimal.h`，与上游 Windows SDK 的头文件边界一致；最终 SDK 已仅导出公开头。该失败发生在应用编译之前，不计为 Windows 单程序验收。

[运行 36398798810](https://github.com/zoujingli/typeapp/actions/runs/36398798810) 在 `45975cf` 上通过完整 PHP/Swoole 静态探针、PHPX 数值与请求生命周期探针、系统加载映像核验，并成功导出包含 28 份静态归档的 SDK。PHP embed 的 SHA-256 为 `ce1d02c9881942ae56555522daca4e41ff7de43ffeede0cf0090417fb6063b66`，PHPX 探针为 `6c1db84b3cad7052b113c14f5c7f5c3e269fdf20f5006205cf57a526c107dab8`。运行库证据附件已下载回读，ZIP 摘要为 `e32676e7783efee2db2adfc37ad164946d944c9f8771ac7e5cbe567d63c7494a`；两个原始报告分别标明其探针范围，不能代替完整应用验收。

同次运行的完整应用 AOT 和单程序封存成功，SQLite 部署在执行应用前失败：系统 PowerShell 5.1 将无 BOM 的 UTF-8 ACL 脚本按旧编码读取，触发语法错误。原独立探针使用 pwsh，未覆盖这个解释器差异。后续为脚本保留 UTF-8 BOM、显式读取 UTF-8 JSON，并让独立探针调用与应用测试相同的系统解释器，覆盖中文和空格目录；三库部署必须重新执行。SDK ZIP 摘要为 `6d8e1d3a708c4cdb4ba97c7057977104655179c7ea5f67a1df5f71d35f141f85`，清单摘要为 `5c16c9f372b246a9d48eebb8e3e5cfcafe02a9c231b78f80235c63d490a50070`；28 份归档及 3060 个头文件已逐项回读一致。

后续独立探针已通过受限令牌的真实读写检查，但暴露了恢复路径的问题：PowerShell 5.1 的 JSON 数组不能再套管道数组；全部原始 ACL 须在任何父目录变更之前保存。[运行 36404795327](https://github.com/zoujingli/typeapp/actions/runs/36404795327) 的逐项差异进一步确认，所有 ACE 权限均已恢复，剩余差别仅为系统设置了 `SE_DACL_AUTO_INHERITED` 状态位。后续校验只排除此处理标记，继续严格比较权限、顺序、继承范围及其他控制标记；只有恢复成功才写通过回执。该失败探针不是应用运行或三库验收结果。

[运行 36405276056](https://github.com/zoujingli/typeapp/actions/runs/36405276056) 在 `7166501` 上通过上述恢复回归。系统 PowerShell 5.1、中文与空格路径、源码/编译器拒绝读取、程序只读、数据可写、控制端不受影响及原 ACL 恢复均通过；成功后恢复账本已删除。原始报告和失败差异保全于 `.cache/retained-evidence/static-ci-20260928/windows-sandbox/`，成功证据 ZIP 的 SHA-256 为 `5fa47929569eb12889679a4a8111274d221fefa581ecd102a34ecb0e06de2a28`。这项结果仅证明隔离设施，应用仍须完成同一 EXE 的三库验收。

[运行 36405920804](https://github.com/zoujingli/typeapp/actions/runs/36405920804) 在同一源码 `7166501` 上完成全量 AOT 和 EXE 封存，但 SQLite 隔离准备因 `Microsoft.PowerShell.Security` 模块加载失败而停止，尚未执行应用。原 EXE 为 53,638,175 字节，SHA-256 为 `7a05e96ec93969970ca58dfa2a385160179ce35d740a78655d2a69f91eb280f5`，构建 ID 为 `60b2d7874a36e7f570d81fe4d9f3c36751621058ed5f2e3b8b3261fd0e30bb1b`。原始附件、日志、清单与全部成员回读结果已保全于 `.cache/retained-evidence/static-ci-20260928/windows-candidate-36405920804/`；此原文件供后续诊断复用，不改变源码身份。

此前 pwsh 直接调用系统解释器会处理旧版模块搜索路径，未覆盖 PHP 普通子进程的环境继承。[最小复现 36408993531](https://github.com/zoujingli/typeapp/actions/runs/36408993531) 在 `a7d0a9e` 上通过普通子进程稳定触发相同加载失败；仅将权限模块固定为当前系统解释器自带路径后，[回归 36409239233](https://github.com/zoujingli/typeapp/actions/runs/36409239233) 在 `e6855b4` 上通过全部权限与恢复检查。成功 ZIP 摘要为 `c4c0e4bd57cb979f8aac0e46e31589f42559c67e5918144686ad525af1399e27`，失败和成功证据均回读保全于 `.cache/retained-evidence/static-ci-20260928/windows-module/`。这证明模块定位修复，不代替原 EXE 的三库业务复验。

[原程序复验 36409443356](https://github.com/zoujingli/typeapp/actions/runs/36409443356) 使用 `bb5adb0` 的测试脚本，保持原 EXE 摘要不变；通过独立启动、外部 INI 拒绝与内嵌许可读取，安装因数据目录无法解析而失败。最小探针 [36410082319](https://github.com/zoujingli/typeapp/actions/runs/36410082319) 复现 `FindFirstFileExW` 查询数据目录时返回拒绝访问：旧规则阻断了父目录查询，而 PHP `realpath` 必须逐层读取目录元数据。后续仅向源码和工具文件传播内容读取、执行拒绝，保留目录元数据查询；[回归 36410335126](https://github.com/zoujingli/typeapp/actions/runs/36410335126) 在 `5fe9457` 上同时通过目录解析、源码内容/执行拒绝、只读程序、可写数据、中文路径和 ACL 恢复。成功 ZIP 摘要为 `b1d4d96d991f74d1bf6724cb69675102e131bccd7f944c1722056811756a7e0b`，原始报告保全于 `.cache/retained-evidence/static-ci-20260928/windows-directory-metadata/`；应用安装与三库仍须复验。

原 EXE 的后续 [三库复验 36410391326](https://github.com/zoujingli/typeapp/actions/runs/36410391326) 已全部成功：程序源码仍为 `7166501`，测试源码为 `5fe9457`，程序 SHA-256 始终为 `7a05e96ec93969970ca58dfa2a385160179ce35d740a78655d2a69f91eb280f5`。SQLite、MySQL、PostgreSQL 初始化分别为 0.658、2.343、21.798 秒，均在 30 秒预算内。三库均通过源码/SDK 内容不可读、只读程序目录、不同工作目录、外部 INI 无效、启动不释放文件、57 份许可原文核对、前端安装/预演/强制更新、上传保留、页面缓存、双端登录、站点默认值、角色 CRUD 和正常停止。33 份原始报告与日志已回读保全于 `.cache/retained-evidence/static-ci-20260928/windows-replay-36410391326/`；重复程序不另保存，引用原候选保全位置。该诊断不生成可发布候选，最终构建与重建附件须重新完成。

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

## 独立模板，macOS ARM64 本机 SQLite

本轮从模板独立安装组件、修改业务并全量编译 183 个生产源码文件，通过 `tests/application-template.php sqlite --onboarding --native --package`。源码基于 `32884ce788279a30634e0e50d5d005b5867acb30` 加本轮模板许可与单程序验收变更；准确补丁与新增源码摘要保留于归档清单。本机 SDK 的最低系统为 26.0，此结果不替代 macOS 15 CI 或其他数据库与平台。

程序为 39,763,976 字节，SHA-256 为 `ff7d23fbd74b97476ff7eeff85f6aa22bd56eb64cfe0a12eadbbdad676e99a8d`，构建 ID 为 `2a718de2e2b1b404d6672cc9024fc1ba8192bd67b2024d71242c53a6c1fded70`。独立消费覆盖迁移、鉴权、CRUD、分页、排序、过滤、PATCH、软删除、过期版本拒绝与正常停止；模板不携带物联中心页面。

同一文件通过无源码部署：含空格的只读程序目录、不同工作目录、拒绝读取源码和 SDK、恶意外部 PHP INI 无效、普通启动不释放文件。`licenses` 在程序内读取 44 份许可原文并逐项核对摘要。安装后的组件 CLI 负责导出、拒绝覆盖和校验程序；业务 HTTP 确认执行了创建项目后修改的实现。

程序、构建身份、锁文件、原始日志与两次同程序部署报告已回读保全于 `.cache/retained-evidence/static-ci-20260928/template-macos-sqlite/`。`retention.json` 记录原相对路径、来源与恢复方法。静态许可文本齐全不代替分发所需的源码或重链接材料，模板结果也不计入物联中心四平台候选。

## 同一 macOS 候选的系统服务复验

使用上文运行 `36383075960` 保全的原文件，SHA-256仍为 `c129ff59722c707bae0979015db02e37145ae336115ad67a687dd7916c967980`，本机执行更新后的 `tests/native-service.php`。通过服务配置CLI、含空格程序路径、非root账号、外置私有配置、登录与CRUD、进程崩溃自动恢复、数据保留、SIGTERM零状态退出、正常停止不重启、日志私有权限、程序字节不变，以及两个进程、监听端口、launchd作业和临时秘密清理。

首次测试仅日志权限失败，作业已卸载；独立最小探针确认本机launchd对整数63与字符串0077均创建0644日志，预先创建0600日志则保持原权限。验收和安装说明据此补齐首次加载前的私有日志准备，不改全局launchd设置。第二轮完整通过；原始失败、探针及成功报告保全于 `.cache/retained-evidence/static-ci-20260928/macos-service/`。此次复验不改变旧程序的源码身份；Linux与Windows系统服务不引用此结果作为通过依据。

## 静态分发的源码与重建材料

[macOS 运行 36403881240](https://github.com/zoujingli/typeapp/actions/runs/36403881240) 在 `06a06d6` 上完成 SDK、全量 AOT、同一程序三库隔离、重建材料和 launchd 生命周期验收，整次运行成功。程序为 69,408,888 字节，SHA-256 为 `29547c5b32c79f391cd3df008300ac17c2cc9d43a8d725ae1af7e294d19a5e25`；三个数据库及服务报告均绑定此摘要。launchd 覆盖私有配置与日志、认证和业务写入、崩溃恢复、数据保留、SIGTERM 零状态退出、不重启、程序目录不变及清理。重建附件为 84,712,215 字节，SHA-256 为 `8c1ed689a1691b377c39fdc665b506f3c34b1be2f79c3782e7aa70209fc38a4f`，本地已逐项回读 6,486 个成员；原始 ZIP、日志、身份与回读报告保全于 `.cache/retained-evidence/static-ci-20260928/macos-36403881240/`。这份预检证据保留其准确源码，最终发布仍须统一四平台源码。

新增生成器的本机 macOS 材料封存与完整回读已通过，共 6486 个成员，原始字节总计 190,129,210。测试 ZIP 为 84,466,794 字节，SHA-256 为 `b5fba458f22e2d4e347f6cdbf4d558ede9a17a84be3715c51c7925a79e82833e`。该测试使用既有 macOS 26 SDK 与源码 `62b72da`，只证明材料收集和校验，不是四平台发布候选或重编译后的应用验收。

Windows 材料采集另用已保全的实际 SDK 与 vcpkg 输入核对 GMP 6.3.0#5、MPFR 4.2.2#1、libiconv 1.19 的归档身份，下载原始源码并校验 SPDX 的 SHA-512，通过固定 vcpkg 源码与 PHP/libmbfl 材料封存、成员回读；测试 ZIP 摘要为 `6ddae2f92b13da8707105d2ee4c31260942bec8ef546bb69c645749a7153934e`。这项在本机进行的材料核对不替代 Windows 原生运行。Linux 发行版源码采集及最终四平台发布附件仍须由各自候选执行。

离线回归拒绝错误来源、成员篡改、额外文件、越界路径、重复文件、缺失目录和符号链接。候选门禁同时要求应用源码、SDK 清单与重建附件的身份一致，草稿封存、回读、摘要清单与幂等发布均包含该附件；部署仍仅使用主程序和外置配置。

## Linux 双架构的完整服务与重建材料复验

[运行 36406813793](https://github.com/zoujingli/typeapp/actions/runs/36406813793) 在 `0961cf9` 上全部成功。两个架构分别从最终单程序完成 MySQL、PostgreSQL、SQLite 隔离部署，随后用同一程序验证 systemd 的私有配置、认证与 CRUD、崩溃恢复、数据保留、正常停止、日志脱敏，以及进程、端口、服务单元和秘密清理。

| 平台 | 程序字节数 | 程序 SHA-256 | 重建材料 SHA-256 |
| --- | --- | --- | --- |
| Linux x64 | 148,731,220 | `18ebdaf8391771736b7029108d02d6c605f295f7b0c8e7193a2efe19cce0d46e` | `ef58962f532a5bf5d1973370c25a03c50f751f6e693e3fdb80a7097bb3d54ca3` |
| Linux ARM64 | 138,655,588 | `8799e6e0940c84d2139f75bf0f8127f1181fc68783564b906059151c944cac3c` | `8444f4ec728fe151b10a8bcad8905badf30c2f1968ac66ab7deb7e36a7e957bc` |

两份程序、三库原始日志、服务回执及重建材料均已本地回读，重建归档逐成员核对。原始候选 ZIP、证据 ZIP、日志、GitHub 身份与回读记录保全于 `.cache/retained-evidence/static-ci-20260928/linux-36406813793/`。这次结果确认 systemd 清理变量修复；最终发布仍需与其他平台固定同一源码，不能将上述预检直接当作 RC 发布矩阵。
