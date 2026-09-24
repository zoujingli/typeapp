# type-scheduler

提供 Cron、固定间隔、时区与有限补跑，通过独立作用域执行已注册的 `Task`，记录计划时刻、开始、完成、失败及中断。任务与依赖整体 AOT，历史原生与多实例故障证据见本页验收链接；这些记录不替代当前修改的重新验证。

资源清理失败且作用域尚未关闭时，当前记录保存为 `failed`，保留 `cleanup_error`，`finished_at` 保持 null；本角色撤销就绪、保留在途额度并停止本轮后续计划，不推进未执行的游标。`cleanup_failures` 单独计数，已有业务失败和组租约/fencing 协议继续适用；不自动重跑结果未知的旧执行。永久不合作的资源仍由角色外部监督处理。

## 安装与版本

本组件通过 Packagist 提供 Composer 安装，源码在对应 GitHub 子仓维护。Composer 自动解析传递依赖，消费应用无需逐一登记 VCS 仓库。

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer require zoujingli/type-scheduler:dev-main
```

`dev-main` 的分支别名为 `1.0.x-dev`；本仓组件间使用 `~1.0.0@dev` 约束。开发分支不等于已发布稳定 1.0 版本。提交应用的 `composer.lock` 固定实际分发提交；构建工具只放 `require-dev`。详细依赖与公开分发规则见[组件组织与安装](https://github.com/zoujingli/typeapp/blob/main/docs/development/component-structure.md)。

## 注册与执行

```php
<?php

declare(strict_types=1);

use Type\Scheduler\CronSchedule;
use Type\Scheduler\Definition;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SystemClock;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

/** 展示显式任务接口；每次计划执行由调度器建立独立上下文。 */
final class DailyReport implements Task
{
    /** @return array{completed: bool} 有界且可记录的执行结果。 */
    public function run(TaskContext $context): array
    {
        $context->assertActive();

        return ['completed' => true];
    }
}

/**
 * 在启动期启用 I/O hook，再于同一协程装配并执行一次有限调度。
 * 文件目录的创建与权限属于部署方责任。
 *
 * @param list<string> $argv 第二项为安全本地状态文件路径。
 */
