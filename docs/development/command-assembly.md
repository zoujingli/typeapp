# 编译期命令装配

复用原生命令构建链，增加启用模块、直接构造工厂、配置快照和同步资源生命周期。

## 使用入口

```bash
composer install --no-scripts --no-plugins
composer check:assembly

# 在已有锁定 Linux SDK 的环境中执行：
composer build:commands
composer test:commands
composer test:assembly-consumer
```

SDK 准备和隔离运行方式见[原生命令说明](native-command.md)。`docs/build-config/type-commands.json` 是可以运行的声明示例；手写 entry 模式继续保留，同一配置不能同时选择 entry 与 application 装配。

## 模块声明

每个已安装生产包保留协议 1 的 `extra.type.sources`，可额外通过 `extra.type.module` 声明 services 和 commands。应用的 application 声明使用根 Composer 包名作为模块标识。

application.enabled 明确列出启用的模块；只有这些模块的服务与命令进入注册图。全部生产依赖仍接受编译覆盖检查、源码符号解析和 TypePHP 编译，禁用不是源码卸载或安全沙箱。库类仍可以通过明确的应用绑定使用。

命令可以直接写 `{"name":"greet","class":"App\\\\GreetCommand"}`，构建器沿具名具体类构造器递归推导依赖。扫描到其他具体类不会新增命令。服务声明保留 `id`、`class`、`lifetime` 和可选 `arguments`；省略 `arguments` 使用构造器类型推导，显式列表继续支持 `service`、`config`、`value`，不会执行构造器来猜测类型。

接口与抽象类型使用 `bindings` 列表，例如 `{"type":"App\\\\Clock","service":"clock"}`。标量按参数绑定，例如 `{"type":"App\\\\Settings::$name","config":"name"}`，也可明确提供 `value`。相同具体类型有多个服务时必须显式选择，不能依赖声明顺序。组件在 `extra.type.module` 声明默认服务/绑定；应用在唯一的 `application` 中按同一个服务 id 或绑定 type 覆盖。组件层之间及同一个声明列表内的重复明确失败，报告记录服务覆盖来源。

复杂构造使用服务的具名 `factory`，例如 `{"id":"clock","class":"App\\\\ClockImpl","factory":{"class":"App\\\\ClockFactory","method":"create"}}`。静态工厂必须公开、返回确定的兼容类型，全部服务依赖使用类型化参数；实例工厂还需写 `service` 指向其工厂对象。工厂参数沿同一服务图推导，生命周期检查包含工厂对象和全部参数依赖。联合类型、交叉类型、无类型、引用、可变参数与不确定返回类型会在构建期拒绝；工厂中的直接全局取值、闭包和已识别服务定位调用也拒绝。这些检查不证明任意工厂体无副作用，构造和工厂仍必须遵守资源所有权约定。

服务类、命令接口、资源接口、监听器接口和参数数量/类型由源码 AST 检查，PHP 开发准备与 AOT 通过同一个生成器消费这些规则，最终原生类型与行为仍接受 TypePHP 验收。

构建期拒绝缺失引用、循环依赖、重复注册、未知模块、抽象服务、非法构造和单例直接或间接依赖执行作用域服务；诊断指出模块与声明标识。构建过程不加载和执行应用构造函数。

## 运行行为

- 生成的命令应用持有一次启动取得的 Configuration。当前配置只支持字符串环境变量与字符串默认值；默认值进入源码产物，真实密钥通过启动环境提供。
- 同一装配实例在一个 worker 内复用显式 singleton；每次 `run` 先绑定新的 `ExecutionScope`，再由 `Application::runFactories()` 构造命令、监听器和资源。execution 服务通过当前 scope 惰性缓存，同一范围复用，作用域关闭且真实收尾后释放；并发请求和受管子作用域各自拥有独立缓存。同步命令入口禁止重入，HTTP 通过 `registerRoutes()` 登记独立请求工厂。
- 每个命令声明自己的资源和 ready/completed 监听器，非目标命令的服务不会提前构造。资源声明顺序就是启动顺序，停止顺序相反。
- ManagedResource 由 ExecutionScope 在 start 前登记，部分启动失败也会收尾。关闭具有幂等性，关闭后不再接受新资源。
- 清理失败保留原始业务错误和清理信息。尚未真正退出的 scope 继续持有 execution 服务，同一装配实例拒绝后续命令；确认关闭后才恢复，其他独立实例不共享该失败范围。
- 构造函数承担依赖赋值且必须在作用域绑定后调用；外部连接放入受管 start/stop。框架无法替任意构造函数中未经登记的 I/O 收尾。
- 监听器按声明顺序执行，失败后当前事件剩余监听器不再调用；异常不会跳过资源清理。
- 正常命令保留返回退出码，未捕获的命令或清理错误由生成入口输出中文错误并返回 70。

