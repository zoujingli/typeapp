<?php

declare(strict_types=1);

use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionOwner;

const THREAD_GLOBAL = ['value' => 'global', 'nested' => [3, 5]];
const THREAD_TEXT = 'thread-string';

enum ThreadPhase: string
{
    case Ready = 'ready';
}

interface ThreadConstants
{
    public const CONTRACT = ['interface' => 11];
}

class ThreadParent implements ThreadConstants
{
    public const DATA = ['parent' => [13, 17]];
    public const PHASE = ThreadPhase::Ready;
}

final class ThreadCleanup
{
    public function __construct(public string $path)
    {
    }

    public function __destruct()
    {
        ThreadProbe::check(THREAD_TEXT === 'thread-string' && constant('ThreadProbe::DATA') === ['parent' => [13, 17]], '请求析构前编译状态已被清理');
        file_put_contents($this->path, 'cleaned');
    }
}

/** 只以原生分配触发 GC，哨兵释放次数由所属请求的全局计数记录。 */
#[Native]
class ThreadNativeValue
{
    public int $kind;
    public string $value;

    /** kind 为 1、2 时代表交错协程，3 代表异常越过的栈帧。 */
    public function __construct(int $kind)
    {
        $this->kind = $kind;
        $this->value = 'native:' . $kind;
    }

    /** 析构结果不越过线程传递，只在线程结束后写入公开报告。 */
    public function __destruct()
    {
        global $threadNativeGarbage, $threadNativeLeft, $threadNativeRight, $threadNativeThrown;
        if ($this->kind === 1) {
            ++$threadNativeLeft;
        } elseif ($this->kind === 2) {
            ++$threadNativeRight;
        } elseif ($this->kind === 3) {
            ++$threadNativeThrown;
        } else {
            ++$threadNativeGarbage;
        }
    }
}

/** 堆关闭时一个 Native finalizer 的 bailout 不得阻止其余对象释放。 */
#[Native]
class ThreadNativeCleanup
{
    public string $path;
    public bool $fail;

    public function __construct(string $path, bool $fail)
    {
        $this->path = $path;
        $this->fail = $fail;
    }

    public function __destruct()
    {
        file_put_contents($this->path, $this->fail ? "failed\n" : "survived\n", FILE_APPEND | LOCK_EX);
        if ($this->fail) {
            trigger_error('thread:native-finalizer', E_USER_ERROR);
        }
    }
}

/** 两个协程共用调度器，但每次真实挂起都由独立 Channel 明确释放。 */
final class ThreadCoroutineFixture
{
    public static ?Swoole\Coroutine\Channel $ready = null;
    public static ?Swoole\Coroutine\Channel $left = null;
    public static ?Swoole\Coroutine\Channel $right = null;
    public static ?Swoole\Coroutine\Channel $done = null;
    public static array $events = [];
    public static array $results = [];
    public static array $ids = [];

    /** 同一阶段必须观察到左右均已挂起，才会恢复任意一方。 */
    public static function pause(string $side, string $stage): void
    {
        $owner = new ExecutionOwner();
        $event = $side . ':' . $stage;
        self::$events[] = $event . ':suspend';
        ThreadProbe::check(self::$ready->push($event, 5), '协程就绪通知超时');
        $gate = $side === 'left' ? self::$left : self::$right;
        ThreadProbe::check($gate->pop(10) === $event, '协程恢复令牌不符');
        $owner->assertCurrent();
        ThreadProbe::check(Swoole\Coroutine::getCid() === self::$ids[$side], '恢复后协程身份改变');
        self::$events[] = $event . ':resume';
    }

    /** 官方阈值用例采用 110 万次分配；析构计数证明实际发生了 Native GC。 */
    public static function collect(): void
    {
        global $threadNativeGarbage;
        $before = $threadNativeGarbage;
        for ($index = 0; $index < 1100000; ++$index) {
            $garbage = new ThreadNativeValue(0);
        }
        $garbage = null;
        ThreadProbe::check($threadNativeGarbage > $before, '原生分配未触发真实 GC');
    }

