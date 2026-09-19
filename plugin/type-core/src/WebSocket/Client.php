<?php

declare(strict_types=1);

namespace Type\Core\WebSocket;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as NativeClient;
use Swoole\WebSocket\Frame;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ManagedResource;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

/**
 * 有界 WebSocket 客户端；握手、掩码、分帧、关闭握手与 TLS 完全由 Swoole 原生持有。
 *
 * 本类只负责升级结果校验、发送与接收上限、以及真实关闭结算，不自己拼装帧或计算
 * 掩码。每次接收返回原生已经重组好的一条消息；控制帧不进入业务消息路径，也不延长
 * 本次接收期限之外的等待。WSS 强制校验证书链和主机名，仅允许 TLS 1.2/1.3，不启用
 * 0-RTT 或自签名绕过。
 *
 * 实例固定在创建线程，同线程允许一读一写并行；不提供自动重连、隐式重发或应用级排队。
 */
final class Client implements ManagedResource
{
    private ExecutionOwner $owner;
    private ?NativeClient $client = null;
    private string $state = 'new';
    private bool $allocated = false;
    private int $sentBytes = 0;
    private int $receivedBytes = 0;

    private function __construct(
        private ResourceBudget $budget,
        private string $host,
        private int $port,
        private bool $tls,
        private string $path,
        private array $headers,
        private int $maxMessageBytes,
        private float $connectTimeout,
        private array $tlsOptions
    ) {
        $this->owner = new ExecutionOwner(false);
    }

    /**
     * 创建尚未握手的客户端。path 必须为以 `/` 开头的绝对路径；WSS 强制校验证书链。
     *
     * @param array<string, string> $headers 附加请求头，可包含 Origin 与 Sec-WebSocket-Protocol。
     * @param array{ssl_cafile?: string, ssl_host_name?: string, ssl_protocols?: int} $tlsOptions 仅在 tls=true 时使用。
     * @throws TaskException 主机、端口、路径、字节上限、期限或 TLS 配置无效。
     */
    public static function create(
        ResourceBudget $budget,
        string $host,
        int $port,
        string $path = '/',
        bool $tls = false,
        array $headers = [],
        int $maxMessageBytes = 1048576,
        float $connectTimeout = 5.0,
        array $tlsOptions = []
    ): self {
        return new self(
            $budget,
            $host,
            $port,
            $tls,
            self::path($path),
            $headers,
            self::bytes($maxMessageBytes),
            self::timeout($connectTimeout),
            self::tls($host, $tls, $tlsOptions)
        );
    }

    /**
     * 完成真实握手；TLS 与 HTTP 升级由原生一次完成，合计受 connectTimeout 约束。
     * @throws TaskException 缺少协程、状态不符、握手失败或期限用尽。
     * @throws \Type\Runtime\CapacityException 线程共享额度已满。
     */
    public function start(): void
    {
        $this->owner->assertCurrent();
        CoroutineRuntime::assertAvailable();
        if (Coroutine::getCid() < 0) {
            throw new TaskException('websocket_coroutine_required', 'WebSocket 握手需要已有协程');
        }
        if ($this->state === 'active') {
            return;
        }
        if ($this->state !== 'new') {
            throw new TaskException('websocket_client_stopped', 'WebSocket 客户端已经退役');
        }
        if (!$this->allocated) {
            $this->budget->acquire();
            $this->allocated = true;
        }
        $client = new NativeClient($this->host, $this->port, $this->tls);
        $settings = ['timeout' => $this->connectTimeout, 'websocket_mask' => true];
        if ($this->tls) {
            $settings = $settings + $this->tlsOptions;
        }
        $client->set($settings);
        $client->setHeaders($this->headers + ['Host' => $this->host . ':' . $this->port]);
        try {
            if (!$client->upgrade($this->path)) {
                $this->state = 'closed';
                throw new TaskException('websocket_upgrade_failed', 'WebSocket 握手未成功：' . $client->statusCode);
            }
        } catch (\Throwable $error) {
            $this->client = $client;
            $this->stop();
            throw $error;
        }
        $this->client = $client;
        $this->state = 'active';
    }

    /**
     * 发送一条消息；超过上限直接拒绝，不提交给原生，也不自动分片。
     * @throws TaskException 未握手、长度超限或原生发送失败。
     */
    public function send(string $data, bool $binary = true, float $timeout = 5.0): int
    {
        $this->assertActive(self::timeout($timeout));
        $length = (int) strlen($data);
        if ($length > $this->maxMessageBytes) {
            throw new TaskException('websocket_message_too_large', 'WebSocket 单条消息超过字节上限，未提交');
        }
        $opcode = $binary ? \WEBSOCKET_OPCODE_BINARY : \WEBSOCKET_OPCODE_TEXT;
        if ($this->client->push($data, $opcode, \SWOOLE_WEBSOCKET_FLAG_FIN) !== true) {
            throw new TaskException('websocket_send_failed', 'WebSocket 原生发送失败，errno=' . $this->client->errCode);
        }
        $this->sentBytes += $length;
        return $length;
    }

