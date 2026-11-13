# 验收入口与迁移边界

本页区分当前测试入口、已退出的业务流程和仍需验收的专项。运行记录必须绑定源码、程序摘要与实际参数；文件存在或基础场景通过，不代表附加专项已经执行。公开能力及待完成要求见[实现规划](../guide/roadmap.md)。

## 当前架构已验证入口

| 验证范围 | 入口与前置条件 |
| --- | --- |
| 独立身份、Tenant 与 Center 管理 HTTP | `composer test:tenant` 或 `php tests/tenant.php sqlite`；网络数据库使用 `composer test:tenant-mysql -- <MySQL工具根>`、`composer test:tenant-pgsql -- <PostgreSQL工具根>`，也可直接执行 `php tests/tenant.php mysql <MySQL工具根>`、`php tests/tenant.php pgsql <PostgreSQL工具根>`；测试自己建立、停止并回收专用实例 |
| 同一 Tenant 入口的原生应用 | `composer test:tenant-native` 或 `php tests/tenant.php sqlite <原生程序>`；原生程序路径可放在项目根的相对路径或使用绝对路径，测试仍使用全新 SQLite 数据库 |
| MQTT 运维归属与统一节点目录 | 上述三库入口覆盖 Platform 只读账号拒绝调试写入、已退出人员命令拒绝；`tests/fixtures/mqtt-node-observation.php` 模拟设备/协议节点上报，核对真实数据库与 HTTP 投影、迟到运行拒绝及停止事实 |
| 当前独立身份恢复 | 上述三库入口在停止 HTTP 后执行 `center:recovery snapshot` 和 `tests/fixtures/tenant-recovery.php`，验证指纹、隔离、权威清单、会话/交接码清理及重启后的登录行为 |
| MySQL 反向模型同步 | `php tests/model-sync.php <MySQL工具根>`；真实驱动验证预览、生成、重复同步、增改列、删除保护、方法保全、精确字段、生成列和 Tenant 隔离 CRUD |

这些结果证明当前源码的 PHP 管理链路和真实数据库行为。模拟节点上报不代表真实 MQTT/TLS，恢复状态机不代表物理备份/复制故障，PHP HTTP 也不代表全量 AOT、无源码运行或正式平台矩阵。

## 合并后的构建与组件入口

仅负责重复编排的 ORM 矩阵脚本已经退出；具体模型、事务、分页、HTTP 及故障用例继续维护，不因入口合并减少覆盖。

| 验证范围 | 当前入口 |
| --- | --- |
| Platform/System/Center 新安装管理链路（PHP） | 见上表；覆盖独立账号、Tenant 入口、RBAC、菜单、品牌、模拟交接、设备及应用授权、节点观测与统一恢复 |
| 模型基础公开行为 | 根 Composer 的 `test:models`、`test:relations`、`test:transactions`、`test:outcomes` 等；完整脚本和驱动参数以 `composer.json` 为准 |
| MySQL 反向模型同步 | `composer test:model-sync -- <MySQL工具根目录>`；创建专用 MySQL 实例，验证公开命令的预览/写入、字段增删、注释、方法保全、精确整数、生成列及 Tenant 隔离 CRUD，结束后清理 |
| 三库独立 ORM 的 PHP 与 AOT 消费 | `tests/native-database-orm.php <MySQL工具根> <PostgreSQL工具根> <Composer程序> [mysql\|pgsql\|sqlite]`，复用 `tests/orm-suite-consumer.php`；保留双进程竞争、所选驱动及无源码验收 |
| 完整应用构建 | `tools/build-application.php` 校验前端输入后执行全量编译；共享库开发验收须明确追加 `--shared-development`，不作为静态发布结果 |
| 同一最终应用的三库业务 | 当前 `typeapp:test` / `typeapp:test-native` 先执行 Tenant 入口；MySQL、PostgreSQL、AOT、无源码及 MQTT 专项仍须使用对应专用入口，旧人员装置尚须迁移到当前独立身份后重验 |
| 独立原生格式与命令控制器 | `tests/Unit/TestCommandTest.php` 在无 `vendor` 的临时目录中运行真实子进程，并核对格式校验、输出及清理；由 `composer test:unit` 执行，不代表应用无 PHP 部署验收 |
| 发布程序及历史共享库包 | `tests/release-candidate.php` 仍含旧升级验收装置，必须改为当前新安装及旧安装拒绝，保留 Demo、通信、跨节点转配和存储只读义务后重验；`tests/native-package.php` 的共享库目录包结果与静态单程序分别记录 |

