<?php

declare(strict_types=1);

namespace Type\Core;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ManagedResource;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

/**
 * 有界 TCP/TLS 字节流与监听资源；网络与 TLS 状态机完全由 Swoole 持有。
 *
 * 每个实例固定在创建线程，同线程允许一读一写并行。用 ExecutionScope::open()
 * 启动并登记，或在 finally 中 stop()；不提供应用排队、分帧、重连或隐式重发。
 */
final class TcpSocket implements ManagedResource
{
    private ExecutionOwner $owner;
    private ?Socket $socket = null;
    private string $state = 'new';
    private bool $allocated = false;
    private bool $stopping = false;
    private bool $closeScheduled = false;
    private bool $expired = false;
    private bool $readClosed = false;
    private bool $writeClosed = false;
    private array $operations = [];
    private ?Channel $completion = null;
    private array $local = [];
    private array $peer = [];
    private int $receiveBufferBytes = 0;
    private int $sendBufferBytes = 0;
    private int $writingBytes = 0;
    private int $receivedBytes = 0;
    private int $sentBytes = 0;

    private function __construct(
        private ResourceBudget $budget,
        private string $host,
        private int $port,
        private int $maxChunkBytes,
        private array $options,
        private string $kind
    ) {
        $this->owner = new ExecutionOwner(false);
    }

    /**
     * 创建尚未连接的客户端。数字 IPv6 使用 AF_INET6，域名沿原生 AF_INET DNS 解析。
     * TLS 用 open_ssl=true 启用，默认校验证书链及 ssl_host_name（缺省取 host）。
     *
     * @param array{socket_buffer_size?: int, open_ssl?: bool, ssl_cafile?: string, ssl_host_name?: string,
     *     ssl_cert_file?: string, ssl_key_file?: string, ssl_verify_peer?: bool, ssl_protocols?: int} $options 原生名称及含义。
     * @throws TaskException 地址、端口、字节上限或 TLS 配置无效。
     */
    public static function client(ResourceBudget $budget, string $host, int $port, int $maxChunkBytes = 65536, array $options = []): self
    {
        return new self($budget, $host, $port, $maxChunkBytes, self::configure($host, $port, $maxChunkBytes, $options, false), 'client');
    }

    /**
     * 创建尚未绑定的监听；只接受数字 IP，端口 0 由系统分配，IPv6 不隐式接收 IPv4。
     * 监听、等待接入的预留槽及全部连接共用注入的线程部署分额；backlog 仅限制内核等待队列。
     *
     * @param array{backlog?: int, socket_buffer_size?: int, open_ssl?: bool, ssl_cert_file?: string,
     *     ssl_key_file?: string, ssl_cafile?: string, ssl_verify_peer?: bool, ssl_protocols?: int} $options TLS 监听必须提供证书和私钥。
     * @throws TaskException 监听位置、缓冲或 TLS 配置无效。
     */
    public static function listener(ResourceBudget $budget, string $address, int $port = 0, int $maxChunkBytes = 65536, array $options = []): self
    {
        return new self($budget, $address, $port, $maxChunkBytes, self::configure($address, $port, $maxChunkBytes, $options, true), 'listener');
    }

