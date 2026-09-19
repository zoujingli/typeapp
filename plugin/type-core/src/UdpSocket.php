<?php

declare(strict_types=1);

namespace Type\Core;

use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ManagedResource;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

/**
 * 同一线程内有界的原生 UDP 端点，客户端与服务端共用报文契约。
 *
 * 用 ExecutionScope::open() 启动，或在 finally 中 stop()。收发固定在启动协程，
 * 同线程控制方可以停止；不持有应用报文队列，不提供重试、可靠交付或分包。
 */
final class UdpSocket implements ManagedResource
{
    private ResourceBudget $budget;
    private ExecutionOwner $threadOwner;
    private ?ExecutionOwner $owner = null;
    private ?Socket $socket = null;
    private string $address;
    private int $port;
    private bool $ipv6;
    private int $maxDatagramBytes;
    private int $socketBufferBytes;
    private float $writeTimeout;
    private float $effectiveWriteTimeout = 0.0;
    private string $state = 'new';
    private bool $allocated = false;
    private bool $stopping = false;
    private bool $closeScheduled = false;
    private int $operation = -1;
    private array $local = [];
    private int $receiveBufferBytes = 0;
    private int $sendBufferBytes = 0;
    private int $received = 0;
    private int $sent = 0;
    private int $oversized = 0;

    /**
     * 只接受数字 IP，IPv6 不隐式接收 IPv4；端口 0 由系统分配。
     *
     * 同一线程的全部端点与退役代次必须注入同一部署分额，不能每个端点复制预算。
     * @param array{socket_buffer_size?: int, write_timeout?: float|int} $options 沿用 Swoole 选项名，超时单位秒。
     * @throws TaskException 地址、端口、单报文上限或原生缓冲/期限配置无效。
     */
    public function __construct(ResourceBudget $budget, string $address, int $port = 0, int $maxDatagramBytes = 8192, array $options = [])
    {
        $ipv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $ipv6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $buffer = array_key_exists('socket_buffer_size', $options) ? $options['socket_buffer_size'] : 65536;
        $timeout = array_key_exists('write_timeout', $options) ? $options['write_timeout'] : 1.0;
        if ((!$ipv4 && !$ipv6) || $port < 0 || $port > 65535 || $maxDatagramBytes < 1 || $maxDatagramBytes > 65507
            || array_diff(array_keys($options), ['socket_buffer_size', 'write_timeout']) !== []
            || !is_int($buffer) || $buffer < 65536 || $buffer > 1048576
            || (!is_float($timeout) && !is_int($timeout)) || !is_finite((float) $timeout) || $timeout <= 0 || $timeout > 60) {
            throw new TaskException('udp_invalid_configuration', 'UDP 需要数字 IP、有效端口、1–65507 字节报文、64 KiB–1 MiB 缓冲与 (0,60] 秒发送期限');
        }
        $this->budget = $budget;
        $this->threadOwner = new ExecutionOwner(false);
        $this->address = $address;
        $this->port = $port;
        $this->ipv6 = $ipv6;
        $this->maxDatagramBytes = $maxDatagramBytes;
        $this->socketBufferBytes = $buffer;
        $this->writeTimeout = (float) $timeout;
    }

