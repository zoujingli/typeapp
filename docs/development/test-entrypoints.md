# 验收入口与迁移边界

本页区分当前测试入口、已退出的业务流程和仍需验收的专项。运行记录必须绑定源码、程序摘要与实际参数；文件存在或基础场景通过，不代表附加专项已经执行。公开能力及待完成要求见[实现规划](../guide/roadmap.md)。

## 当前应用入口

| 验证范围 | 入口与前置条件 |
| --- | --- |
| 双端账号、租户、角色及旧入口拒绝 | `tests/iot-identity.php <程序或--php> <驱动> --app` |
| 设备注册资格管理 | 同一入口追加 `--enrollments`；三库控制器支持相同参数，覆盖签发、查询、换码、停用、并发版本、资格期限、租户隔离与审计失败回滚；实际设备注册另行验收 |
| 接入应用管理与密钥 | 同一入口追加 `--applications`；三库控制器支持相同参数，覆盖独立权限、一次性交付、幂等并发、换证撤销、模拟来源与审计失败回滚；`tests/iot-device-mqtt.php <程序或--php> --applications-mqtt` 使用真实 PostgreSQL 同步主备、MQTT.js/TLS 验证独立认证、无设备授权拒绝及活动连接撤权 |
| 逐设备应用通信授权 | `--applications` 同时覆盖三项权限、当前关系、并发版本、撤销和审计回滚；`tests/iot-device-mqtt.php <程序或--php> --communication-mqtt` 验证真实原字节双向通信、QoS、活动订阅及持久会话撤权，不用 HTTP 保存成功替代协议验收 |
| 分类、设备及使用关系 | 同一入口追加 `--products --devices --enrollments --applications --reassignments`；分类可选、无模型注册、授权、转配和审计回滚分别断言，不再执行旧处理链 |
| 独立 Broker 身份与观察 | 同一入口的 `--broker`；`--broker-observability` 由真实 PostgreSQL 同步装置调用 |
| 同一程序三库 | `tests/iot-identity-databases.php <程序或--php> <MySQL工具根> <PostgreSQL工具根> --app`；原生隔离追加 `--no-source` |
| 当前应用升级、就绪与停止 | `tests/application-runtime.php`，参数见该脚本及[修复验收](../evidence/system-architecture-fixes-20261004.md) |
| 双端页面 | 身份入口的 `--app --browser-dist=<本轮前端目录>`；覆盖双端工作区、分类、注册资格、应用授权和重新分配 |
| 生命周期页面 | `tests/iot-lifecycle-browser-fixture.php <程序或--php> <隔离HTTP端口>`，用 `TYPE_PGSQL_TOOLS` 指定 PostgreSQL 工具；再运行 `node tests/iot-lifecycle-browser.mjs <输出目录/fixture.json> <HTTP地址>`。使用当前管理端安装、客户账号与角色，覆盖真实 Broker 撤权、凭据清除、冲突、响应未知和窄屏；结束后向夹具所有者发送 SIGTERM 并核对 `cleanup.json` |
| IoT 节点隔离审计 | `tests/broker-observability.php <程序或--php> --iot-audit-fence`，使用同一 PostgreSQL 主备装置和当前 `admin` 审计域、`app:audit-clean admin`；覆盖代际冻结、硬隔离依据、未知结果对账和有界清理 |
| WAL 与物理恢复 | 独立 `tests/iot-recovery.php`；`--physical` 执行物理链，见[恢复说明](iot-recovery.md)；不使用身份入口的旧 `--recovery` 组合 |
| Demo 只读 | `tests/demo-mode.php <程序或--php>` 验证 SQLite、环境、HTTP 与命令；追加 `--pgsql-store` 使用隔离 PostgreSQL 同步主备，核对内部 worker 的读写动作、过期会话原件及精确后端清理 |

物联应用与独立 Broker 模式不能混用。身份入口在创建运行目录、读取产物或连接数据库前拒绝已退出及尚未接入的专项，避免只执行基础用例后报告组合成功。

生命周期页面及节点隔离审计已恢复现行入口并通过 PHP 验收；报告中的 `native`、程序摘要和实际参数区分 PHP 与原生执行，不能把 PHP 结果计为同候选原生矩阵通过。生命周期浏览器脚本保留恢复后的页面分支，该分支仍须由恢复装置单独验收，普通生命周期结果不覆盖恢复场景。

