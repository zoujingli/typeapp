# 受管子任务与截止预算

runtime 的作用域状态为 active → closing → closed，所有业务借用校验进程、线程请求、Fiber、Swoole 协程和租约代次。作用域显式绑定到当前 Swoole 协程；子任务复制字符串快照，共享截止、取消和任务预算，不共享父连接或可变对象。整体的进程/线程/协程使用和控制见[进程、线程与协程](../guide/runtime.md)。

## 当前作用域与应用绑定

`ExecutionScope::current()` 取得通过 `run()` 绑定到当前协程的作用域，并校验活性和执行者。`run()` 的回调为 `Closure(ExecutionScope): mixed`，正常返回和异常均恢复外层；同作用域重入不会另建资源，嵌套独立作用域不会继承外层身份。关闭责任仍属于创建者。

```php
CoroutineRuntime::run(static function (): void {
    $scope = new ExecutionScope(null, ['request_id' => 'operation-1']);
    try {
        $scope->run(static function (ExecutionScope $current): void {
            $task = $current->spawn(static function (ExecutionScope $child): string {
                return ExecutionScope::current()->context()['request_id'];
            });
            echo $task->await();
        });
    } finally {
        $scope->close();
    }
});
```

上例使用 `Type\Runtime\CoroutineRuntime` 和 `Type\Runtime\ExecutionScope`。独立入口在官方 Scheduler 的协程中创建资源；已有协程时复用当前执行者，保留启动期 hook flags，异常原样传回。不允许把外部执行者的连接带入新协程。

`context()` 返回请求或消息的关联信息，不能直接授予权限。应用在完成验证后通过 `run($operation, ['tenant_id' => $verifiedTenantId])` 显式绑定字符串值，组件以 `binding('tenant_id')` 读取；运行时不验证业务身份，也不查询业务表。输入数组中的引用被切断，绑定在重入结束后恢复；受管子任务取得创建当时的绑定快照及独立当前作用域，普通原生子协程不隐式继承。

无绑定时 `current()` 抛 `scope_missing`；非协程调用抛 `coroutine_required`；关闭、取消、截止和跨执行者使用遵守原有拒绝规则。HTTP 请求、WebSocket 公开回调、MQTT Broker 事件、生成 CLI、队列及定时任务已接入当前作用域。自定义 Socket 消息仍须在其实际装配入口绑定。持久 worker 的公开操作回调及设备授权 worker 在自己的协程内创建作用域，不从管道输入自动授予租户；具体业务装配和平台验证分别记录。

MQTT Broker 保持原生事件自动协程关闭，在有界接纳后显式使用官方 `Coroutine::create()` 与一个 `Channel` 串行处理协议事件；每次处理创建独立作用域。等待与执行共用 `BrokerOptions::callbackSeconds`，同一连接在途事件全部收尾才恢复原生读取；定时维护至多一项在途，不堆积 tick。认证、授权及观察回调可以使用当前作用域，但普通关联信息不成为可信绑定。连接关闭立即撤销传输资格，排队事件按接纳代次排除失效 fd；停止前等待作用域真实收尾，停止观察在独立作用域内完成。启动前补齐官方 I/O 与 PROC hook；已有 Server 事件循环内不嵌套 Scheduler。MQTT 客户端的同步 `receive()` 返回消息，由调用它的应用入口建立自己的作用域。

管理策略可能等待外部工作进程，Broker 在这些等待段之间推进已接纳的持久工作，避免会话恢复的身份交换、打开和保存每一步都额外等待整轮管理查询。协议状态仍串行修改；握手、管理操作和工作进程各自的既定截止继续生效。

Broker 的停止信号使用 Swoole 异步 worker 退出机制；撤销维护定时器并关闭在途 CRL HTTP 客户端后，继续等待在途协程收尾，超出原生等待上限则报告未完整收尾。

`php tests/mqtt-consumer.php --scopes` 通过独立安装的组件、真实 TCP/TLS 和 MQTT 客户端验证连续认证、授权、观察、子任务快照、异常恢复、截止及清理超时。加 `--native` 对相同装配执行完整 AOT 和禁止读取生产源码的原生验证；这些入口的存在不等于目标平台矩阵全部通过。

## 子任务与真实收尾

`ExecutionScope` 可配置单调时钟 `Deadline`、显式字符串上下文、子任务容量和清理预算。`spawn()` 创建拥有独立作用域的子任务，继承同一个剩余截止时间和上下文副本，不继承父连接及可变模型。整个子任务树共用容量，递归创建不能绕过根上限。

`ManagedTask::await()` 返回结果或抛出业务异常。等待预算耗尽会发出取消意图并抛 `task_timeout`，不会宣称远端 SQL 或其他副作用已撤销。子任务通过 `assertActive()` 配合取消检查；ORM 操作在返回后再次检查租约和截止状态，取消过的数据库会话丢弃复用资格。

协程借用默认进入有界池等待，显式 `connect($scope, 0)` 保留即时拒绝。父任务占有最后一个连接并等待独立借用的子任务时，不会隐式继承连接：子任务按较小截止失败，等待登记撤销，父连接仍可正常收尾。取消等待由 Swoole Channel 通知唤醒，不使用定时轮询。

Scope 关闭时先拒绝新工作并取消子任务，再用共享清理预算等待子树。资源在各自执行者退出后逆序释放。取消监听器抛出的异常会在收尾后报告，不会跳过子任务等待或资源释放；如果已全部收尾，作用域仍进入 closed，重复关闭不会再次释放资源。清理超时会明确报错，作用域保持 closing；后台操作仍由受管任务引用持有，继续占用任务预算和连接容量，直到真实结束后转为 closed。永久不退出的任务需要工作进程监督器结束并替换进程，不强行释放仍在使用的原生句柄。

`ResourceLease::hold()` 将句柄使用期与等待期分开，ORM 的查询、写入、事务以及 Redis 调用均在实际操作期间持有容量。跨协程捕获父连接会被拒绝。完成的子任务错误需要 await 观察或由 Scope 收尾报告，未观察错误数量同样受限。

PHP 真实 MySQL 运行验证包括延迟 SELECT、短等待超时、父先关闭、跨协程捕获、共享子树预算、清理超时后完成及新连接恢复。HTTP 验证包括 503 返回期间池仍满、客户端断开后收尾、后续请求不串结果。对应原生入口为 `docs/build-config/type-tasks.json` 与 `docs/build-config/type-task-http.json`，已接入 Linux x64 原生 CI 的可靠性分组；固定源码与运行结果见[四平台验收记录](../evidence/native-release-20260925.md)。这些场景不自动代表其他平台或后续提交也已通过。

`php tests/tasks.php --php --scope-only` 单独验证通用当前作用域、嵌套恢复、绑定快照、原生子协程隔离及 hook 配置，不连接数据库，也不读取身份业务数据。相同场景包含在 `type-tasks.json` 的编译输入内，原生运行时使用 `php tests/tasks.php build/tasks/type-app --scope-only`；PHP 通过不代表原生或完整角色验收通过。

通用绑定契约已通过 PHP 行为验证，以及 macOS ARM64 上仅包含 `type-runtime` 全量生产源码的 TypePHP 0.9 原生验证。原生环境为 PHP 8.5.10 ZTS、Swoole 6.2.1；禁止读取应用、组件、vendor 与测试入口源码后相同测试仍通过。这一结果只覆盖当前作用域契约，各角色自动接入、ORM 和其他平台仍按各自范围验收。
