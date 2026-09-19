# type-runtime · 执行作用域与资源管理

[返回组件总览](../components.md)

为一次 HTTP 请求、命令或后台任务建立明确的资源边界：参数先校验，资源按顺序启动，结束时逆序关闭；截止时间、取消和容量沿调用链传递。其他插件的数据库连接、Redis 租约和日志绑定都复用这一层。

## 安装与依赖

需要 PHP `>=8.4 <8.6` 和 `ext-filter`。同步作用域无需 Swoole；只有使用受管协程任务时才需要 Swoole 与协程上下文。

源码位于本仓库对应 plugin 目录。在消费应用根声明依赖后执行：

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer require zoujingli/type-runtime:dev-main
```

依赖包的 repositories 不会传递给根应用，因此上述命令包含组件的全部传递依赖，使用公开 HTTPS 地址，无需 SSH 密钥。提交应用的 `composer.lock`；`dev-main` 是开发版本，不能等同稳定发布。公共安装约定见[组件总览](../components.md#安装组件)。

## 最小使用示例

将以下代码保存为独立物联中心应用的 `app/main.php`。按[运行声明式示例](../components.md#运行声明式示例)准备开发启动器，使用带参数的 `main($argc, $argv)` 调用。

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

执行 `php dev.php --name TypeApp --times 2`，应输出两行 `TypeApp`；增加 `--quiet` 后不输出。`--times 0`、重复或未知选项均抛出异常，不能当作默认值继续运行。

## 参数解析

`Arguments($argv, $valueOptions, $switches)` 的第一个数组包含程序名。上例允许 `--name value`、`--name=value` 和 `--quiet`；通过 `text()` 读取字符串，通过 `integer()` 声明默认值及闭区间。空字符串和 `0` 按显式输入处理，业务是否接受由调用方决定。

## 截止、取消与资源清理

以下片段放在业务函数内，替换最小示例的作用域创建与处理部分：

```php
$cancel = new \Type\Runtime\Cancellation();
$scope = new \Type\Runtime\ExecutionScope(
    deadline: new \Type\Runtime\Deadline(2.0),
    context: ['command_id' => 'sync-42'],
    childLimit: 4,
    cleanupSeconds: 0.5,
    cancellation: $cancel
);
try {
    $scope->assertActive();
    // 在业务循环和外部效果前重复检查。
    $cancel->cancel();
} finally {
    $scope->close();
}
```

| 配置 | 单位与作用 |
| --- | --- |
| `Deadline($seconds)` | 秒，使用单调时钟；默认 null 无业务截止 |
| `context` | 字符串键值，保存执行关联身份 |
| `childLimit` | 1–1024，默认 16，限制直接子任务数量 |
| `cleanupSeconds` | 秒，0–60，默认 5，控制合作式清理 |
| `TaskBudget` | 可共享的执行额度，限制任务树总在途量 |

自定义资源实现 `ManagedResource::start()/stop()`，以 `$scope->open($resource)` 登记。登记发生在启动之前，因此部分启动失败也会清理；`stop()` 必须能处理该状态。单个关闭异常不阻止其他资源关闭。

## 资源池与租约

`ResourcePool($factory, $capacity, $idleLimit)` 接收零参数工厂，返回实现 `ReusableResource` 的对象；通过 `borrow($scope)` 获取 `ResourceLease`。业务使用 `hold(static function (ReusableResource $resource): mixed { ... })`，在途操作结束前持有容量。不要把租约内资源保存到其他请求。

池满时立即抛 `CapacityException`，不进入等待队列。`idleLimit` 不得超过总容量。Scope 负责归还本次租约；池的所有者在进程结束时调用 `close()`。需要 SQL 或 Redis 时直接使用对应插件的管理器，通常无需自己再包装一层池。

## 受管并发

在 `Swoole\Coroutine\run()` 内调用 `$scope->spawn(static function (ExecutionScope $child): mixed { ... })`。子任务使用自己的 Scope 登记资源，通过任务对象等待结果；父 Scope 关闭时取消并等待受管子任务。回调即使不使用上下文，也必须保留参数。

同步命令不必启用协程。缺少协程上下文抛 `TaskException`，错误码为 `coroutine_required`；不会自动改成串行执行。

## 常见问题

| 现象 | 原因与处理 |
| --- | --- |
| `deadline_exceeded` / `cancelled` | 当前预算耗尽或已取消，停止发起新操作并清理 |
| 执行者不匹配 | Scope/连接跨了进程、Fiber 或协程；在当前执行者重新创建 |
| 池满 | 检查租约释放与进程数，再按下游容量调整上限 |
| close 后仍有底层 I/O | 取消是合作式的；原生阻塞操作还需要驱动超时和进程监督 |

## 编译与验证

作用域、资源实现及回调与应用一起全量编译，运行仍需要匹配 PHPX/libphp。自定义并发回调按 TypePHP 的完整参数签名编写。

本仓库可运行 `composer test:tasks` 与 `composer test:deployment-budget`；对应原生验收为 `composer build:tasks` 后执行 `composer test:tasks-native`。

继续阅读：[核心](type-core.md)、[数据库](type-orm.md)、[Redis](type-redis.md)、[日志](type-log.md)。

本文以本仓库当前公开接口为依据；安装版本请同时核对包内 README。[对应源码与包说明](https://github.com/zoujingli/type-runtime)。
