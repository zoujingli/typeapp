# type-queue

复用 Redis Streams 和 type-runtime，独立安装无需 core、ORM、cache 或 scheduler。`Message` 只包含稳定 ID、任务类型、版本、JSON 数据与字符串关联上下文；不从消息加载类或 PHP 源码。

## 安装与版本

源码在 [TypeApp 主仓](https://github.com/zoujingli/typeapp/tree/main/plugin/type-queue)维护，经分发子仓进入 Packagist。以下命令安装已公开的 RC14 候选版，Composer 自动解析传递依赖。

```sh
composer config minimum-stability RC
composer config prefer-stable true
composer require zoujingli/type-queue:1.0.0-rc.14
```

RC 尚非稳定版。使用该版本时以 [RC14 包文档](https://github.com/zoujingli/type-queue/blob/v1.0.0-rc.14/README.md)为准；当前源码教程见[开发文档](https://iots.top/next/#/guide/plugins/type-queue)。文中标注的 `main` 新接口需要同批次开发组件，安装方法和升级核对统一见[版本与接口依据](https://iots.top/next/#/guide/components?id=版本与接口依据)。提交应用的 `composer.lock` 固定实际版本及来源；构建、测试工具放在 `require-dev`。

应用通过 `application.jobs` 自动装配 Job 及其依赖，生成 `CommandApplication::jobs()`；声明规则与底层接口分别见下文。Worker 每次执行创建新 Job、JobContext 和 ExecutionScope。

底层直接注册与生成装配的工厂都使用 `Closure(JobContext): Job`，例如 `static fn (JobContext $context): Job => new ReportJob()`。即使构造本身不使用上下文，也要显式声明这个参数；TypePHP 对非 variadic 回调严格检查实参数量。

`Queue::publish()` 投递，`reserve()` 通过消费组一次领取一条并原子登记持有者 token 和到期时间。一个队列命名空间只使用自有 workers 消费组，不支持其他应用共用同一 Stream 增加任意消费组。消息容量达到上限即拒绝新投递，不裁剪未确认消息。

`publish($message, $guard)` 可显式传入 type-redis 的 `ScriptGuard`，由实际写入目标在同一 Lua 脚本中校验上游权限、检查容量并 XADD。它不依赖 scheduler；应用可将调度租约作为 guard。保护实现必须校验同一个 Redis 目标，不能先查锁后另行发布。默认无 guard 的投递接口保持原有行为；无可靠 Stream 回执时报告结果未知，不把未知结果当作没有投递。

Worker 先取得自身执行额度再领取，正常处理并清理作用域后原子确认；确认校验 token、consumer、到期时间以及 Redis pending 的实际持有者。确认完成后移除当前队列的 Stream 条目和租约。`stop()` 停止后不再领取新消息，当前顺序执行在返回前收尾。

作用域仍为 `closing` 时，Worker 抛出 `QueueException`，错误码为 `cleanup_incomplete`，撤销就绪并保留在途额度，不确认、不安排重试或隔离转移。原投递留在 pending 和既有租约中，按原协议恢复，不能把旧执行未退出当作重放许可。此类故障计入 `cleanup_failures` 和 `failed`，不计作存储失败；已实际收尾的业务失败继续使用原重试策略。

投递是至少一次的业务模型，故障可能导致重投和重复处理，不承诺恰好一次副作用。示例以稳定消息 ID 和载荷指纹，在业务写入目标内原子防重；框架不会替任意外部效果自动建立幂等保证。消息上下文不能覆盖权威 message_id。

组件支持独立 Composer 消费；原生入口为 `docs/build-config/type-queue.json`。失败后的延迟、重试与隔离见下文。

Redis 应采用专门的 noeviction 与明确持久化部署策略，缓存实例不自动具备可靠队列保证。网络故障不会自动重试写命令或把未知结果当作回滚。

## 租约续期与故障重领

`Reservation::renew()` 只允许当前未过期 token 续租，同时重置 Streams pending 空闲时间。`Queue::reclaim()` 有界检查过期 pending，原子 XCLAIM 并替换 token，Worker 优先恢复过期消息。已过期持有者不能确认、续租或释放新租约，确认不是先查后单独 XACK。

`Reservation::effect()` 在同一 Redis 脚本中验证租约后执行可信业务脚本，阻止旧执行者新发起的 Redis 副作用。脚本本身仍可能部分执行后报错，不能推断回滚；其他数据库或外部服务需要其自身的唯一操作 ID、版本或 fencing 约束。已经发生的外部效果依靠业务幂等处理。

Queue 使用专门 script 连接，领取为非阻塞调用，不占用 blocking 用途池。长任务在检查点显式 renew，失去租约立即停止新受管效果；当前没有把异步 heartbeat 伪装为长期有效保证。

真实 PHP Redis 验证覆盖续租保护、过期重领、旧 token 三种操作拒绝、原子幂等效果，以及领取后真实 SIGKILL 和新进程恢复原消息。原生多进程验收入口为 `docs/build-config/type-queue-leases.json`。

租约验收的正向阶段使用 2 秒预算，单独加入领取后 150ms 的调度暂停；过期阶段由有界轮询观察 Redis 的实际判定。此前正向测试仅给 100ms，AOF fsync 或 CI 调度延迟会使合法的过期拒绝被误报为续租错误。专用 AOF/`appendfsync always` Redis 已复现同一 `lease_lost`；修正后在 0.25 CPU 受限环境，PHP 和完整 AOT 各连续五轮通过（包含调度暂停、真实 SIGKILL 和恢复）。原生构建含 20 个生产包、153 个 C/C++ 单元，ELF SHA-256 为 `acff24a88b1051779d058a286ddc578b2f4a1b6869e342b4ecd3c1c1aa7bb14b`，报告为 `build/queue-leases/type-app.build.json`。框架 `Reservation/Queue` 的时间与 token 检查没有放宽。崩溃恢复仍使用 100ms 租约，过期重领、旧 token 的确认/续租/副作用拒绝及新 token 唯一效果均保留；最终 x64 CI 仍独立验收，不能把测试预算当成通用生产租约建议。

## 延迟、有限重试与隔离

`publishDelayed()` 记录到期时间与消息，`promote()` 每次有界提升到 Streams。Redis 服务端时间用于租约、延迟和保留期，不依赖 worker 本机时钟。容量同时计算可消费、延迟和隔离记录，默认不裁剪待确认任务。

Worker 接受 RetryPolicy：最大尝试次数、有上限的指数退避与抖动、最大执行预算。失败在持有有效 token 时先保存目标延迟或隔离记录，再确认并移除源条目；转移失败保留源消息用于重领，Lua 原子可见不等于异常自动回滚，故障可能造成重复，业务仍须幂等。

任务的下一次显式重试递增 attempt，崩溃重领也计入执行次数。达到上限、未知类型/版本或非法载荷进入隔离。隔离记录保存原消息、尝试次数和稳定原因码，不保存异常堆栈或秘密。`quarantined()` 有界查看，`replay()` 保留稳定消息 ID，显式启动新的重试周期；重放未知版本前应先部署兼容处理器。

隔离保留期默认七天，过期后不再允许重放，`collect()` 按上限清理；回收不作用于未确认或延迟消息。过期隔离记录在实际回收前仍计入容量，使用者应定期回收。最大执行预算阻止超时任务的新受管副作用，不声称强杀 PHP 代码或撤销已经发生的外部写入。

PHP 真实 Redis 验证覆盖到期提升、有限重试、未知版本重放、非法载荷、最大执行时间、保留期及目标索引出错后的源消息恢复。原生验收为 `docs/build-config/type-queue-retries.json`。

## 容量、就绪与优雅停止

Worker 当前固定最多一条预取、一条在途执行，不建立隐藏预取队列。`run($maximum = 100)` 限制本轮最多处理的次数；单消息仍受 RetryPolicy 的尝试、退避和执行预算约束。`ready()` 与 `statistics()` 暴露当前就绪、在途与拒绝状态，活着不等于可以继续接收工作。

`stop($drainSeconds = 5.0)` 先取消就绪、禁止下一次领取，再缩短在途作用域的业务与清理预算。已完成效果正常确认；检查点超过预算后按重试/隔离策略保存可恢复状态。存储或租约的非预期错误取消该 Worker 就绪，防止在坏连接上无限重试；修复后由进程管理器建立新连接与 Worker。

停止是合作式控制，不能撤销已开始的原生 I/O、任意 PHP 循环或外部效果。进程监督者需要独立的总排空时限，超时后终止旧进程；未确认消息留在 pending，租约到期后由新 worker 重领。处理器继续使用稳定 ID 和目标端幂等/fencing。运行入口示例用 SIGTERM 取消就绪，测试对不合作任务真实 SIGKILL 后验证恢复。

`Queue::statistics()` 返回消息、租约记录、延迟、隔离、容量、backlog 和最早 Stream 条目的年龄；backlog 包括未确认 Stream 与延迟任务，不把隔离算成待消费。`oldest_stream_age_ms` 衡量当前 Stream 条目年龄，提升/重试会生成新条目。`Reservation::enqueuedAt()/ageMilliseconds()` 保留原投递时间和领取时的实际消息年龄，跨延迟、重试和隔离重放传递，时间由 Redis TIME 计算。

`Queue::counters()` 不访问 Redis，故障时仍可读取本对象累计的 publish_rejected、storage_failures 和 lease_rejected；Worker 另记 received/completed/failed/retried/quarantined、最后领取的 message_age_ms、忙拒绝及停止拒绝。这些是有界本地计数，需由采集端汇总，不能当作持久审计。leased 是尚未清除的租约记录数，可能包含已过期等待重领的记录。

可靠 Redis 应独立于缓存实例，使用明确容量、noeviction 与 AOF 策略。`Type\Redis\StoragePolicy::verify()` 提供真实实例和持久化策略的只读预检；运行时故障与满载仍以实际命令结果为准。专属 Redis 的重启、写满和任务停止验证见开发主仓 `docs/development/task-reliability.md`。

## 应用自动装配

以下统一 `application.jobs` 入口属于当前开发版，尚未包含 RC14。配套使用同批次的构建器与运行组件；独立接入可先运行下方[底层 Registry 使用示例](#底层-registry-使用示例)，确认投递、处理及收尾。

应用安装 `type-core`，开发依赖安装 `type-build`；队列组件本身仍不强制依赖 core。将实现 `Job::handle(JobContext $context, array $payload): void` 的业务类列入生产 sources，在 `type-app.json` 声明：

```json
{
  "application": {
    "enabled": ["example/app"],
    "jobs": [{"type": "reports.daily", "version": 1, "class": "App\\Job\\DailyReport"}]
  }
}
```

`enabled` 使用应用实际 Composer 名称。Job 的具体类型构造参数自动推导；接口或标量歧义使用 `application.bindings`。需要资源时声明 `resources: ["database"]`，对应服务必须是 execution 生命周期的 `ManagedResource`；Job 构造前资源已在同一个消息 scope 开启。也可用 `service` 引用已有的 execution 服务，`class` 与 `service` 只能选择一个。

启动角色在同一 Swoole 协程内建立 Queue 和配置快照，然后取得生成注册表：

```php
$application = new \Type\Generated\CommandApplication($configuration);
$worker = new \Type\Queue\Worker($queue, $application->jobs(), 'reports-worker');
$worker->run(100);
```

启动角色通过 `application.bootstrap` 接入唯一生成入口；可运行的完整示例是 `examples/queue` 与 `docs/build-config/type-queue.json`。开发 `DevelopmentBuilder` 和 AOT 使用同一声明生成过程。旧顶层 `queue`、生成 `Jobs::create($factories)` 的映射已移除；改用 `application.jobs`，无需手写 Job 构造闭包。重复 type/version、错误方法签名、缺失依赖、依赖环和 singleton 持有 execution 服务均在构建期拒绝。每次投递、重试和恢复仍由 Worker 建立新的 Scope，不将 payload/context 自动提升为可信身份。

## 底层 Registry 使用示例

以下声明式入口在独立示例 Redis 命名空间投递并消费一条任务；只演示流程，不将输出当作恰好一次的业务效果。

```php
<?php

declare(strict_types=1);

use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Queue\Registry;
use Type\Queue\Worker;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;

/** 示例任务只输出稳定消息 ID；实际业务效果需在写入目标原子防重。 */
final class ReadmeJob implements Job
{
    /** @param array<string, mixed> $payload 已由消息协议验证的 JSON 数据。 */
    public function handle(JobContext $context, array $payload): void
    {
        $context->assertActive();
        echo $context->message()->id() . "\n";
    }
}

/**
 * 在启动期启用 I/O hook，并在同一协程内装配、执行及关闭队列资源。
 * 示例命名空间只用于投递与消费演示，不提供业务幂等保证。
 */
function main(): void
{
    \Type\Runtime\CoroutineRuntime::enableIo();
    \Type\Runtime\CoroutineRuntime::run(static function (): void {
        $host = getenv('REDIS_HOST');
        $port = filter_var(getenv('REDIS_PORT') === false ? '6379' : getenv('REDIS_PORT'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (!is_int($port)) {
            throw new InvalidArgumentException('REDIS_PORT 必须为有效整数端口');
        }
        $manager = new RedisManager(['default' => new RedisConfiguration($host === false ? '127.0.0.1' : $host, $port)]);
        $scope = new ExecutionScope();
        try {
            $redis = $manager->connection($scope, 'default', Purpose::SCRIPT);
            $queue = new Queue($redis, 'readme-example', 'example', 30000, 100);
            $registry = new Registry();
            $registry->register('readme.echo', 1, static fn (JobContext $context): Job => new ReadmeJob());
            $queue->publish(new Message('readme-' . bin2hex(random_bytes(8)), 'readme.echo', 1, []));
            $worker = new Worker($queue, $registry, 'readme-worker');
            try {
                $worker->run(1);
            } finally {
                $worker->stop();
            }
        } finally {
            try {
                $scope->close();
            } finally {
                $manager->close();
            }
        }
    });
}
```

## 执行路径与教程

```mermaid
flowchart LR
  Publish[稳定 ID 投递] --> Stream[Redis Streams]
  Stream --> Lease[持有者租约]
  Lease --> Job[Job 独立 Scope]
  Job --> Cleanup[资源收尾]
  Cleanup --> Ack[确认 / 延迟 / 隔离]
```

[队列教程](https://iots.top/#/guide/plugins/type-queue)覆盖投递到确认的时序、完整消费者、Redis 目标端原子防重和隔离重放。消息 ID 在重试中保持不变，Stream 回执标识一次投递；稳定业务身份与实际写入必须一起校验。

## 接口与源码组织

`Message/Registry/Job/JobContext` 定义可编译协议与处理器入口；`Queue/Reservation` 持有 Streams 状态、token、领取和受管效果；`Worker/RetryPolicy` 持有执行/重试/停止预算；`QueueException` 保留稳定失败码。队列不依赖 ORM 或调度器，跨系统投递与幂等由应用组合。

上例在专用示例命名空间投递一条只输出 ID 的任务，不能将 stdout 当作幂等业务写入证明。生产 Job 在实际写入目标使用稳定消息 ID 和载荷身份去重；未知投递或提交结果必须对账。外层 Scope 持有队列 Redis 连接，每条 Job 另有 Worker 创建的子作用域，先停止领取、待在途结束，再归还外层连接和管理器。

## AOT 与运行要求

依赖 runtime 与 `type-redis`，不自动要求 core/ORM/cache/scheduler。消息处理器和生成 Registry 全部 AOT，Swoole、phpredis 等非系统库静态链接进主程序。部署另行提供配置了 noeviction/AOF 的可靠 Redis，工作角色由应用入口或进程管理器启动。停止通知沿用 runtime 的平台能力，在途业务及资源退出后才结束角色。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

```sh
composer test:queue
composer build:queue
composer test:queue-native
composer build:queue-leases
composer test:queue-leases-native
composer build:queue-retries
composer test:queue-retries-native
```

- [可靠任务存储与停止](https://github.com/zoujingli/typeapp/blob/main/docs/development/task-reliability.md)
- [Outbox 对接](https://github.com/zoujingli/typeapp/blob/main/docs/development/outbox.md)
- [开发通道连续目录：生成 Job、接受凭据与消费效果](https://iots.top/next/#/guide/catalog-reliability)
- [独立角色无源码部署](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-roles.md)
