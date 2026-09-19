# Swoole 文件 I/O 接入

## 直接复用与必要适配

文件候选仅由显式 `SwooleIoSource::apply($directory)` 入口准备：先核验文件原文 SHA-256，再组合 `SwooleThreadSource` 的线程适配与文件精确替换。默认 `SwooleThreadSource::apply()` 只准备六个线程相关源文件，不依赖文件候选。两者都接受固定的原始源码副本，不能在已经打过补丁的目录上重复应用。`SwooleIoSource` 仍是未放行的构建候选；继续审查其监督、回收和计量补丁，不能由独立消费者通过推定每个分支都已验证。

## 入口与配置

`CoroutineRuntime::startThread()` 在启动业务线程前调用 `enableIo()`；Swoole HTTP 入口也调用同一方法。通用入口只加入 TCP、SLEEP、STREAM_FUNCTION 并保留既有 mask，不要求文件私有 ABI。文件消费者在主线程显式加入原生 `SWOOLE_HOOK_FILE`，子线程直接复用已安装的 hook，不重复调用 `enableIo()`。只有进入协程的适用操作才会让出，同步入口不会自动创建协程。

当前文件消费者要求 `SWOOLE_FILE_IO_ABI=2`，在传入私有配置前检查并于缺失时抛出 `swoole_file_io_unavailable`。候选不依赖额外原生监督线程。当前标志仅在 macOS 候选中注册；其他平台不能使用不匹配模块假装满足文件约束。`enableIo()` 对子线程调用和存在存活业务线程时的配置变更抛出 `swoole_hook_startup_required`，在调用原生配置方法前拒绝，避免失败仍改变线程局部选项。

选择文件候选 SDK 后，生产 `ThreadSupervisor::run()` 在创建业务线程之前调用私有 `Coroutine::typeappSuperviseIo()`，将原生 `AsyncThreads` 接收者附着到独立主控 reactor。既有 Timer 随后检查最早未结算操作的硬截止，原身份表改为按递增提交编号排序，无第二份期限队列或全量周期扫描；未附着的候选 AIO 提交报 `aio_supervisor_required`。业务 reactor 不再覆盖默认回收目标，原 `release_callback` 和完成通道仍执行空闲回收，主控在所有真实 join 后才退出事件循环。心跳只证明业务线程可以前进，不能替代这个操作期限。

原生线程池配置使用现有 `swoole_async_set()`，必须在第一次 AIO 或业务线程启动前配置。以下三项是受控适配提供的键，**不是上游 Swoole 标准配置**：

| 配置 | 候选默认值 | 约束 |
| --- | --- | --- |
| `aio_max_pending` | 128 | 8–65536；涵盖排队、执行和待结算完成项。 |
| `aio_max_bytes` | 16777216 | 2 MiB–1 GiB；当前计量范围见下文。 |
| `aio_max_task_time` | 60 秒 | 0.1–300 秒；从原生接受提交到结算的硬截止。 |

清理预留为 `min(16, pending / 4)` 项及对应固定费用。普通提交超限抛出 `Swoole\Exception('aio_capacity_exceeded')`；错误不能转成“文件不存在”。通知失败、带活动任务销毁完成所有者或清理预留耗尽会终止宿主，避免释放仍被原生线程引用的资源。以上默认值是候选预算，尚无最优性能结论。

## 文件函数适用边界

元数据适配复用 PHP 的 `CWD_REALPATH` / `CWD_EXPAND`，保留 stat/lstat 不同的路径展开规则。独立 AOT 消费者核对符号链接与父目录组合、最终符号链接和 `fopen('missing/../file')` 的原行为。

FILE hook 不涵盖 PHP 的所有文件函数。PHP 8.5 对普通本地路径的 `file_exists()`、`is_writable()`、`realpath()`、`touch()`、`chmod()` 会走部分直接调用。标准 `file://` 路径可让 touch/chmod 进入原有 stream metadata 入口；不能据此声称权限查询或路径解析也已经异步化。