    /** 同一线程运行完整交错序列，写入可由外部消费者核对的结果。 */
    public static function run(string $directory, string $role): void
    {
        global $threadNativeGarbage, $threadNativeLeft, $threadNativeRight, $threadNativeThrown;
        $threadNativeGarbage = 0;
        $threadNativeLeft = 0;
        $threadNativeRight = 0;
        $threadNativeThrown = 0;
        self::$events = [];
        self::$results = [];
        self::$ids = [];
        ThreadProbe::check(Swoole\Coroutine\run(static function (): void {
            self::$ready = new Swoole\Coroutine\Channel(8);
            self::$left = new Swoole\Coroutine\Channel(1);
            self::$right = new Swoole\Coroutine\Channel(1);
            self::$done = new Swoole\Coroutine\Channel(2);
            Swoole\Coroutine::create(static function (): void {
                self::$ids['left'] = Swoole\Coroutine::getCid();
                self::$results['left'] = (new ThreadCoroutineLeft())->run();
                ThreadProbe::check(self::$done->push('left', 5), '左协程结束通知失败');
            });
            Swoole\Coroutine::create(static function (): void {
                self::$ids['right'] = Swoole\Coroutine::getCid();
                self::$results['right'] = (new ThreadCoroutineRight())->run();
                ThreadProbe::check(self::$done->push('right', 5), '右协程结束通知失败');
            });
            foreach (['hook', 'magic', 'callback'] as $stage) {
                ThreadProbe::check(self::$ready->pop(10) === 'left:' . $stage, '左协程未按预定阶段挂起');
                ThreadProbe::check(self::$ready->pop(10) === 'right:' . $stage, '右协程未按预定阶段挂起');
                ThreadProbe::check(self::$left->push('left:' . $stage, 10), '左协程未恢复');
                if ($stage === 'callback') {
                    ThreadProbe::check(self::$done->pop(10) === 'left', '较早创建的原生根未先结束');
                }
                ThreadProbe::check(self::$right->push('right:' . $stage, 10), '右协程未恢复');
            }
            ThreadProbe::check(self::$done->pop(10) === 'right', '较晚创建的原生根未结束');
            ThreadProbe::check(self::$ids['left'] !== self::$ids['right'], '两个任务使用了同一协程');
            self::$ready->close();
            self::$left->close();
            self::$right->close();
            self::$done->close();
        }), '官方协程入口未完成调度');
        self::collect();
        ThreadProbe::check($threadNativeLeft === 1 && $threadNativeRight === 1 && $threadNativeThrown === 1, '原生哨兵没有各释放一次');
        ThreadProbe::check(Swoole\Coroutine::stats()['coroutine_num'] === 0, '调度退出后仍有存活协程');
        $report = ['role' => $role, 'generation' => ThreadProbe::$generation, 'process-id' => getmypid(),
            'native-id' => Swoole\Thread::getNativeId(), 'ids' => self::$ids,
            'events' => self::$events, 'results' => self::$results,
            'native-gc' => ['automatic' => true, 'garbage-finalized' => $threadNativeGarbage,
                'left-finalized' => $threadNativeLeft, 'right-finalized' => $threadNativeRight, 'exception-finalized' => $threadNativeThrown,
                'non-lifo' => true], 'remaining-coroutines' => 0, 'source-free' => get_included_files() === []];
        file_put_contents($directory . '/' . $role . '.coroutines.json', json_encode($report, JSON_THROW_ON_ERROR));
        self::$ready = null;
        self::$left = null;
        self::$right = null;
        self::$done = null;
    }
}

/** 左协程在自身类作用域中读取私有值，并在回调中先于右协程返回。 */
final class ThreadCoroutineLeft
{
    private string $secret = 'left';
    private array $items = [];
    public readonly string $identity;
    public private(set) string $owned = 'left-owned';
    public string $hook {
        get {
            ThreadCoroutineFixture::pause('left', 'hook');
            return $this->secret;
        }
    }

