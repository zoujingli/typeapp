# TypeORM 业务易用性：官方实现对照与取舍

核查日期：2026-10-04。本记录用于内部设计与审查，研究对象为查询、模型持久化、关系、分页、事务及协程连接；不是性能比较，也不是引入其他 ORM 的依赖计划。TypeApp 审查基线为 `638687a5b1910dfb5cae41f2a2051c76e08190ec`。下表的“本次补齐”表示本轮实施范围，是否通过开发、AOT 和三库验证以[本轮验收记录](orm-usability-20261004.md)为准，不反向更新 RC14 的历史结论。

## 固定来源

| 来源 | 版本与源码身份 | 本次核查范围 |
| --- | --- | --- |
| ThinkORM 官方源码 | `v4.0.51`，`46abe2f824eb3bcb117d4c0ce93b203b592b79f7`；标签直接指向该提交 | Model、聚合、批量写入、关系、分页和 PDO 事务 |
| Hyperf 官方源码及随仓文档 | `v3.2.4`；标签对象 `111efff0e97ebef91e09f598b9b4533ed803bffa`，源码提交 `58d9f50ff6f51d4ca828c49e131889a419ea66ce` | 模型与查询 Builder、关系聚合、分页、MySQL upsert、协程连接及事务 |
| TypeApp 本仓源码 | 上述基线提交 | `ModelQuery`、`Query`、`Db`、`ModelDefinition`、`ModelCompiler` 及已有操作说明 |

Hyperf 身份复用同日的[官方架构研究基线](system-architecture-hyperf-baseline.md)，仅补取 ORM 相关原文件；未重复下载完整源码。ThinkORM 标签由官方 GitHub API 核对。两者均使用固定提交链接；本记录不声称已覆盖上游全部功能或运行平台。

本次原始文件、标签响应及 URL/字节数/SHA-256 清单位于任务目录 `build/orm-usability.r0l49mmj/reference/`，交由本轮证据归档保全。成功读取 22 份文件；`sources.json` 的 SHA-256 为 `dd0ec7b8312b39945c6956c1bb94a1a72804035ee5a7be989a5fdc12145a9f4a`。两个猜测的 Hyperf 方言文件路径返回 404，清单单独保留失败请求，未将其作为事实依据。没有启动上游应用或执行性能测试。

## 能力对照

“标准化”指公开入口、结果和失败语义可预测，不意味着复制另一框架的所有别名或动态机制。[模型优先决策](../adr/0023-model-first-crud-boundary.md)继续适用。

| 业务需要 | 已核实的上游能力 | TypeApp 基线 | 本次与后续边界 |
| --- | --- | --- | --- |
| 简短的实体 CRUD | 模型创建、按主键查询、修改保存和缺失异常是 Hyperf 常用入口。[H-model-doc] | 已有 `Model::create/find/query`、实例保存/删除、`findOrFail`、部分字段更新 | 继续作为业务首选，不增加逐层透传的 Repository |
| 类型及输入控制 | Hyperf 模型支持 fillable/guarded 和类型转换；ThinkORM 提供属性、修改器和模型事件。[H-model-doc]、[T-model] | 已有生成字段映射、赋值白名单、严格类型、隐藏输出、必填与生命周期字段 | 批量入口也要保持这些边界，不能直接暴露表查询绕过约束 |
| 模型聚合 | 两者均提供 count/sum/avg/min/max；结果为标量而非模型。[T-aggregate]、[H-aggregate]、[H-model-doc] | 模型自身只有 count；底层 Query 有 aggregate | 本次补 `ModelQuery::sum/avg/min/max`，沿用 SQL 空集与精度语义 |
| 批量新增 | ThinkORM 区分逐模型 `saveAll` 和 SQL `insertAll`；Hyperf 模型 Builder 将 insert 交给底层 Query。[T-save-all]、[T-insert-all]、[H-model-builder] | 底层 `Query::insertMany` 已有，模型级缺失 | 本次补 `ModelQuery::insertMany`；集合语义，不水合、不触发逐模型事件 |
| 冲突写入 | Hyperf Query 提供 upsert，由方言生成 SQL；MySQL 的实现不使用传入的 uniqueBy。[H-upsert]、[H-mysql-upsert] | 底层区分指定冲突目标与 MySQL 任意唯一键；模型级缺失 | 模型 upsert 继续明确为缺口，不能以薄转发宣称补齐 |
| 关系与避免 N+1 | 两者均提供显式预加载和关系统计；Hyperf 有 whereHas、withCount/Sum/Avg/Min/Max。[T-relations]、[H-relations] | 已有嵌套 with、load/loadMissing、whereHas/whereDoesntHave、withCount/withSum | 继续显式加载；关系 avg/min/max、through、多态等不冒称已有 |
| 分页与批处理 | ThinkORM 有总数/简单分页和 paginateX；Hyperf 有总数、简单、游标分页及 chunkById。[T-pagination]、[H-pagination] | 已有三类分页及基于稳定主键的 chunk | 不再增加同义入口；说明总数成本、排序及每批关联预算 |
| 同库事务 | 两者有回调事务；Hyperf 提供受限重试和保存点逻辑。[T-transaction]、[H-transaction] | `Db::transaction` 不向业务暴露连接，另有 afterCommit、保存点和 Outbox | 保持提交未知与不可重放外部副作用的边界，不默认重试全部业务 |
| 长驻协程连接 | Hyperf 按协程与连接名取得并归还连接，归还清理未结束事务。[H-resolver]、[H-release] | 当前作用域持有租约，业务无需手动 connect/release；三库有各自会话恢复策略 | 本次补选定 PDO 驱动的 hook 能力前置检查，不能把扩展存在当作等待已协程化 |
| 并发“查询或创建” | Hyperf firstOrCreate 在唯一约束异常后重查；updateOrCreate 仍是查询、赋值、保存流程。[H-create-or-first] | 无同名通用入口 | 后续若增加，必须说明唯一约束、主库路由、失败重查和事务状态；不承诺无条件原子性 |