    /**
     * 在已有协程中取得一份额度、配置并绑定原生 Socket；失败仍按真实关闭时序结算。
     * @throws TaskException 缺少协程能力、端点已退役或原生配置/绑定失败。
     * @throws \Type\Runtime\CapacityException 同线程共享的端点额度已满。
     */
    public function start(): void
    {
        $this->threadOwner->assertCurrent();
        if ($this->state === 'active') {
            $this->owner->assertCurrent();
            return;
        }
        if ($this->state !== 'new') {
            throw new TaskException('udp_stopped', 'UDP 端点已退役，请创建新实例');
        }
        CoroutineRuntime::assertAvailable();
        if (Coroutine::getCid() < 0) {
            throw new TaskException('udp_coroutine_required', 'UDP 启动与收发需要已有协程');
        }
        $this->owner = new ExecutionOwner();
        $this->budget->acquire();
        $this->allocated = true;
        try {
            $this->socket = new Socket($this->ipv6 ? AF_INET6 : AF_INET, SOCK_DGRAM, 0);
            $timeoutMicros = max(1, (int) ceil($this->writeTimeout * 1000000.0));
            if (!$this->socket->setOption(SOL_SOCKET, SO_RCVBUF, $this->socketBufferBytes)
                || !$this->socket->setOption(SOL_SOCKET, SO_SNDBUF, $this->socketBufferBytes)
                || ($this->ipv6 && !$this->socket->setOption(IPPROTO_IPV6, IPV6_V6ONLY, 1))
                || !$this->socket->setOption(SOL_SOCKET, SO_SNDTIMEO, ['sec' => intdiv($timeoutMicros, 1000000), 'usec' => $timeoutMicros % 1000000])) {
                throw new TaskException('udp_option_failed', 'UDP 原生选项配置失败');
            }
            $this->receiveBufferBytes = (int) $this->socket->getOption(SOL_SOCKET, SO_RCVBUF);
            $this->sendBufferBytes = (int) $this->socket->getOption(SOL_SOCKET, SO_SNDBUF);
            $timeout = $this->socket->getOption(SOL_SOCKET, SO_SNDTIMEO);
            $this->effectiveWriteTimeout = (float) $timeout['sec'] + (float) $timeout['usec'] / 1000000.0;
            // Linux 会双倍报告 socket_buffer_size；实测值不得超过已预留的两倍。
            if ($this->receiveBufferBytes < 1 || $this->sendBufferBytes < 1
                || $this->receiveBufferBytes > 2 * $this->socketBufferBytes || $this->sendBufferBytes > 2 * $this->socketBufferBytes) {
                throw new TaskException('udp_buffer_limit', 'UDP 原生缓冲未落在预留预算内');
            }
            if (!$this->socket->bind($this->address, $this->port)) {
                $this->fail('udp_bind_failed');
            }
            $local = $this->socket->getsockname();
            if (!is_array($local) || !isset($local['address'], $local['port'])) {
                $this->fail('udp_address_failed');
            }
            $this->local = ['address' => (string) $local['address'], 'port' => (int) $local['port']];
            $this->state = 'active';
        } catch (\Throwable $error) {
            $this->state = 'stopping';
            $this->release();
            throw $error;
        }
    }

