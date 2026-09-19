# type-runtime

线程与线程内协程是 TypeApp 的运行时基础能力，由本组件统一承接。按照 [TypePHP＋Swoole 底层决策](https://github.com/zoujingli/typeapp/blob/main/docs/adr/0015-typephp-and-swoole-foundation.md)，TypePHP 负责框架与业务的全量编译，Swoole 提供原生线程、协程和事件循环；各业务角色共用执行作用域、资源所有权及预算契约。

构建期登记的业务线程通过 `CoroutineRuntime::startThread()` 启动，复用 Swoole Thread 句柄；需要受控 Swoole/PHPX ABI 2、开启 fiber 通知、完整 AOT 入口及调用者显式 join。接入方式、参数预算、线程内协程和失败收尾的实际验证边界见[已编译业务线程](https://github.com/zoujingli/typeapp/blob/main/docs/development/compiled-business-threads.md)。

显式完成等待候选直接使用句柄上的 `joinWithin(0..60000)`，额外检查 TypeApp 私有 `Thread::TYPEAPP_JOIN_ABI=1`。超时仍持有线程和资源，不可提前释放额度；非零等待阻塞 OS 线程，仅用于独立主控。它不替代任务停止、排空或永久阻塞后的进程故障处理，旧 `join()` 调用不依赖此候选。

`startThread($entry, $payload, $socket)` 可选传入一个原生协程 Socket，复用 Swoole 自有的描述符复制；子线程从 `Thread::getArguments()[1]` 取得自己的对象。该入口额外要求 TypeApp 私有 `Thread::TYPEAPP_SOCKET_ARGUMENT_ABI=1`，缺失时抛出 `compiled_thread_socket_unavailable`，未传 Socket 的原两参数入口不依赖此能力。调用者负责原对象、各副本及 join，不能共享 PDO 或业务对象；当前用途与候选边界见[HTTP 线程接入](https://github.com/zoujingli/typeapp/blob/main/docs/development/http-native-threads.md)。

第四参数可传 `Swoole\Thread\Map`，需要 TypeApp 私有 `TYPEAPP_CONTROL_ARGUMENT_ABI=1`。控制 Map 复用上游原生 ThreadResource，固定在 `getArguments()[2]`；没有 Socket 时下标 1 为 null，未传 Map 时保持旧参数形状。各线程重新取得自己的 Zend 对象，共享的是原生控制数据；不传容器、PDO 或业务对象。

`ThreadSupervisor($maximumThreads, $startupSeconds=10, $progressSeconds=5, $stopSeconds=10)` 以一次 `run($entry, $payloads, $listener=null)` 拥有有限线程组。它要求编译 embed 主线程、原生控制/完成能力及独占事件循环；复用 `ProcessSignals` 和原生 Timer 接收停止与探测完成。`stop()` 幂等撤销组就绪并请求停止，真实 join 后才归还线程额度，`statistics()` 只报告状态、持有、就绪和已 join 数。启动失败或线程异常先收尾其余线程再报告；无法在停止期限内回收时以 `_Exit(75)` 结束角色进程，不 detach 或自动重启。

业务入口通过上述 Map 写 `ready`/`failed` 布尔值及递增的 `pulse` 单调时钟纳秒整数，只读主控写入的 `stop`；只使用这四个固定键。HTTP 已由 `SwooleServer::serveThread()` 完成线程内部分。进度检查只证明事件循环前进，不代替各项 I/O 的截止与真实完成；完整角色迁移和其他平台仍按[实际证据](https://github.com/zoujingli/typeapp/blob/main/docs/development/http-native-threads.md)汇合。

TypeApp 应用的通信与基础并发必须使用 Swoole；线程与协程入口按角色职责及构建能力选择，缺少必需能力时明确失败。完整角色与目标平台验收条件见[实现规划](https://github.com/zoujingli/typeapp/blob/main/docs/guide/roadmap.md)。

提供参数解析、受管执行作用域、资源池/租约以及截止/取消预算，不承担 HTTP 或数据库协议。`Type\Runtime\Arguments` 读取显式声明的命令选项；重复选项、未知选项、缺失值和整数越界都会抛出 `InvalidArgumentException`。

## 安装与版本

本组件通过公开 Git 分发子仓安装，不假设已发布到 Packagist。先在应用的 Composer 根配置登记下列组件及传递依赖仓库；HTTPS 读取不需要 SSH 密钥，依赖包自己的 repositories 不会自动传递给消费应用。

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer require zoujingli/type-runtime:dev-main
```

`dev-main` 的分支别名为 `1.0.x-dev`；本仓组件间使用 `~1.0.0@dev` 约束。开发分支不等于已发布稳定 1.0 版本。提交应用的 `composer.lock` 固定实际分发提交；构建工具只放 `require-dev`。详细依赖与公开分发规则见[组件组织与安装](https://github.com/zoujingli/typeapp/blob/main/docs/development/component-structure.md)。

支持带值选项的空格和等号两种形式，以及不带值的开关。选项名由调用者声明；业务输出与退出码由应用入口决定。

还提供 `ExecutionScope`、`ManagedResource`、有界 `ResourcePool` 与 `ResourceLease`：按登记顺序启动，逆序停止；部分启动失败可收尾，单个清理失败不阻止其余资源释放。作用域绑定当前执行者，受管子任务共享截止和容量预算，资源操作实际退出前继续持有租约。

`ManagedResource::stop()` 抛错后，作用域继续持有失败项并保持 `closing`；同一所有者再次 `close()` 只收尾剩余项，成功项不重复停止。资源实现须保留再次收尾所需状态；正常返回也可表示在途所有权已交给既有池或原生完成回调。迟到子任务不能越过失败资源将作用域标成 `closed`，不增加后台重试或 Timer。`WorkLifecycle::finish()` 遇到未关闭作用域会撤销就绪并保持在途额度，拒绝替换该作用域；业务已返回且真实清理完成后，统计回收额度，角色仍保持停止。

运行时回调使用固定签名：`ExecutionScope::spawn()` 接收 `Closure(ExecutionScope): mixed`，子任务始终获得自己的作用域；`ResourceLease::hold()` 接收 `Closure(ReusableResource): mixed`；`ResourcePool` 工厂为 `Closure(): ReusableResource`。即使不使用上下文也须声明相应参数，TypePHP 对非 variadic 回调严格检查实参数量。依赖通过构造或 `use` 捕获传入，业务扩展不依赖参数引用写回。

本包第一方源码按 Apache-2.0 提供；仓库可见性、分发批次和稳定版本仍由维护者按发布门槛管理。第三方运行时依赖保留各自许可证。

## 声明式使用示例

以下代码可保存为应用的声明式入口；不在全局加载业务文件或自动启动服务，编译时与生产依赖一起纳入 AOT。

```php
<?php

declare(strict_types=1);

use Type\Runtime\Arguments;
use Type\Runtime\ExecutionScope;

/**
 * 读取受限命令参数并在命令作用域中计算；结束时收回所有受管资源。
 *
 * @param list<string> $argv 命令入口提供的完整参数。
 */
function main(int $argc, array $argv): void
{
    $arguments = new Arguments($argv, ['name', 'times'], ['quiet']);
    $scope = new ExecutionScope();
    try {
        $scope->assertActive();
        $result = str_repeat($arguments->text('name', 'Type') . "\n", $arguments->integer('times', 1, 1, 10));
        if (!$arguments->has('quiet')) {
            echo $result;
        }
    } finally {
        $scope->close();
    }
}
```

上例展示独立的同步命令用法，只使用参数解析和作用域清理，不涉及通信和并发启动；完整 TypeApp 应用仍要求 Swoole。线程与协程应用使用上文的运行时基础能力；`ExecutionScope::spawn()` 的受管子任务在当前线程内创建协程，需要已启用的 Swoole 协程上下文。角色入口可直接调用官方 `Swoole\Coroutine\run()`，或沿用原生 `Swoole\Coroutine::create()` 和 `Swoole\Event::wait()`。官方内置 PHP 库按 Swoole 请求初始化加载，是全量 AOT 的唯一例外；框架、业务、生成入口和其他生产 PHP 仍完整编译。调用 run 前应明确既定 `hook_flags`，避免它默认补齐全部 hook；缺少上下文会抛出 `TaskException` 并保留 `coroutine_required` 错误码。

## 接口与源码组织

`src/` 根保留 `Arguments`、`ExecutionScope`、`ResourcePool` 等稳定公共入口。资源组为 `ManagedResource/ReusableResource/ResourceLease`；执行组为 `ExecutionOwner/ManagedTask/Deadline/Cancellation/TaskBudget`；预算与角色组为 `ResourceBudget/DeploymentBudget/WorkLifecycle`；`QueryString` 处理有界查询编码，`ReleaseCompatibility` 检查滚动共存协议。它们共同属于执行资源语境，不为一个类额外建立空目录。

`ReusableResource` 实现须同时提供 `reset(): bool` 和幂等的 `close(): void`。返回才表示对应原生操作完成；关闭失败抛出异常，池继续持有资源及预算，后续 `close()` 或 `retire()` 可再次收尾。迁移已有自定义资源时必须补齐这一方法，不能以空方法代替物理关闭。`hold()` 内提出关闭意图后，池仍持有租约，直至操作退出且资源完成重置或关闭。统计中的 `in_flight` 包含在途 hold、建连及收尾，`quarantined` 包含已请求释放的活动租约与关闭失败；`created` 包含全部尚未归还的容量。

`DeploymentBudget(..., $threadsPerProcess = 1)` 将进程额度按最大同时使用连接的线程数向下取整分配；统计返回 `per_process`、`threads_per_process`、`per_thread` 和 `maximum_application_connections`。部署计划应包含所有角色、滚动副本、管理预留以及未 join 的旧代线程，主线程使用数据库时也占一份。每线程为同一服务端连接域创建一个预算实例，注入全部命名池和凭据代次；跨线程只传部署参数，不能传已使用的预算对象。预算是显式部署分配，不是自动发现集群拓扑或可重复领取的进程共享额度；启动者必须遵守最大存活线程数，不能在旧线程未退出时复用其份额。分配后的局部空闲容量不会自动借给其他线程。

停止不能强杀未退出的原生 I/O；需要独立进程监督时，监督器持有总硬截止。受管任务在清理预算耗尽后通过 Swoole Channel 等待后代真实退出，期间继续持有整棵子树的额度；原生取消唤醒等待也不能提前归还额度。

## AOT 与运行要求

参数和作用域辅助接口可以独立使用；TypeApp 应用统一依赖 Swoole，HTTP、数据库和 Redis 组件按业务安装。Composer 声明 `ext-filter`；线程与协程路径必须具备匹配的 Swoole 原生能力，业务线程还需要上文的受控 ABI 与完整编译入口。AOT 运行仍依赖匹配的 PHPX/libphp，不能把不需要 PHP CLI 解释器写成不需要 PHP 运行库。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

```sh
composer build:native
composer test:native
composer test:tasks
composer build:tasks
composer test:tasks-native
composer test:deployment-budget
```

- [受管任务](https://github.com/zoujingli/typeapp/blob/main/docs/development/managed-tasks.md)
- [运行角色与停止](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-roles.md)
