# TypeORM 业务入口补齐与协程连接核验

日期：2026-10-04。开发基线：`638687a5b1910dfb5cae41f2a2051c76e08190ec`，分支 `main`。本轮以简洁、可预测的业务入口为目标，复用现有模型、查询、作用域和事务；官方实现对照与接口取舍见[研究记录](orm-usability-reference-20261004.md)。本轮能力未包含在已经发布的 RC14 中，不改写历史产物身份。

## 实施范围

| 行为 | 本轮实现及边界 |
| --- | --- |
| 模型统计 | 新增 `ModelQuery::sum/avg/min/max`，保留属性映射、租户、软删除与读路由；不水合模型。空集及全 null 返回 null，COUNT(*) 仍统计匹配行 |
| 数值语义 | SUM/AVG 保留数据库标量，不套用单值精度或强制 float；MIN/MAX 按字段回读。整数算术核对实际整型列，精确数值拒绝 TEXT 隐式转换 |
| 模型批量新增 | 整批校验输入、修改器、必填和列类型后写主库；自动补租户、版本和软删除初值；一条 INSERT，由事务或保存点保护。没有新增行数上限、静默截断或隐式分批 |
| 集合与实例区分 | `insertMany` 返回影响行数，不猜自动主键、不水合、不触发逐模型事件。需要逐条事件与对象时使用事务中的 `create` |
| 协程 PDO 前置检查 | 三驱动物理建连前检查所选驱动。MySQL 核对 mysqlnd 与网络 hook，PostgreSQL/SQLite 核对对应编译能力及 flags；缺能力和缺启动配置分别返回稳定错误 |
| 业务线程配置 | 受控启动消息携带主线程已验证 flags，生成入口校验原生参数并只恢复线程本地选项。消息协议记为 2，构建器拒绝不匹配的运行组件 |
| 文档与示例 | 更新 CRUD、事务、统计、导入、主从路由、能力表及流程图。公开教程描述 TypeApp 自身契约，不写上游功能数量或未经测量的性能优劣 |

模型级 upsert、更多关系统计与关系类型，以及 MySQL/SQLite 完整物理会话复用仍是独立缺口。没有增加 Repository、动态方法转发、第二套连接池或调度器。

## 发现并修复的问题

完整应用首轮编译成功，但 HTTP 业务线程首次建连被新增 hook 检查拒绝。Swoole 固定源码的 `get_hook_flags()` 读取线程本地 `config.hook_flags`；进程共享 handler 已在主线程安装，子线程配置值却未继承。修复沿现有受控启动边界传递真实快照，在创建业务协程前调用官方 `Coroutine::set()` 同步本地选项，不在线程中调用 `enableCoroutine()` 修改共享 handler。新增真实线程成功、缺失或替换消息、缺 flags、错误类型及协程内重入拒绝测试。

首轮失败程序 SHA-256 为 `40ab0b11e97fc07c6709b4c652f64f72e8d4a673e84096c88ea0403cb85efbb5`，原报告为 `build/iot-identity-databases-998c9ca3d038/verification.json`；失败程序、构建清单、冻结输入和日志保存在 `build/orm-usability.r0l49mmj/failed-thread-hooks/`，逐文件身份由 `preserved.json` 记录，没有用后续重建文件替换它。

批量新增验收还收紧主动回滚断言，防止吞掉保存点后续写入错误而产生假阳性。主从专项原临时构建配置遗漏主仓自动加载的应用源码，全量编译审计正确拒绝；随后改用实际 Composer 安装 ORM、三驱动与 runtime 的独立消费者。该示例又暴露了启动入口未调用 `enableIo()` 的缺口，修复与最终复验状态在下文单独记录。

## 检查与文档验证

| 检查 | 已核对结果 | 原始记录 |
| --- | --- | --- |
| 全量格式 | 814 个文件，0 个待修复；包含后续示例启动配置 | `build/orm-usability.r0l49mmj/format-entry-final.log` |
| 基础与分发拒绝检查 | 835 个 PHP 文件；分发拒绝 3 用例通过，包含后续示例启动配置 | `build/orm-usability.r0l49mmj/check-entry-final.log` |
| 单元测试 | 199 tests / 15508 assertions，全部通过 | `build/orm-usability.r0l49mmj/unit-thread-final.log` |
| 文档一致性 | 1844 处引用、204 条路由、318 个命令、250 个 Composer 脚本；两个历史旧命令夹具不计通过 | `build/orm-usability.r0l49mmj/check-entry-final.log` |
| PDO hook 专项 | 初始 4 tests / 20 assertions；SQLite 作用域 10 项行为含真实等待通过，stderr 为空 | `build/orm-usability.r0l49mmj/coroutine/report.json` |
| 线程启动专项 | 5 tests / 57 assertions；6 个真实线程顺序执行成功及拒绝分支，全部 join，父线程 flags 未变 | `build/orm-usability.r0l49mmj/coroutine/thread-startup-report.json` |
| 前端资源校验 | 83 文件、2,805,419 字节；这是资源校验，不是浏览器页面全回归 | `build/orm-usability.r0l49mmj/frontend-verify.log` |
| Docsify 导出 | `build/docs-site.ofiJ4P` 的 66 文件逐项摘要一致；保留标题“物联开源分享”，内部 evidence 未导出 | `build/orm-usability.r0l49mmj/docs-export-verification.json` |

