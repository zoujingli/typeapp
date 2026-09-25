# 四平台原生 CI 与开发分支发布验收

验收日期：2026-09-25。固定源码为 `bf28c8bd276f7d0f25535e82ec3ca4a59c4f241a`，使用 PHP 8.5.10 ZTS、TypePHP 0.9.3、PHPX 2.9.2 和组件内置 Swoole 6.2.1。本文记录该提交实际执行的结果；后续文档或代码提交不继承其验收身份。

## 默认平台矩阵

| 平台 | 实际运行 | 结果与范围 |
| --- | --- | --- |
| Linux x64 | [36141190608](https://github.com/zoujingli/typeapp/actions/runs/36141190608) | 19 个分组及 `native-complete` 汇总成功，含组件、三库应用、交付、隔离部署、TLS、恢复与回滚 |
| Linux ARM64 | [36141196518](https://github.com/zoujingli/typeapp/actions/runs/36141196518) | 原生 `ubuntu-24.04-arm` runner 的 9 个默认分组成功，含独立 ORM、完整应用 AOT、三库应用、恢复与回滚；不是早期 QEMU 补测 |
| macOS ARM64 | [36141179921](https://github.com/zoujingli/typeapp/actions/runs/36141179921) | 原生 `macos-15` runner 的 8 个默认分组成功，含契约、应用、部署、回滚、恢复、HTTP、ORM、可靠性与 TLS |
| Windows x64 | [36141201763](https://github.com/zoujingli/typeapp/actions/runs/36141201763) | 完整流程成功，含匹配 SDK、三库独立 ORM、主应用 PHP/AOT、模板与搬迁包 |

这里的“默认矩阵成功”只指工作流实际运行的场景，不表示全部规划功能、五种协议的每个平台组合、MQTT 完整规范或容量验收完成。本轮未运行可选性能基准组。

## 产物与隔离范围

| 场景 | 实际证明 | 限制 |
| --- | --- | --- |
| Linux x64 三库干净部署 | 同一主程序、发布清单和 scratch 镜像；无 PHP 源码、PHP CLI、Composer、SDK 或编译器；只读根、非 root、私有 PID 与数据挂载；原生备份和新目标恢复通过 | 普通搬迁、归档用例的隔离范围较弱，不能与本项混称 |
| Windows 三库主应用 | PHP/AOT 每项 510 个身份 HTTP 检查，共 3060 项；三库 AOT 使用同一主程序 | 主应用报告为 `no_source=false` |
| Windows 搬迁包 | 主应用 SQLite 包及三个独立数据库模板包均无 PHP 源码，启动、HTTP、停止和篡改拒绝通过 | 未禁止读取原项目/SDK 或执行编译器，不能视为完全隔离环境 |
| macOS 三库主应用 | 同一主程序的身份 HTTP 检查通过；MySQL PHP/AOT 为 510/510，SQLite 为 506/506，PostgreSQL 为 511/507 | 报告为 `no_source=false`；不同后端的实际计数分别记录 |
| macOS 三库独立模板 | 发布包无源码，禁止读取原项目/SDK，禁止执行 PHP/编译器，业务与正常停止通过 | 三个模板各有自己的产物，不能称为主应用同一产物的无源码验收 |
| macOS / Windows 独立 ORM | 三库各移除 101 个 PHP 文件后运行，覆盖上下文、连接所有权、父取消、Deadline、晚完成、真实锁等待、乐观锁竞争及原子更新 | PostgreSQL 物理重置复用；MySQL/SQLite 安全关闭重建，完整物理复用未完成 |

关键 SHA-256：

| 身份 | 摘要 |
| --- | --- |
| Linux x64 干净部署主程序 | `4c11a11f32523b773d23981c791d5a98fb4d08fe1e63e475d7acd098eb3bdb03` |
| Linux x64 发布清单 | `50723ceea1728335b762d113b6aa84f2747d819af990de4c4810423980416259` |
| Windows 三库主程序 | `dd8e289a99396201e6e46183ab1fb643ec630b1ad913117d22c86eb7cb88747e` |
| macOS 三库主程序 | `0e7c50f76049057b101ab94b49671f2812be5915e31629b978c05b7d69853375` |
| macOS 完整可靠性组 TLS 程序 | `961adaa2ee55e22a5e5423ccc27fdcc37073728584a8bba165dad4b2704247ff` |

## 发布与自动同步

[15 组件批次分发](https://github.com/zoujingli/typeapp/actions/runs/36144180719)的 18 个任务成功，批次 ID 为 `8e0157d2e5fba75aa6d974358ad89710025258abe2c921806fa2198d512a36be`。所有组件为 `already-current`：远端内容已匹配计划，本轮没有新增组件子仓提交。公开安装、组合全量 AOT 和三库集成通过；每库编译 255 个生产输入，部署报告为 `chroot-without-php-source`，各库产物独立记录。

[应用模板分发](https://github.com/zoujingli/typeapp/actions/runs/36146310707)成功，三库原生模板干净部署通过。模板拆分提交为 `7167135558ea66f4784e0a93a4bf3f22b0142a81`，同样已是远端现有内容。

截至 2026-09-25 14:27 UTC，Packagist 的 16 个 `dev-main` 引用全部与实际 GitHub 拆分提交一致；16 个 push webhook 均启用，最近一次可观察响应均为 HTTP 202。这是引用与自动同步配置核验，不是本轮重新触发了 16 次 webhook。未创建稳定版本标签。使用者通过默认 Packagist 索引安装并提交应用 `composer.lock`，不需逐个添加组件 Git 仓库。

## 修复与保留事项

本轮修复了 PDO/Swoole hook 加载顺序、macOS SDK 的 Zend signals 与 iconv 链接、隔离部署 DNS、Windows 含空格路径启动、分发 CLI 初始化、开发代次测试预算、Windows Timer 回调签名、PostgreSQL 测试等待及异常栈持有连接、Redis 租约 TTL 观察等问题。

macOS TLS 固定使用 OpenSSL 3，测试 PostgreSQL 时通过 `hostaddr` 固定 IPv4 连接地址，同时保留 `verify-full` 对原主机名的校验。[定向 TLS](https://github.com/zoujingli/typeapp/actions/runs/36140239460)及之后完整矩阵的 MySQL、PostgreSQL、Redis PHP/AOT 均通过；完整组使用 OpenSSL 3.6.4，私钥未进入附件，专用服务退出。定向成功没有代替完整矩阵。

Windows SQLite 曾出现一次最高管理员并发结果不符，原始失败未保存两个响应，根因仍未确认。新增诊断后 Windows 五轮、本机三十轮及本次完整平台回归通过。断言继续要求 `[200,409]`、`last_tenant_admin` 和恰好一位有效最高管理员，不能仅凭复跑通过宣称已根治。

当前交付仍为携带动态原生库的目录包与归档；非系统运行库完整静态链接、一个主程序加配置且启动不释放库的目标未完成。其他能力缺口集中维护在[实现规划](../guide/roadmap.md)。

## 证据保全

原始日志、报告及产物位于受控本地归档 `.cache/ci-release-evidence.r5l6y_yr/final-ci-release-evidence.tar.gz`，SHA-256 为 `33dc7e253fff0103dea2f98b9c8ba435ab7f278c7ea4973930c9b24d8d2f576d`；613 个文件已逐项回读验证，清单为同目录 `final-ci-release-manifest.json`。归档与此前复现证据保持各自身份，不进入源码分发。恢复时按清单选择所需条目，在新的任务目录解包；原测试目录、进程与临时发布计划已回收。公开可复核入口为上列 Actions 运行。
