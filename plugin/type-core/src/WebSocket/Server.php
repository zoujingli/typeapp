<?php

declare(strict_types=1);

namespace Type\Core\WebSocket;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request as HttpRequest;
use Swoole\Http\Response as HttpResponse;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as NativeServer;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

/**
 * 有界 WebSocket 服务；握手、分帧、控制帧、关闭握手与 TLS 完全由 Swoole 原生持有。
 *
 * 本类只负责升级授权、连接会话、每条消息的 ExecutionScope、发送排队上限和真实关闭
 * 结算，不重新实现 RFC 6455 的帧解析、掩码或状态机。同一监听上的普通 HTTP/HTTPS
 * 请求继续交给 `onRequest` 处理器，与升级后的长连接共存；未登记处理器时返回 404。
 *
 * 连接会话只保存协议状态和有界缓冲；每条消息在独立作用域中投递，回调返回或失败后
 * 立即关闭该作用域，因此数据库租约不能活到连接断开。每条连接同一时刻只有一个消息
 * 所有者。实例固定在创建线程，只能在该线程 start 与 stop。经典 `Swoole\WebSocket\Server`
 * 在 Windows 原生路线不可用，start 会拒绝。
 */
final class Server implements ManagedResource
{
    private ExecutionOwner $owner;
    private ?NativeServer $server = null;
    private string $state = 'new';
    private bool $allocated = false;
    private array $connections = [];
    private array $incomingBytes = [];
    private array $scopes = [];
    private array $queuedBytes = [];
    private int $sentBytes = 0;
    private int $receivedBytes = 0;
    private int $acceptedCount = 0;
    private int $rejectedCount = 0;
    private ?\Closure $onOpen = null;
    private ?\Closure $onMessage = null;
    private ?\Closure $onClose = null;
    private ?\Closure $onRequest = null;
    private string $certificate = '';
    private string $privateKey = '';
    private string $passphrase = '';
    private int $sslProtocols = 0;

    private function __construct(
        private ResourceBudget $budget,
        private string $host,
        private int $port,
        private array $options
    ) {
        $this->owner = new ExecutionOwner(false);
        if ($this->options['open_ssl']) {
            $this->certificate = $this->options['ssl_cert_file'];
            $this->privateKey = $this->options['ssl_key_file'];
            $this->sslProtocols = $this->options['ssl_protocols'];
            $this->passphrase = $this->options['ssl_passphrase'] ?? '';
        }
    }

    /**
     * 创建尚未监听的服务。端口 0 由系统分配，实际端口在 serve 后由 statistics 报告。
     *
     * @param array{subprotocol?: string, allowed_origins?: list<string>, max_frame_bytes?: int,
     *     package_max_bytes?: int, max_connections?: int, max_queued_bytes?: int, max_pending_messages?: int, idle_seconds?: float,
     *     heartbeat_seconds?: float, message_seconds?: float, open_websocket_close_frame?: bool,
     *     open_websocket_ping_frame?: bool, open_websocket_pong_frame?: bool, websocket_compression?: bool,
     *     open_ssl?: bool, ssl_cert_file?: string, ssl_key_file?: string, ssl_passphrase?: string,
     *     ssl_protocols?: int} $options 原生名称及含义。WSS 须 open_ssl 并提供证书与私钥。
     * @throws TaskException 地址、端口、子协议、TLS 或字节上限无效。
     */
    public static function create(ResourceBudget $budget, string $host, int $port = 0, array $options = []): self
    {
        return new self($budget, $host, $port, self::configure($host, $port, $options));
    }

    /**
     * 登记握手完成回调；同一连接的后续消息在回调返回后才继续投递。
     *
     * 配置了 `subprotocol` 时，未真正提供该子协议的连接不会进入本回调（已按 1002 关闭）。
     * 配置了 `allowed_origins` 时，Origin 存在但不匹配白名单的连接同样不进入（按 1008 关闭）；
     * Origin 缺失是非浏览器的标准情形，允许连接但 `originVerified` 为 false，由业务鉴权决定。
     *
     * @param \Closure(int, array<string, string>, bool): void $handler 连接标识、升级请求头与 Origin 是否已核实。
     */
    public function onOpen(\Closure $handler): void
    {
        $this->onOpen = $handler;
    }