## 聚合应便利，但不能丢失数据含义

ThinkORM 固定版本的 `sum()`、`avg()` 返回类型为 `float`，且使用强制数值转换；Hyperf 的 `sum()` 将假值聚合结果变为 0，而 avg/min/max 返回底层聚合结果。[T-aggregate]、[H-aggregate] 这说明同一个方法名也没有跨框架统一的空集或精度契约，不能以“常见写法”代替本项目的数据规则。

TypeApp 采用明确的模型聚合方法，业务用 PHP 属性名，内部完成列映射并沿用租户、软删除、数据源及当前事务。聚合不加载模型和关系，不将全部数据读回 PHP。SUM/AVG/MIN/MAX 的空集或全 null 结果保留为 null；调用者需要展示 0 时显式处理。金额和大整数不得统一转 float；数据库原生返回值和实际数值列语义优先。SQLite 精确数字存储为 TEXT 时，不能用隐式数值转换伪造精确 SUM/AVG，也不能把 TEXT 字典序 MIN/MAX 当成数值顺序。

测试应覆盖属性与列名不同、租户隔离、软删除、主从路由、空集、全 null、负数、大整数/小数及真实数据库返回差异。聚合后原查询仍可用于其他读取，不改变不可变查询契约。

## 三种“批量”必须分开

ThinkORM `Model::saveAll()` 在循环中逐条新建模型并调用 save，返回模型集合；该方法本身没有包住整个循环的事务。`PDOConnection::insertAll()` 则生成批量 SQL：指定批大小或记录达到阈值时分批，并由该分支包住事务。[T-save-all]、[T-insert-all] 因此不能把 saveAll、单 SQL insertMany 与逐条模型事件看作同一种行为。

Hyperf 官方文档明确批量更新不会触发 saved/updated，也不会执行模型 casts，因为没有逐条实例化模型；固定源码的模型 Builder 也将 insert/upsert 直接转给 Query。[H-model-doc]、[H-model-builder] 这是借鉴行为分类的依据，不是 TypeApp 可以绕过赋值、租户或精确字段转换的理由。

TypeApp 本次模型 insertMany 应保持：

1. 输入是同类模型字段数据，整批校验字段、必填、类型和修改器后再写入；不返回“前半批成功”的模糊结果。
2. 自动使用当前可信租户，拒绝冲突归属和人工注入版本、删除状态等受管值；写主库。
3. 单条 SQL 执行集合写入，在实际可用的事务表上保持整批失败回滚；不按任意 maxRows 改变业务写入范围，也不暗中拆批。
4. 返回数据库影响行数，不承诺逐条主键、模型事件、逐行乐观锁或领域副作用；这些需要模型实例流程或事务 Outbox。

模型 upsert 不能简单调用已有 `Query::upsert()`。WHERE 租户范围通常不会进入 INSERT 的冲突更新分支；唯一键可能命中另一租户，MySQL 还可能命中调用者没有指定的其他唯一键。[H-mysql-upsert] 软删除行是否复活、版本如何比较或推进、不可赋值字段是否被更新、数据库约束是否包含租户都需要先定清。当前保留底层真实方言区别，比提供表面统一但会越权的模型接口更符合业务可预测性。

## 关系、分页与协程的简化方向

关系属性只读取已加载结果，继续使用 `with()` 或批量 `loadMissing()`；不用“访问属性即查询”隐藏数据库 I/O。两套上游都具备更多关系类型和统计方法，[T-relations]、[H-relations] 但 TypeApp 先补真实业务需要，不能为了功能数量增加未验收的动态加载。