    /**
     * 收到一个完整报文；空 payload 仍有来源，超时和取消均抛出有区别的错误。
     *
     * 原生接收保留至多 65536 字节，超应用上限的报文被完整消费后拒绝，不返回截断成功。
     * 普通 IPv4/IPv6 UDP 都小于原生缓冲；不支持 IPv6 jumbogram。
     * @return array{data: string, address: string, port: int}
     * @throws TaskException 等待期限无效、端点已停止、报文超长、来源缺失或原生接收失败。
     */
    public function receive(float $timeout = 1.0): array
    {
        $this->assertActive();
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 60) {
            throw new TaskException('udp_invalid_timeout', 'UDP 单次接收期限必须为 (0,60] 秒');
        }
        $this->operation = Coroutine::getCid();
        try {
            $peer = [];
            $data = $this->socket->recvfrom(\std::ref($peer), $timeout);
            if ($this->state !== 'active') {
                throw new TaskException('udp_stopped', 'UDP 等待期间端点已停止');
            }
            if ($data === false) {
                $this->fail('udp_receive_failed');
            }
            if (strlen($data) > $this->maxDatagramBytes) {
                $this->oversized++;
                throw new TaskException('udp_datagram_too_large', 'UDP 收到超过应用上限的完整报文，已消费并拒绝');
            }
            if (!isset($peer['address'], $peer['port']) || !is_string($peer['address']) || !is_int($peer['port'])) {
                throw new TaskException('udp_peer_unavailable', '原生 UDP 未写回来源地址；空报文需要已修正的 Swoole Socket');
            }
            $this->received++;
            return ['data' => $data, 'address' => $peer['address'], 'port' => $peer['port']];
        } finally {
            $this->operation = -1;
            if ($this->state === 'stopping' && !$this->stopping) {
                $this->release();
            }
        }
    }

    /**
     * 提交一个报文，不分片、不自动重试；返回字节数只表示本地提交，不保证远端收到。
     * @throws TaskException 地址族/长度无效、已停止、超时、取消或原生发送失败。
     */
    public function sendTo(string $address, int $port, string $data): int
    {
        $this->assertActive();
        $flags = $this->ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if (filter_var($address, FILTER_VALIDATE_IP, $flags) === false || $port < 1 || $port > 65535) {
            throw new TaskException('udp_invalid_peer', 'UDP 对端必须是同地址族的数字 IP 与有效非零端口');
        }
        if (strlen($data) > $this->maxDatagramBytes) {
            $this->oversized++;
            throw new TaskException('udp_datagram_too_large', 'UDP 待发报文超过应用上限，未提交');
        }
        $this->operation = Coroutine::getCid();
        try {
            $written = $this->socket->sendto($address, $port, $data);
            if ($this->state !== 'active') {
                throw new TaskException('udp_stopped', 'UDP 发送期间端点已停止，不能证明远端未收到');
            }
            if ($written === false) {
                $this->fail('udp_send_failed');
            }
            if ($written !== strlen($data)) {
                throw new TaskException('udp_partial_send', 'UDP 未完整提交报文，不自动重发');
            }
            $this->sent++;
            return $written;
        } finally {
            $this->operation = -1;
            if ($this->state === 'stopping' && !$this->stopping) {
                $this->release();
            }
        }
    }

    /** @return array{address: string, port: int} 启动时的本地绑定地址快照，不泄露原生 Socket。 */
    public function localAddress(): array
    {
        $this->assertActive();
        return $this->local;
    }

    /**
     * 同线程停止；先原生取消等待，收发返回且原生延迟关闭完成后才归还额度。
     *
     * 取消重入不重复关闭；保持 stopping/allocated 直到原生事件循环完成关闭后结算。
     * 已停止的实例不能重启。调用者应在所属作用域结束时显式调用，即使启动部分失败。
     */
    public function stop(): void
    {
        $this->threadOwner->assertCurrent();
        if ($this->state === 'closed' || $this->stopping) {
            return;
        }
        $this->state = 'stopping';
        $this->stopping = true;
        try {
            if ($this->operation >= 0) {
                // 使用当前等待登记的原生取消函数，保留 IOCP/io_uring 的实际完成责任。
                Coroutine::cancel($this->operation);
            }
        } finally {
            $this->stopping = false;
            if ($this->operation < 0) {
                $this->release();
            }
        }
    }

    /** @return array<string, int|float|string|bool> 同线程可观察在途、实际发送期限与缓冲预算；内核丢包不伪造成功计数。 */
    public function statistics(): array
    {
        $this->threadOwner->assertCurrent();
        return ['state' => $this->state, 'allocated' => $this->allocated, 'in_flight' => $this->operation >= 0 ? 1 : 0,
            'queued_datagrams' => 0, 'max_datagram_bytes' => $this->maxDatagramBytes,
            'write_timeout' => $this->effectiveWriteTimeout,
            'socket_buffer_size' => $this->socketBufferBytes, 'receive_buffer_bytes' => $this->receiveBufferBytes,
            'send_buffer_bytes' => $this->sendBufferBytes, 'native_receive_bytes' => 65536, 'native_write_bytes' => 65536,
            'received' => $this->received, 'sent' => $this->sent, 'oversized' => $this->oversized];
    }

    /** 活动原生资源禁止序列化、线程传递和复制。 */
    public function __serialize(): array
    {
        throw new TaskException('resource_transfer_forbidden', 'UDP 端点不能序列化或跨线程传递');
    }

    /** 不允许从外部数据恢复资源归属与额度。 */
    public function __unserialize(array $data): void
    {
        throw new TaskException('resource_transfer_forbidden', 'UDP 端点必须在所属线程创建');
    }

    private function __clone(): void
    {
    }

    private function assertActive(): void
    {
        $this->threadOwner->assertCurrent();
        $this->owner?->assertCurrent();
        if ($this->state !== 'active') {
            throw new TaskException('udp_stopped', 'UDP 端点尚未启动或已经退役');
        }
        if ($this->operation >= 0) {
            throw new TaskException('udp_operation_active', 'UDP 端点已有在途收发');
        }
    }

    private function fail(string $reason): never
    {
        $code = $this->socket->errCode;
        if ($code === SOCKET_ETIMEDOUT) {
            $reason = 'udp_timeout';
        } elseif ($code === SOCKET_ECANCELED) {
            $reason = 'udp_cancelled';
        } elseif ($code === SOCKET_EMSGSIZE) {
            $reason = 'udp_message_size';
        }
        throw new TaskException($reason, 'UDP 原生操作失败：' . $reason . '，errno=' . $code);
    }

    /** 无在途收发且退出取消栈后撤销引用；原生 Socket::free 在 Event defer 中才关闭 FD。 */
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
        // 固定上游 defer 按登记顺序执行：Socket 析构先登记真实关闭，本回调随后结算。
        if (!\Swoole\Event::defer(function (): void {
            $this->settle();
        })) {
            throw new TaskException('udp_close_pending', 'UDP 关闭结算未登记，继续保留资源额度');
        }
    }

    private function settle(): void
    {
        $this->state = 'closed';
        if ($this->allocated) {
            $this->allocated = false;
            $this->budget->release();
        }
    }
}
