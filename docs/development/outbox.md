# 事务 Outbox 与消息凭据

ORM 提供 Outbox Store、Record 和 Publisher 契约，应用 adapter 将其组合到 Queue；ORM 不依赖 queue，queue 也不依赖 ORM。

Store 提供明确迁移。业务入口 `enqueue($id, $topic, $version, $payload, $context = [], $database = 'default')` 要求当前执行作用域已有同来源活动事务，复用 `Db::transaction()` 或 `#[Transactional(database: 'outbox')]` 与 Model 的主库会话。调用者不传 Connection；无事务、来源无效或跨来源事务均拒绝，检查本身不借连接。基础设施已有显式事务连接时使用 `enqueueUsing($connection, ...)`。

```php
Db::transaction(function () use ($notice, $store): void {
    $notice->save(); // Notice 的 Table 同样声明 database: 'outbox'。
    $store->enqueue('notice.' . $notice->id, 'notice.created', 1,
        ['notice_id' => $notice->id], database: 'outbox');
}, 'outbox');
```

稳定 ID、版本、载荷及指纹使业务与消息同时提交或同时回滚。同 ID 同内容返回 false，不同内容拒绝，并发重复插入由数据库唯一约束裁决，不能静默覆盖消息。确认提交后即使 afterCommit 回调失败，意图仍已持久化，可由独立 Relay 恢复；COMMIT 断线只能返回 UNKNOWN，不自动重做业务，按稳定 ID 核对真实持久化结果。

`new Relay($databaseManager, $store, $publisher, 'outbox', 30000)` 绑定一个明确命名源，最后一项是整批执行预算毫秒数。单一 Database 仅支持 default。Relay 使用短事务 `claim()`，MySQL/PG 行锁跳过已被其他执行者锁住的记录，SQLite 使用 IMMEDIATE 写事务。领取 token 和租约到期由数据库时钟决定。关闭领取作用域后调用 Publisher，外部等待期间不占数据库连接或租约；随后重新借同来源连接，以 token 和租约登记接受凭据。来自另一物理来源的领取凭据拒绝，发布后标记前崩溃会重发同一稳定消息 ID，消费者仍须幂等。

Relay 一次仅有一批在途工作。`stop($drainSeconds)` 撤销新批次和后续投递并缩短现有作用域预算；异常、超时或收尾失败停止接单。`statistics()` 保留尚未清理资源的在途所有权，不能把业务返回当作清理完成。Publisher 应遵守当前 ExecutionScope 的合作式截止与取消，不为未知外部效果自动重试。

Publisher 返回的凭据只说明目标接受，不代表已经永久保存或消费。消费者在同来源事务调用 `consumed($id, $receipt, $database = 'default')`；仅当返回 true 才执行业务效果，使凭据与效果一起提交。同一 ID 的重复消费不会再次执行示例业务副作用。基础设施显式连接入口是 `consumedUsing($connection, ...)`。消费凭据可先于 Relay 的接受登记到达，两类事实独立保存。外部系统不能共享该事务时，仍需要其自身的幂等操作键或对账机制。

`replay()` 只在保留窗口内对已发布意图显式重放，要求人工说明并保留 ID。`collect()` 只删除已发布、已收到消费凭据且超过重放窗口的记录，未知或未消费结果继续保留用于对账。默认保留窗口七天，可明确配置；部署新旧消息版本必须保留可解码的处理器。

`tests/outbox-databases.php` 对 MySQL、PostgreSQL、SQLite 与真实 Redis 验证上述命名源、回滚、SIGKILL 重发、实际并发消费先于登记、重放、旧 token、错误物理源、保留期与角色故障；MySQL COMMIT 代理分别切断提交前和后边界，核对 UNKNOWN 的真实落库与独立 Relay 恢复。测试保存证据并回收随机身份的数据库、队列键及临时开发代次。原生演练入口 `docs/build-config/type-outbox.json` 已接入统一 CI，已通过的固定源码范围见[四平台验收记录](../evidence/native-release-20260925.md)；后续修改需要自己的原生回归结果，PHP 验证不能替代 AOT。

`tokens` 故障夹具只在本轮专用数据库中按消息 ID 与旧 token 将租约设为过期，分别断言过期领取拒绝、新领取更换 token、旧 token 拒绝及有效新领取成功。新领取使用 5 秒租约，并覆盖领取后暂停 150 毫秒的情形，避免把短测试租约过期误报成旧 token 越权。生产 Store 继续按数据库时钟检查 token 与租约，不增加重试或放宽过期条件；未消费消息即使超过保留期也不能被回收。