分页参数继续由业务显式传入，不让 ORM 从全局请求或当前 URL 推断。总数分页适合需要页数的列表，简单分页减少 COUNT，游标与 chunk 用于顺序浏览或导出。当前 maxRows 是父模型与预加载结果的共享水合预算，超限明确失败，不是静默截断或限制集合写入；这个边界应在教程示例中紧邻关系加载说明。

协程简洁接口需要建立在正确归属之上。Hyperf 的 resolver 将连接放入协程 Context，在 defer 中归还；release 对未完成事务回滚，回滚失败标记重置。[H-resolver]、[H-release] TypeApp 继续由当前 ExecutionScope 管理连接和事务，不把数据库对象、事务或活动 Model 放入跨请求共享状态；不新增第二套连接池。

Swoole 扩展存在、PDO 能连接和 PDO 等待能让出协程是三个条件。驱动能力预检应核对所选 PDO 扩展、官方 hook 常量及实际启用状态，在借连接前给出稳定错误；错误提示说明重新使用匹配 SDK 构建，不回退到 stream 或隐藏同步阻塞。此处是 TypeApp 原生架构的要求，不以 Hyperf 的运行平台说明替代本项目验收。

## 适合 TypePHP AOT 的接口取舍

- 保留具名类、显式方法和编译期字段/关系映射；聚合、批量写入复用现有 ModelQuery、ModelDefinition、Query 与租约职责。
- 不复制 Hyperf Builder 的动态 macro、mixin、`__call` 链式转发机制。[H-model-builder] 简短业务代码通过可发现、可静态检查的公开接口完成，不为缩短几个字符增加运行时方法猜测。
- 不把重复静态包装当作能力补齐。先保证 `Device::query()->sum(...)`、`Device::query()->insertMany(...)` 的完整语义、生成注释和示例；只有真实高频且类型收益明确时再扩展模型静态入口。
- 不扩散底层连接参数。普通 CRUD、列表、聚合、批量新增及同库事务都从模型或 Db 入口开始；基础设施表查询保留明确例外。
- 教程按“列表筛选 → 单条修改 → 事务 → 批量导入 → 关系及统计 → 异常处理”给完整案例，说明结果类型、查询数量和事务范围。公开教程只描述本项目实际契约，不写“参考某框架”，也不写未经测量的性能优劣。

新增能力最终需要同一原生产物的真实 MySQL、PostgreSQL、SQLite 验证，尤其是大数/小数、重复唯一键、末条失败回滚、租户与软删除过滤、协程争用及作用域结束归还。本研究仅给出事实和取舍依据，不代替这些验收。

[T-model]: https://github.com/top-think/think-orm/blob/46abe2f824eb3bcb117d4c0ce93b203b592b79f7/src/Model.php
[T-aggregate]: https://github.com/top-think/think-orm/blob/46abe2f824eb3bcb117d4c0ce93b203b592b79f7/src/db/concern/AggregateQuery.php#L89-L130
[T-save-all]: https://github.com/top-think/think-orm/blob/46abe2f824eb3bcb117d4c0ce93b203b592b79f7/src/Model.php#L555-L577
[T-insert-all]: https://github.com/top-think/think-orm/blob/46abe2f824eb3bcb117d4c0ce93b203b592b79f7/src/db/PDOConnection.php#L1062-L1110
[T-relations]: https://github.com/top-think/think-orm/blob/46abe2f824eb3bcb117d4c0ce93b203b592b79f7/src/db/concern/ModelRelationQuery.php#L355-L555
[T-pagination]: https://github.com/top-think/think-orm/blob/46abe2f824eb3bcb117d4c0ce93b203b592b79f7/src/db/BaseQuery.php#L860-L1006
[T-transaction]: https://github.com/top-think/think-orm/blob/46abe2f824eb3bcb117d4c0ce93b203b592b79f7/src/db/PDOConnection.php#L1559-L1575
[H-model-doc]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/docs/zh-cn/db/model.md#L310-L450
[H-model-builder]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/database/src/Model/Builder.php#L101-L167
[H-aggregate]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/database/src/Query/Builder.php#L2550-L2605
[H-upsert]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/database/src/Query/Builder.php#L2788-L2828
[H-mysql-upsert]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/database/src/Query/Grammars/MySqlGrammar.php#L141-L164
[H-relations]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/database/src/Model/Concerns/QueriesRelationships.php#L130-L310
[H-pagination]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/database/src/Model/Builder.php#L842-L905
[H-create-or-first]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/database/src/Model/Builder.php#L557-L590
[H-resolver]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/db-connection/src/ConnectionResolver.php#L40-L72
[H-release]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/db-connection/src/Connection.php#L115-L145
[H-transaction]: https://github.com/hyperf/hyperf/blob/58d9f50ff6f51d4ca828c49e131889a419ea66ce/src/database/src/Concerns/ManagesTransactions.php