    /** 只允许声明类初始化 readonly 身份。 */
    public function __construct()
    {
        $this->identity = 'left';
    }

    /** 魔术访问本身可挂起；未知或私有字段不得借此获得访问权限。 */
    public function __get(string $name): string
    {
        if ($name !== 'missing') {
            throw new Error('private:left');
        }
        ThreadCoroutineFixture::pause('left', 'magic');
        return $this->secret . ':magic';
    }

    private function nested(int $value): string
    {
        return $this->secret . ':' . $value;
    }

    private function callback(int $value): string
    {
        ThreadCoroutineFixture::pause('left', 'callback');
        threadScopeDenied($this);
        return (string) array_map([$this, 'nested'], [$value])[0];
    }

    /** 异常越过持有原生根的挂起栈帧，随后由另一协程触发回收。 */
    private static function escapeNative(): void
    {
        $live = new ThreadNativeValue(3);
        Swoole\Coroutine::sleep(0.001);
        ThreadProbe::check($live->value === 'native:3', '异常路径挂起根丢失');
        throw new RuntimeException('native:unwind');
    }

    /** 活原生对象贯穿三次挂起；结束前 GC 不能回收左右任一哨兵。 */
    public function run(): array
    {
        global $threadNativeLeft, $threadNativeRight;
        $live = new ThreadNativeValue(1);
        $hook = $this->hook;
        $magic = $this->missing;
        $arguments = [[$this, 'callback'], 7];
        $callback = call_user_func(...$arguments);
        threadScopeDenied($this);
        ThreadProbe::check($hook === 'left' && $magic === 'left:magic' && $callback === 'left:7'
            && $this->identity === 'left' && $this->owned === 'left-owned', '左协程类作用域被污染');
        $trace = '';
        try {
            throw new RuntimeException('left-trace');
        } catch (RuntimeException $failure) {
            $trace = $failure->getTraceAsString();
            ThreadProbe::check(str_contains($trace, 'ThreadCoroutineLeft::run(')
                && !str_contains($trace, 'ThreadCoroutineRight::'), '左协程异常栈混入了右协程');
        }
        ThreadCoroutineFixture::collect();
        ThreadProbe::check($threadNativeLeft === 0 && $threadNativeRight === 0 && $live->value === 'native:1', '挂起协程的活原生对象被回收');
        try {
            self::escapeNative();
        } catch (RuntimeException $unwindFailure) {
            ThreadProbe::check($unwindFailure->getMessage() === 'native:unwind', '原生根异常传播失败');
        }
        return ['scope' => true, 'private-denied' => true, 'readonly-denied' => true,
            'private-set-denied' => true, 'nested-unpacked-callback' => $callback, 'trace' => $trace,
            'suspended-roots-alive' => true];
    }
}

/** 右协程以不同声明类交错调用，验证左协程退出不会损坏较新的根。 */
final class ThreadCoroutineRight
{
    private string $secret = 'right';
    private array $items = [];
    public readonly string $identity;
    public private(set) string $owned = 'right-owned';
    public string $hook {
        get {
            ThreadCoroutineFixture::pause('right', 'hook');
            return $this->secret;
        }
    }

    /** 只允许声明类初始化 readonly 身份。 */
    public function __construct()
    {
        $this->identity = 'right';
    }

    /** 挂起魔术访问与另一个声明类的回调保持独立。 */
    public function __get(string $name): string
    {
        if ($name !== 'missing') {
            throw new Error('private:right');
        }
        ThreadCoroutineFixture::pause('right', 'magic');
        return $this->secret . ':magic';
    }

    private function nested(int $value): string
    {
        return $this->secret . ':' . $value;
    }

    private function callback(int $value): string
    {
        ThreadCoroutineFixture::pause('right', 'callback');
        threadScopeDenied($this);
        return (string) array_map([$this, 'nested'], [$value])[0];
    }

