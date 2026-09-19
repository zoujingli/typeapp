# type-log

提供 PSR-3 日志、级别过滤、多通道、JSON Lines stdout 和显式本地文件输出。默认记录 UTC 微秒时间、日志级别、通道、构建标识、进程 ID、执行关联和安全上下文。生产依赖只有 `type-runtime` 与 `psr/log`；HTTP、队列和调度在应用装配时组合。

## 安装与版本

本组件通过公开 Git 分发子仓安装，不假设已发布到 Packagist。先在应用的 Composer 根配置登记下列组件及传递依赖仓库；HTTPS 读取不需要 SSH 密钥，依赖包自己的 repositories 不会自动传递给消费应用。

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer config repositories.type-log vcs https://github.com/zoujingli/type-log.git
composer require zoujingli/type-log:dev-main
```

`dev-main` 的分支别名为 `1.0.x-dev`；本仓组件间使用 `~1.0.0@dev` 约束。开发分支不等于已发布稳定 1.0 版本。提交应用的 `composer.lock` 固定实际分发提交；构建工具只放 `require-dev`。详细依赖与公开分发规则见[组件组织与安装](https://github.com/zoujingli/typeapp/blob/main/docs/development/component-structure.md)。

## 使用

```php
<?php

declare(strict_types=1);

use Type\Log\Channel;
use Type\Log\Formatter;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Runtime\ExecutionScope;

/**
 * 在一个命令作用域中绑定日志并有界清理，不记录实际数据库凭据。
 */