    /**
     * 登记消息回调；原生已按帧边界重组分片，回调收到的是完整消息。
     * 第四个参数是本条消息的作用域，数据库连接必须挂在其上；回调返回后作用域关闭，
     * 即使对端仍连接或发送尚未被读取，事务也不会继续占用。
     * @param \Closure(int, string, bool, ExecutionScope): void $handler 连接标识、消息、是否二进制与消息作用域。
     */
    public function onMessage(\Closure $handler): void
    {
        $this->onMessage = $handler;
    }

    /**
     * 登记关闭回调；用于释放业务资源，不能在其中再次发送。
     * @param \Closure(int, int): void $handler 连接标识与原生关闭原因。
     */
    public function onClose(\Closure $handler): void
    {
        $this->onClose = $handler;
    }

    /**
     * 登记同一监听上的普通 HTTP 处理器；未登记时非升级请求返回 404。
     * @param \Closure(HttpRequest, HttpResponse): void $handler 原生请求与响应。
     */
    public function onRequest(\Closure $handler): void
    {
        $this->onRequest = $handler;
    }

    /**
     * 启动监听并进入事件循环，直到 stop；本方法在当前协程阻塞，不返回中间状态。
     * 停止由同线程的信号、定时器或另一协程调用 stop 触发。
     * @throws TaskException 已经启动或退役、原生监听失败。
     */
    public function start(): void
    {
        $this->owner->assertCurrent();
        CoroutineRuntime::assertAvailable();
        if (PHP_OS_FAMILY === 'Windows') {
            throw new TaskException(
                'websocket_unsupported_platform',
                '经典 Swoole\\WebSocket\\Server 不支持当前 Windows 原生路线，须使用协程 HTTP 升级并完成独立验收'
            );
        }
        if ($this->state !== 'new') {
            throw new TaskException('websocket_already_served', 'WebSocket 服务只能启动一次');
        }
        if (!$this->allocated) {
            $this->budget->acquire();
            $this->allocated = true;
        }
        $this->state = 'active';
        $sockType = (int) (\SWOOLE_SOCK_TCP | ($this->options['open_ssl'] ? \SWOOLE_SSL : 0));
        $native = new NativeServer($this->host, $this->port, (int) \SWOOLE_BASE, $sockType);
        $this->apply($native);
        $this->bind($native);
        $this->server = $native;
        $this->port = (int) $native->port;
        try {
            $native->start();
        } catch (\Throwable $error) {
            $this->stop();
            throw new TaskException('websocket_serve_failed', 'WebSocket 原生监听失败：' . $error->getMessage());
        }
        if ($this->state === 'active' || $this->state === 'stopping') {
            $this->stop();
        }
    }

    /**
     * 向指定连接入队一条消息；超过单条或排队上限时拒绝并关闭该连接。
     * 入队成功只表示已交给原生发送缓冲，不表示对端已经收到。
     * @throws TaskException 服务未活动、连接不存在、长度超限、排队已满或原生拒绝。
     */
    public function send(int $fd, string $data, bool $binary = true, float $timeout = 5.0): void
    {
        $this->owner->assertCurrent();
        self::timeout($timeout);
        if ($this->state !== 'active' || $this->server === null) {
            throw new TaskException('websocket_not_active', 'WebSocket 服务未活动，不能发送');
        }
        if (!$this->server->isEstablished($fd)) {
            throw new TaskException('websocket_unknown_connection', 'WebSocket 连接不存在或尚未完成握手');
        }
        $length = (int) strlen($data);
        if ($length > $this->options['max_frame_bytes']) {
            $this->disconnect($fd, 1009, 'message_too_large');
            $this->rejectedCount++;
            throw new TaskException('websocket_message_too_large', 'WebSocket 单条消息超过字节上限，已关闭该连接');
        }
        $queued = ($this->queuedBytes[$fd] ?? 0) + $length;
        if ($queued > $this->options['max_queued_bytes']) {
            $this->disconnect($fd, 1009, 'queue_full');
            $this->rejectedCount++;
            throw new TaskException('websocket_queue_full', 'WebSocket 排队发送字节超过上限，已关闭该连接');
        }
        $this->queuedBytes[$fd] = $queued;
        $deadline = new Deadline($timeout);
        $opcode = $binary ? \WEBSOCKET_OPCODE_BINARY : \WEBSOCKET_OPCODE_TEXT;
        try {
            $sent = $this->server->push($fd, $data, $opcode, \SWOOLE_WEBSOCKET_FLAG_FIN);
        } finally {
            $pending = ($this->queuedBytes[$fd] ?? 0) - $length;
            if ($pending > 0) {
                $this->queuedBytes[$fd] = $pending;
            } else {
                unset($this->queuedBytes[$fd]);
            }
        }
        if ($sent !== true || $deadline->expired()) {
            throw new TaskException('websocket_send_failed', 'WebSocket 原生发送失败或已超过期限');
        }
        $this->sentBytes += $length;
    }

