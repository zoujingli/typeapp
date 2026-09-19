# 已编译业务线程

本文描述基于 Swoole 的已编译线程入口、作用域、资源归属与 AOT 衔接；完整平台和角色验收条件见[实现规划](../guide/roadmap.md)。

## TypePHP 0.9.0 编译接入

当前线程编译基线为 PHP `8.5.10 ZTS`、TypePHP `0.9.0`、PHPX `2.9.0`。TypePHP 0.9 的进程池会直接生成编译命令，绕过适配器的 `compileFile()`；`TypephpCompatibility::compile()` 仅在线程二进制构建中临时将上游 `maxJob` 设为 1，调用父类编译流程后恢复原值。因此源码队列、对象缓存、编译选项和链接仍由 TypePHP 负责，没有另建并行编译器或运行时调度器。

TypePHP 0.9 将声明拆分为多个 `*_decl.h`。写文件适配现在只把线程辅助代码注入运行时公共声明头，按当前头文件是否包含符号决定是否跳过；实际存在的变量仍必须唯一替换为线程局部存储。PHPX runtime 通过真实路径匹配后替换为 `plugin/type-build/src/Native/thread-runtime.cc`，避免同名或缓存路径误替换。

## 运行时基础能力

- 保留独立角色进程，进程内业务线程负责并行执行，线程内有界协程处理请求、消息与任务。Swoole 提供原生调度，TypeApp 承接已编译入口、作用域及生命周期；原生 I/O 辅助线程池承担阻塞操作，其容量单独受控。
- 线程数、业务协程并发与数据库连接总额分别约束。每线程拥有独立运行时、容器与连接池，增加线程不能复制部署总连接额度；连接、事务、结果流与可变业务对象保留明确所有者。
- 普通受管子任务通过现有 `ExecutionScope::spawn()` 在线程内创建协程；启动业务线程使用构建期登记的 `CoroutineRuntime::startThread()` 入口，跨线程只传有界数据。业务复用现有组件接口，平台差异集中在原生接入层。
- TypePHP 与 Swoole 共同组成统一底层。网络通信、协程调度及适用的 I/O 能力直接使用 Swoole；三库的等待、会话重置、取消与关闭分别按真实语义验证，开启 hook 不等于完整 I/O 已可协作执行。
- 应用通信与并发必须基于 Swoole；线程、协程入口按实际构建能力与角色职责选择。选择线程与协程路径后缺少必要能力明确失败，各平台分别生成和验证产物。

## 官方内置库

## 已解析属性与可空调用

修正限定于现有 `TypephpCompatibility::threadPropertyRead()`：已解析属性沿用 TypePHP 原生 `.attr` 路径，只有按名称访问才补调用方作用域。没有修改 Swoole、PHPX、MQTT 状态机或引入新适配层。当前 TypePHP 0.9.0 提交与两个属性生成文件的原文摘要门禁已重新核对；原生生成路径足以承担作用域语义后撤除相应适配。

原因位于现有作用域适配对上游返回值的保存：固定 PHPX 的普通赋值与移动构造都会解开间接值。现通过已有 `direct_ptr()`、`Ctor::Indirect` 与 `Ctor::CopyRef` 显式保存属性目标、引用或值，仍由原 Zend 边界恢复作用域并传播异常；没有修改上游库、增加生产文件或另建属性系统。线程消费者验证原对象写回、数组副本隔离和权限拒绝；MQTT 与完整应用分别运行对应回归。

## 应用入口

应用安装 `type-runtime`，开发端安装 `type-build`。在原有构建声明中登记有限的线程入口：

```json
{
  "threads": {
    "worker": "App\\Worker::run"
  }
}
```

入口为完整函数名或静态方法，签名为 `(string $payload): int`。标识最多 64 字节，采用小写字母、数字、点、横线及下划线，首字符为字母；一份应用登记 1–128 个入口。入口与全部生产依赖一起编译，运行时不能指定脚本、闭包或任意 callable。

```php
$thread = Type\Runtime\CoroutineRuntime::startThread('worker', $payload);
$thread->join();
$status = $thread->getExitStatus();
```