旧 `orm-matrix.php`、`orm-app-matrix.php` 的职责由上述现行入口承接，`standalone-native-verifier.php` 的行为已并入 PHPUnit。历史运行记录保留原入口和源码身份，不重写成新一轮通过证据。

## 已退出与待迁移的范围

当前生产入口只使用 Platform/System 独立身份和受控模拟登录，独立 Broker 人员后台及旧成员关系已退出。`tests/tenant.php` 是当前 Tenant/Platform/System/Center 的正式管理验收入口；`tests/iot-identity.php`、`tests/broker-*.php` 及相关浏览器装置仍保留历史协议和故障断言，但不属于当前管理入口门禁。迁移时保留其独有协议、并发和故障断言，不能通过恢复生产兼容分支或删除有效断言取得通过。

原遥测混合负载及历史、告警、导出专项随案例能力退出；协议、容量、故障和恢复的有效覆盖继续保留：

| 范围 | 当前入口与验收边界 |
| --- | --- |
| 旧身份与 MQTT 管理装置 | `tests/iot-identity.php`、`tests/broker-*.php` 及相关浏览器装置仍须迁移安装、Platform 登录、Tenant 固定入口和审计来源；旧 `--broker` 人员模式不再对应生产能力 |
| 资源观察与页面 | `tests/iot-device-mqtt.php` 的 `--broker-resources` 分支及浏览器装置需同步当前身份；保留跨页、主题、精确连接和授权断言 |
| 容量与多节点 | 同一 MQTT 入口分别追加 `--capacity` 或 `--cluster`，使用真实注册设备与独立应用凭据 |
| PostgreSQL HA | 同一入口追加 `--ha`，需要专用 Patroni、etcd 和 HAProxy；按协议确认、原始 retained、离线 pending 及资源收尾验收，不再检查退役业务回执 |
| 重新分配 | 同一入口追加 `--reassignments-mqtt`，覆盖旧节点冻结、硬隔离、旧关系清理与目标确认 |
| WAL、物理恢复与 Demo | `tests/iot-recovery.php`、`tests/broker-recovery.php`、`tests/demo-mode.php` 及应用运行装置需同步统一安装和恢复门；保留物理恢复、内部 worker 只读、停止及精确资源清理 |

Windows 跨节点故障使用 `tests/windows-process-fault.ps1` 暂停及终止本轮程序，核对可执行路径、进程启动身份和整个子进程树退出后才登记硬隔离；同步备库只绑定 Windows 数据库装置明确拥有的数据目录，结束时恢复该主库的原同步配置。Unix 继续使用本轮进程的信号与进程树检查。

以上待迁移装置尚不能计为当前源码通过。真实 PostgreSQL 同步/TLS MQTT、持久会话、retained、跨 Tenant 转配和正式原生矩阵仍须按同一最终产物执行。

`tests/docs-consistency.php` 核对测试、工具与示例中的应用命令调用；当前仍需清除未迁移装置的旧命令，不能为未注册命令添加全局豁免。显式验证旧入口拒绝的负向用例继续保留。

没有 Composer 或 CI 调用者也不能直接作为删除依据。开发模式权限、分阶段 I/O 测量、备份保留、延迟发布和 macOS 隔离构建等独立专项仍保留；其中 macOS 旧隔离脚本的构建配置、产物路径及报告字段需要同步后重验。它们的独有断言须完成迁移或证明由现行入口覆盖，才能合并，不能随文件清理宣称验收完成。

历史源码可从清理前提交 `4fe316fe40db83f1c9fe3d71cc112320abb4f89f` 查阅；原始运行证据保持自己的提交和产物身份。测试清理不将未迁移的义务改成已完成，也不以旧页面或旧人员接口恢复生产兼容。