    /** 恢复时左协程已返回；其根应回收，而当前根仍可访问。 */
    public function run(): array
    {
        global $threadNativeLeft, $threadNativeRight;
        $live = new ThreadNativeValue(2);
        $hook = $this->hook;
        $magic = $this->missing;
        $arguments = [[$this, 'callback'], 9];
        $callback = call_user_func(...$arguments);
        threadScopeDenied($this);
        ThreadProbe::check($hook === 'right' && $magic === 'right:magic' && $callback === 'right:9'
            && $this->identity === 'right' && $this->owned === 'right-owned', '右协程类作用域被污染');
        $trace = '';
        try {
            throw new RuntimeException('right-trace');
        } catch (RuntimeException $failure) {
            $trace = $failure->getTraceAsString();
            ThreadProbe::check(str_contains($trace, 'ThreadCoroutineRight::run(')
                && !str_contains($trace, 'ThreadCoroutineLeft::'), '右协程异常栈混入了左协程');
        }
        ThreadCoroutineFixture::collect();
        ThreadProbe::check($threadNativeLeft === 1 && $threadNativeRight === 0 && $live->value === 'native:2', '非栈序根摘除损坏了存活原生对象');
        return ['scope' => true, 'private-denied' => true, 'readonly-denied' => true,
            'private-set-denied' => true, 'nested-unpacked-callback' => $callback, 'trace' => $trace,
            'older-root-released' => true, 'newer-root-alive' => true];
    }
}

/** 外部作用域必须拒绝私有方法/属性以及 readonly 和 private(set) 写入。 */
function threadScopeDenied(object $target): void
{
    $denied = 0;
    $method = 'nested';
    try {
        $target->$method(1);
    } catch (Error $privateMethodFailure) {
        ++$denied;
    }
    $property = 'secret';
    try {
        $unused = $target->$property;
    } catch (Error $privatePropertyFailure) {
        ++$denied;
    }
    try {
        $nullsafe = $target?->$property;
    } catch (Error $nullsafePropertyFailure) {
        ++$denied;
    }
    try {
        $target->items[] = 'forbidden';
    } catch (Error $privateAppendFailure) {
        ++$denied;
    }
    $items = 'items';
    try {
        $target->$items[0] = 'forbidden';
    } catch (Error $privateArrayWriteFailure) {
        ++$denied;
    }
    $readonly = 'identity';
    try {
        $target->$readonly = 'forbidden';
    } catch (Error $readonlyFailure) {
        ++$denied;
    }
    $privateSet = 'owned';
    try {
        $target->$privateSet = 'forbidden';
    } catch (Error $privateSetFailure) {
        ++$denied;
    }
    ThreadProbe::check($denied === 7, '外部作用域拒绝数量不符：' . (string) $denied);
}

/** 请求析构异常用公开 __destruct 行为观察，marker 记录调用次数。 */
final class ThreadFailureCleanup
{
    /** 保存本次验收目录内的标记位置。 */
    public function __construct(public string $path)
    {
    }

    /** 先记录再抛出，以区分未运行、重复运行与异常退出。 */
    public function __destruct()
    {
        file_put_contents($this->path, "destructor\n", FILE_APPEND | LOCK_EX);
        throw new RuntimeException('thread:destructor');
    }
}

/** 关闭回调仍需读取编译类常量；可选异常由线程宿主报告退出状态。 */
function threadShutdownProbe(string $path, string $failure): void
{
    ThreadProbe::check(THREAD_TEXT === 'thread-string' && ThreadProbe::OWN === ['child' => 19], 'shutdown 前编译状态已清理');
    file_put_contents($path, "shutdown\n", FILE_APPEND | LOCK_EX);
    if ($failure === 'bailout') {
        swoole_implicit_fn('bailout', 95);
    }
    if ($failure === 'throw') {
        throw new RuntimeException('thread:shutdown');
    }
}

