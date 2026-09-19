# 可靠存储故障与任务优雅停止

依赖队列重试、调度租约和有界日志，源码已按锁定 TypePHP 语法统一。Linux ARM64 独立原生角色已完成下述真实故障演练，最终 x64 与无源码部署验收仍在进行，实际范围须按当前产物验证。

## 公共行为

`StoragePolicy` 在显式预检中校验缓存和任务的真实 Redis 实例身份，拒绝把不同逻辑数据库当作隔离。可靠实例需要明确 maxmemory、noeviction、AOF 与 fsync 策略。缓存可独立淘汰，缓存连接或日志输出不占用协调/队列的独立命名用途额度。

Worker 保持一个预取、一个在途，新增有界 `run(maximum)`；RetryPolicy 继续约束尝试次数、延迟和执行时间。Scheduler 同时限制单定义补跑和全轮 tickLimit，达到总上限不越过未尝试计划的游标。

两个角色复用 runtime 的 `WorkLifecycle`：先撤销 ready，再缩短在途 `ExecutionScope` 的业务截止和清理等待。Deadline 只缩短，不能因停止或子任务重建而重新获得预算。正常完成确认，超时按现有重试/隔离或失败记录收尾；下一轮不再领取或触发。遇到存储失败，旧角色取消就绪并停止使用坏连接；恢复后重建进程、连接和角色，禁止写命令自动重试。

就绪、在途、积压、消息年龄、重试、隔离、租约冲突和拒绝计数通过 Queue/Worker/Scheduler 的统计入口取得。消息原始 enqueued_at 作为 Streams/延迟/隔离元数据保存，既不改业务 Message 编码，也不破坏现有基于消息内容的幂等指纹。领取年龄使用 Redis 服务端时间；最早 Stream 条目年龄与原始消息年龄分别命名。

日志沿用 type-log 的记录数、字节数和停止预算，不把故障记录写回故障 Redis。故障计数是进程内有界累计量，采集器应按部署维度汇总；不要将任意消息或 occurrence ID 当作指标标签。它们不代替持久任务记录或事务审计。

## 原生验收入口

```bash
php tests/native-task-reliability.php "$(command -v redis-server)"
```

该入口先完整编译消费者，然后分别执行 PHP 与 AOT 场景。每轮新建独立回环端口及数据目录，可靠实例使用 16 MiB、noeviction、AOF always，缓存实例使用 4 MiB、allkeys-lru；真实 SIGKILL 后从原数据目录重启，随后验证消息/租约/延迟/调度游标恢复、容量耗尽与解除、四类 worker 停止和活动调度截止。原生重启可能快于原租约到期，恢复用例在五秒上限内轮询公开 worker 入口，不提前夺取有效租约。

## 主仓与集中原生接入

主仓 Composer 和原生 CI 已接入 scheduler、queue、log 与可靠性分组。构建入口为 `docs/build-config/type-task-reliability.json`；重复原生验收可以运行：

```bash
TYPE_COMPOSER_PHAR="$(command -v composer)" \
TYPE_PHPX_SDK="${PHPX_HOME:?请先配置已核验的SDK}" \
TYPE_RELIABILITY_NATIVE=1 \
  bash tools/test-task-reliability.sh
```

## 在途调度停止的编译入口

覆盖复核发现：此前 `tests/task-reliability-process.php` 虽然在原生模式下使用 ELF 验证 worker，文件末尾的慢调度停止场景仍直接由 PHP 宿主执行。已有原生调度测试覆盖停止后拒绝新计划、AOF 游标、写满和恢复，但没有证明编译后的活动任务能收到缩短后的排空截止。

现在同一可靠性命令新增 `scheduler-stop` 模式，复用公开 `Scheduler`、`TaskContext`、`FileStateStore` 和已有可控时钟。任务按完整 `(TaskContext $context): Task` 工厂契约创建，在 `run()` 内请求 10ms 排空并读取真正的 `draining` 状态；30ms 慢处理后执行公开截止检查，记录并重抛 `deadline_exceeded`。调度器按既有协议记录一次失败，后续补跑不再开始。此模式不需要 Redis；它没有替换调度器实现或用宿主结果冒充 ELF 输出。

`tests/task-reliability-process.php` 每次先调用消费者自己的该模式，检查停止时 `ready=false/in_flight=1`、截止错误、只有一次触发与失败、完成后 `stopped/in_flight=0`、已到期排空、失败历史和下一次 tick 拒绝。原来的 PHP 宿主场景保留；完整可靠性脚本已调用本测试，原生模式因此自动带入新验收。仅检查这一条时执行：

```bash
php tests/task-reliability-process.php build/reliability-consumer-本轮标识 --native --scheduler-stop-only
```

## 部署边界

合作式截止检查不能强行中断任意用户 PHP、扩展调用或内核 I/O。监督进程必须有独立总时限：先 SIGTERM 取消就绪，等待配置的排空与日志预算，逾期终止并重启。不要把“停止等待”当作外部效果回滚，未确认/中断任务继续按稳定业务 ID 对账和幂等恢复。

AOF always 与 noeviction 描述的是一个独立 Redis 权威的策略，不自动提供跨机同步复制、磁盘断电或多主一致性。运行中磁盘故障、持久化状态和容量仍需监控。测试中的内存上限降为 1 字节只用于专属资源制造确定 OOM；生产恢复应释放/扩容真实资源并核对持久化状态，不靠清空消息或关闭 noeviction 解决压力。