    /**
     * 启动客户端、监听或已接入连接；timeout 是本次 DNS、连接与握手合计截止，单位秒。
     * 接入后的 TLS 握手在调用本方法的协程完成，不占用监听协程。启动失败自动停止。
     * @throws TaskException 缺少协程、已经退役、截止、取消、绑定、连接或 TLS 失败。
     * @throws \Type\Runtime\CapacityException 线程共享额度已满。
     */
    public function start(float $timeout = 5.0): void
    {
        $this->assertCoroutine();
        self::timeout($timeout);
        if ($this->state === 'active') {
            return;
        }
        if (!in_array($this->state, ['new', 'accepted'], true)) {
            throw new TaskException('tcp_stopped', 'TCP 资源正在启动或已经退役');
        }
        if (!$this->allocated) {
            $this->budget->acquire();
            $this->allocated = true;
        }
        $this->state = 'starting';
        $this->operations['start'] = Coroutine::getCid();
        $deadline = new Deadline($timeout);
        $timer = \std::any(false);
        try {
            $timer = \Swoole\Timer::after(max(1, (int) ceil($timeout * 1000.0)), function (): void {
                $this->expired = true;
                if (isset($this->operations['start'])) {
                    Coroutine::cancel($this->operations['start']);
                }
            });
            if ($timer === false) {
                throw new TaskException('tcp_timer_failed', 'TCP 启动截止未登记');
            }
            if ($this->socket === null) {
                $this->socket = new Socket(str_contains($this->host, ':') ? AF_INET6 : AF_INET, SOCK_STREAM, 0);
            }
            $this->buffers();
            if ($this->kind !== 'accepted' && $this->options['open_ssl'] && !$this->socket->setProtocol($this->options)) {
                $this->fail('tcp_tls_configuration_failed');
            }
            if ($this->kind === 'listener') {
                if (!$this->socket->setOption(SOL_SOCKET, SO_REUSEADDR, 1)
                    || (str_contains($this->host, ':') && !$this->socket->setOption(IPPROTO_IPV6, IPV6_V6ONLY, 1))) {
                    $this->fail('tcp_option_failed');
                }
                if (!$this->socket->bind($this->host, $this->port) || !$this->socket->listen($this->options['backlog'])) {
                    $this->fail('tcp_listen_failed');
                }
            } elseif ($this->kind === 'client') {
                // 原生 connect 自行解析 DNS 并进行 TLS；单次 Timer 约束所有阶段的合计等待。
                if (!$this->socket->connect($this->host, $this->port, $timeout)) {
                    $this->fail('tcp_connect_failed');
                }
            } elseif ($this->options['open_ssl'] && !$this->socket->sslHandshake()) {
                $this->fail('tcp_tls_handshake_failed');
            }
            $this->expired = $this->expired || $deadline->expired();
            $this->checkCompletion();
            $local = $this->socket->getsockname();
            $peer = $this->kind === 'listener' ? [] : $this->socket->getpeername();
            if (!is_array($local) || !is_array($peer)) {
                $this->fail('tcp_address_failed');
            }
            $this->local = $local;
            $this->peer = $peer;
            $this->state = 'active';
        } catch (\Throwable $error) {
            $this->stop();
            throw $error;
        } finally {
            if ($timer !== false && \Swoole\Timer::exists($timer)) {
                \Swoole\Timer::clear($timer);
            }
            $this->finish('start');
        }
    }

    /**
     * 先预留一份额度，再交给原生 accept；满额立即拒绝，不接入后排队。
     * 返回尚待 start 的连接，可在同线程的另一协程交给 ExecutionScope::open；交接失败须 stop。
     * @throws TaskException 期限、监听状态、重复接入、取消或原生接入失败。
     * @throws \Type\Runtime\CapacityException 共享连接额度已满。
     */
    public function accept(float $timeout = 5.0): self
    {
        $this->begin('read', $timeout, true);
        $connection = new self($this->budget, $this->host, $this->port, $this->maxChunkBytes, $this->options, 'accepted');
        try {
            $this->budget->acquire();
            $connection->allocated = true;
            $accepted = $this->socket->accept($timeout);
            if ($accepted === false) {
                $this->fail('tcp_accept_failed');
            }
            $connection->socket = $accepted;
            unset($accepted);
            $connection->state = 'accepted';
            $this->checkCompletion();
            return $connection;
        } catch (\Throwable $error) {
            $connection->stop();
            throw $error;
        } finally {
            $this->finish('read');
        }
    }

    /**
     * 返回至多 maxChunkBytes 的字节流片段；空字符串仅表示读 EOF，不代表一条空消息。
     * 超时/取消不吞掉已经返回的字节，也不自建累积缓冲，调用者负责协议边界和总消息上限。
     * @throws TaskException 资源状态、并发读取、等待超时、取消或网络错误。
     */
    public function receive(float $timeout = 5.0): string
    {
        $this->begin('read', $timeout, false);
        try {
            if ($this->readClosed) {
                return '';
            }
            $data = $this->socket->recv($this->maxChunkBytes, $timeout);
            $this->checkCompletion();
            if ($data === false) {
                $this->fail('tcp_receive_failed');
            }
            $this->readClosed = $data === '';
            $this->receivedBytes += (int) strlen($data);
            return $data;
        } finally {
            $this->finish('read');
        }
    }