final class ThreadProbe extends ThreadParent
{
    public const OWN = ['child' => 19];
    public array $defaults = ['instance' => 23];
    public static array $state = ['initial'];
    /** 消费者分配的启动代次；不等同于底层池或请求缓存的 epoch。 */
    public static int $generation = 0;
    public static ?ThreadCleanup $cleanup = null;
    public static ?ThreadFailureCleanup $failureCleanup = null;

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** 普通同步限 15 秒；显式资源观察可覆盖控制器的整轮 60 秒预算。 */
    public static function waitFor(string $file, bool $exercise = false, float $seconds = 15.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (!is_file($file)) {
            self::check(microtime(true) < $deadline, '线程同步超时：' . $file);
            if ($exercise) {
                self::inspectStrings();
            }
            usleep(1000);
            clearstatcache(true, $file);
        }
    }

    public static function inspectStrings(): void
    {
        for ($index = 0; $index < 128; ++$index) {
            $copy = THREAD_TEXT;
            $copy[0] = 'T';
            $copy .= ':copy';
            self::check($copy === 'Thread-string:copy', '线程字符串副本写入失败');
            self::check(THREAD_TEXT === 'thread-string', '线程修改副本污染了共享字符串');
            $literal = 'shared-thread-literal';
            $lookup = [$literal => $index];
            $literal[0] = 'S';
            self::check($lookup['shared-thread-literal'] === $index && $literal === 'Shared-thread-literal', '字符串键或写入隔离失败');
        }
    }

    /** 可空调用的参数仍须读取准确的静态属性，不能把属性偏移当作名称。 */
    public static function nullableSnapshot(?ThreadProbe $receiver, ThreadProbe $source): ?array
    {
        return $receiver?->snapshot($source->defaults);
    }

    /** 回传真实参数，以调用结果观察编译后属性访问。 */
    public function snapshot(array $values): array
    {
        return $values;
    }

    /** 未静态确定类型的接收者仍须写回真实属性；对应消息确认前的嵌套状态更新。 */
    public static function writeSnapshot(object $target): void
    {
        $target->defaults['exchange'] = ['state' => 'reserved', 'responses' => 1];
        $target->defaults['exchange']['state'] = 'sent';
        $target->defaults['exchange']['responses']++;
        unset($target->defaults['instance']);
    }

    public static function inspect(string $role): array
    {
        self::inspectStrings();
        $library = new ReflectionFunction('Swoole\\Coroutine\\run');
        self::check(!$library->isInternal() && $library->getFileName() === '@swoole/library/core/Coroutine/functions.php'
            && (new ReflectionFunction('type_app_compiled_thread_run'))->isInternal(), '官方 PHP 库或编译线程入口来源不符');
        $instance = new ThreadProbe();
        self::check(self::nullableSnapshot($instance, $instance) === ['instance' => 23], '可空调用把静态属性参数解析成了错误名称');
        self::check(self::nullableSnapshot(null, $instance) === null, '可空调用没有短路');
        $mutable = new ThreadProbe();
        $before = $mutable->defaults;
        self::writeSnapshot($mutable);
        self::check($mutable->defaults === ['exchange' => ['state' => 'sent', 'responses' => 2]], '动态属性间接写入没有更新原对象');
        self::check($before === ['instance' => 23], '属性写入污染了既有数组副本');
        self::check(THREAD_GLOBAL === ['value' => 'global', 'nested' => [3, 5]], '全局数组常量被污染');
        self::check(THREAD_TEXT === 'thread-string', '全局字符串常量被污染');
        self::check(self::DATA === ['parent' => [13, 17]] && self::OWN === ['child' => 19], '编译类常量被污染');
        self::check(constant('ThreadProbe::DATA') === self::DATA, 'Zend 继承常量表被污染');
        self::check(constant('ThreadProbe::OWN') === self::OWN, 'Zend 自有常量表被污染');
        self::check(constant('ThreadProbe::CONTRACT') === ['interface' => 11], '接口常量表被污染');
        self::check(self::PHASE === ThreadPhase::Ready && constant('ThreadProbe::PHASE') === ThreadPhase::Ready, '枚举 AST 常量未保留当前请求的 case 身份');
        self::check($instance->defaults === ['instance' => 23], '实例默认数组被污染');
        self::check(self::$state === [$role], '线程静态属性被其他线程改变');
        self::check(threadProbeFunction($role) === $role . ':function', '线程函数符号不可用');
        self::check(get_included_files() === [], '业务线程加载了 PHP 源码');
        try {
            throw new RuntimeException('role:' . $role);
        } catch (RuntimeException $failure) {
            self::check($failure->getMessage() === 'role:' . $role, '线程异常内容被污染');
            $trace = $failure->getTraceAsString();
            self::check(str_contains($trace, 'ThreadProbe::inspect('), '线程调试栈缺少当前业务入口');
            self::check(!str_contains($trace, 'main('), '线程调试栈混入了主入口');
        }
        return ['role' => $role, 'generation' => self::$generation, 'process-id' => getmypid(),
            'native-id' => Swoole\Thread::getNativeId(), 'state' => self::$state,
            'constant-text' => THREAD_TEXT, 'literal-text' => 'shared-thread-literal',
            'official-library' => $library->getFileName(), 'compiled-entry' => true];
    }