## 合并后的构建与组件入口

仅负责重复编排的 ORM 矩阵脚本已经退出；具体模型、事务、分页、HTTP 及故障用例继续维护，不因入口合并减少覆盖。

| 验证范围 | 当前入口 |
| --- | --- |
| 模型基础公开行为 | 根 Composer 的 `test:models`、`test:relations`、`test:transactions`、`test:outcomes` 等；完整脚本和驱动参数以 `composer.json` 为准 |
| 三库独立 ORM 的 PHP 与 AOT 消费 | `tests/native-database-orm.php <MySQL工具根> <PostgreSQL工具根> <Composer程序> [mysql\|pgsql\|sqlite]`，复用 `tests/orm-suite-consumer.php`；保留双进程竞争、所选驱动及无源码验收 |
| 完整应用构建 | `tools/build-application.php` 校验前端输入后执行全量编译；共享库开发验收须明确追加 `--shared-development`，不作为静态发布结果 |
| 同一最终应用的三库业务 | `tests/iot-identity-databases.php <程序> <MySQL工具根> <PostgreSQL工具根> --app --no-source --products --devices --enrollments --applications --reassignments`；PHP/原生基础对照仍由 `tests/native-database-application.php` 保留 |
| 独立原生格式与命令控制器 | `tests/Unit/TestCommandTest.php` 在无 `vendor` 的临时目录中运行真实子进程，并核对格式校验、输出及清理；由 `composer test:unit` 执行，不代表应用无 PHP 部署验收 |
| 发布程序及历史共享库包 | `tests/release-candidate.php` 验证静态单程序；`tests/native-package.php` 保留共享库目录包的专用回归，两类结果分别记录 |

旧 `orm-matrix.php`、`orm-app-matrix.php` 的职责由上述现行入口承接，`standalone-native-verifier.php` 的行为已并入 PHPUnit。历史运行记录保留原入口和源码身份，不重写成新一轮通过证据。

## 已退出与待迁移的范围

旧 `/iot/auth`、人员开通及临时支持授权已移除；当前使用独立的管理/客户账号域，以及受控的模拟登录。身份入口原有不可达的旧业务尾部、旧审计浏览器辅助函数和无调用者的支持授权测试已删除。旧入口返回拒绝、伪造身份头被拒绝及模拟来源撤销的用例继续由当前身份、设备和应用授权测试覆盖。

原遥测混合负载及历史、告警、导出专项随案例能力退出；协议、容量、故障和恢复的有效覆盖继续保留：

| 范围 | 当前入口与验收边界 |
| --- | --- |
| 资源观察与页面 | `tests/iot-device-mqtt.php <程序或--php> --broker-resources`；`TYPE_BROKER_RESOURCE_DIST` 指向本轮前端时追加真实浏览器、跨页和主题检查 |
| 容量与多节点 | 同一 MQTT 入口分别追加 `--capacity` 或 `--cluster`，使用真实注册设备与独立应用凭据 |
| PostgreSQL HA | 同一入口追加 `--ha`，需要专用 Patroni、etcd 和 HAProxy；按协议确认、原始 retained、离线 pending 及资源收尾验收，不再检查退役业务回执 |
| 重新分配 | 同一入口追加 `--reassignments-mqtt`，覆盖旧节点冻结、硬隔离、旧关系清理与目标确认 |

以上入口已迁移到当前身份合同；是否通过以对应运行报告为准，不能由脚本存在推断矩阵通过。

`tests/docs-consistency.php` 核对测试、工具与示例中的应用命令调用；已迁移夹具的旧命令登记已移除，未注册命令没有存量或全局豁免。显式验证旧入口拒绝的负向用例继续保留。

没有 Composer 或 CI 调用者也不能直接作为删除依据。开发模式权限、分阶段 I/O 测量、备份保留、延迟发布和 macOS 隔离构建等独立专项仍保留；其中 macOS 旧隔离脚本的构建配置、产物路径及报告字段需要同步后重验。它们的独有断言须完成迁移或证明由现行入口覆盖，才能合并，不能随文件清理宣称验收完成。

历史源码可从清理前提交 `4fe316fe40db83f1c9fe3d71cc112320abb4f89f` 查阅；原始运行证据保持自己的提交和产物身份。测试清理不将未迁移的义务改成已完成，也不以旧页面或旧人员接口恢复生产兼容。
