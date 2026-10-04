# Model 优先的数据访问复核

日期：2026-10-04。开发基线：`b72f4db701a171b56e80ebabe623285caa77230e`，分支 `main`。本轮检查现有数据库处理，将站点设置、产品资料的普通读写迁入 Model，并验证原有授权、并发版本及审计事务。新增实现属于当前源码，不改写 RC14 或上一轮 ORM 验收的身份。

## 实现与保留边界

| 范围 | 本轮处理 | 必须保留的语义 |
| --- | --- | --- |
| 站点设置 | 新增 `SiteSetting`，公共服务入口去除 Connection 参数，主读、模型保存与显式投影 | 公共 HTTP 入口先核对安装身份；管理保存和审计在原授权事务内；整数布尔存储和外部布尔输出不变 |
| 产品资料 | 新增租户 `Product`，CRUD 与分页使用 Model/ModelQuery，控制器去除数据库管理器 | 认证授权成功后才绑定租户；同值保存仍消耗版本；旧版本保持 `stale_version`；公开字段不变 |
| 物模型与转移 | 同步调用者的无连接接口；永久编号由原授权事务中的受控表操作分配 | `next_model_version` 不推进产品资料版本；`iot_models` 保留真实三列主键；已发布快照使用调用方原连接；嵌套复制受外层事务保护 |
| 整数模型算术 | SQLite 拒绝溢出转 REAL；MySQL 无符号列同时校验现值和结果的 PHP 整数范围 | NULL 不变；失败回滚整批与保存点；不通过 WHERE 跳过非法行；不收窄普通 Query 的数据库数值语义 |
| 结构门禁 | 对 5 个已迁移服务和 3 个控制器登记具体方法、表表达式、固定 JOIN SQL 及基础设施参数例外 | 拒绝未登记底层调用、直接 PDO、公开连接、部分间接调用与失效例外；不声称实现完整数据流分析 |

门禁覆盖 `IdentityService`、`RoleService`、`TenantService`、`SiteSettings`、`ProductService`、`AuthController`、`AdminController`、`ProductController`。其中前 3 个服务的主体是此前已经迁移的实现。本轮没有增加 Repository、第二套连接池、动态转发或写入行数上限。表例外只绑定方法及表表达式，不证明 WHERE、更新字段、锁序或授权正确；这些仍依赖代码复核和真实业务回归。

## 审计范围与后续切片

IoT 审计逐项覆盖 21 个服务文件，原始分类保存在任务证据的 `iot-db-audit.md`；公共服务与 Broker 的相邻风险另见 `common-broker-db-audit.md`。分类是源码审查结果，不表示这些服务全部完成本轮原生验收。

后续适合独立迁移的普通实体包括设备资料、告警规则及确认、导出任务的查询和操作、指令与转移记录的只读投影。每个切片须先明确平台与租户授权、锁序、版本、失败码，再一起迁移调用者并执行三库与 AOT 回归。设备转移涉及两个租户，不能通过关闭租户过滤来简化。

复合主键、CAS 状态转换、Outbox、同步持久回执、历史时间桶聚合、完整导出快照、恢复代次和设备本地 SQLite 状态机继续使用明确的底层接口。当前 Model 只支持单字段主键，不能取复合键中的一列假装唯一身份。审计中列出的 Broker 并发发布与审计事务风险仍需专门复现，没有计作本轮已修复项。

## 诊断证据

SQLite 最小复现中，原 integer 模型对包含最大整数的一批数据执行递增，SQL 成功但该行转为 REAL，随后模型无法水合。修复后同一入口抛出数据库异常，匹配行和版本全部保持原值。前后报告分别为任务目录中的 `integer-overflow-before.json` 与 `integer-overflow-after.json`。

交叉复核又发现 MySQL `BIGINT UNSIGNED` 可接受大于 PHP 最大整数的值；现值已经超界时，即使递减结果能回到范围内，也不能在模型运算中隐式修复。新增公共消费用例覆盖有/无版本列、最大合法增量、上下溢、NULL、脏值、整批回滚、保存点及通用 Query 的原有范围。