    /**
     * 原生 sendAll 在一次期限内提交至多 maxChunkBytes；成功只表示本地提交。
     * 短写、取消或错误会停止连接，结果可能部分到达，调用者不得自动重发同一业务。
     * @throws TaskException 长度超限、写半关闭、重复写、超时、取消、短写或网络错误。
     */
    public function send(string $data, float $timeout = 5.0): int
    {
        $this->assertCoroutine();
        if (strlen($data) > $this->maxChunkBytes) {
            throw new TaskException('tcp_chunk_too_large', 'TCP 待发片段超过字节上限，未提交');
        }
        if ($this->writeClosed) {
            throw new TaskException('tcp_write_closed', 'TCP 写方向已经关闭');
        }
        $this->begin('write', $timeout, false);
        $this->writingBytes = strlen($data);
        try {
            if ($data === '') {
                return 0;
            }
            $written = $this->socket->sendAll($data, $timeout);
            $this->checkCompletion();
            if ($written === false || $written !== strlen($data)) {
                $this->fail('tcp_partial_write');
            }
            $this->sentBytes += (int) $written;
            return $written;
        } catch (\Throwable $error) {
            $this->stop();
            throw $error;
        } finally {
            $this->writingBytes = 0;
            $this->finish('write');
        }
    }

    /**
     * 关闭写方向后仍可读取对端剩余数据；沿用原生 TLS close_notify/SHUT_WR，不等待远端确认。
     * @throws TaskException 非活动连接、在途写入或原生半关闭失败。
     */
    public function shutdownWrite(): void
    {
        $this->begin('write', 1.0, false);
        try {
            if (!$this->writeClosed && !$this->socket->shutdown(SHUT_WR)) {
                $this->fail('tcp_shutdown_failed');
            }
            $this->writeClosed = true;
        } finally {
            $this->finish('write');
        }
    }

    /** @return array{local: array{address: string, port: int}, peer: array} 原生地址快照，监听的 peer 为空。 */
    public function addresses(): array
    {
        $this->owner->assertCurrent();
        return ['local' => $this->local, 'peer' => $this->peer];
    }

    /**
     * 同线程请求停止；取消在途操作且退出取消调用栈后，撤销唯一原生引用并登记关闭结算。
     * 不关闭已经交给调用者的其他连接；服务作用域负责结束所有已登记的连接任务。
     */
    public function stop(): void
    {
        $this->owner->assertCurrent();
        if ($this->state === 'closed' || $this->stopping) {
            return;
        }
        $this->state = 'stopping';
        $this->stopping = true;
        try {
            $operations = $this->operations;
            foreach ($operations as $cid) {
                if ($cid !== Coroutine::getCid() && Coroutine::exists($cid)) {
                    Coroutine::cancel($cid);
                }
            }
        } finally {
            $this->stopping = false;
            if ($this->operations === []) {
                $this->release();
            }
        }
    }

    /**
     * 在已有协程中等待 stop 的真实完成；超时/取消只结束等待，仍保留未关闭资源的额度。
     * @throws TaskException 未停止、期限无效或关闭仍未完成。
     */
    public function awaitClosed(float $timeout = 5.0): void
    {
        $this->assertCoroutine();
        self::timeout($timeout);
        if ($this->state === 'closed') {
            return;
        }
        if ($this->state !== 'stopping') {
            throw new TaskException('tcp_not_stopped', 'TCP 等待关闭前必须先 stop');
        }
        $this->completion ??= new Channel(1);
        $this->completion->pop($timeout);
        if ($this->state !== 'closed') {
            throw new TaskException('tcp_close_pending', 'TCP 关闭尚未完成，继续保留额度');
        }
    }