返回 Swoole 原有的线程句柄。所有者必须在主请求关闭前显式 `join()`，随后读取 `0–255` 的角色退出码；字符串参数复制到线程自己的请求。当前启动信封采用 JSON，字符串须为有效 UTF-8，编码后上限为 1 MiB。未知入口和超预算分别返回 `compiled_thread_unknown`、`compiled_thread_payload_limit`；缺少编译入口或受控线程模块返回 `compiled_thread_unavailable`。编码失败保留 `JsonException`。

扩展未加载或版本不符时，保留 `swoole_required`、`swoole_incompatible` 错误边界；`compiled_thread_unavailable` 用于仍缺少线程接入能力的情况。构建默认开启官方内置库；显式关闭库的调用者只能使用仍可用的原生接口。

线程应用的 `exit()` 结束当前请求，由 Swoole 或主 embed 边界回收。普通角色建议返回退出码。`join()` 沿用 Swoole 的等待语义；本入口尚未提供任务排空截止、取消传播或强制终止协议，不能把线程句柄当作这些能力的完成证明。

### 有截止的完成等待候选

固定上游 `living` 在线程实际进入前是 false，并在 PHP/TSRM 最后清理之前变为 false；`isAlive()` 不能证明 join 不阻塞。`joinable()` 只说明仍有 join 所有权，`activeCount()` 不能归属某一线程，detach 也不证明资源已回收。原生脚本诊断连续 30 次观察到创建后立即 isAlive=false，收到 Queue 就绪后 join 仍等待约 20–25ms；该诊断不是 AOT 验收。

适配只在现有 PhpThread 句柄保存标准 C++ future，在原生线程执行器完整返回后调用 `promise::set_value_at_thread_exit()`，由标准库在线程局部析构结束后通知。没有新增工作池、线程或扫描器，不依赖上述 living 标记。等待阻塞调用方 OS 线程，不能用于业务协程中的非零等待；由独立主控线程使用。毫秒参数约束完成通知的等待预算，调度及最后的原生 join 不构成硬实时保证，也不代替永久阻塞时的独立故障退出。

线程角色可直接使用官方 `Swoole\Coroutine\run()`，已有 `Swoole\Coroutine::create()` 与 `Swoole\Event::wait()` 仍是原生入口。库按 Swoole 请求初始化加载；调用 run 前明确局部 `hook_flags`，避免它默认补齐 `SWOOLE_HOOK_ALL` 改变既定配置。协程通过 Channel、sleep 或实际异步操作让出时，PHPX 保存各协程的 lexical/fake scope 与调试栈；Native 对象继续由整个请求的根注册表保持可达，协程结束顺序不要求后进先出。

普通 PHP 异常、fatal、exit、shutdown 回调和析构异常可能形成不同的请求退出结果。PHP 8.5 的 fatal 会将 Zend 对象标为已析构，因此不会再执行其用户析构方法；资源可靠清理不能只依赖 `__destruct()`。Native finalizer 的 bailout 由 GC 边界暂时接住，完成剩余对象释放后按请求失败语义收尾。未 join 就关闭主请求会由 Swoole 终止整个进程，不能当作线程局部失败。

### 控制参数与生产监督候选

编译线程入口新增可选第四参数 `Thread\Map`，TypeApp 私有能力为 `TYPEAPP_CONTROL_ARGUMENT_ABI=1`。运行时仅在使用它时检查能力；原两参数及仅带 Socket 的三参数调用不变。Map 固定在子线程 `getArguments()[2]`，无 Socket 时下标 1 是 null；原生 ZendArray/ThreadResource 负责共享数据引用，各线程创建自己的 Zend 对象。当前生产调用者为 `ThreadSupervisor` 与 `SwooleServer::serveThread()`，不经业务源码解释或文件消息通道。

固定上游 `ArrayItem::fetch()` 缺少 `IS_NULL` 分支，`ZendArray::to_array()` 的未初始化局部值沿用了前一项。独立原生脚本线程诊断传入 `['marker', null, Map]`，实际类型为 `string/string/Map`；同一诊断在补齐 `RETVAL_NULL()` 后为 `string/null/Map`。此最小修正归入原六文件线程适配，不改线程参数序列化、共享或回收机制；上游修复 null 还原后撤除该分支补丁。脚本诊断仅证明原生缺口，编译入口另由无源码消费者验证。