`docs-export-verification-pre-thread.json` 和 `docs-export-verification-pre-entry.json` 保留前两阶段导出的原身份；最新站点导出不反向覆盖这些报告。最后一轮还将运行教程中不存在的 `Coroutine::run()` 改为 `CoroutineRuntime::run()`，并写清每组线程和自定义数据库任务的启动配置。`format-final.log` 是一次格式工具参数使用失败的原日志，后续修正调用及全量格式检查才计为通过。

## 原生验收的阶段与身份

本机为 macOS ARM64，工具链为 PHP 8.5.10 ZTS、TypePHP 0.9.4、PHPX 2.9.3，Swoole 固定提交 `4aff74a9ac086458d1c5251e71ac6e080f68b390`，运行时版本字符串 `6.3.0RC1`。这里使用开发 embed SDK，生产实现全量 AOT；不将其当作新的静态 Release，也不替代四平台 × 三 profile 门禁。

六个产物的最终摘要取自 `build/orm-usability.r0l49mmj/artifacts-verification.json`，主从结果另与 `acceptance-summary.json` 核对。记录复核 284 个冻结输入，补齐独立示例后再复核 301 个最终输入；每个程序保留自身原始构建身份。独立 ORM 程序在模型功能及 PDO 检查完成后、线程协议修复前编译；最终应用、线程和主从程序使用协议 2。前者的模型与最终回滚测试输入已核对一致，但没有把线程协议修复后的源码身份写回旧程序。

| 产物 | 编译阶段 | 程序字节数 | 声明源码输入数 | 程序 SHA-256 |
| --- | --- | --- | --- | --- |
| MySQL 独立 ORM | 模型与 PDO 检查，线程协议修复前 | 6,733,688 | 94 | `2844ab81633af408eaba93427869141dabb90c28a935fbf007152b0bd85048b8` |
| PostgreSQL 独立 ORM | 模型与 PDO 检查，线程协议修复前 | 6,750,984 | 94 | `09e373e427e749676f7cd8da56634652d9bd980d6b961c609d75025f2fc7ccf7` |
| SQLite 独立 ORM | 模型与 PDO 检查，线程协议修复前 | 6,733,992 | 94 | `e48e5d45ab33d31f0d0c592d401e7a4a11a721b51a6deb12858fb82818519de8` |
| 原生线程资源程序 | 线程协议 2 | 7,076,504 | 87 | `93c02f6e5e580a3f4c9d69c79f357285a972cda2e316e6fbaaca3fae2a638cef` |
| 完整物联中心 | 线程协议 2 | 32,374,232 | 259 | `762618368202708cda2b80ae3400a83101ba51b2b99d0f9a2e8e2f839f27d23b` |
| 主从读写独立消费者 | 线程协议 2，补齐示例启动 hook | 5,171,400 | 90 | `696e0f73c0b9db7db67bc3fb74736dfb592c8b350f6bd5fb8138f40f4e3bb357` |

声明源码输入数不等于 TypePHP 的实际编译翻译单元数量。完整应用编译 283 文件；其构建输入重新核对 29,149 项，线程程序重新核对 28,710 项。主从消费者声明 90 个源码输入，实际编译 85 个翻译单元，两项指标分别记录。程序摘要取原验收文件，没有为写证据而重建。