    /**
     * 主动完成关闭握手；reason 使用标准状态码，不等待对端确认。
     */
    public function disconnect(int $fd, int $code = 1000, string $reason = ''): void
    {
        $this->owner->assertCurrent();
        $gate = $this->connections[$fd] ?? null;
        unset($this->connections[$fd], $this->queuedBytes[$fd], $this->incomingBytes[$fd]);
        $gate?->close();
        if ($this->server !== null && $this->server->isEstablished($fd)) {
            $this->server->disconnect($fd, $code, $reason);
        }
    }

    /**
     * 同线程请求停止；正在投递的消息回调结束后才结算并释放额度。
     */
    public function stop(): void
    {
        $this->owner->assertCurrent();
        if ($this->state === 'closed' || $this->state === 'stopping') {
            return;
        }
        $this->state = 'stopping';
        foreach ($this->scopes as $scope) {
            $scope->cancellation()->cancel();
        }
        if ($this->server !== null) {
            foreach (array_keys($this->connections) as $fd) {
                $this->disconnect($fd, 1001, 'server_stopping');
            }
            $this->connections = [];
            $this->queuedBytes = [];
            $this->incomingBytes = [];
            $this->server->shutdown();
        }
        $this->release();
    }

    /**
     * 在已有协程中等待 stop 的真实完成；超时只结束等待，不释放额度。
     * @throws TaskException 期限无效或关闭尚未完成。
     */
    public function awaitClosed(float $timeout = 5.0): void
    {
        $this->owner->assertCurrent();
        self::timeout($timeout);
        if ($this->state !== 'closed') {
            throw new TaskException('websocket_close_pending', 'WebSocket 关闭尚未完成，继续保留额度');
        }
    }

    /** @return array<string, mixed> 监听地址、连接数、字节上限与真实收发计数。 */
    public function statistics(): array
    {
        $this->owner->assertCurrent();
        return ['state' => $this->state, 'host' => $this->host, 'port' => $this->port,
            'allocated' => $this->allocated, 'tls' => $this->options['open_ssl'],
            'connections' => count($this->connections),
            'accepted' => $this->acceptedCount, 'rejected' => $this->rejectedCount,
            'max_frame_bytes' => $this->options['max_frame_bytes'],
            'max_queued_bytes' => $this->options['max_queued_bytes'],
            'message_seconds' => $this->options['message_seconds'],
            'queued_connections' => count($this->queuedBytes),
            'sent_bytes' => $this->sentBytes, 'received_bytes' => $this->receivedBytes];
    }

    /** 活动监听禁止序列化、跨线程传递和复制。 */
    public function __serialize(): array
    {
        throw new TaskException('resource_transfer_forbidden', 'WebSocket 服务不能序列化或跨线程传递');
    }

    /** 不允许从外部数据恢复资源归属及额度。 */
    public function __unserialize(array $data): void
    {
        throw new TaskException('resource_transfer_forbidden', 'WebSocket 服务必须在所属线程创建');
    }

    private function __clone(): void
    {
    }