监督使用既有停止信号入口、原生 Timer 和 `joinWithin(0)`，拥有启动/进度/停止期限、全部线程句柄以及不可回收时的进程级 `_Exit(75)`，不创建线程池、自动重启器或独立原生扫描线程。部分启动失败也先停止并回收已启动线程，再抛出原错误。每线程只用 Map 的 `stop/ready/pulse/failed` 四个固定标量键；异步 I/O 的真实完成与资源责任仍由原调用者持有。完整 HTTP 契约见[生产线程生命周期](http-native-threads.md#生产线程生命周期)。

## 受控工具链

基础版本为工具链锁中的 PHP 8.5.10 ZTS、TypePHP 0.9.0 与 PHPX 2.9.0。额外适配只写显式的独立源码副本，先核对原文件 SHA-256 和替换次数，不修改已安装的共享 vendor 或 SDK。

- `Type\Build\SwooleThreadSource::apply($directory)` 接受 Swoole 源码 `0f3bee2f0ed8704ce33a336e7feabb0115411dd7`，增加 `Thread::startNative` 与 `NATIVE_ENTRY_ABI=2`。该源码实际报告 Swoole **6.2.1**。中断钩子在进程模块初始化/关闭时安装/恢复，线程不再相互覆盖；关闭回调使用其自身的有效 bailout 边界。原生 embed 线程拥有 `php://stdin/stdout/stderr` 的独立 FD 副本，注册及 RSHUTDOWN 均允许请求关闭这些副本，避免每次重建泄漏三个 FD；主请求、CLI 和脚本入口保留原契约。此修复需要重新适配并编译扩展，旧 ABI 2 模块不会自动获得修复。当前构建使用 `swoole.enable_library=On`，继续保留 `swoole.enable_fiber_mock=On`。
- `Type\Build\PhpxThreadSource::apply($directory)` 接受锁定 PHPX 2.9.0 源码，按线程和协程隔离调试与权限状态，保护 Native finalizer 的堆清理边界。持久字符串保留原有进程寿命，预计算哈希并设为不可变，避免跨线程修改引用计数；`String::offsetSet()` 和 `Variant::setByteOfStr()` 使用 `zend_string_separate()` 取得可写副本，不能使用只按引用计数判断的 `SEPARATE_STRING`。重新编译整份 PHPX；ABI 2、头文件、核心、debug、GC 及字符串源码摘要共同防止混用旧适配。
- `TypephpCompatibility` 使用受审 TypePHP 生成接缝，分离进程主入口与请求初始化；线程应用的主入口直接调用已编译 Zend handler，不使用 `eval`。全局常量存储使用 TLS，类与接口常量使用 Zend 自有的请求常量表；未静态解析的属性读取、nullsafe 读取及数组间接写入携带调用方权限，私有回调在解包后仍保留声明作用域。初始化错误先在编译缓存存活时报告，再按已获得的请求阶段清理。

构建器检查 PHPX 源码与头文件摘要，自动要求 Swoole，使用同一运行配置探测线程方法并启动编译子进程。应用模块显式依赖 Swoole，在正常 MINIT、ZTS 符号发布和 RINIT 收集前注册；工作线程由 Swoole 创建 TSRM 请求并查找已编译入口。主入口只在主线程执行一次。

主入口使用进程标题初始化函数返回的 `argv` 副本，保持完整启动参数；该函数会改写原始参数槽，不能忽略其返回值后继续传递旧指针。

准备源码副本后，可通过安装后的构建包调用适配器，例如：

```sh
php -r 'require "vendor/autoload.php"; echo json_encode((new Type\Build\SwooleThreadSource())->apply($argv[1]), JSON_PRETTY_PRINT), PHP_EOL;' "$task_swoole_source"
php -r 'require "vendor/autoload.php"; echo json_encode((new Type\Build\PhpxThreadSource())->apply($argv[1]), JSON_PRETTY_PRINT), PHP_EOL;' "$task_phpx_source"
```

变量分别指向已经核对的独立源码目录。使用所选 SDK 的 `phpize`、`php-config` 编译 Swoole，使用该 `php-config` 为 PHPX 配置 CMake 并构建 `phpx` 目标。在 macOS/Linux 上，已有 `tools/configure-toolchain.php` 可为新 Swoole 模块建立独立 SDK 视图，保留原 SDK；`PHPX_HOME` 指向重新编译的 PHPX 副本。扩展的真实加载与产物封装继续遵守[运行依赖约定](runtime-profiles.md)。Windows 原生 SDK 接入仍需目标平台验证。

## 验证入口

在主仓根设置明确的 `PHP_HOME`、`PHPX_HOME`，用锁定 ZTS PHP 运行：

```sh
php tests/compiled-threads.php "$task_consumer_directory"
```

参数必须是本仓 `build/` 下尚不存在的专用目录。测试实际安装独立 Composer 消费者，完整编译 `type-runtime`、业务与生成代码；运行时移走应用源码、vendor 和生成源码目录，再对同一产物执行三轮验证。观察两个同时存活的原生线程身份、全局与嵌套数组常量、字符串副本并发写入与键查找、类继承及接口常量、对象默认属性、静态属性、函数符号、异常调试栈、非零返回、线程 `exit`、8 次重建，以及主入口恰好一次。

```sh
php tests/compiled-threads.php "$task_initialization_directory" --initialization-failure
php tests/compiled-threads.php "$task_consumer_directory" --verify
php tests/compiled-threads.php "$task_initialization_directory" --verify --initialization-failure
```

为每轮的 11 个工作线程记录消费者启动代次、进程和原生线程身份；该代次不是底层缓存 epoch。线程对象完成 `join()` 并释放后，外部控制器在 11 个握手检查点观察 FD/句柄、OS 线程和当前 RSS：Linux 使用 `/proc`，macOS 使用 `ps/lsof`，Windows 观察器使用 PowerShell。重建过程中不允许句柄和线程累积；RSS 保存真实差值，不把 allocator 缓存当作必须立即归还的内存，也不据此宣称性能提升。Windows 观察器尚缺真实目标验收。

各角色还持有一个直到请求关闭才释放的对象，析构中读取编译常量并写出完成标记；控制器在 `join()` 后检查标记，覆盖正常返回、非零返回与 `exit()` 的请求清理顺序。

## 线程内资源借用与部署分配

实际覆盖真实 SQLite 读写、同线程协程轮流借用、跨执行者误用和租约传递拒绝；同时存活线程各持有 1 个连接，总占用 2，符合每进程预算。排队、零等待、队列满、作用域取消、Swoole 原生取消、动态缩短截止、父持最后租约等待子任务、凭据轮换和退役均有可失败的行为断言。门闩包装实际 SQLite 资源，将操作退出与物理关闭分开；关闭返回前保持额度，关闭失败保留隔离，确认关闭后才唤醒新池。

项目根复现入口（先设置已核验的受控 `PHP_HOME`、`PHPX_HOME`）：

```sh
task_dir="$(mktemp -d build/io-resources.XXXXXX)"
php tests/compiled-threads.php "$PWD/$task_dir/consumer" --resources
```

## 原生通知与线程 hook 清理

按 [Swoole 复用标准](../standards/swoole-reuse.md)移除 `ManagedTask` 收尾时的 1ms 轮询。`ExecutionScope` 仅在延期收尾时创建原生 Channel，最后一个后代的完成回调关闭通道；父任务保持占额直到整棵子树退出。清理超时、原生取消、先完成再等待、重复观察错误和重复关闭均通过实际编译消费者验证。三次同功能 PHP 辅助对照中，三层任务等待时的额外定时器由 2 个降为 0 个，释放前额度均为 3，释放后均归零；这不是端到端吞吐或延迟结论。

通用线程启动已与未完成的文件候选分开：默认源码准备仅涉及原有六个线程文件，普通线程不要求文件私有 ABI。主线程统一安装 hook，保留用户已有 UDP 等标志，子线程直接复用；禁止的配置变更在原生调用前拒绝。原生运行发现子线程局部选项不能代表进程已安装 hook，现通过实际 `usleep()` 让出验证复用效果，没有为此新增 C++ 配置补丁。文件候选改由显式入口组合准备，产出的十五个原生文件与原候选逐字节一致；内部监督等机制仍待收缩。
