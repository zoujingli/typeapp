<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;
use Throwable;

/** 沿用 Swoole 的协程、线程和启动期 hook，衔接已编译业务入口。 */
final class CoroutineRuntime
{
    /** @internal 必须与构建器的线程消息协议一致；2 表示携带已验证的 hook 快照。 */
    public const THREAD_ENTRY_PROTOCOL = 2;

    /**
     * 当前主线程是否已经为一组原生业务线程完成过启动前 hook 安装。
     *
     * Swoole 的运行时 hook 属于进程级 PHP handler，只能在主线程且没有
     * 子线程时修改。这个状态只抑制同一组线程的重复配置；每次公开
     * enableIo() 仍会复核能力，Scheduler 结束后也会清掉它以便重新安装。
     */
    private static bool $threadIoPrepared = false;

    /**
     * 已在协程时直接调用，否则由官方 Scheduler 运行；保留启动期 hook 配置。
     *
     * 必须在回调内创建作用域和连接，不能将外层执行者持有的资源带入新协程。
     * 本入口不创建作用域、不接管已有事件循环，异常原样传回调用者。
     * @param Closure(): mixed $operation 协程中的装配和工作入口。
     * @throws TaskException Swoole 不可用或协程启动失败。
     */
    public static function run(Closure $operation): mixed
    {
        self::assertAvailable();
        if (Coroutine::getCid() >= 0) {
            return $operation();
        }
        $scheduler = new Scheduler();
        $scheduler->set(['hook_flags' => \Swoole\Runtime::getHookFlags()]);
        $result = null;
        $failure = null;
        try {
            if ($scheduler->add(static function () use ($operation, &$result, &$failure): void {
                try {
                    $result = $operation();
                } catch (Throwable $error) {
                    $failure = $error;
                }
            }) === false || !$scheduler->start()) {
                throw new TaskException('coroutine_start_failed', '无法启动 Swoole 协程入口');
            }
        } finally {
            // Scheduler 的 reactor 退出时会撤销实际 handler；下一组原生
            // 线程必须回到主线程重新安装，不能只相信 getHookFlags()。
            if (self::isMainThreadOnly()) {
                self::$threadIoPrepared = false;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }

    /**
     * 启动构建时登记的业务入口；调用者负责 join 后再关闭进程运行时。
     *
     * 入口签名为 (string $payload): int，返回 0–255 退出码；UTF-8 字符串被复制到新线程。
     * 可选原生 Socket 由 Swoole 复制描述符，子线程从 Thread::getArguments()[1] 取得自己的对象。
     * 调用者负责各线程副本、原对象和 join 的生命周期；传递不转移原对象的所有权。
     * 可选控制 Map 复用原生 ThreadResource，固定在 getArguments()[2]，不传时保持旧参数形状。
     * 不传 PDO、业务对象、任意资源数组或 Socket 的 Zend 指针。
     * @throws TaskException 缺少受控线程或套接字传递能力、入口未登记或消息超过 1 MiB。
     * @throws \JsonException 启动字符串不是有效 UTF-8。
     * @throws \Swoole\Exception 套接字描述符无法复制或原生线程无法创建。
     */
    public static function startThread(string $entry, string $payload, ?\Swoole\Coroutine\Socket $socket = null, ?\Swoole\Thread\Map $control = null): \Swoole\Thread
    {
        self::assertAvailable();
        if (!class_exists(\Swoole\Thread::class, false) || !method_exists(\Swoole\Thread::class, 'startNative')
            || !defined('Swoole\\Thread::NATIVE_ENTRY_ABI') || constant('Swoole\\Thread::NATIVE_ENTRY_ABI') !== 2
            || !filter_var(ini_get('swoole.enable_fiber_mock'), FILTER_VALIDATE_BOOL)
            || !function_exists('type_app_compiled_thread_has_entry') || !function_exists('type_app_compiled_thread_run')) {
            throw new TaskException('compiled_thread_unavailable', '业务线程需要已编译入口和受控 Swoole 原生线程扩展');
        }
        if (!\type_app_compiled_thread_has_entry($entry)) {
            throw new TaskException('compiled_thread_unknown', '业务线程入口未登记');
        }
        if ($socket !== null && (!defined('Swoole\\Thread::TYPEAPP_SOCKET_ARGUMENT_ABI')
            || constant('Swoole\\Thread::TYPEAPP_SOCKET_ARGUMENT_ABI') !== 1)) {
            throw new TaskException('compiled_thread_socket_unavailable', '业务线程套接字传递需要对应的受控 Swoole 能力');
        }
        if ($socket !== null) {
            // 新版 Swoole 在关闭套接字后仍可能把参数列表交给原生线程；
            // 线程入口才发现多余参数时，错误既晚又无法保持调用方的异常语义。
            // 官方 isClosed() 是跨平台的所有权边界检查，必须在复制描述符前完成。
            if (!method_exists($socket, 'isClosed')) {
                throw new TaskException('compiled_thread_socket_unavailable', '业务线程套接字传递需要可检查的 Swoole 套接字能力');
            }
            if ($socket->isClosed()) {
                throw new \Swoole\Exception('cannot pass a closed socket to a native thread');
            }
        }
        if ($control !== null && (!defined('Swoole\\Thread::TYPEAPP_CONTROL_ARGUMENT_ABI')
            || constant('Swoole\\Thread::TYPEAPP_CONTROL_ARGUMENT_ABI') !== 1)) {
            throw new TaskException('compiled_thread_control_unavailable', '业务线程控制状态需要原生 Map 传递能力');
        }
        if (strlen($payload) > 1048576) {
            throw new TaskException('compiled_thread_payload_limit', '业务线程启动数据不能超过 1 MiB');
        }
        // 运行时 hook 只能在第一个业务线程创建前安装。新版 Swoole 在已有
        // 子线程时会拒绝重复 enableCoroutine()；后续线程只复核已安装的能力。
        self::prepareThreadIo();
        $message = json_encode([$entry, $payload, \Swoole\Runtime::getHookFlags()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($message) > 1048576) {
            throw new TaskException('compiled_thread_payload_limit', '业务线程启动数据不能超过 1 MiB');
        }
        if ($control !== null) {
            return \Swoole\Thread::startNative('type_app_compiled_thread_run', $message, $socket, $control);
        }
        return $socket === null
            ? \Swoole\Thread::startNative('type_app_compiled_thread_run', $message)
            : \Swoole\Thread::startNative('type_app_compiled_thread_run', $message, $socket);
    }

    /**
     * 校验原生启动消息，在业务协程创建前继承已经安装的 hook 配置。
     *
     * @internal 仅供生成的线程入口调用；flags 来自主线程启动前验证的快照。
     * Swoole 的 handler 在进程内共享，getHookFlags() 却读取线程本地选项；新线程
     * 只通过 Coroutine::set() 同步选项，不重复安装或改变进程级 handler。
     * @return array{0: string, 1: string} 登记入口与业务启动数据。
     * @throws TaskException 消息缺失、被替换、缺少启动期 hook，或调用时机不符。
     * @throws \JsonException 原生启动消息不是有效 JSON。
     */
    public static function enterThread(string $message): array
    {
        self::assertAvailable();
        if (!class_exists(\Swoole\Thread::class, false) || \Swoole\Thread::getInfo()['is_main_thread']
            || Coroutine::getCid() >= 0 || strlen($message) > 1048576) {
            throw new TaskException('compiled_thread_message_invalid', '编译线程消息只能由新线程的受控入口接收');
        }
        $arguments = \Swoole\Thread::getArguments();
        if (!is_array($arguments) || !is_string($arguments[0] ?? null) || $arguments[0] !== $message) {
            throw new TaskException('compiled_thread_message_invalid', '编译线程消息与原生启动参数不一致');
        }
        $data = json_decode($message, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data) || count($data) !== 3
            || !is_string($data[0]) || !is_string($data[1]) || !is_int($data[2]) || $data[2] < 0) {
            throw new TaskException('compiled_thread_message_invalid', '编译线程消息缺少有效的入口、数据或 hook 快照');
        }
        $flags = $data[2];
        $required = self::requiredHookFlags();
        if (($flags & $required) !== $required) {
            throw new TaskException('swoole_hook_startup_required', '编译线程未收到完整的启动期 I/O hook 配置');
        }
        Coroutine::set(['hook_flags' => $flags]);
        self::assertRequiredHooks();
        return [$data[0], $data[1]];
    }

    /** 调用协程 API 前检查扩展版本；官方内置库沿用 Swoole 的请求初始化加载。 */
    public static function assertAvailable(): void
    {
        if (!extension_loaded('swoole')) {
            throw new TaskException('swoole_required', '协程 I/O 需要原生 Swoole');
        }
        $version = phpversion('swoole');
        if (!is_string($version) || version_compare($version, '6.2.0', '<') || version_compare($version, '7.0.0', '>=')) {
            throw new TaskException('swoole_incompatible', '协程 I/O 需要 Swoole >=6.2 <7');
        }
    }

    /**
     * 在主线程启动期补齐网络、等待、独立命令进程及已编译的 PDO 钩子；保留已有配置，可重复调用。
     *
     * 子线程直接使用启动前安装的原生 hook，不重复修改进程配置。
     * 主线程独占运行时时重新应用配置：Scheduler 退出会撤销实际 hook，但保留 getHookFlags 的配置值。
     * 文件 hook 的完整有界接入仍在独立验收，不作为普通线程和网络的启动前置。
     * @throws TaskException 扩展不符合要求，或运行期间试图改变进程级钩子。
     */
    public static function enableIo(): void
    {
        self::assertAvailable();
        $required = self::requiredHookFlags();
        $current = \Swoole\Runtime::getHookFlags();
        if (class_exists(\Swoole\Thread::class, false)
            && (!\Swoole\Thread::getInfo()['is_main_thread'] || \Swoole\Thread::activeCount() > 1)) {
            if (($current & $required) !== $required) {
                throw new TaskException('swoole_hook_startup_required', 'I/O 钩子必须在主线程启动业务线程前启用');
            }
            return;
        }
        // 新版 Swoole 的原生线程 join 返回与其清理完成之间可能有极短窗口；
        // 主线程仍独占时只重试有限次数，避免把可恢复的启动竞态报告成配置错误。
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (@\Swoole\Runtime::enableCoroutine($current | $required)) {
                return;
            }
            if ($attempt < 2) {
                usleep(1000);
            }
        }
        throw new TaskException('swoole_hook_startup_required', 'I/O 钩子必须在主线程启动业务线程前启用');
    }

    /**
     * 在建立协程 PDO 连接前检查所选驱动，保持同步维护命令的原有行为。
     *
     * @internal 由 PDO 驱动在创建物理连接前调用；只读取能力，不在请求中修改进程级 hook。
     * MySQL 通过 mysqlnd 使用网络 hook，PostgreSQL 与 SQLite 使用各自编译进 Swoole 的 PDO hook。
     * @throws TaskException 所选驱动缺少协程能力，或对应 hook 尚未在启动期启用。
     */
    public static function assertPdoHooks(string $driver): void
    {
        if (!extension_loaded('swoole') || Coroutine::getCid() < 0) {
            return;
        }
        self::assertAvailable();
        $required = 0;
        if ($driver === 'mysql') {
            // 此 PDO 常量只在 PDO_USE_MYSQLND 构建时声明；另一个扩展加载 mysqlnd 不足以证明 PDO 的客户端类型。
            if (!extension_loaded('mysqlnd') || !defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new TaskException('swoole_pdo_hook_unavailable', 'MySQL 协程连接需要 mysqlnd；当前构建不能安全执行协程 PDO');
            }
            $required = SWOOLE_HOOK_TCP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_TLS;
            if (defined('SWOOLE_HOOK_UNIX')) {
                $required |= (int) constant('SWOOLE_HOOK_UNIX');
            }
        } else {
            $hook = match ($driver) {
                'pgsql' => 'SWOOLE_HOOK_PDO_PGSQL',
                'sqlite' => 'SWOOLE_HOOK_PDO_SQLITE',
                default => '',
            };
            if ($hook === '' || !defined($hook)) {
                throw new TaskException('swoole_pdo_hook_unavailable', '所选数据库 ' . $driver . ' 缺少已编译的 Swoole PDO 协程能力');
            }
            $required = (int) constant($hook);
        }
        if ((\Swoole\Runtime::getHookFlags() & $required) !== $required) {
            throw new TaskException('swoole_hook_startup_required', '所选数据库 ' . $driver . ' 的 I/O 钩子必须在启动协程或业务线程前启用');
        }
    }

    /**
     * 为当前线程组安装一次进程级 hook，并在后续创建前只校验能力。
     *
     * 不能通过 activeCount() 预测即将创建的线程：新线程注册存在时序窗口，
     * 这正是新版 Swoole 拒绝重复 enableCoroutine() 的触发条件。状态只在本
     * 进程主线程中使用；缺失 hook 仍交给 enableIo() 返回稳定错误码。
     */
    private static function prepareThreadIo(): void
    {
        if (self::$threadIoPrepared) {
            self::assertRequiredHooks();
            return;
        }
        self::enableIo();
        if (self::isMainThreadOnly()) {
            self::$threadIoPrepared = true;
        }
    }

    /** 读取公开入口所需的 hook，避免在线程创建后再次调用原生配置接口。 */
    private static function assertRequiredHooks(): void
    {
        $required = self::requiredHookFlags();
        if ((\Swoole\Runtime::getHookFlags() & $required) !== $required) {
            throw new TaskException('swoole_hook_startup_required', 'I/O 钩子必须在主线程启动业务线程前启用');
        }
    }

    /** 计算当前扩展组合真正需要的网络、进程和 PDO hook。 */
    private static function requiredHookFlags(): int
    {
        $required = SWOOLE_HOOK_TCP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_TLS | SWOOLE_HOOK_PROC
            | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_STREAM_FUNCTION;
        if (defined('SWOOLE_HOOK_UNIX')) {
            $required |= (int) constant('SWOOLE_HOOK_UNIX');
        }
        // mysqlnd 复用上面的网络 hook，Swoole 没有独立的 PDO MySQL hook。
        foreach (['pdo_pgsql' => 'SWOOLE_HOOK_PDO_PGSQL', 'pdo_sqlite' => 'SWOOLE_HOOK_PDO_SQLITE'] as $extension => $hook) {
            if (extension_loaded($extension) && defined($hook)) {
                $required |= (int) constant($hook);
            }
        }
        return $required;
    }

    /** 当前 PHP 执行单元是否是唯一的主线程。 */
    private static function isMainThreadOnly(): bool
    {
        return !class_exists(\Swoole\Thread::class, false)
            || (\Swoole\Thread::getInfo()['is_main_thread'] && \Swoole\Thread::activeCount() === 1);
    }
}