function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::enableIo();
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argv): void {
        $path = $argv[1] ?? '';
        if ($path === '') {
            throw new InvalidArgumentException('请传入已准备目录中的调度状态文件绝对路径');
        }
        $scheduler = new Scheduler(new SystemClock(), new FileStateStore($path), [
            new Definition('reports.daily', new CronSchedule('0 9 * * *', 'Asia/Shanghai'),
                static fn (TaskContext $context): Task => new DailyReport()),
        ]);
        try {
            echo json_encode($scheduler->tick(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
        } finally {
            $scheduler->stop();
        }
    });
}
```

定义与任务类在应用构建时一并编译；注册工厂必须是显式 `Closure(TaskContext): Task`。即使构造时不使用上下文，也须声明 `TaskContext $context`，TypePHP 严格检查实参数量。配置不接受 shell、PHP 文件、字符串调用器或运行时目录发现。每次 occurrence 都调用工厂，工厂与任务可通过 `TaskContext::scope()` 登记资源，执行后无论成功或失败都会关闭独立 `ExecutionScope`。

Cron 已验证 `dragonmantank/cron-expression` **3.6.0**，时钟采用 PSR-20 `ClockInterface` **1.0.0**。Composer 接受兼容补丁版本，应用通过 lock 文件锁定；两个生产依赖的 TypePHP 源码适配严格绑定上述已验证版本，升级后须更新适配并重新验收。包按既定依赖方向依赖 runtime 与 Redis，本地文件调度不连接 Redis；多实例使用下文 Redis 存储，不隐式拉入 queue、core 或 ORM。

## 时间与身份

- Cron 默认 UTC，不受 PHP 默认时区影响；显式时区使用 IANA 名称。解析库在时区偏移恒定区间中计算当地日期，再转换成真实 UTC 秒。
- 夏令时向前跳跃时，不存在的当地计划时刻直接跳过。向后重叠默认 `first`，只执行第一次；`new CronSchedule($expression, $timezone, 'both')` 可执行两个不同的 UTC 时刻。没有将缺失时刻挪到下一小时的隐式补偿。
- `IntervalSchedule($seconds, $anchor = 0)` 使用固定 UTC 锚点，支持 1 秒至 366 天。不以进程启动或任务结束时间作为下一次锚点，避免重启漂移与 DST 干扰。
- occurrence ID 是协议前缀、稳定任务 ID 与 UTC 计划秒的 SHA-256。Cron 文本、时区文本和 `revision` 不参与身份。滚动修改同一任务保留 ID；真正不同的业务任务必须使用新的任务 ID。
- 同一存储中的游标只前进；时钟回拨到游标之前不会再次执行。时区数据版本变更可能改变未来计划时刻，部署时应保持同一时区数据，已执行 UTC 时刻不被改写。

## 错过执行与重启

`Definition` 默认 `misfire='skip'`、`graceSeconds=59`：只考虑正常轮询宽限内的最新一个时刻，其余旧计划跳过。同一个时刻不会因宽限窗口跨多次轮询而重复执行。对于秒级间隔，可配置更小的宽限。

需要补跑时显式使用 `misfire='catch-up'`，并指定 `catchUpLimit` 与 `lookbackSeconds`。窗口只取 `(max(游标, 当前时间 - 回看秒数), 当前时间]` 内最新的有限次计划，再按时间升序执行；超过上限或窗口的旧计划跳过。首次运行没有游标，也使用相同回看窗口。上限为每任务每轮 1000 次，回看最多 366 天。由于作用域同步执行，任务时长会影响下一轮的错过判定。

开始执行之前，原 occurrence ID、`running` 与游标先写入存储；任务与清理结束后写入 `succeeded/failed`、结果和结束时间。异常是失败结果，清理异常单独保留；日志输出不代表成功。单条 JSON 结果最多 64 KiB，历史保留默认 1000 条，最多 10000 条，状态文件上限 16 MiB；超出存储能力则停止调度，不假装记录成功。

进程中断后，新执行者将持久化的 `running` 标为 `interrupted`，保留身份并说明业务效果需要核对。该次 occurrence 不自动重试，剩余计划按错过策略处理。任务失败也不会自动重试；需要重试的业务应使用显式队列或修复命令，并继续使用稳定业务幂等键。

`FileStateStore` 面向同一台机器的本地文件系统，通过稳定 `.lock` 文件阻止重叠执行，状态以临时文件同步后原子替换。进程退出会释放锁，状态损坏直接报错，不重置游标。状态路径要求预先创建可写目录，运行者应限制目录权限；备份必须包含状态文件。文件同步不能替代存储设备与文件系统的断电保证。多个独立主机使用 `RedisStateStore`，不能把 NFS 文件锁当作本地文件保证。调度锁和身份均不构成业务全局恰好一次保证。

## 多实例租约与受管效果

`RedisStateStore($redis, $application, $name = 'default', $leaseMilliseconds = 30000)` 使用 script 用途的 `RedisConnection` 保存共享状态。相同应用和调度组共享游标、历史与租约，同组每次只允许一个实例执行完整 tick。需要并行调度组时按互不重叠的任务集合分组；同一任务不能配置在两个独立组中。新旧版本保留同一应用、组和任务身份。

租约使用随机 256 位 token 与 Redis `INCR` 代次，每次续租、效果、状态保存和释放都原子比较当前值。TTL 由 Redis 管理，不使用应用时钟推断租约是否到期。代次通过字符串传递，避免大整数经 Lua 浮点数丢精度。旧执行者不能续期或删除新锁。

`RedisLease::effect()` 与 `ScriptGuard::execute()` 在实际 Redis 写入目标的同一脚本中先校验 token，再执行可信业务脚本。`TaskContext::lease()` 返回绑定当前任务作用域的接口，不允许上一次任务在下一次 tick 借用新权限。纯 PHP 代码或不受管的外部调用无法被租约强制撤销；其他数据库、文件和服务必须自己原子校验代次、唯一业务 ID 或版本。Lua 执行失败可能已有部分写入，不能把异常当作事务回滚。

Redis 状态与代次不设置 TTL。检测到仍有代次但状态缺失时停止调度并要求恢复，避免静默回到首次启动；完全丢失命名空间与初次部署无法由客户端自行区分。使用独立、明确持久化的 `noeviction` Redis，备份状态和代次，避免把缓存实例当作调度权威。当前只支持既有独立 Redis 部署，不提供 Cluster、多主或异步故障切换下的全局互斥保证。

## 显式队列投递

开发主仓的 `examples/coordination/QueueDispatchTask.php` 是应用组合适配器。它将 occurrence ID 作为 `Message` 的稳定 ID，显式指定消息类型、版本和数据，调用 `Queue::publish($message, $context->lease())`；scheduler 的 Composer 依赖仍没有 queue。

通用 `ScriptGuard` 属于 type-redis，Queue 只依赖这个现有下层边界。投递容量检查与 XADD 在受保护脚本内执行，不采用先查租约再单独投递。为确保实际目标一致，当前 Redis 租约保护要求 Queue 与 RedisStateStore 使用同一个 `RedisConnection` 对象；跨 Redis 实例的投递必须另外实现持久化意图与投递对账，不能借用本接口假装原子。

调度的开始记录、业务效果、投递和最终完成记录仍是不同操作。网络断开或进程中断可能留下 `running`，新执行者将其标为 `interrupted`，不会擅自重放；需按稳定 occurrence/message ID 对账，明确补偿或重投。队列消息仍可能重复，消费者须在实际业务写入目标验证幂等。稳定身份、防重入与安全释放不等于全局恰好一次。

## 独立命令

`SchedulerConsole` 提供 `once`、`history` 和 `work <次数> <间隔毫秒>`；后者是有界轮询，可由进程管理器重复启动，也可由应用在退出条件内循环调用 `tick()`。`help` 不打开状态文件或任何外部连接。失败或恢复出的中断返回 70，本地执行冲突返回 75。

开发主仓 `examples/scheduler-command.php` 展示直接编译注册的任务与可控时钟，`docs/build-config/type-scheduler.json` 为集中原生构建准备入口。任务与状态路径可以由应用自行绑定，无须安装 core。

## 总触发限额与停止

Scheduler 构造参数 `tickLimit` 默认 100，范围 1 至 1000，限制同一轮所有定义的总触发数；每个定义的 catchUpLimit 和回看窗口仍独立生效。达到总限额只推进已尝试的 occurrence 游标，剩余计划可由下轮按错过策略继续处理。`executionMilliseconds` 默认 30000，单任务最大一小时，防止无限预算。

`stop($drainSeconds = 5.0)` 先取消 `ready()`，停止新的 tick 和当前轮余下补跑，再限制在途任务及资源清理的剩余预算；控制台 work 观察停止后退出循环。Redis 失效或状态保存错误取消就绪，维护者恢复存储后重建调度实例；正常租约竞争只记录冲突，不把待命实例永久停止。

`statistics()` 暴露就绪/排空/停止、在途、总触发限制、triggered、failed、interrupted、lease_conflicts、storage_failures、limit_reached 和停止拒绝计数。指标键固定，不用任务身份或 occurrence ID 生成无限标签。慢任务的非受管效果仍需监督进程硬时限与业务幂等，不能仅凭取消就绪声称底层操作已经结束。

## 执行路径与教程

```mermaid
flowchart LR
  Plan[Cron / 固定间隔] --> Cursor[持久游标与有限补跑]
  Cursor --> Running[先保存 running]
  Running --> Task[独立 Scope 执行 Task]
  Task --> Record[收尾并保存结果]
  Record --> Unlock[释放组执行权]
