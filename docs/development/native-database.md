# ORM 运行与数据库验收

`type-orm` 的数据库协议由 PDO 及所选 PDO 驱动承担。MySQL、PostgreSQL 和 SQLite 的连接、SQL 语义、事务特性及错误行为仍以真实数据库为准。Swoole 提供协程执行、上下文与等待，`type-runtime` 基于原生机制管理取消、截止及资源收尾，ORM 管理连接租约与会话恢复；TypePHP 负责 ORM、模型和业务代码的全量 AOT 编译。

## 运行边界

- `ExecutionScope` 是一次请求、消息或后台任务的资源所有者。连接必须在当前作用域借用并在 `finally` 中关闭，不能存入全局变量。
- `Database` 通过 `type-runtime` 的有界 `ResourcePool` 管理 PDO 会话。连接租约绑定创建它的进程、线程、协程和 Fiber，跨协程、线程或进程使用会被拒绝。
- `query/execute` 是受管 SQL 入口；会话设置和 DDL 使用 `raw/rawQuery` 并使当前会话退役，框架不自动重试未知提交。
- 父作用域取消、关闭或 Deadline 到期会唤醒子协程。子作用域完成真实收尾后才解除父子监听并归还连接、预算和池容量；等待超时不会提前释放仍在途的 PDO 会话。

## 事务与模型

事务固定使用借入的 `Connection`。嵌套事务使用数据库支持的 savepoint；异常回滚当前层，最外层提交确认后才运行 `afterCommit`。提交确认失败属于未知结果，不能自动重跑写入。模型、关系、乐观锁、迁移和 Outbox 均通过同一连接执行，回滚或提交未知后应重新查询失效模型。

PDO 负责参数绑定、预处理、事务和驱动差异；ORM 负责不可变查询、模型状态、字段校验、连接租约和结果投影。ORM 不新增数据库网络层、连接线程池或私有协程调度器。

## 可复现检查

SQLite 不依赖外部服务：

```bash
composer test:orm-context
composer test:orm-suite sqlite
```

配置真实 MySQL 或 PostgreSQL 后运行对应 PHP 消费验收：

```bash
composer test:orm-suite
composer test:orm-suite-native
```

独立消费者必须保留 Swoole `>=6.2 <7`，报告记录 PHP、Swoole 版本、加载方式（静态或动态）、实际 PDO 驱动、构建包清单及运行模式。未配置 `TYPE_MYSQL_*` 或 `TYPE_PGSQL_*` 时，相关矩阵应明确标记为未运行，不得用 SQLite 结果代替。

## 交付判定

ORM 只有在 SQLite、MySQL、PostgreSQL 的 PHP 与独立 Composer 消费、TypePHP AOT、移除业务源码后的原生运行，以及目标平台的 Swoole、PDO 驱动、协程上下文和资源所有权验收均有同一提交证据后，才可在发布文档中称为完整交付。单次 PHP 测试通过只证明对应模式和数据库的行为。
