<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 显式启用可选的 Swoole 协程能力，不改变未选择该引擎的业务运行方式。 */
final class CoroutineRuntime
{
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
        if ($control !== null && (!defined('Swoole\\Thread::TYPEAPP_CONTROL_ARGUMENT_ABI')
            || constant('Swoole\\Thread::TYPEAPP_CONTROL_ARGUMENT_ABI') !== 1)) {
            throw new TaskException('compiled_thread_control_unavailable', '业务线程控制状态需要原生 Map 传递能力');
        }
        if (strlen($payload) > 1048576) {
            throw new TaskException('compiled_thread_payload_limit', '业务线程启动数据不能超过 1 MiB');
        }
        $message = json_encode([$entry, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($message) > 1048576) {
            throw new TaskException('compiled_thread_payload_limit', '业务线程启动数据不能超过 1 MiB');
        }
        self::enableIo();
        if ($control !== null) {
            return \Swoole\Thread::startNative('type_app_compiled_thread_run', $message, $socket, $control);
        }
        return $socket === null
            ? \Swoole\Thread::startNative('type_app_compiled_thread_run', $message)
            : \Swoole\Thread::startNative('type_app_compiled_thread_run', $message, $socket);
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
     * 仅在主线程启用 Swoole TCP、文件 API 与等待钩子；保留已有配置，可重复调用。
     *
     * 子线程直接使用启动前安装的原生 hook，不重复修改进程配置。
     * 文件 hook 的完整有界接入仍在独立验收，不作为普通线程和网络的启动前置。
     * @throws TaskException 扩展不符合要求，或运行期间试图改变进程级钩子。
     */
    public static function enableIo(): void
    {
        self::assertAvailable();
        if (class_exists(\Swoole\Thread::class, false) && !\Swoole\Thread::getInfo()['is_main_thread']) {
            throw new TaskException('swoole_hook_startup_required', 'I/O 钩子必须在主线程启动业务线程前启用');
        }
        $required = SWOOLE_HOOK_TCP | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_STREAM_FUNCTION;
        $current = \Swoole\Runtime::getHookFlags();
        if (($current & $required) === $required) {
            return;
        }
        if (class_exists(\Swoole\Thread::class, false) && \Swoole\Thread::activeCount() > 1) {
            throw new TaskException('swoole_hook_startup_required', 'I/O 钩子必须在主线程启动业务线程前启用');
        }
        if (!\Swoole\Runtime::enableCoroutine($current | $required)) {
            throw new TaskException('swoole_hook_startup_required', 'I/O 钩子必须在主线程启动业务线程前启用');
        }
    }
}
