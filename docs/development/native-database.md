# 原生数据库命令

当前对应 ，建立数据库中立接口、MySQL 驱动和有界同步连接池。

 增加独立 PostgreSQL 驱动。通过 TYPE_PGSQL_HOST、TYPE_PGSQL_PORT、TYPE_PGSQL_DATABASE、TYPE_PGSQL_USER、TYPE_PGSQL_PASSWORD 指定专属测试服务，运行 `composer build:pgsql`、`composer test:pgsql` 和 `composer test:pgsql-consumer`。测试采用 PostgreSQL 的 identity/RETURNING 与真实事务行为，不复用 MySQL 方言冒充兼容。

 增加 SQLite 文件与内存驱动，使用 `composer build:sqlite`、`composer test:sqlite` 和 `composer test:sqlite-consumer` 验证。验证器只创建专属临时文件；崩溃用例明确启动子进程并确认其被 SIGKILL 终止，再重新打开数据库检查已提交内容，不能用普通异常退出冒充崩溃恢复。

在已准备的 Linux TypePHP 工具链中安装 pdo_mysql，通过 TYPE_MYSQL_HOST、TYPE_MYSQL_PORT、TYPE_MYSQL_DATABASE、TYPE_MYSQL_USER、TYPE_MYSQL_PASSWORD 指定专属测试数据库，运行：

```bash
composer build:mysql
composer test:mysql
composer test:mysql-consumer
```

命令只操作连接专属临时表，验证参数数据不作为 SQL、主键、读取、更新、删除、提交、回滚、SQL 错误、满载拒绝与释放后失效。独立消费项目只安装 ORM、MySQL 驱动和 runtime 生产包，使用自己的构建工具编译，不依赖 HTTP 核心。

测试凭据只适用于隔离测试服务，不是生产配置。测试不会连接或修改现有业务数据库。

## 当前同步接口

- ExecutionScope 负责关闭登记的租约；Connection.close 可提前归还，之后调用明确失败。
- ResourcePool 的容量、空闲上限明确，同步满载立即拒绝，等待者上限为零。
- 工厂异常归还预留容量，残留事务清理失败或 SQL 错误的资源丢弃，不无限重连。
- query/execute 由调用者承担受管 SQL 约定，改变会话或执行 DDL 使用 raw 并退役租约；不把简单 ping 当作完整会话重置。
- 当前不支持嵌套事务和协程等待；后续按已列出的事务、会话和并发任务扩展。

这些限制是本切片的真实边界，不代表整个 ORM 已完成。完整交付仍需模型、三库能力矩阵、迁移、回滚状态和故障验收。
