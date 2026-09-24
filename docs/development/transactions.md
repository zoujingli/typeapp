# 嵌套事务与模型失效

业务通过 `Db::transaction()` 表达同库事务，回调没有连接参数并保留返回值。当前作用域内的 Model 读取、写入和关系查询使用同一主库租约；内层采用三库共有的 savepoint 语义，不另借连接，访问另一逻辑数据源报 `cross_database_transaction`。

```php
$id = Db::transaction(static function (): int {
    $user = User::create(['name' => '成员甲', 'age' => 20]);
    Db::afterCommit(static function (): void {
        invalidateUserList();
    });
    return $user->getId();
});
```

应用启动期通过 `Db::configure()` 装配管理器，调用时须处于已绑定的 Swoole 执行作用域。完整装配示例见[模型连接与主从路由](model-connections.md)。事务外普通查询按读库配置路由，写入后需要立刻确认时显式使用 `master()`；框架不自动粘主。

`Db::transaction($operation, $database = 'default', $mode = 'default')` 保留业务返回值；业务异常完成回滚后继续抛出原异常。SQLite 可通过 `mode: 'immediate'` 在开始时取得写事务；内层沿用外层模式，不切换隔离方式。基础设施的 `Connection::transaction($operation, $mode)` 保留显式连接回调，`transactionDepth()` 返回当前嵌套深度。

每层事务登记经该连接水合、保存或删除的模型。内层成功后将登记合并到父层；内层回滚只使本层登记失效，外层回滚使所有合并的参与对象失效。需要让只做内存编辑的对象参与该事务时，可以显式调用 `trackModel()`。不同查询得到的对象分别登记，不使用隐式全局身份表。

失效后不能读字段、读关系、JSON 序列化、PHP 序列化或继续保存；自增 ID 同样通过受控访问器检查。克隆共享失效状态，不能在回滚前复制对象来绕过登记。模型不会自动恢复为原快照或自动重载，调用者显式重新查询。

运行时 `ExecutionOwner` 同时检查进程、线程请求、PHP Fiber 和 Swoole 协程。Scope 与租约都执行所有权检查，其他执行者不能查询或关闭当前作用域。受管子任务继承可信值快照，但新建作用域并独立取得连接与事务。`ResourceLease::hold()` 在操作退出前保留资源和池容量；关闭租约只是发出关闭意图，事务会在结束前检查该意图并安全回滚，之后才归还资源。

事务控制发生故障时丢弃可复用资格，并拒绝继续使用该连接。取消与截止会拒绝新工作；等待超时不代表底层 I/O 已经停止，在途操作真实退出前仍占用租约和容量。PHP 专项覆盖父取消、Deadline、子任务隔离与等待超时，原生及平台结果按实际产物另行验收。

## 事务结果与提交后回调

引入 `TransactionOutcome`：NOT_STARTED、ACTIVE、COMMITTED、ROLLED_BACK、UNKNOWN。`Connection::transactionOutcome()` 表示当前或最近一次最外层事务的状态；状态在事务进入、返回或抛错时更新。普通业务异常确认回滚后仍抛出原异常；协议失败用 `TransactionException::outcome()` 保留对应事实。

开始失败不进入事务体。COMMIT 不能确认时即使清理阶段再次 rollback，也一直保留 UNKNOWN；参与模型失效，连接不可继续用于业务，框架不会重新运行事务体。

`afterCommit()` 只能在活动事务中登记。内层成功将回调按登记顺序合并，内层回滚丢弃其回调；最外层确认提交后才依次执行。单个回调失败继续执行后续回调，最后通过 `AfterCommitException` 汇总错误，该异常始终携带 COMMITTED。它不保证可靠消息投递，可靠跨系统衔接使用 Outbox。

业务 `Db` 入口通过捕获 `TransactionException` 并读取 `outcome()` 判断协议结果；`AfterCommitException::errors()` 保留各回调错误。`Connection::transactionOutcome()` 和 `ReadWriteSession::reconcile()` 属于显式基础设施对象，`Db` 没有对应方法。普通模型业务提交未知后退出原作用域，在新作用域以 `master()` 和稳定操作 ID 对账；不复用原模型，不把连接恢复等同于旧事务已确定。

提交后回调可以开启新事务。外层 `AfterCommitException` 继续表示原事务已提交，连接的 `transactionOutcome()` 则保留回调事务的最新结果；两者属于不同事务。回调事务得到 `UNKNOWN` 后，同一 Db 会话拒绝普通读写；底层 `ReadWriteSession::reconcile()` 可以换用新主库连接对账，但不会清除原未知事实。业务优先结束原作用域，再通过新的作用域和操作 ID 对账。

普通事务接受参数化 SELECT、INSERT、UPDATE、DELETE，拒绝显式事务控制和多语句。事务与迁移共用单语句词法检查，对注释、美元引用及模式相关反斜杠明确拒绝。PG/SQLite 的事务 DDL 经显式 `schema` 模式执行，迁移执行器使用该模式；MySQL 迁移仍独立使用非事务 DDL。MySQL 的业务事务要求表使用 InnoDB 等实际支持事务的存储引擎。

PHP 三库的结果、回调顺序和迁移回归已经验证。`tests/mysql-commit-proxy.php` 是受控测试代理，分别在开始前、COMMIT 转发前和数据库确认 COMMIT 后切断连接；不记录认证包、SQL 或参数值。测试经真实 MySQL 验证事务体执行次数为 0/1、回调始终不执行、模型按状态失效、实际写入为 0/0/1。相同代理可连接编译产物，原生验收仍待最终集中完成。