    public static function run(string $payload): int
    {
        $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        $directory = (string) $data[0];
        $role = (string) $data[1];
        self::check(count($data) === 3 && (int) $data[2] > 0, '线程缺少有效的消费者启动代次');
        self::check(self::$state === ['initial'] && self::$generation === 0, '新线程继承了其他线程的静态属性或代次');
        self::$generation = (int) $data[2];
        self::$state = [$role];
        // 官方 run 默认会补 HOOK_ALL；本消费者沿用主线程已安装的实际钩子。
        Swoole\Coroutine::set(['hook_flags' => Swoole\Runtime::getHookFlags()]);
        self::check(defined('SWOOLE_LIBRARY') && SWOOLE_LIBRARY
            && function_exists('Swoole\\Coroutine\\run'), '线程初始化未加载官方 Swoole 库');
        self::check(Swoole\Coroutine\run(static function (): void {
            self::inspectStrings();
        }), '重建线程无法执行官方协程入口');
        self::$cleanup = new ThreadCleanup($directory . '/' . $role . '.cleanup');
        $first = self::inspect($role);
        file_put_contents($directory . '/' . $role . '.ready', json_encode($first, JSON_THROW_ON_ERROR));
        if ($role === 'linger') {
            sleep(10);
            return 0;
        }
        if (str_starts_with($role, 'failure-')) {
            $shutdownFailure = $role === 'failure-shutdown' ? 'throw' : ($role === 'failure-shutdown-bailout' ? 'bailout' : '');
            register_shutdown_function('threadShutdownProbe', $directory . '/' . $role . '.shutdown', $shutdownFailure);
            if ($role === 'failure-native-finalizer') {
                global $nativeCleanupSurvivor, $nativeCleanupFailure;
                $nativeCleanupSurvivor = new ThreadNativeCleanup($directory . '/' . $role . '.native', false);
                $nativeCleanupFailure = new ThreadNativeCleanup($directory . '/' . $role . '.native', true);
                return 0;
            }
            if ($role === 'failure-destructor') {
                // Zend 对象槽可复用，析构顺序不等于创建顺序；先显式完成所属清理，再注入析构故障。
                self::$cleanup = null;
                self::$failureCleanup = new ThreadFailureCleanup($directory . '/' . $role . '.destructor');
                return 0;
            }
            if ($role === 'failure-throw') {
                throw new RuntimeException('thread:uncaught');
            }
            if ($role === 'failure-fatal') {
                trigger_error('thread:fatal', E_USER_ERROR);
            }
            if ($role === 'failure-exit') {
                exit(17);
            }
            return 0;
        }
        if ($role === 'a' || $role === 'b') {
            self::waitFor($directory . '/' . ($role === 'a' ? 'b' : 'a') . '.ready');
            ThreadCoroutineFixture::run($directory, $role);
            register_shutdown_function('threadShutdownProbe', $directory . '/' . $role . '.shutdown', '');
        }
        if ($role === 'b') {
            // 原子发布，确保控制器采样时协程报告的写句柄已经关闭。
            self::check(file_put_contents($directory . '/b.waiting.pending', 'ready') === 5
                && rename($directory . '/b.waiting.pending', $directory . '/b.waiting'), '无法发布存活线程等待状态');
            self::waitFor($directory . '/release-b', true, is_file($directory . '/observe-resources') ? 60.0 : 15.0);
        } else {
            self::waitFor($directory . '/b.ready');
        }
        threadStdioProbe($role);
        $last = self::inspect($role);
        file_put_contents($directory . '/' . $role . '.json', json_encode($last, JSON_THROW_ON_ERROR));
        if ($role === 'exit') {
            exit(9);
        }
        return $role === 'a' ? 7 : 0;
    }
}