    /** @return array<string, mixed> 线程内可观察的资源、字节上限与真实关闭状态。 */
    public function statistics(): array
    {
        $this->owner->assertCurrent();
        return ['kind' => $this->kind, 'state' => $this->state, 'allocated' => $this->allocated,
            'in_flight' => count($this->operations), 'max_chunk_bytes' => $this->maxChunkBytes,
            'reading_bytes' => isset($this->operations['read']) && $this->kind !== 'listener' ? $this->maxChunkBytes : 0,
            'writing_bytes' => $this->writingBytes, 'queued_bytes' => 0,
            'receive_buffer_bytes' => $this->receiveBufferBytes, 'send_buffer_bytes' => $this->sendBufferBytes,
            'read_closed' => $this->readClosed, 'write_closed' => $this->writeClosed,
            'received_bytes' => $this->receivedBytes, 'sent_bytes' => $this->sentBytes];
    }

    /** 活动原生连接与监听禁止序列化、跨线程传递和复制。 */
    public function __serialize(): array
    {
        throw new TaskException('resource_transfer_forbidden', 'TCP 资源不能序列化或跨线程传递');
    }

    /** 不允许从外部数据恢复资源归属及额度。 */
    public function __unserialize(array $data): void
    {
        throw new TaskException('resource_transfer_forbidden', 'TCP 资源必须在所属线程创建');
    }

    private function __clone(): void
    {
    }

    private function assertCoroutine(): void
    {
        $this->owner->assertCurrent();
        CoroutineRuntime::assertAvailable();
        if (Coroutine::getCid() < 0) {
            throw new TaskException('tcp_coroutine_required', 'TCP 启动与读写需要已有协程');
        }
    }

    private function begin(string $direction, float $timeout, bool $listener): void
    {
        $this->assertCoroutine();
        self::timeout($timeout);
        if ($this->state !== 'active' || ($this->kind === 'listener') !== $listener) {
            throw new TaskException('tcp_not_active', 'TCP 操作需要对应的活动监听或连接');
        }
        if (isset($this->operations[$direction])) {
            throw new TaskException('tcp_operation_active', 'TCP 同一方向已有在途操作');
        }
        $this->operations[$direction] = Coroutine::getCid();
    }

    private function finish(string $direction): void
    {
        unset($this->operations[$direction]);
        if ($this->state === 'stopping' && !$this->stopping && $this->operations === []) {
            $this->release();
        }
    }

    private function checkCompletion(): void
    {
        if ($this->state === 'stopping') {
            throw new TaskException('tcp_stopped', 'TCP 操作期间已经停止，发送结果可能部分到达');
        }
        if ($this->expired) {
            throw new TaskException('tcp_timeout', 'TCP 启动合计截止已用尽');
        }
    }

    private function fail(string $reason): never
    {
        $this->checkCompletion();
        $code = $this->socket?->errCode ?? 0;
        if ($code === SOCKET_ETIMEDOUT) {
            $reason = 'tcp_timeout';
        } elseif ($code === SOCKET_ECANCELED || Coroutine::isCanceled()) {
            $reason = 'tcp_cancelled';
        }
        throw new TaskException($reason, 'TCP 原生操作失败：' . $reason . '，errno=' . $code);
    }

    private function buffers(): void
    {
        $bytes = $this->options['socket_buffer_size'];
        if (!$this->socket->setOption(SOL_SOCKET, SO_RCVBUF, $bytes) || !$this->socket->setOption(SOL_SOCKET, SO_SNDBUF, $bytes)) {
            $this->fail('tcp_option_failed');
        }
        $this->receiveBufferBytes = (int) $this->socket->getOption(SOL_SOCKET, SO_RCVBUF);
        $this->sendBufferBytes = (int) $this->socket->getOption(SOL_SOCKET, SO_SNDBUF);
        if ($this->receiveBufferBytes < 1 || $this->sendBufferBytes < 1
            || $this->receiveBufferBytes > 2 * $bytes || $this->sendBufferBytes > 2 * $bytes) {
            throw new TaskException('tcp_buffer_limit', 'TCP 原生缓冲超过预留上限');
        }
    }