function main(): void
{
    $password = getenv('DB_PASSWORD');
    $secrets = $password === false || $password === '' ? [] : [$password];
    $logs = new LogManager('readme-example', [
        'app' => new Channel(Output::stdout(), 'info'),
        'security' => new Channel(Output::stdout(), 'warning'),
    ], 0.25, new Formatter(['payment_card'], $secrets));
    $scope = new ExecutionScope(context: ['command_id' => 'command-123']);
    try {
        $scope->open($logs);
        $logger = $logs->logger($scope, ['trace_id' => 'trace-123']);
        $logger->info('任务开始：{name}', ['name' => '数据同步']);
        $logger->channel('security')->warning('访问被拒绝');
    } finally {
        $scope->close();
    }
}
```

`logger()` 返回 `Psr\Log\LoggerInterface` 的实现，并提供 `channel()` 选择同一作用域的通道。支持 PSR-3 八个标准级别；`log()` 收到其他级别抛 `Psr\Log\InvalidArgumentException`。无效配置、未知通道、执行者不匹配及停止后写入会明确报错。正常输出失败通过计数处理，不在业务日志调用中递归输出异常。

构建标识由应用在启动时明确传入，例如 Git SHA 或制品摘要，不从目录、环境或 Git 仓库隐式推断。`request_id`、`message_id`、`occurrence_id` 等关联标识由请求、队列或调度入口写入 Scope/日志绑定；没有进程全局的“当前请求”。

## 作用域与生命周期

`logger($scope, $context)` 在当前 Scope 登记一个日志上下文资源。上下文在绑定时递归复制、规范化和脱敏，外部变量的后续修改不会改变已绑定值；同名关联键以 Scope 的值为准。记录的 `context` 与 `correlation` 分开，普通日志参数不能覆盖构建或执行关联信息。

Scope 关闭时日志绑定清空关联数据并释放 Scope 引用；旧 Logger 无法继续输出。它不能跨进程、Fiber 或协程直接使用，子任务应以自己的 Scope 显式建立日志绑定。多次 `channel()` 共享同一个绑定，不增加资源登记。

`LogManager` 可以作为 `ManagedResource` 放入应用生命周期。顺序应先登记管理器、再创建 Logger：

```php
$scope->open($logs);
$logger = $logs->logger($scope);
```

关闭时先使 Logger 失效，再排空并关闭管理器。共享进程管理器时，每次执行只关闭 Scope，在进程停止时关闭管理器。管理器与 Output 首次使用后绑定所属进程，fork 后应在工作进程内创建新实例；尚未打开输出的纯配置可在启动阶段准备。

排队的记录已经是脱敏后的 JSON 字符串，保留产生时的关联标识，稍后排空不会改用下一请求的信息。普通日志没有事务审计或持久投递保证，也不参与数据库提交结果判定。

## 脱敏与格式化

- 默认对规范化名称包含 `password`、`passwd`、`pwd`、`token`、`secret`、`authorization`、`cookie`、`apikey`、`privatekey`、`sessionid` 的字段递归脱敏，忽略大小写和标点，可追加敏感字段。
- 可登记已知敏感字符串，消息、键名、上下文和异常摘要会替换为 `[REDACTED]`。常见 `password=...`、Authorization Bearer/Basic 与 URL 用户名密码也会清除。
- 插值使用规范化后的上下文，再处理消息文本；未知对象只记录类型，不调用上下文对象的 `__toString()` 或 `jsonSerialize()`。消息自身的 Stringable 转换失败时记录 `[message_unavailable]`。
- `exception` 中的 Throwable 保留类型、消息、代码、位置和最多 12 层无参数堆栈，不记录异常链与堆栈参数。资源、已关闭资源、无效 UTF-8、非有限浮点和循环数组有安全表示。
- 默认深度 6、上下文节点预算 128、单字符串 2048 字节，超限截断。输出还有独立单记录上限；格式化后超过上限的记录整体丢弃，不输出无效 JSON。

字段和已登记值规则无法识别所有自然语言秘密。业务应将敏感数据放在明确字段中并登记必要敏感值，避免主动拼接凭据。消息对象的自定义转换仍是调用方代码，应保持有限计算和无外部 I/O。

## 输出与容量

`Output::stdout()` 是默认建议入口。`Output::file()` 要求显式本地绝对路径，拒绝远程包装器、符号链接和非普通目标文件；不自动创建父目录。文件以追加方式打开，新文件权限遵循进程 umask，已有文件权限保持不变。部署时应预建目录与权限，轮转由外部管理，轮转后重新创建 Output。

`Output::stream()` 可接入已建立的原生本地文件或 socket 流，必须可写并支持非阻塞；不接受自定义流、压缩或 TLS 包装器。默认不关闭调用方资源，停止时恢复原有阻塞模式；`closeStream: true` 明确移交关闭责任。日志管理期间该流应专用，避免其他代码改变模式或交错写入。

默认每个 Output 最多 1024 条、总记录字节 1 MiB、单记录 4096 字节。条数或字节容量满时丢弃新记录，保留队列顺序；多个通道可共享一个 Output，容量合并计算。每次日志调用只进行一次不等待的写入尝试，积压可通过事件循环或明确生命周期调用 `drain($seconds)` 继续排空。

`LogManager::stop()` 使用一个总的单调时钟预算，默认 250ms，所有 Output 共享该预算。超时后丢弃剩余记录并关闭输出。`fwrite` 失败时立即退役该 Output，丢弃队列和后续记录，不进行无限重试，也不向另一个日志输出递归报告。已经部分写出的记录可能截断，单独计数；普通日志不保证跨进程原子写或恰好一次投递。

等待非阻塞管道/socket 的循环有预算；本地普通文件的 `open/stat/write/close` 仍由操作系统执行，PHP 无法用 O_NONBLOCK 中断文件系统内核调用。因此显式文件输出不承诺在故障磁盘、网络挂载或内核不可中断 I/O 下的严格时限。需要隔离此类故障时使用 stdout 管道交给独立收集进程，日志文件不得放在网络挂载上。当前实现也不提供 fsync 持久性保证。

`stats()` 返回可接入指标的累计计数与当前状态，不自行发送告警：

| 指标 | 含义 |
| --- | --- |
| filtered | 该通道被级别过滤的记录 |
| accepted / written | 被缓冲接受 / 完整写出的记录 |
| dropped_full / dropped_oversize | 容量满载 / 单条超限丢弃 |
| dropped_failure / write_failures | 输出失败导致的记录丢弃 / 退役次数 |
| dropped_stop / drain_timeouts | 停止时或停止后丢弃 / 有等待的排空超时 |
| partial_records | 失败或停止时已部分写出的记录 |
| pending_records / pending_bytes | 当前缓冲条数 / 保留记录字节数 |
| high_water_records / high_water_bytes | 历史缓冲高水位 |
| failed / stopped | 输出退役 / 已停止状态 |

共享 Output 时输出计数在各通道的结果中重复显示，汇总时按 Output 去重；`filtered` 始终是单通道计数。严重级别不绕过容量限制，避免故障高峰突破内存边界。

## 验证

命令、文件、多通道、上下文隔离、任意值、脱敏、真实管道满载与恢复、断开输出、Linux 满设备以及 24 个并发 HTTP 请求均有专门验收入口。历史 PHP 与原生证据分别记录，不由文档更新宣称新的构建已通过。仓库接入与执行命令见[开发说明](https://github.com/zoujingli/typeapp/blob/main/docs/development/logging.md)。

## 接口与源码组织

`LogManager/Logger/LogContext` 管理生命周期、PSR 接口与作用域关联；`Channel/Level` 描述通道与级别；`Formatter` 负责有界规范化和脱敏；`Output` 持有输出缓冲与实际资源。用户通过构造声明这些差异，不增加全局 logger 或自动上下文。

完整声明式示例仅使用 stdout；需要文件输出时显式选择 `Output::file()` 并预建本地目录。`Formatter` 的敏感值从运行环境取得，不写入构建常量。`LogManager::stats()` 可供指标采集，清理期间不会自动写入第二个日志通道。共享进程管理器只在进程结束时 `stop()`，请求 Scope 只回收自己的日志绑定。

## AOT 与运行要求

生产依赖为 `type-runtime`、`psr/log ~3.0.2` 与 JSON，不要求 core 或数据库。PSR 源码按精确适配版本一起编译，运行保留匹配 PHPX/libphp；HTTP 示例额外依赖对应的 Swoole 原生环境。日志文件和秘密不能作为无源码镜像的默认构建输入。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

```sh
composer test:log
composer build:log
composer test:log-native
```

- [日志公开行为与故障验收](https://github.com/zoujingli/typeapp/blob/main/docs/development/logging.md)