    private function bind(NativeServer $native): void
    {
        $native->on('open', function (NativeServer $server, HttpRequest $request): void {
            $fd = $request->fd;
            $headers = [];
            foreach ($request->header as $name => $value) {
                $headers[(string) $name] = (string) $value;
            }
            // 原生 websocket_subprotocol 会无条件回显配置值，因此必须自行核对客户端是否真的提供。
            if ($this->options['subprotocol'] !== '' && !self::offers($headers, $this->options['subprotocol'])) {
                $this->rejectedCount++;
                $this->disconnect($fd, 1002, 'subprotocol_not_offered');
                return;
            }
            $origin = strtolower($headers['origin'] ?? $headers['sec-websocket-origin'] ?? '');
            $verified = $origin !== '' && in_array($origin, $this->options['allowed_origins'], true);
            if ($this->options['allowed_origins'] !== [] && $origin !== '' && !$verified) {
                $this->rejectedCount++;
                $this->disconnect($fd, 1008, 'origin_not_allowed');
                return;
            }
            $gate = new Channel(1);
            $this->connections[$fd] = $gate;
            $this->acceptedCount++;
            try {
                if ($this->onOpen !== null) {
                    $this->invoke(function (ExecutionScope $scope) use ($fd, $headers, $verified): void {
                        ($this->onOpen)($fd, $headers, $verified);
                    }, ['connection' => (string) $fd]);
                }
            } catch (\Throwable $error) {
                $this->disconnect($fd, 1011, 'open_failed');
            } finally {
                if (($this->connections[$fd] ?? null) === $gate) {
                    $gate->push(true);
                }
            }
        });

        $native->on('message', function (NativeServer $server, Frame $frame): void {
            $fd = $frame->fd;
            if (!isset($this->connections[$fd])) {
                return;
            }
            $received = (int) strlen($frame->data);
            $this->receivedBytes += $received;
            // 控制帧不进入业务消息路径，也不延长任何业务超时。
            if ($frame->opcode === \WEBSOCKET_OPCODE_PING || $frame->opcode === \WEBSOCKET_OPCODE_PONG
                || $frame->opcode === \WEBSOCKET_OPCODE_CLOSE) {
                return;
            }
            if ($this->onMessage === null) {
                return;
            }
            // 官方 Channel 串行放行业务；等待者同时受条数、字节和期限限制。
            $gate = $this->connections[$fd];
            $pending = ($this->incomingBytes[$fd] ?? 0) + $received;
            if ($received > $this->options['max_frame_bytes'] || $pending > $this->options['max_queued_bytes']
                || $gate->stats()['consumer_num'] >= $this->options['max_pending_messages']) {
                $this->rejectedCount++;
                $this->disconnect($fd, 1009, 'message_queue_full');
                return;
            }
            $this->incomingBytes[$fd] = $pending;
            $acquired = false;
            try {
                $acquired = $gate->pop($this->options['message_seconds']) === true;
                if (!$acquired || ($this->connections[$fd] ?? null) !== $gate) {
                    if (($this->connections[$fd] ?? null) === $gate) {
                        $this->disconnect($fd, 1013, 'message_wait_timeout');
                    }
                    return;
                }
                $this->invoke(function (ExecutionScope $scope) use ($fd, $frame): void {
                    ($this->onMessage)($fd, $frame->data, $frame->opcode !== \WEBSOCKET_OPCODE_TEXT, $scope);
                }, ['connection' => (string) $fd]);
            } catch (\Throwable $error) {
                if (($this->connections[$fd] ?? null) === $gate) {
                    $this->disconnect($fd, 1011, 'message_failed');
                }
            } finally {
                if (($this->connections[$fd] ?? null) === $gate) {
                    $this->incomingBytes[$fd] -= $received;
                    if ($acquired) {
                        $gate->push(true);
                    }
                }
            }
        });

        $native->on('close', function (NativeServer $server, int $fd, int $reactorId): void {
            $gate = $this->connections[$fd] ?? null;
            unset($this->connections[$fd], $this->queuedBytes[$fd], $this->incomingBytes[$fd]);
            $gate?->close();
            if ($gate !== null && $this->onClose !== null) {
                try {
                    $this->invoke(function (ExecutionScope $scope) use ($fd, $reactorId): void {
                        ($this->onClose)($fd, $reactorId);
                    }, ['connection' => (string) $fd]);
                } catch (\Throwable $error) {
                    $this->stop();
                }
            }
        });

        $native->on('request', function (HttpRequest $request, HttpResponse $response): void {
            if ($this->onRequest === null) {
                $response->status(404);
                $response->end('not_found');
                return;
            }
            try {
                $this->invoke(function (ExecutionScope $scope) use ($request, $response): void {
                    ($this->onRequest)($request, $response);
                });
            } catch (\Throwable $error) {
                if ($response->isWritable()) {
                    $response->status(500);
                    $response->end('request_failed');
                }
            }
        });
    }