生成工厂缓存按服务标识区分，不通过运行时反射或目录扫描发现依赖。业务文件、生成入口和全部生产源码被统一编译；构建报告分别记录生产包、已启用模块、未启用模块与目标命令。

## HTTP 共用装配

同一配置同时声明 `application` 和 `routing` 时，显式路由中的控制器成为同一服务图的根；具名中间件通过 `application.http.middleware` 的名称到服务 id 映射选择。控制器、命令、中间件使用相同的构造器推导、应用覆盖和生命周期拒绝规则。没有路由声明的类不会公开接口。

启动角色在建立 `Router` 后调用 `(new Type\Generated\CommandApplication($configuration))->registerRoutes($router)`。该方法只登记已有 `Routes::register()` 所需的直接工厂；匹配请求后才从服务器绑定的当前 `ExecutionScope` 构造对象。注册不打开资源，生成链接不执行工厂。工厂不捕获请求 scope，受管子任务使用自己的 scope。首次实际执行时绑定 worker 所有权；已执行装配不能跨进程或线程复用。同步命令 `run()` 的不可重入限制不影响独立 HTTP 请求工厂。

需要保留 `serve`、`migrate`、许可等角色生命周期时，可显式声明 `application.bootstrap: {"class":"App\\Bootstrap","method":"run"}`。该方法必须是 `public static`、接收完整 `array` 参数与 `bool` 开发标志，并返回 `void`；构建器静态校验但不执行它。生成 `main` 仍是唯一原生入口，传入 `false`；开发启动器可以明确传入 `true`。bootstrap 只负责角色选择、配置与生命周期，业务依赖交由生成工厂，不维护第二份控制器或命令工厂表。独立模板已经按此方式登记 HTTP 装配。

`php tests/http-assembly.php` 验证标准开发命令、真实 HTTP 与同一业务服务、请求内复用、八个在途请求及受管子任务隔离、构造和动作失败的错误边界及资源归还。PHP 与原生模板验收结果仍分别记录。

## 离线应用检查

`vendor/bin/type inspect-application type-app.json` 输出可读声明；追加 `--json` 输出协议 1 的 `application-declarations` 报告。它与 `--inspect <已有程序>` 的产物检查不同：只分析已安装生产依赖和显式声明，不代表 TypePHP 编译或部署通过。

检查直接调用开发与 AOT 共用的 `ApplicationGeneration`，报告生成身份、生产包、命令根、HTTP 根、服务依赖与生命周期、绑定和覆盖来源、配置名称、Model、路由、Job 及能力名称。不会输出配置值、环境值或工厂源码，也不会加载应用、执行构造器/工厂/角色或连接外部服务。该边界不等于能静态证明任意工厂体无副作用。分析临时目录在成功和失败时均删除。

`php tests/application-inspection.php` 从不同工作目录检查含空格项目路径，验证公开命令、人读/JSON、秘密脱敏、带副作用工厂不执行，以及检查和开发生成的身份与诊断一致。

## 同步业务事件

`application.events` 是明确事件类及有序监听关系的列表，与命令的 `ready/completed` 生命周期通知分开。例如：

```json
{
  "events": [{
    "class": "App\\UserCreated",
    "listeners": [
      {"class": "App\\AuditUser", "method": "handle"},
      {"service": "notify-user", "method": "handle"}
    ]
  }]
}
```

监听方法是公开实例方法，唯一参数必须为声明的事件具体类型，返回 `void`。事件类、重复事件/监听、方法签名、缺失绑定、构造依赖环和生命周期均在构建期检查。监听根使用现有 `services/bindings` 与应用覆盖；扫描到其他类不会订阅事件。`listeners: []` 表示已声明的无监听事件，派发无操作；未声明的具体事件类型以 `event_not_declared` 拒绝，不从事件载荷解析类名或方法名。