首次应用编译在发现 MySQL 缺口后受控中断，未生成通过的应用产物。`app-build.log`、`build-interruption.json` 和原 `build-source-digests.json` 保留该次输入；最终编译使用独立的 `app-build-final.log` 与 `build-source-digests-final.json`，不覆盖前一阶段身份。

## 已执行检查

| 检查 | 结果 | 原始记录 |
| --- | --- | --- |
| 全量格式 | 818 个文件，0 个待修复 | `build/model-first-audit.bxbc6hee/cs-check.log` |
| 基础、分发与文档 | 839 个 PHP 文件；3 项分发拒绝；1844 处引用、204 条路由、318 个命令和250 个 Composer 脚本 | `check.log`、`docs-final.log` |
| 全量单元 | 203 tests / 15418 assertions | `unit.log` |
| Model 优先门禁专项 | 10 tests / 585 assertions | `model-boundary-unit-final.log` |
| PHP HTTP 三库 | 身份、站点、产品与设备通过；该阶段未带 `--business` | `php-http-matrix.log`、`build/iot-identity-databases-2a57132801a5/verification.json` |
| PHP 接收三库 | SQLite、MySQL、PostgreSQL 通过，产品服务调用者已同步 | `php-ingestion.log`、`build/iot-ingestion-e4e201a15043/verification.json` |
| PHP SQLite 业务补验 | 包含新增转移复制外层审计失败、完整回滚和幂等重试 | `php-sqlite-business.log`、`build/iot-identity-441ba43f0355/verification.json` |
| 前端资源与 Docsify | 复用已有前端并核对资源；公开站点导出 66 文件逐项摘要一致，备案标题保留 | `frontend-manifest.json`、`docs-export-verification.json` |

表中省略目录的日志均位于 `build/model-first-audit.bxbc6hee/`。文档检查仍单独列出两个历史旧命令夹具，不将其计入通过；本轮清理产品与转移文档的过时说明，没有改写旧验收证据。站点导出未部署，内部 evidence 不进入 Docsify 公开目录。

## 同一完整应用的原生三库结果

环境为 macOS ARM64、PHP 8.5.10 ZTS、TypePHP 0.9.4、PHPX 2.9.3；Swoole 固定提交 `4aff74a9ac086458d1c5251e71ac6e080f68b390`，运行时字符串 `6.3.0RC1`。使用现有开发 embed SDK，全量编译生产实现与依赖；不把本机结果当作新的四平台静态 Release 或三个数据库 profile 全矩阵。

完整应用声明 259 项源码输入、实际编译 283 个翻译单元，包含本轮新增模型的生成实现。最终文件大小 32,662,280 字节，SHA-256 为 `42aeabfff1040a32b1edc3c5b09ce10c6e71577cb7d916dfa45ae1143a27bed7`；构建 ID 为 `6dbd1fab33e12bfa8c933bcd734c48657254865282dbf1c15220907af7efe65f`，构建清单 SHA-256 为 `324a1f478b15aa124aa99a16f47dbfd692addad7c6615d5418c903beaabf4de0`。

最终同一文件执行 `--app --no-source --products --devices --business`。矩阵报告为 `build/iot-identity-databases-aecede32ee18/verification.json`，逐项摘要和清理状态在任务 `acceptance-summary.json` 中复核。

| 数据库 | HTTP 检查数量 | 最终报告 |
| --- | --- | --- |
| MySQL | 899 | `build/iot-identity-e4caca079d92/verification.json` |
| PostgreSQL | 899 | `build/iot-identity-c2f007871680/verification.json` |
| SQLite | 899 | `build/iot-identity-b9aeb0e3341e/verification.json` |

覆盖双端身份、安装门、站点设置、产品 CRUD 与不可变物模型、租户隔离、撤权与并发冲突、设备及业务 HTTP、同值保存版本、真实触发器导致的审计失败回滚。转移复制用例核对产品、物模型、内层成功审计、转移及设备完整持久状态回滚，再验证并发和重复审批只提交一次。三个应用实例均正常退出，自建数据库正常关闭。