| 产物 | 构建 ID | 构建清单 SHA-256 |
| --- | --- | --- |
| MySQL 独立 ORM | `e0699a3a9fd3dd3a5fb85189e64d164d599ad770856060b9fb7b92f2d35f1e8a` | `b8724dbfdc9b53168ce63bc198dc850b62ded202d26ffbc50ee8d2aebf2c292f` |
| PostgreSQL 独立 ORM | `dd109b0c467997c7a282039896aa6ee725377664d7a1830d90dc222e94101ce4` | `2d616006e7494a57e0b35702f28fb004438e355a5091b8c62474ce6b4ee7f5b0` |
| SQLite 独立 ORM | `74c2ae63f9f9184e2f0bbee2f9c056084c92b8a4c4c6afac8b9cd1a3704f3547` | `2f18f71a7d929202e5ce94ad29c8e0e4d73c7ff67bbb7bd257219ea50f83c53e` |
| 原生线程资源程序 | `40303cf0f55e816dd0f6ded67619dd68febac7cb84849dbacc322eafec89a4e5` | `f122326abf286bce97235ab71089ec60079eecd980182881d226ddb8b4b8499d` |
| 完整物联中心 | `2753f38c34f1ee294d73521628c51f12497af973708d69ce834db091cb279505` | `ee23e68e6998e13d79c8f7621d4f09326f66ef35416a50a1a112149f5a678afe` |
| 主从读写独立消费者 | `9618a18afb47e7dc0e76e7cb2f99279f4785f94d3c1ece465119ff76196bb3ba` | `e4d796f2ee10be23fc31bc6b4e92dac37ade25f9a9372b4402858c5f106f020c` |

### 三库独立 ORM 消费

各驱动实际安装 Composer 生产包，分别执行 PHP 和原生程序；覆盖用户、文章、标签业务，聚合、批量新增、租户与版本约束、整批失败及保存点回滚、双进程并发和原生无业务源码消费。每个原生程序与自己的报告、源码输入归档对应。

| 数据库 | 最后通过的矩阵报告 | 对应原生消费者目录 |
| --- | --- | --- |
| MySQL | `build/native-database-orm-08780b29a2cb/verification.json` | `build/orm-suite-mysql-f86bcae03d` |
| PostgreSQL | `build/native-database-orm-939d1d24e48c/verification.json` | `build/orm-suite-pgsql-81572e6102` |
| SQLite | `build/native-database-orm-ee37bbf3f427/verification.json` | `build/orm-suite-sqlite-5783d1a667` |

三库实际 SQL 语义分别验证，没有使用 Docker，也没有把 PostgreSQL 延迟外键结果当作 MySQL 相同故障通过。该阶段 `CoroutineRuntime.php` 输入 SHA-256 为 `6fbe93c13c14833d7a8e1f3c88927c9007e21f05b2d34ff8ec9b7f9880ce170e`，与后续线程配置修复阶段明确分开。

### 最终线程资源程序

`build/orm-usability.r0l49mmj/threads/resource-evidence.json` 记录同一原生程序运行三轮，每轮两个业务线程、每线程 105 项检查。六个线程均记录 hook flags 为 `526062`、剩余协程为 0；各轮退出码为 `[0, 0]`，结束后只剩主线程。覆盖原生线程中的资源池、截止、收尾与隔离，三轮均在无业务源码环境执行；未启用文件 I/O 候选 ABI。

原始逐轮报告在 `threads/resources-*/execution.json`、`left.json`、`right.json`，不将测试中的故障注入或隔离统计解释为生产泄漏。

### 最终完整应用

同一摘要的完整应用在 MySQL、PostgreSQL、SQLite 下通过原生无业务源码身份与双端 HTTP 验收。矩阵报告为 `build/iot-identity-databases-88f455a2f08c/verification.json`。

| 数据库 | HTTP 检查数量 | 原始报告 |
| --- | --- | --- |
| MySQL | 510 | `build/iot-identity-64228f51abcd/verification.json` |
| PostgreSQL | 506 | `build/iot-identity-70d692f08047/verification.json` |
| SQLite | 506 | `build/iot-identity-8ee416da7bb1/verification.json` |

覆盖平台及客户身份、站点默认信息与完整保存、配置审计、用户/租户/成员/角色管理、并发版本与唯一性、权限撤销、代登录及失败回滚。三个 HTTP 实例均正常退出。这是身份与管理 API 回归，未将其扩展为完整设备业务、所有前端页面、MQTT 或全部任务系统重新验收。

### 主从读写专项与其他示例

主从示例补齐启动期 `enableIo()` 后，MySQL、PostgreSQL、SQLite 的 PHP 和 native 六组均通过。覆盖模型执行时选择数据源、显式主读、事务固定主库、主从内容有差异时的行为以及写后不自动粘主。MySQL/PostgreSQL 使用有受控数据差异的两个真实端点；这不是数据库复制集群验收。

| 数据库 | PHP 报告 | 同一最终原生程序报告 |
| --- | --- | --- |
| MySQL | `.cache/orm-routing-evidence/read-write-5c4aaf2abf2d.json` | `.cache/orm-routing-evidence/read-write-036edb503edb.json` |
| PostgreSQL | `.cache/orm-routing-evidence/read-write-17e760d606cc.json` | `.cache/orm-routing-evidence/read-write-6b19cee8723f.json` |
| SQLite | `.cache/orm-routing-evidence/read-write-79de32a56947.json` | `.cache/orm-routing-evidence/read-write-c93229a28462.json` |