    /**
     * 每次公开回调拥有独立资源；异常和停止不提前释放尚未收尾的作用域额度。
     * @param \Closure(ExecutionScope): void $operation
     * @param array<string, string> $context
     */
    private function invoke(\Closure $operation, array $context = []): void
    {
        $scope = new ExecutionScope(new Deadline($this->options['message_seconds']), $context);
        $id = spl_object_id($scope);
        $this->scopes[$id] = $scope;
        try {
            $scope->run($operation);
        } finally {
            try {
                $scope->close();
            } finally {
                if ($scope->state() === 'closed') {
                    unset($this->scopes[$id]);
                } else {
                    $this->stop();
                    // 保持原协程所有权，直到后代真实结束；永久清理故障由角色监督处理。
                    $scope->awaitClosed();
                    unset($this->scopes[$id]);
                }
                if ($this->state === 'stopping') {
                    $this->release();
                }
            }
        }
    }

    private function apply(NativeServer $native): void
    {
        $settings = [
            'worker_num' => 1,
            'enable_coroutine' => true,
            'max_coroutine' => $this->options['max_connections'] * ($this->options['max_pending_messages'] + 2) + 32,
            'log_level' => \SWOOLE_LOG_ERROR,
            'log_file' => '/dev/null',
            'package_max_length' => $this->options['package_max_bytes'],
            'max_connection' => $this->options['max_connections'],
            'open_websocket_close_frame' => $this->options['open_websocket_close_frame'],
            'open_websocket_ping_frame' => $this->options['open_websocket_ping_frame'],
            'open_websocket_pong_frame' => $this->options['open_websocket_pong_frame'],
            'websocket_compression' => $this->options['websocket_compression'],
        ];
        if ($this->options['idle_seconds'] > 0) {
            $settings['heartbeat_idle_time'] = (int) ceil($this->options['idle_seconds']);
            $settings['heartbeat_check_interval'] = max(1, (int) ceil($this->options['heartbeat_seconds']));
        }
        if ($this->options['subprotocol'] !== '') {
            // 原生只负责在响应中回显该名称；是否真的被提供由 onOpen 核对。
            $settings['websocket_subprotocol'] = $this->options['subprotocol'];
        }
        if ($this->options['open_ssl']) {
            $settings['ssl_cert_file'] = $this->certificate;
            $settings['ssl_key_file'] = $this->privateKey;
            $settings['ssl_verify_peer'] = false;
            $settings['ssl_allow_self_signed'] = false;
            $settings['ssl_compress'] = false;
            $settings['ssl_protocols'] = $this->sslProtocols;
            if ($this->passphrase !== '') {
                $settings['ssl_passphrase'] = $this->passphrase;
            }
        }
        if ($native->set($settings) === false) {
            throw new TaskException('websocket_configuration_failed', 'WebSocket 原生配置未被接受');
        }
    }

    private function release(): void
    {
        if ($this->scopes !== []) {
            return;
        }
        $this->server = null;
        $this->state = 'closed';
        if ($this->allocated) {
            $this->allocated = false;
            $this->budget->release();
        }
    }

