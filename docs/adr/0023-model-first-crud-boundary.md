# Model 优先的业务 CRUD 边界

TypeApp 的 ORM 同时提供具体 Model/ModelQuery 和面向表的通用 Query。若业务服务随意从 `Connection::table()` 开始，字段状态、软删除、乐观锁、关系和业务归属就会分散到调用者，多个数据库入口也容易产生不一致语义。

业务实体采用稳定映射的领域 Model，Service 优先通过 Model 与 ModelQuery 完成 CRUD 和关系访问，输入遵守赋值约束，输出采用显式投影，租户范围仍显式声明。连接由框架按当前 Swoole 执行作用域自动借还，业务使用无参 `query()`、`save()` 等入口；默认写主、读从，`master()` 指定主读，事务内全部操作固定同一主库连接，事务外写后不自动粘主，从库故障明确失败，以保留可预测的数据库负载和一致性选择。

迁移、ORM 基础设施、审计/Outbox/运行观测和跨模型复杂投影/聚合保留受限表查询，业务目录通过静态检查约束例外；批量算术沿用 `ModelQuery::increment/decrement` 的明确语义，不能宣称任意批量写入都具有逐模型事件或乐观锁检查。复用现有连接管理、受管资源和 TypePHP 生成入口，不增加通用 Repository、独立调度器或第二套池；自动连接、物理会话复用及业务迁移的实际状态与验收见[模型连接与主从路由](../development/model-connections.md)和[实现规划](../guide/roadmap.md)。