六份报告的摘要逐一与 `acceptance-summary.json` 核对一致，`passed` 均为 true；MySQL/PostgreSQL 各报告的 primary/reader 均记录 closed 和自有服务已停止。原生程序从 `read-write-consumer/build/type-app` 原字节复制到 `read-write/type-app`，两处 SHA-256 相同；构建清单及许可资源随同保全。

此前 PHP 入口因缺少启动 hook 被拒绝；重编译完成前曾误复制旧候选运行 native，也被拒绝。这些诊断与最终六组分开：旧程序 SHA-256 为 `f6f3bb532c7b98cff9db9c043ff8b96614c62f95835e3accebb18051346ccdf3`，旧程序与清单位于 `failed-read-write-startup/`；三个早期 routing 报告由 `acceptance-summary.json` 的 `earlier_diagnostics` 原样列出，其中包含修复过程中的 PHP 成功记录，不把它们计作最终成套结果。

其他显式 PDO 协程示例同步补充启动期 hook 配置：模型 CRUD、精确字段、乐观锁、关系及关联表、生命周期、分页、事务及提交结果、helpers、operations、Outbox、integration、rollout、MQTT，共 15 个示例入口；应用模型测试和接收测试夹具的两个入口同步修正。生产应用已经承担启动责任，没有向数据库库方法中加入全局 hook 改写。

附加 PHP 行为验证共 38 项通过，原报告为 `build/orm-usability.r0l49mmj/additional/verification.json`：三库分别执行 11 个模型/事务/操作/Outbox 测试；再执行 helpers、应用模型（含 PostgreSQL 同步主从持久化）、三库接收服务、SQLite 组合应用及新旧版本回滚。测试复用两个专用原生 Redis 实例，服务全部正常关闭；没有使用日常 Redis 服务。临时控制器首次调用误将超时写为 float，被测试工具的 int 参数拒绝，未执行业务用例且已正常回收服务；改正后完成整套验证，原诊断另存 `additional-setup-error.log`。

这些额外示例未逐例重新 AOT，不能以主应用或独立 ORM 通过代替它们的原生验收。MQTT 示例 `--store-worker-pipe` 分支只核对了启动责任及语法，未执行专项行为或原生重编；普通 MQTT 消费测试也不覆盖该分支。

## 证据归档与资源回收

唯一原始归档位于 `.cache/retained-evidence/orm-usability-20261004-2owydd60/evidence.tar.gz`，大小 44,389,824 字节，SHA-256 为 `fbc6c5f4603299c37ae07fe482dab34a7405fc0463382b1f929fa509cbdf5d24`。同目录 `manifest.json` 摘要为 `b7304ae7c4d0442067e175c1d587ca704ef093a32ab1de66fef2faf0371691fa`。归档回读 619 个成员全部匹配，616 个源文件在归档后再次核对，另保留 Git 差异与状态；没有用重建程序替换原验收字节。

归档包含六个通过的原程序、两个被拒绝的旧程序、构建身份、日志、冻结输入、上游研究材料、最终 Docsify 导出及许可材料。准备脚本采用本轮目录差集及正向白名单；数据库 data、私钥、秘密配置、初始化日志及重复 SDK/编译中间文件排除，原报告不改写。归档内保留原项目相对路径；恢复时先核对同目录 `SHA256SUMS`，再按 `RESTORE.md` 解压到新的空目录，检查后按需取回。共享 SDK 未重复归档。归档中的文档快照早于本段归档/清理回执，最终说明以本次 Git 提交为准。

逐项确认无进程占用后，清理了本轮 35 个 build 目录、两个由构建清单证明新生成的共享缓存项，以及已归档的 11 个外部报告原副本，共 29,469 个文件、逻辑大小 3,250,780,043 字节。`cleanup.json` 记录准确目标、缓存身份和完成状态，摘要为 `273ba6301532decd290016e13d4cc351bf706f10e174253014debe122c255c1e`。自建数据库、Redis 与应用进程均退出；既有 PHP watch、日常 Redis、共享 SDK、其他缓存及历史证据保留。上述 build 原始路径现在通过归档恢复，不再保留重复工作副本。

## 验收范围与限制

没有执行缺失 PDO 编译能力的裁剪 SDK、其他平台、完整前端页面回归或性能对照。当前 SDK 三库 hook 均齐全，缺编译能力的拒绝条件由固定源码核对，未伪造真实裁剪 SDK 通过。不能据此宣称吞吐、延迟或程序体积改善。

文档检查仍单独列出两个历史旧命令夹具待迁移，没有将它们计入通过。本轮不推送、发版或替换本机站点；后续发布仍需使用准确组件批次重跑四平台与三 profile 门禁。