    private static function timeout(float $timeout): void
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 60) {
            throw new TaskException('websocket_invalid_timeout', 'WebSocket 单次期限必须为 (0,60] 秒');
        }
    }

    /** 客户端提供的子协议必须逐个精确匹配；不做大小写折叠或空白裁剪。 */
    private static function offers(array $headers, string $required): bool
    {
        $offered = $headers['sec-websocket-protocol'] ?? '';
        if ($offered === '') {
            return false;
        }
        foreach (explode(',', $offered) as $candidate) {
            if (trim($candidate) === $required) {
                return true;
            }
        }
        return false;
    }

    private static function configure(string $host, int $port, array $options): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false || $port < 0 || $port > 65535) {
            throw new TaskException('websocket_invalid_configuration', 'WebSocket 监听需要数字 IP 与 0–65535 端口');
        }
        $allowed = ['subprotocol', 'allowed_origins', 'max_frame_bytes', 'package_max_bytes', 'max_connections',
            'max_queued_bytes', 'max_pending_messages', 'idle_seconds', 'heartbeat_seconds', 'message_seconds', 'open_websocket_close_frame',
            'open_websocket_ping_frame', 'open_websocket_pong_frame', 'websocket_compression', 'open_ssl',
            'ssl_cert_file', 'ssl_key_file', 'ssl_passphrase', 'ssl_protocols'];
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new TaskException('websocket_invalid_configuration', 'WebSocket 含有未声明的监听选项');
        }
        $options += ['subprotocol' => '', 'allowed_origins' => [], 'max_frame_bytes' => 1048576, 'package_max_bytes' => 2097152,
            'max_connections' => 64, 'max_queued_bytes' => 1048576, 'max_pending_messages' => 16, 'idle_seconds' => 0.0, 'heartbeat_seconds' => 0.0,
            'message_seconds' => 30.0, 'open_websocket_close_frame' => true, 'open_websocket_ping_frame' => false,
            'open_websocket_pong_frame' => false, 'websocket_compression' => false, 'open_ssl' => false];
        if (!is_string($options['subprotocol']) || !is_array($options['allowed_origins']) || !is_int($options['max_frame_bytes'])
            || $options['max_frame_bytes'] < 1 || $options['max_frame_bytes'] > 1048576
            || !is_int($options['max_queued_bytes']) || $options['max_queued_bytes'] < 1 || $options['max_queued_bytes'] > 1048576
            || !is_int($options['max_connections']) || $options['max_connections'] < 1 || $options['max_connections'] > 4096
            || !is_int($options['max_pending_messages']) || $options['max_pending_messages'] < 1 || $options['max_pending_messages'] > 1024
            || !is_int($options['package_max_bytes']) || $options['package_max_bytes'] < $options['max_frame_bytes']
            || $options['package_max_bytes'] > 2 * $options['max_frame_bytes']
            || !is_float($options['idle_seconds']) || $options['idle_seconds'] < 0 || $options['idle_seconds'] > 600
            || !is_float($options['heartbeat_seconds']) || $options['heartbeat_seconds'] < 0
            || ($options['idle_seconds'] > 0 && $options['heartbeat_seconds'] <= 0)
            || !is_float($options['message_seconds']) || $options['message_seconds'] <= 0 || $options['message_seconds'] > 60
            || !is_bool($options['open_ssl'])) {
            throw new TaskException('websocket_invalid_configuration', 'WebSocket 需要 1 B–1 MiB 帧与排队上限、1–4096 连接、包上限介于帧上限与两倍之间，成对的空闲与心跳秒数，以及 (0,60] 秒的消息作用域期限');
        }
        foreach (['open_websocket_close_frame', 'open_websocket_ping_frame', 'open_websocket_pong_frame',
            'websocket_compression'] as $key) {
            if (!is_bool($options[$key])) {
                throw new TaskException('websocket_invalid_configuration', 'WebSocket 原生开关必须是显式布尔值');
            }
        }
        if ($options['subprotocol'] !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $options['subprotocol']) !== 1) {
            throw new TaskException('websocket_invalid_configuration', 'WebSocket 子协议只接受单个合法 token，不提供多选');
        }
        foreach ($options['allowed_origins'] as $origin) {
            if (!is_string($origin) || $origin === '' || $origin !== strtolower($origin)) {
                throw new TaskException('websocket_invalid_configuration', 'Origin 白名单必须是非空的小写字符串，比较前不做归一化');
            }
        }
        if (!$options['open_ssl']) {
            foreach (array_keys($options) as $key) {
                if (str_starts_with((string) $key, 'ssl_')) {
                    throw new TaskException('websocket_invalid_configuration', 'WebSocket 未启用 TLS 却提供了 TLS 选项');
                }
            }
            return $options;
        }
        $protocols = (int) (\SWOOLE_SSL_TLSv1_2 | \SWOOLE_SSL_TLSv1_3);
        $options += ['ssl_protocols' => $protocols];
        if (!isset($options['ssl_cert_file'], $options['ssl_key_file'])
            || !is_string($options['ssl_cert_file']) || $options['ssl_cert_file'] === '' || str_contains($options['ssl_cert_file'], "\0")
            || !is_string($options['ssl_key_file']) || $options['ssl_key_file'] === '' || str_contains($options['ssl_key_file'], "\0")
            || !is_int($options['ssl_protocols']) || $options['ssl_protocols'] <= 0 || ($options['ssl_protocols'] & ~$protocols) !== 0
            || (isset($options['ssl_passphrase']) && (!is_string($options['ssl_passphrase']) || str_contains($options['ssl_passphrase'], "\0")))) {
            throw new TaskException('websocket_invalid_configuration', 'WSS 需要成对证书与私钥，且仅允许 TLS 1.2/1.3');
        }
        return $options;
    }
}
