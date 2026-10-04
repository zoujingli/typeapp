# 测试入口清理与跨平台日志修复

日期：2026-10-04。审查基线：远端 `638687a5b1910dfb5cae41f2a2051c76e08190ec`，待上传提交范围延续当前 `main` 的 ORM 与 Model 改动。本记录只保存本轮清理、兼容修复和本地检查结果，不改写历史发布证据。

## 清理范围

删除了三个已被现行入口接替的旧编排脚本：

- `tests/orm-matrix.php`：由 `tests/native-database-orm.php` 与 `tests/orm-suite-consumer.php` 承担三库独立 PHP/AOT 消费和竞争回归。
- `tests/orm-app-matrix.php`：由 `tools/build-application.php`、`tests/iot-identity-databases.php` 和 `tests/native-database-application.php` 承担完整应用构建及三库业务验收。
- `tests/standalone-native-verifier.php`：无 Composer 的原生命令契约并入 `tests/Unit/TestCommandTest.php`，保留真实临时目录、无 `vendor`、参数数组、输出、退出码和异常清理断言。

产品、设备和业务操作入口在 `docs/development/test-entrypoints.md` 中同步为 `--products --devices --business`。浏览器生命周期、混合负载、Broker 观察及历史高可用/容量专项仍保留；它们的旧身份依赖仍标为待迁移，不因入口清理而计作通过。

## Windows 日志兼容修复

Windows 原生契约运行发现 `Output::stream()` 对 `tmpfile()` 创建的普通文件强制设置非阻塞模式，而该平台的 PHP/Swoole 普通 STDIO 文件句柄不支持 `PHP_STREAM_OPTION_BLOCKING`，导致 `ArchitectureRuntimeTest` 抛出“日志输出不能设置非阻塞模式”，`LocalFileTest` 写出空文件。

`Output::prepareStream()` 现在只对已打开句柄经 `fstat()` 判定为 `S_IFREG` 的普通文件跳过非阻塞切换；管道、设备和 socket 仍必须支持非阻塞，特殊文件无法通过识别时仍拒绝。普通文件仍受缓冲容量和排空循环约束，但不承诺中断操作系统内部的磁盘 I/O。Windows stdout 管道和控制台不因本修复而宣称已支持，仍需单独的平台能力验收。

三库 PDO 驱动直接声明 `zoujingli/type-runtime >=1.0.0-rc.15 <1.1.0@dev`，避免与缺少 `CoroutineRuntime::assertPdoHooks()` 的旧 Runtime 混装；模型持久化字段可按数据库列使用 `snake_case`，普通对象属性和方法继续使用 `camelCase`。

## 检查结果

| 检查 | 结果 |
| --- | --- |
| `composer cs-check` | 815 个文件，0 个待修复 |
| `composer check` | PHP 语法与公共接口 836 个文件、分发拒绝 3 项、文档一致性 1853 处引用/204 条路由/318 个命令/250 个 Composer 脚本通过；Docsify 发布边界通过 |
| `composer test:unit` | 207 tests / 15484 assertions，通过 |
| 定向依赖、发布、日志与命令契约 | 64 tests / 12614 assertions，通过 |
| Composer 离线求解 | rc.14 拒绝，rc.15、`1.0.x-dev` 与 `dev-main` 接受，`1.1.0` 拒绝；锁文件可离线安装 |
| 差异与语法 | `git diff --check`、修改文件 PHP lint、Composer JSON 校验通过 |

本轮没有重新执行完整四平台 AOT 或数据库矩阵；已有同一生产源码的原生证据继续保留原身份。Windows 修复需要下一次 Windows 原生工作流重新验证，普通文件修复通过前不把先前失败运行标为平台全量通过。