命令、Service 或监听者通过构造器注入 `Type\Core\BusinessEvents`，调用 `$events->dispatch(new UserCreated(...))`。生成器提供当前 scope 的固定派发入口，按声明顺序惰性构造监听者并直接调用。适配器本身属于 execution，不能设为 singleton；同一 scope 内复用，跨作用域、取消或关闭后拒绝使用。宿主也可在已绑定的 scope 调用生成的 `$application->events()`。业务不保存事件到长寿命对象；构造器只赋依赖，需打开的资源由现有作用域登记。监听抛错立即传播并停止后续监听，不吞错、不重试、不自动排队。

同步派发保留当前事务边界。在 `Db::transaction()` 内直接派发时，监听运行在该活动事务中；需要确认提交后执行时，在事务体内明确登记 `Db::afterCommit(fn () => $events->dispatch($event))`。回滚会丢弃回调，确认提交后的监听失败通过 `AfterCommitException` 报告“已提交”，不能重跑整笔业务或宣称已经回滚。`afterCommit` 没有跨崩溃投递保证；需要持久投递应使用 Outbox/Queue，仅保存稳定标识和可序列化业务值，不序列化连接、活动 Model、Service 或 scope。

统一检查的 `assembly.events` 包含事件类型、监听服务/类型/方法和顺序。`tests/business-events.php` 通过真实命令、受管子任务和 SQLite 验证顺序、无监听、异常中断、作用域隔离、资源归还以及提交/回滚/提交后失败。执行环境须使用锁定且包含 Swoole PDO 能力的开发 PHP；原生验收另行记录。

## 验证范围

快速验证通过公开装配生成入口运行生成命令，而不是快照整份生成源码。覆盖多层构造器推导、接口/抽象/标量绑定、具名工厂、应用覆盖、配置引用隔离，以及构造和工厂错误拒绝。名称解析保留方法内具名类的重名检查；具名工厂按完整类名定位，其嵌套表达式同样接受全局取值、服务定位器和闭包捕获检查，不能因生成器减少内存占用而漏检。

原生验证复用同一组命令输出、退出码和清理断言，包含多次执行时单例复用及执行对象重建。独立消费项目真正安装带注册声明的可选插件，对照标准开发入口与同配置 AOT 的 `declaration-generation` 和实际行为，验证禁用时仍编译、启用后按目标懒构造，以及无外部服务时 help/check 可用。

不支持任意业务对象泄漏分析或全部 PHP 动态注入规则。PHP 回归与同一产物的原生验收分别记录；现有原生消费者结束后回收临时安装与生成物，保留组件、锁文件、工具链、产物摘要和装配图报告。

## Job 与定时 Task

`application.jobs` 是 type/version 与唯一 `class` 或 `service` 的声明列表；`application.schedules` 是稳定 id、唯一任务目标及 `schedule` 的声明列表。它们共用命令、控制器的构造器依赖图与 bindings，生成实例方法 `jobs(): Registry` 和 `schedules(): array`。Job、Task 与声明 resources 必须使用 execution 生命周期。资源先在本次 scope 开启，随后构造业务对象；工厂参数保持明确 `JobContext` 或 `TaskContext`，不会捕获上一条投递或 occurrence 的 scope。

时间声明接受 `{"cron":"* * * * *","timezone":"UTC","overlap":"first"}` 或 `{"interval":60,"anchor":0}`；补跑字段为 `misfire`、`catch-up-limit`、`lookback-seconds`、`grace-seconds` 与 `revision`。构建校验复用调度组件的纯时间逻辑，不执行 Task。时钟、Queue、状态存储和角色预算由 bootstrap 显式持有。旧顶层 queue 和自定义 Jobs 工厂映射不再支持。

`tests/queue-consumer.php` 验证独立安装、真实 Redis、构造注入、逐次资源隔离、清理失败保留租约及恢复；`tests/scheduler-consumer.php` 验证生成 Task 工厂、scope 归属、游标、有限补跑、真实 SIGKILL 和清理失败。两者通过标准 DevelopmentBuilder 装入同一生成过程，`--native` 另跑全量原生构建，PHP 通过不代表 AOT 完成。