一次验收命令在构建清单封存结束前调用，被“身份验证产物不存在”拒绝，未进入业务测试；原日志另存 `native-app-matrix-before-seal.log`。仅构建器正常退出后执行的最终矩阵计为通过。

## 独立 ORM 三库消费

`build/native-database-orm-bdae2b1b9524/verification.json` 记录 MySQL、PostgreSQL、SQLite 各自独立 Composer 安装后的 PHP 与全量 AOT 消费。六组均通过，双进程竞争均得到一个 `updated` 和一个 `conflict`；生产包严格为 ORM、所选数据库驱动及 runtime。原生程序在移除业务源码后运行，新整数边界用例和原有用户、文章、标签、关系、迁移及事务行为共用同一公开入口。

| 数据库 | 原生消费者目录 | 程序字节数 | 程序 SHA-256 |
| --- | --- | --- | --- |
| MySQL | `build/orm-suite-mysql-a71b49fb6d` | 6,893,432 | `8bf83275687dc59a625caf11873d960b20bfa50719eb6eb5e8a86737aff51918` |
| PostgreSQL | `build/orm-suite-pgsql-9b6bf5486b` | 6,910,776 | `2ec621a43737fe41f2171eb88a3feadca68af5376b44272a6d2cc794df3d5e2a` |
| SQLite | `build/orm-suite-sqlite-9d12ec3415` | 6,877,240 | `3786ef684419722cc73a7f1be02033d4c755b4ecc5ca0e67b1a3219baf9c3009` |

每个消费者声明 94 个源码输入、实际编译 89 个翻译单元，保全并逐文件回读 104 项源输入。三份原程序与各自构建清单、报告摘要一致；构建 ID 及清单摘要见 `acceptance-summary.json`。另核对三个消费者的 Query、ModelQuery 和 MutationExercise 摘要与冻结源码一致，没有用旧程序证明新用例通过。

完整应用的 29,227 项构建文件输入和 468 项冻结文件均复核未变，记录为 `source-identity-verification.json`。运行结论限于本机 embed 环境；本轮未重新执行其他平台、静态 profile 发布矩阵、完整浏览器页面、MQTT 设备联调或性能对照，不能推断这些项目通过，也不宣称吞吐或体积提升。

## 证据归档与资源回收

唯一归档为 `.cache/retained-evidence/model-first-db-20261004-5g66qfen/evidence.tar.gz`，32,987,028 字节，SHA-256 为 `9b693d117939600a1719d82661eb64e1254d380d3cefea5b83c088b2ccddcf6a`。回读 747 个成员全部匹配，745 个原文件再次核对无变化；同目录 `manifest.json` 的 SHA-256 为 `44f83edd95aebb777fa6ea5fd6e82e19b864706f0a520961be3ba7ae25d6c9b7`。

保全四个原程序及各自构建清单、独立消费者的源输入、通过与中断日志、源码审计、最终文档导出、Git 差异和许可材料。归档保留原项目相对路径；恢复前核对同目录 `SHA256SUMS`，再按 `RESTORE.md` 解压到新的空目录。数据库数据、临时凭据和可重建中间文件不归档，共享 SDK 保留原位置。归档文档快照早于本段回执，最终说明以 Git 中此文件为准。

核对进程引用及真实打开文件后，回收本轮 20 个 build 目录与 1 个由 cache miss 和同一摘要确认的应用缓存目录，共 11,137 个文件、逻辑大小 1,832,075,593 字节。清理回执为同目录 `cleanup.json`，SHA-256 为 `f17a79cdfd44440789ac0c35972c4d23eb8567c24dea0e26108bc0ab52cf11bc`。日常 PHP watch、Redis、共享 SDK、旧 build 目录与历史证据保留。本轮不推送、发版或替换本机业务站点。
