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

本记录形成时，macOS 15 的候选仍在运行，Windows 仍在验证静态 PHP 核心及第三方依赖，尚未形成 Windows 完整应用候选。最终发布必须以最终同一源码重新完成四平台验收，不能用本记录跳过剩余门禁。RC7 的历史目录包和此前本机结果保持原身份。