路径解析、open_basedir 和用户名/组名解析继续遵守 PHP 原语义，其中可能存在同步 I/O。应用初始化可在开始接收工作前完成确定的目录/权限检查；动态请求路径仍需逐调用者补齐测量和适用处理，不能靠替换整个 PHP 函数实现掩盖差距。

## 验收与未完成项

项目根使用已经核验的 `PHP_HOME`、`PHPX_HOME` 运行。命令中的 `php` 必须是锁定 SDK 的 PHP 8.5.10 ZTS；只设置 `PHP_HOME` 而继续使用版本不符的系统 PHP，会在编译前被拒绝：

```sh
task_dir="$(mktemp -d build/io-files.XXXXXX)"
php tests/compiled-threads.php "$PWD/$task_dir/consumer" --files
```

消费者实际安装 type-core、type-runtime 和四个 PSR 生产包，完整 AOT 后移走 app、vendor、编译源码再运行。已有产物可加 `--verify`；必须等待整个构建进程退出成功，不能只凭日志中的 `Build successful` 提前移动输入。

测试场景包括双业务线程 8 MiB 分块文件读写、metadata、flush/fsync/truncate/close、上传提交与删除、锁竞争、迟到完成、字节拒绝和线程重建。容量场景包含阻塞原生提交、metadata 拒绝、预留 close、完成归还及原有空闲回收配置，并检查永久阻塞 FIFO 和未 join 退出。候选仍需完成受控适配审查、目标平台及同一产物的完整验收；测试入口存在不表示当前发布源码已通过全部场景。

## HTTP 容量边界与锁路径核对

显式 FILE 候选的容量拒绝由所属 PHP 线程抛出 `Swoole\Exception('aio_capacity_exceeded')`。HTTP 服务、应用 ApiErrors 及响应流发送复用 CapacityException 的统一判定，将符合约定的原生拒绝归入容量错误。

服务分派、应用中间件和响应首块发送前的容量拒绝均返回 503、`resource_capacity_exceeded` 与 `Retry-After: 1`；HEAD 不返回响应体。已发送首块后只关闭连接，不拼接第二份响应或写出完整结束标记。只有框架容量异常，或类型和消息同时符合文件候选约定的原生异常，才按容量处理；任意 RuntimeException 的同名消息、其他 Swoole 异常仍保留普通 500 语义。响应不泄漏内部错误详情，应用请求关联继续保留。

```sh
task_dir="$(mktemp -d build/io-http-capacity.XXXXXX)"
php tests/io-capacity-http.php "$PWD/$task_dir/consumer"
```

## 整文件缓冲预算与 hook 清理

固定 Swoole 的线程数、扩容等待及空闲回收配置均不约束 String 分配；PHP 侧只限制并发数量也无法覆盖未知长度文件的真实增长。当前沿用 System::readFile() 的 open、锁、fstat、读取和释放顺序，将 Swoole String 的 Allocator 传入 File 读取入口，共享 ThreadPool 的字节准入与归还。

每个缓冲按原生 capacity、同等容量的 PHP 返回字符串拷贝预留及记账费用计量。扩容时先保留旧额度，再预留新额度，原生 realloc 成功后才归还旧额度；分配失败或预算拒绝保留旧缓冲，由正常析构释放。线程池引用随缓冲持有，原生工作线程只记录错误，String/File 完成收尾后由所属 PHP 线程报告 aio_capacity_exceeded。这是保守的在途容量预留，不是 RSS 上限，也不限制应用在读取返回后保留或复制的任意 PHP 字符串；大文件生产消费仍应使用已有分块流。

主线程在启动业务线程前配置，子线程直接使用上游已安装的 hook，不镜像原生配置。文件适配核验 12 个固定上游文件，与线程适配组合后共 17 个；默认线程准备器不组合文件候选，FILE 私有 ABI 只在显式消费者检查。

## 作用域失败收尾

ExecutionScope 仅移除停止成功的资源，单独保护关闭重入，失败资源继续保持 closing；同一所有者可显式再次收尾，迟到后代完成不能越过它。ManagedTask 原生 Channel 和 HttpControl 提供隔离与监督入口。WorkLifecycle 保留未清理作用域，队列不提前确认或重投，调度保留未完成清理的记录并停止本角色后续计划。