    /** 最后引用释放使原生 Socket::free 登记 defer 关闭 FD；项目回调按 FIFO 在其后结算。 */
    private function release(): void
    {
        if ($this->closeScheduled) {
            return;
        }
        if ($this->socket === null) {
            $this->settle();
            return;
        }
        $this->closeScheduled = true;
        if (!$this->socket->isClosed()) {
            $this->socket->close();
        }
        $this->socket = null;
        if (!\Swoole\Event::defer(function (): void {
            $this->settle();
        })) {
            throw new TaskException('tcp_close_pending', 'TCP 关闭结算未登记，继续保留额度');
        }
    }

    private function settle(): void
    {
        $this->state = 'closed';
        if ($this->allocated) {
            $this->allocated = false;
            $this->budget->release();
        }
        $this->completion?->close();
    }

    private static function timeout(float $timeout): void
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 60) {
            throw new TaskException('tcp_invalid_timeout', 'TCP 单次期限必须为 (0,60] 秒');
        }
    }

    private static function configure(string $host, int $port, int $bytes, array $options, bool $listener): array
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ((!$ip && ($listener || strlen($host) > 253 || preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $host) !== 1))
            || $port < ($listener ? 0 : 1) || $port > 65535 || $bytes < 1 || $bytes > 1048576
            || array_diff(array_keys($options), ['backlog', 'socket_buffer_size', 'open_ssl', 'ssl_cafile', 'ssl_host_name',
                'ssl_cert_file', 'ssl_key_file', 'ssl_verify_peer', 'ssl_protocols']) !== []
            || (!$listener && array_key_exists('backlog', $options))) {
            throw new TaskException('tcp_invalid_configuration', 'TCP 地址、端口、1–1048576 字节片段或原生选项无效');
        }
        $options += ['socket_buffer_size' => 65536, 'backlog' => 128, 'open_ssl' => false];
        if (!is_int($options['socket_buffer_size']) || $options['socket_buffer_size'] < 65536 || $options['socket_buffer_size'] > 1048576
            || !is_int($options['backlog']) || $options['backlog'] < 1 || $options['backlog'] > 4096 || !is_bool($options['open_ssl'])) {
            throw new TaskException('tcp_invalid_configuration', 'TCP 需要 64 KiB–1 MiB 缓冲、1–4096 backlog 与显式布尔 TLS 选项');
        }
        if (!$options['open_ssl']) {
            foreach (array_keys($options) as $key) {
                if (str_starts_with($key, 'ssl_')) {
                    throw new TaskException('tcp_invalid_configuration', 'TCP 未启用 TLS 却提供了 TLS 选项');
                }
            }
            return $options;
        }
        $protocols = SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3;
        $options += ['ssl_verify_peer' => !$listener, 'ssl_protocols' => $protocols];
        if (!$listener) {
            $options += ['ssl_host_name' => $host];
        }
        if (!is_bool($options['ssl_verify_peer']) || (!$listener && !$options['ssl_verify_peer'])
            || !is_int($options['ssl_protocols']) || $options['ssl_protocols'] <= 0 || ($options['ssl_protocols'] & ~$protocols) !== 0
            || (isset($options['ssl_cert_file']) !== isset($options['ssl_key_file']))
            || ($listener && (!isset($options['ssl_cert_file']) || array_key_exists('ssl_host_name', $options)))) {
            throw new TaskException('tcp_invalid_configuration', 'TLS 需要成对证书与私钥、TLS 1.2/1.3；客户端必须校验对端');
        }
        foreach (['ssl_cafile', 'ssl_cert_file', 'ssl_key_file', 'ssl_host_name'] as $key) {
            if (array_key_exists($key, $options) && (!is_string($options[$key]) || $options[$key] === '' || str_contains($options[$key], "\0"))) {
                throw new TaskException('tcp_invalid_configuration', 'TLS 路径与对端名称必须是非空字符串');
            }
        }
        $options['ssl_allow_self_signed'] = false;
        return $options;
    }
}
