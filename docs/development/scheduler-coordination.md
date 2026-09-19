# 多实例调度协调与队列组合

按当前开发顺序已完成标准 PHP 功能和真实 Redis 验证；TypePHP 集中原生验收尚未运行，实际范围须按当前产物验证。

## 复用与接入

原有 Scheduler 继续负责计划、游标、任务实例、作用域和开始/结束记录。新增 `RedisStateStore` 实现同一个 `StateStore` 契约，文件与 Redis 共用状态编码校验；`LeasedStateStore` 将当前租约绑定给每次 TaskContext，任务结束后不能重用该权限。记录、续租、写入和释放都由 Redis 原子校验 token 与代次。

Queue 原有容量与消息协议继续由 Queue 所有，只增加可选 `ScriptGuard` 参数。`type-redis` 定义这个写入权限边界，`type-scheduler` 的 RedisLease 实现它；应用中的 `QueueDispatchTask` 显式把调度 occurrence 映射成 Queue Message。scheduler 不强制 require queue，queue 也不反向 require scheduler。

```php
$scope = new Type\Runtime\ExecutionScope();
$redis = $manager->connection($scope, 'default', Type\Redis\Purpose::SCRIPT);
$store = new Type\Scheduler\RedisStateStore($redis, '业务应用', 'reports');
$queue = new Type\Queue\Queue($redis, '业务应用', 'reports');
// 应用显式构造 QueueDispatchTask，注册到 Definition 中。
// RedisStateStore 与 Queue 使用同一个 script 连接，保护脚本执行于同一个实际目标。
```

同一个 application/name 对应一个串行调度组，不是每个任务单独分布式锁。多个进程共享该组的高水位游标，滚动部署不改变同一任务的 occurrence 身份；需要并行时将不相交任务集合分到不同组。状态与代次需要持久化，不能丢弃后仍承诺过去计划不重跑。

## PHP 与真实 Redis 验证

运行环境为 `type-app-toolchain:redis-local`、专用 `type-app-redis-test`。验证使用真实独立 Composer 镜像安装和真实进程，覆盖：

- 续租使初始租约期后的竞争继续拒绝；到期后接管增加代次。
- 旧 token 的续租、状态保存、释放、业务效果和 Queue XADD 均拒绝，当前持有者仍可正常执行。
- Queue 与保护租约不是同一个目标连接时拒绝，不将脚本送到不确定目标。
- 当前任务作用域结束后，捕获的租约包装不能继续投递。
- 使用 CLIENT KILL 真正断开持锁客户端，续租报告丢失，受管效果停止，不自动重试。
- 状态丢失但仍保留代次时直接停止，不自动初始化空游标。
- 双进程竞争同一次计划时刻，只有持租约者实际写入与投递；新版本不重复，真正新任务 ID 可以执行。
- 真实 SIGSTOP 暂停旧执行者、等待过期、新进程接管；SIGCONT 后旧执行者的业务效果、投递和状态覆盖被拒绝。
- 组合消息经真实 Worker 执行并产生可验证业务结果；复跑原有调度和队列租约检查，接口兼容。

```bash
TYPE_REDIS_HOST=127.0.0.1 php tests/scheduler-coordination-consumer.php

# 验证 scheduler 单独安装仍不含 queue：
php tests/scheduler-consumer.php
```

测试只清理自己随机命名空间下的 Redis 键，不对共享测试服务执行全库清空。日志不是验收结果，断言检查实际 Redis 效果、消息、执行历史和系统报告的暂停状态。

## 集中原生验证接入

本任务没有修改根 Composer/CI。主仓已有 scheduler、queue、Redis 与 runtime 依赖；合并本提交后可以追加：

```json
{
  "build:scheduler-coordination": "@php vendor/bin/type docs/build-config/type-scheduler-coordination.json",
  "test:scheduler-coordination": "@php tests/scheduler-coordination-consumer.php",
  "test:scheduler-coordination-native": "@php tests/scheduler-coordination-consumer.php --native"
}
```

`--native` 为独立消费项目安装锁定 TypePHP/PHPX 与 type-build，编译直接注册的任务及应用队列适配器，使用同一真实多进程验收驱动原生产物。还需运行无业务源码部署检查和远端 CI，按实际结果更新实现规划。

## 故障处理边界

长任务在明确检查点调用 `TaskContext::renew()`，失锁后停止新的受管效果。同步 PHP 不会因为锁过期自动终止，外部写入必须由实际写入目标提供 fencing 或幂等，Redis 脚本保护只覆盖同一独立 Redis 的操作。

任务开始、业务效果、Queue 投递和完成记录并非一笔事务。中断或结果未知时保留稳定 occurrence/message ID，按历史与实际队列/业务目标对账后显式补偿；默认不会把未知投递自动重跑。守护锁、单调代次、稳定身份和 Redis 原子可见性都不构成全局恰好一次，也不能把 Lua 出错当作自动回滚。详细运行和恢复要求见 [插件说明](../../plugin/type-scheduler/README.md)。