    /**
     * 接收一条完整消息；原生已重组分片，控制帧不会作为消息返回。
     * 返回 null 表示连接已关闭或本次接收超时，不表示收到一条空消息。
     * @throws TaskException 未握手或单条消息超过上限。
     */
    public function receive(float $timeout = 5.0): ?string
    {
        $timeout = self::timeout($timeout);
        $this->assertActive($timeout);
        $deadline = new Deadline($timeout);
        while (true) {
            $remaining = $deadline->remaining() ?? 0.0;
            if ($remaining <= 0) {
                return null;
            }
            $frame = $this->client->recv($remaining);
            if ($frame === false || $frame === '') {
                return null;
            }
            if ($frame instanceof Frame && ($frame->opcode === \WEBSOCKET_OPCODE_PING
                || $frame->opcode === \WEBSOCKET_OPCODE_PONG
                || $frame->opcode === \WEBSOCKET_OPCODE_CLOSE)) {
                if ($frame->opcode === \WEBSOCKET_OPCODE_CLOSE) {
                    $this->stop();
                    return null;
                }
                continue;
            }
            $data = $frame instanceof Frame ? $frame->data : (string) $frame;
            $length = (int) strlen($data);
            if ($length > $this->maxMessageBytes) {
                $this->stop();
                throw new TaskException('websocket_message_too_large', 'WebSocket 收到超过字节上限的消息，已关闭连接');
            }
            $this->receivedBytes += $length;
            return $data;
        }
    }

    /** 关闭连接并释放额度；不等待对端确认，也不保留可重连状态。 */
    public function stop(): void
    {
        $this->owner->assertCurrent();
        if ($this->state === 'closed') {
            return;
        }
        $this->state = 'closed';
        if ($this->client !== null) {
            $this->client->close();
            $this->client = null;
        }
        if ($this->allocated) {
            $this->allocated = false;
            $this->budget->release();
        }
    }

    /** @return array<string, mixed> 连接目标、状态与真实收发计数。 */
    public function statistics(): array
    {
        $this->owner->assertCurrent();
        return ['state' => $this->state, 'host' => $this->host, 'port' => $this->port, 'tls' => $this->tls,
            'path' => $this->path, 'max_message_bytes' => $this->maxMessageBytes,
            'sent_bytes' => $this->sentBytes, 'received_bytes' => $this->receivedBytes];
    }

    /** 活动连接禁止序列化、跨线程传递和复制。 */
    public function __serialize(): array
    {
        throw new TaskException('resource_transfer_forbidden', 'WebSocket 客户端不能序列化或跨线程传递');
    }

    /** 不允许从外部数据恢复资源归属及额度。 */
    public function __unserialize(array $data): void
    {
        throw new TaskException('resource_transfer_forbidden', 'WebSocket 客户端必须在所属线程创建');
    }

    private function __clone(): void
    {
    }

    private function assertActive(float $timeout): void
    {
        if ($this->state !== 'active' || $this->client === null) {
            throw new TaskException('websocket_not_active', 'WebSocket 客户端尚未完成握手');
        }
    }

    private static function path(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '#') || str_contains($path, "\0")) {
            throw new TaskException('websocket_invalid_configuration', 'WebSocket 路径必须是无片段与空字节的绝对路径');
        }
        return $path;
    }

    private static function bytes(int $bytes): int
    {
        if ($bytes < 1 || $bytes > 1048576) {
            throw new TaskException('websocket_invalid_configuration', 'WebSocket 单条消息上限必须为 1 B–1 MiB');
        }
        return $bytes;
    }

    private static function timeout(float $timeout): float
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 60) {
            throw new TaskException('websocket_invalid_timeout', 'WebSocket 单次期限必须为 (0,60] 秒');
        }
        return $timeout;
    }

    /**
     * @param array{ssl_cafile?: string, ssl_host_name?: string, ssl_protocols?: int} $options
     * @return array<string, mixed>
     */
    private static function tls(string $host, bool $enabled, array $options): array
    {
        if (!$enabled) {
            if ($options !== []) {
                throw new TaskException('websocket_invalid_configuration', 'WebSocket 未启用 TLS 却提供了 TLS 选项');
            }
            return [];
        }
        $allowed = ['ssl_cafile', 'ssl_host_name', 'ssl_protocols'];
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new TaskException('websocket_invalid_configuration', 'WebSocket 含有未声明的 TLS 选项');
        }
        $protocols = (int) (\SWOOLE_SSL_TLSv1_2 | \SWOOLE_SSL_TLSv1_3);
        $options += ['ssl_host_name' => $host, 'ssl_protocols' => $protocols];
        if (!is_string($options['ssl_host_name']) || $options['ssl_host_name'] === '' || str_contains($options['ssl_host_name'], "\0")
            || !is_int($options['ssl_protocols']) || $options['ssl_protocols'] <= 0 || ($options['ssl_protocols'] & ~$protocols) !== 0
            || (isset($options['ssl_cafile']) && (!is_string($options['ssl_cafile']) || $options['ssl_cafile'] === ''
                || str_contains($options['ssl_cafile'], "\0")))) {
            throw new TaskException('websocket_invalid_configuration', 'WSS 客户端必须校验证书链与主机名，且仅允许 TLS 1.2/1.3');
        }
        $settings = [
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_host_name' => $options['ssl_host_name'],
            'ssl_protocols' => $options['ssl_protocols'],
        ];
        if (isset($options['ssl_cafile'])) {
            $settings['ssl_cafile'] = $options['ssl_cafile'];
        }
        return $settings;
    }
}