```

[调度教程](https://iots.top/#/guide/plugins/type-scheduler)从单机状态文件开始，解释固定时刻练习、执行历史、Redis 多实例及队列组合。生产游标是业务执行事实，不作为可随意清空的缓存；interrupted 需要核对业务效果。

## 接口与源码组织

`Schedule/CronSchedule/IntervalSchedule/Definition/SystemClock` 负责时间声明与时钟；`Scheduler/Task/TaskContext/SchedulerConsole` 负责执行入口；`StateStore/FileStateStore/RedisStateStore/StateCodec` 负责持久状态；`ExecutionLease/RedisLease/ScopedLease/LeasedStateStore` 负责当前持有者权限。角色在同一语境内，保持现有公共 FQCN，不为每个类建立一层目录。

示例要求由使用者提前准备安全的本地状态目录，只执行一次 tick，通常不在当前秒的 09:00 窗口时返回空列表；这不是执行失败。每个任务独立 Scope 负责资源清理，`Scheduler::stop()` 只控制就绪和后续执行，不拥有调用者另行创建的 RedisManager。使用 RedisStateStore 时在调度角色结束后继续关闭外层 Redis Scope 与管理器。

## AOT 与运行要求

Composer 包含 runtime、Redis、`dragonmantank/cron-expression ~3.6.0` 与 PSR-20；本地文件调度不连 Redis，但当前 Composer 安装仍检查 Redis 扩展依赖。Cron/PSR 源码按精确 imports 一起 AOT，运行携带时区数据，所需原生库按实际产物清单交付。多实例调度连接真实 Redis 服务；停止信号按 runtime 的平台能力装配，宿主可显式调用 stop。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

```sh
composer test:scheduler
composer test:scheduler-consumer
composer build:scheduler
composer test:scheduler-native
composer test:scheduler-native-consumer
composer test:scheduler-coordination-native
```

- [时间、DST 与持久计划](https://github.com/zoujingli/typeapp/blob/main/docs/development/scheduler.md)
- [多实例协调与投递](https://github.com/zoujingli/typeapp/blob/main/docs/development/scheduler-coordination.md)
- [在途任务限时停止](https://github.com/zoujingli/typeapp/blob/main/docs/development/task-reliability.md)
