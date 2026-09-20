# 数据库与模型

`type-orm` 提供连接、不可变查询、生成模型、事务、关系和迁移。MySQL、PostgreSQL、SQLite 由各自驱动实现，保留真实数据库语义。

ORM 使用 PDO 访问数据库协议；Swoole 提供执行上下文、协程等待、父子取消、Deadline 和连接资源生命周期；TypePHP 负责 ORM、模型及业务代码的全量 AOT 编译。ORM 不新增数据库网络协议、连接线程池或私有协程调度器。

业务数据访问以领域 Model 为标准。已确定的接口方向为无参 `Model::query()`、`save()`，框架自动管理连接；默认写主、读从，`master()` 指定主读，事务固定同一主库连接，从库故障明确失败。当前 Model 仍要求显式连接，物理 PDO 复用也尚待完成，详见[模型连接与主从路由](../development/model-connections.md)和[实现规划](roadmap.md)。

## 选择数据库

| 数据库 | 组件 | 准备事项 |
| --- | --- | --- |
| MySQL | `type-orm-mysql` | PDO 驱动、已创建的数据库和账号 |
| PostgreSQL | `type-orm-pgsql` | PDO 驱动、数据库、账号及 schema 权限 |
| SQLite | `type-orm-sqlite` | PDO 驱动、可写的数据目录与持久化文件 |

物联中心成品案例通过 `DB_DRIVER` 选择已安装的驱动，独立模板在首次安装前选择一个驱动。切换数据库不会迁移原数据，也不会删除旧数据库。

## 显式迁移

在本仓库根执行：

```bash
composer typeapp:migrate -- run
composer typeapp:migrate -- status
composer typeapp:migrate -- history
```

独立模板将上述入口替换为 `php dev.php migrate`。`run` 按版本执行，重复执行保持幂等；SQLite 只有在显式运行迁移时创建数据文件。

迁移具有校验和和历史记录。MySQL DDL 不等同于事务性 DDL：失败后先检查数据库实际状态与迁移历史，再决定恢复或重试，不能假定自动回滚。PostgreSQL、SQLite 也要按各自的事务、锁与文件语义验证。

独立使用 ORM 时，可参考[迁移与事务意图示例](plugins/type-orm.md#运行迁移与事务意图示例)，从 Migration 声明、显式 run 到业务写入逐步运行。模型映射和建表迁移是两份不同声明；新增模型字段也需要相应数据库迁移。

## 声明模型

在 `app/` 的领域 `model/` 目录中声明直接继承 `Type\Orm\Model` 的业务类，用 `#[Table(...)]` 指定表，用公开类型属性声明字段。物联中心先完成身份/租户模型与服务，再覆盖角色权限、产品、物模型、设备、遥测、告警、通知和导出任务；该范围是实施要求，不表示所有业务已完成 Model 迁移。`Column` 补充列名、精确类型、赋值权限和输出可见性，可空性来自 `?string` 等 PHP 类型。

构建器从 `sources` 静态识别模型，保留原类名和业务方法，生成字段映射、水合工厂、查询入口和属性钩子。业务服务负责租户条件、角色权限和设备归属；不能用通用 users 表或跨租户全表查询替代这些约束。PHP 开发入口先加载本代转换结果，AOT 编译同一结果；业务源码、完整转换结果和生成器身份都进入审计。不要直接加载未经准备的模型源码。

旧 `models` 构建键及模型 JSON 已移除；保留旧键会收到迁移错误。删除旧声明并将 PHP 模型加入 `sources` 后重新 prepare/build，旧模型缓存不能复用。完整声明与开发加载例子见[模型与关系](plugins/type-orm.md#models-relations-output)。

查询的 `find()`、`first()` 未命中返回 null，`get()` 返回列表。过滤、选列、排序与限制返回新的查询对象；部分字段查询保留持久化所需的主键，响应字段仍应显式选择。

## 字段与修改

字段有「未加载」「已加载为 null」「已加载为值」的区别。读取未加载字段会报 `field_not_loaded`，未知字段会报 `unknown_field`；不能用默认值掩盖数据缺失。

`fill()` 先验证整批输入，再更新对象；未知字段、不可赋值字段和持久化后修改主键会被拒绝。`dirty()` 记录真实修改，部分模型保存只写入已提供且发生变化的字段。

属性钩子与 `get/set` 共用这些检查。JSON 数组需先读取、修改副本，再整值赋回；属性不支持取引用或间接修改。关系属性只读取显式加载结果，未加载时报告 `relation_not_loaded`。

输出使用 `project()` 或业务自己的投影方法。字段可见性与赋值权限分别声明，不能将持久化对象中的所有字段直接作为 API 响应。

## 并发与删除

模型声明 `version` 后，写入使用主键与旧版本共同匹配，成功时推进版本。过期版本报 `optimistic_conflict`；物联中心成品案例将其转成 HTTP 409。冲突或事务失败后重新读取模型，不继续复用已经失效的对象。

软删除字段由 `Table(softDelete: 'deleted_at')` 声明。默认查询隐藏已删除记录，`withTrashed()`、`onlyTrashed()` 显式改变查询范围。底层 Query 批量操作不会自动触发逐模型乐观锁或事件，调用者负责其明确语义。`ModelQuery::increment/decrement` 执行条件原子更新，并同时推进声明的版本列；已经读取的模型需要重新查询。

PATCH 的缺失字段保持不变，明确的 null 用于清空可空字段。提交版本应来自最近一次读取，不应在客户端固定为 1。

## 事务与精确值

事务/缓存 Attribute 通过构建生成的操作组合对象执行；直接调用原服务不会自动开启事务。可靠外部效果可使用事务 Outbox，在事务内记录意图，再由转发器交付；消费者仍须处理重复。

Outbox 的[投递、对账与回收](plugins/type-orm.md#投递、对账与回收)需要应用明确实现 Publisher；记录 pending 不等于已经投递，published 不等于目标业务已消费。

`decimal`、`bigint` 使用精确的十进制字符串，拒绝有损浮点输入；日期统一为 UTC，并明确精度与时区。SQLite 的精确数值示例使用 TEXT，避免数值亲和转换丢失精度。三库都应按实际列类型验证，不把一种数据库的结果推论到其他数据库。

继续阅读：[组件参考](components.md) · [构建与部署](deployment.md)。
