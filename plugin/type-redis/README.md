# type-redis

普通 Composer 库，依赖 phpredis 6.3 和 type-runtime。命名连接由不可变 `RedisConfiguration` 定义，客户端和连接池在实际工作进程借用时创建。

## 安装与版本

本组件通过公开 Git 分发子仓安装，不假设已发布到 Packagist。先在应用的 Composer 根配置登记下列组件及传递依赖仓库；HTTPS 读取不需要 SSH 密钥，依赖包自己的 repositories 不会自动传递给消费应用。

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer config repositories.type-redis vcs https://github.com/zoujingli/type-redis.git
composer require zoujingli/type-redis:dev-main
```

`dev-main` 的分支别名为 `1.0.x-dev`；本仓组件间使用 `~1.0.0@dev` 约束。开发分支不等于已发布稳定 1.0 版本。提交应用的 `composer.lock` 固定实际分发提交；构建工具只放 `require-dev`。详细依赖与公开分发规则见[组件组织与安装](https://github.com/zoujingli/typeapp/blob/main/docs/development/component-structure.md)。

```php
<?php

declare(strict_types=1);

use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;

/**
 * 只对声明的 Redis 发出 PING，所有退出路径关闭租约及连接管理器。
 */
function main(): void
{
    $host = getenv('REDIS_HOST');
    $port = filter_var(getenv('REDIS_PORT') === false ? '6379' : getenv('REDIS_PORT'), FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if (!is_int($port)) {
        throw new InvalidArgumentException('REDIS_PORT 必须为有效整数端口');
    }
    $manager = new RedisManager(['default' => new RedisConfiguration($host === false ? '127.0.0.1' : $host, $port)]);
    $scope = new ExecutionScope();
    try {
        $redis = $manager->connection($scope);
        echo $redis->command('PING', ['type-redis-readme']) . "\n";
    } finally {
        try {
            $scope->close();
        } finally {
            $manager->close();
        }
    }
}
```

`Purpose` 区分 command、blocking、pipeline、transaction、script，每个命名连接的用途容量分别限制。普通会话可复用，其余用途默认归还即释放。通过 runtime 租约检查作用域与执行者，在途调用持有容量到实际退出。

普通命令拒绝改变连接状态、执行脚本、管理服务器和阻塞调用；这些操作使用对应入口或独立管理客户端。`blocking()` 只进入阻塞池。批次为 `[[命令, 参数列表]]`，最多 1000 项；`pipeline()` 不保证原子性。

`transaction($watchKeys, $operation)` 建立 WATCH 后调用一次回调。回调通过同一连接读取状态并返回待执行命令列表，再执行 MULTI/EXEC。`TransactionResult::committed()` 为 false 表示 WATCH 冲突，框架不重跑回调；EXEC 中命令错误仍可能已有部分效果，Redis 事务不等同于数据库回滚。回调中关闭租约会阻止后续提交。

事务回调固定为 `Closure(RedisConnection): array`；即使不读取连接，也须显式声明 `RedisConnection $connection` 参数。TypePHP 对非 variadic 回调严格检查实参数量，返回值是已有批次格式的命令列表。

`script()` 接收显式 Lua 和键/参数；仅允许应用可信脚本，脚本运行出错可能已经写入，不能推断全部回滚。脚本会话归还后销毁，下一次执行重选声明数据库。

`ScriptGuard` 为上层组件提供可选的受管写入边界：接收实际 `RedisConnection`、可信 Lua 与参数，在同一写入脚本内验证权限。Queue 的可选受保护投递复用该接口；接口本身不声称有租约，实现必须对目标身份与持有者做原子检查。

连接默认不持久化，关闭 phpredis 自动重试，使用明确连接和读取超时。超时报告 UNKNOWN，并让会话失效；服务器拒绝、批次或脚本部分执行的错误分别保留 REJECTED/MAY_HAVE_APPLIED 等状态，不自动重试写入。原始 key/value 不写入错误文本。

正常归还取消 WATCH、重选数据库、恢复无序列化及无前缀选项，失败或未结束批次直接丢弃。ACL 用户名/密码与 TLS 验证配置显式声明；TLS 固定校验证书和主机名，不提供忽略证书选项。ACL 与 TLS 的真实正反向验收见本页对应记录，错误证书与主机名不会降级为不校验。

仅支持独立 Redis 服务；Cluster、Sentinel 和自动故障切换不在这个入口的保证范围。缓存和可靠任务存储的故障域由后续应用装配分别配置，不把不同逻辑数据库等同于独立故障域。

PHP 真实 Redis 8.10.1 的用途隔离、数据库隔离、WATCH 冲突、pipeline/脚本部分失败、超时恢复和 ACL 已验证；独立 Composer 消费和原生入口分别记录验收结果。

## 可靠任务存储预检

`StoragePolicy::verify($reliableConfiguration, $cacheConfiguration, $appendFsync = 'always')` 在部署或角色启动预检中读取两个服务的真实 run_id、角色、容量、淘汰策略和 AOF 状态。同一实例不同逻辑数据库会被拒绝；可靠目标必须是主库、maxmemory 有明确上限、noeviction、启用 AOF 且 fsync 策略匹配，最近 AOF 写入状态正常。

`always` 是默认配置；显式选择 `everysec` 意味着接受配置中的同步窗口，返回值 `configured_fsync_window_seconds` 只描述策略，不承诺故障磁盘、异步复制或底层设备的端到端持久性。缓存的实际淘汰和持久化配置随检查结果返回，不强制所有缓存都采用同一淘汰算法。

预检需要 INFO 与 CONFIG GET 的只读诊断权限，不能读取到策略时明确失败。检查不会修改服务器，不替代运行期监控；Worker、Scheduler 不会在每条任务处理中访问缓存服务。生产业务连接仍使用独立命名用途池，预检连接始终关闭。

## 接口与源码组织

`RedisConfiguration/RedisManager` 负责声明与命名用途池，`RedisConnection/RedisSession/Purpose` 负责公开命令和实际会话，`RedisException/TransactionResult` 保存结果语义，`ScriptGuard` 定义受管写入接口，`StoragePolicy` 执行可靠存储只读预检。普通客户端、可靠消息与缓存的角色区分来自用途和部署，不通过全局默认连接混用。

完整示例只发 PING，实际认证与 TLS 通过 `RedisConfiguration` 参数传入。`RedisConnection::close()` 归还当前租约，Scope 关闭也会清理；管理器持有的空闲连接仍需 `RedisManager::close()`，使用嵌套 finally 防止第一次清理异常跳过管理器。共享管理器只能留在创建它的线程请求，不将具体连接跨执行者捕获。

各用途继续使用独立容量。Swoole 协程池满时默认最多排队 64 项、等待 1 秒，并受作用域更短截止及取消约束；`connection($scope, $name, $purpose, 0)` 显式立即拒绝，同步调用者不排队。底层关闭失败时资源池保留隔离额度，可通过管理器统计观察，不能仅凭发出了关闭调用判定释放。这是运行时池契约；Redis 全部命令和重置路径的线程、协程及原生验收仍按对应任务进行。

## AOT 与运行要求

Composer 要求 phpredis `^6.3` 与 runtime；AOT 运行包含匹配 Redis 原生扩展、PHPX/libphp 及 TLS 传递库。固定关闭持久连接与隐式写入重试是协议的一部分。单机 Redis、认证与 TLS 的实际版本和证据见相应记录，不扩大为 Cluster/Sentinel 兼容。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

```sh
composer test:redis
composer test:redis-security
composer build:redis
composer test:redis-native
composer build:tls
composer test:tls-native
```

- [TLS 真实验收](https://github.com/zoujingli/typeapp/blob/main/docs/development/tls-verification.md)
- [可靠存储故障与角色停止](https://github.com/zoujingli/typeapp/blob/main/docs/development/task-reliability.md)