function threadProbeFunction(string $role): string
{
    return $role . ':function';
}

/** 主入口的前置检查不触碰角色类，保证子线程独立完成其首次符号访问。 */
function threadMainCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 每次线程关闭后，存活角色及主请求仍能访问各自标准流；控制器提供空输入。 */
function threadStdioProbe(string $role): void
{
    threadMainCheck(fread(STDIN, 1) === '' && feof(STDIN), '标准输入副本已失效：' . $role);
    $message = 'stdio:' . $role . "\n";
    threadMainCheck(fwrite(STDOUT, $message) === strlen($message) && fflush(STDOUT), '标准输出副本已失效：' . $role);
    threadMainCheck(fwrite(STDERR, $message) === strlen($message) && fflush(STDERR), '标准错误副本已失效：' . $role);
}

/** 只有外部控制器显式启用观察时才等待采样，不预热线程角色类。 */
function threadResourceCheckpoint(string $directory, string $phase): void
{
    if (!is_file($directory . '/observe-resources')) {
        return;
    }
    $ready = $directory . '/resource-' . $phase . '.ready';
    $release = $directory . '/resource-' . $phase . '.release';
    $pending = $ready . '.pending';
    threadMainCheck(!is_file($ready) && !is_file($release) && !is_file($pending), '资源检查点已存在：' . $phase);
    $message = json_encode(['phase' => $phase, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
    threadMainCheck(file_put_contents($pending, $message) === strlen($message) && rename($pending, $ready), '无法发布资源检查点');
    $deadline = microtime(true) + 15;
    while (!is_file($release)) {
        threadMainCheck(microtime(true) < $deadline, '外部资源采样超时：' . $phase);
        usleep(1000);
        clearstatcache(true, $release);
    }
}

function main(int $argc, array $argv): void
{
    threadMainCheck(($argc === 2 || $argc === 3) && count($argv) === $argc && is_dir((string) $argv[1]), '编译主入口未收到完整启动参数');
    $directory = (string) $argv[1];
    file_put_contents($directory . '/main-count', "main\n", FILE_APPEND | LOCK_EX);
    if ($argc === 3) {
        $mode = (string) $argv[2];
        if ($mode === 'scope-only') {
            // 独立进程验证未交错时的权限边界，不预热线程场景的角色类。
            threadScopeDenied(new ThreadCoroutineLeft());
            threadScopeDenied(new ThreadCoroutineRight());
            echo json_encode(['scope' => true, 'source-free' => get_included_files() === []], JSON_THROW_ON_ERROR), "\n";
            return;
        }
        if ($mode === 'failure-unjoined') {
            $detached = CoroutineRuntime::startThread('probe', json_encode([$directory, 'linger', 1], JSON_THROW_ON_ERROR));
            ThreadProbe::waitFor($directory . '/linger.ready');
            $detached->detach();
            return;
        }
        threadMainCheck(in_array($mode, ['failure-throw', 'failure-fatal', 'failure-exit', 'failure-shutdown', 'failure-destructor', 'failure-shutdown-bailout', 'failure-native-finalizer'], true), '未知线程故障角色');
        $failing = CoroutineRuntime::startThread('probe', json_encode([$directory, $mode, 1], JSON_THROW_ON_ERROR));
        threadMainCheck($failing->joinWithin(5000), '故障业务线程未在等待期限内完成 join');
        threadMainCheck(Swoole\Thread::activeCount() === 1, '故障结束后仍遗留业务线程');
        echo json_encode(['mode' => $mode, 'thread-exit' => $failing->getExitStatus(),
            'active-threads' => 1, 'source-free' => get_included_files() === []], JSON_THROW_ON_ERROR), "\n";
        return;
    }
    $rejected = 0;
    try {
        CoroutineRuntime::startThread('unknown', '');
    } catch (Type\Runtime\TaskException $error) {
        threadMainCheck($error->errorCode() === 'compiled_thread_unknown', '未知入口错误码不符');
        ++$rejected;
    }
    try {
        CoroutineRuntime::startThread('probe', str_repeat('x', 1048576));
    } catch (Type\Runtime\TaskException $error) {
        threadMainCheck($error->errorCode() === 'compiled_thread_payload_limit', '启动数据预算错误码不符');
        ++$rejected;
    }
    threadMainCheck($rejected === 2, '未拒绝未知入口或超预算消息');
    threadResourceCheckpoint($directory, 'cold');
    $b = CoroutineRuntime::startThread('probe', json_encode([$directory, 'b', 1], JSON_THROW_ON_ERROR));
    $a = CoroutineRuntime::startThread('probe', json_encode([$directory, 'a', 2], JSON_THROW_ON_ERROR));
    ThreadProbe::check($a->join() && $a->getExitStatus() === 7, '非零角色返回或 join 失败');
    unset($a);
    ThreadProbe::waitFor($directory . '/b.waiting');
    threadResourceCheckpoint($directory, 'survivor');
    $statuses = [7];
    for ($index = 0; $index < 8; ++$index) {
        $role = 'again-' . $index;
        $thread = CoroutineRuntime::startThread('probe', json_encode([$directory, $role, $index + 3], JSON_THROW_ON_ERROR));
        ThreadProbe::check($thread->join() && $thread->getExitStatus() === 0, '重复线程创建或清理失败');
        $statuses[] = $thread->getExitStatus();
        unset($thread);
        threadResourceCheckpoint($directory, $role);
    }
    $exiting = CoroutineRuntime::startThread('probe', json_encode([$directory, 'exit', 11], JSON_THROW_ON_ERROR));
    ThreadProbe::check($exiting->join() && $exiting->getExitStatus() === 9, '线程 exit 状态丢失');
    unset($exiting);
    file_put_contents($directory . '/release-b', 'ready');
    ThreadProbe::check($b->join() && $b->getExitStatus() === 0, '存活线程状态或清理失败');
    unset($b);
    ThreadProbe::check(ThreadProbe::$state === ['initial'] && ThreadProbe::$generation === 0, '子线程污染了主线程');
    ThreadProbe::check(Swoole\Thread::activeCount() === 1, 'join 后仍遗留业务线程');
    ThreadProbe::check(get_included_files() === [], '主入口加载了 PHP 源码');
    ThreadProbe::check(file_get_contents($directory . '/main-count') === "main\n", '应用主入口重复执行');
    threadResourceCheckpoint($directory, 'joined');
    threadStdioProbe('main');
    echo json_encode(['main-count' => 1, 'process-id' => getmypid(), 'main-native-id' => Swoole\Thread::getNativeId(),
        'statuses' => $statuses, 'exit' => 9, 'b' => 0], JSON_THROW_ON_ERROR), "\n";
}
