<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine\Http\Client as NativeClient;
use Swoole\Timer;
use Type\Core\Http\Message\Factory;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;
use Type\Runtime\TaskException;

/**
 * 当前执行作用域的有界 HTTP 客户端；通信、TLS 与协议解析由 Swoole 官方客户端执行。
 *
 * 构造不连接，首次请求登记资源归属。同一实例仅在所属 scope 顺序使用，每次请求关闭
 * 原生连接；不持有跨请求池，不跟随跳转，不重试。响应是已完整接收的 PSR 消息，正文流
 * 由接收者关闭。取消只唤醒原生等待，真实请求退出后才完成收尾。
 */
final class Client implements ManagedResource
{
    private ?ExecutionScope $scope = null;
    private ?NativeClient $client = null;
    private bool $stopped = false;
    private bool $requesting = false;

    /** 登记当前作用域；不创建连接，通常由 request() 自动调用。 */
    public function start(): void
    {
        $current = ExecutionScope::current();
        if ($this->stopped) {
            throw new TaskException('http_client_stopped', 'HTTP 客户端已经停止');
        }
        if ($this->scope !== null && $current !== $this->scope) {
            throw new TaskException('http_client_scope_mismatch', 'HTTP 客户端只能在所属执行作用域使用');
        }
        $this->scope = $current;
        // open() 先登记再调用 start()，因此这里的重复调用不会重复登记或打开连接。
        $current->open($this);
    }

    /**
     * 完整接收一次响应；HTTP 4xx/5xx 也是响应，由业务判断状态。
     *
     * timeout 是连接、发送和接收合计的秒数，并受 scope 剩余期限进一步限制；读取中检查
     * maxResponseBytes，不接受部分响应。禁用压缩协商和自动解压，预算针对收到的正文字节。
     * URL 只允许 HTTP/HTTPS，不携带凭据或片段；请求头不得接管 Host、长度或连接协议。
     *
     * @param array<string,string> $headers 单值请求头；名称不区分大小写且不得重复。
     * @param array{ssl_cafile?:string,ssl_host_name?:string,ssl_protocols?:int} $tlsOptions
     *                                                                                       沿用 Swoole 选项；HTTPS 始终校验证书链与主机名，仅允许 TLS 1.2/1.3。
     * @throws TaskException 配置、归属、预算、取消、传输或真实清理失败；错误不含目标凭据。
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        float $timeout = 10.0,
        int $maxResponseBytes = 1048576,
        array $tlsOptions = []
    ): ResponseInterface {
        $this->start();
        if ($this->requesting || $this->client !== null) {
            throw new TaskException('http_client_busy', 'HTTP 客户端已有在途请求');
        }
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 60
            || $maxResponseBytes < 1 || $maxResponseBytes > 16777216 || strlen($body) > 16777216
            || preg_match('/^[A-Z][A-Z0-9!#$%&\'*+.^_`|~-]*$/D', $method) !== 1 || $method === 'CONNECT') {
            throw new TaskException('http_client_invalid_configuration', 'HTTP 方法、期限或正文预算无效');
        }
        $target = self::target($url);
        $settings = self::tls($target['host'], $target['tls'], $tlsOptions);
        $requestHeaders = self::headers($headers);
        $remaining = $this->scope->deadline()->remaining();
        $scopeDeadline = $remaining !== null && $remaining <= $timeout;
        $seconds = $scopeDeadline ? $remaining : $timeout;
        $this->scope->assertActive();
        $client = new NativeClient($target['host'], $target['port'], $target['tls']);
        $this->client = $client;
        $this->requesting = true;
        $receivedBody = '';
        $timedOut = false;
        // Swoole 返回 int|false；保留联合值，避免 AOT 将定时器编号收窄为 bool。
        $timer = \std::any(false);
        $subscription = 0;
        try {
            $client->set($settings + [
                'connect_timeout' => $seconds,
                'timeout' => $seconds,
                'keep_alive' => false,
                'http_compression' => false,
                'body_decompression' => false,
                'write_func' => static function (NativeClient $http, string $chunk) use (&$receivedBody, $maxResponseBytes): void {
                    if (strlen($chunk) > $maxResponseBytes - strlen($receivedBody)) {
                        throw new TaskException('http_client_response_too_large', 'HTTP 响应正文超过字节预算');
                    }
                    $receivedBody .= $chunk;
                },
            ]);
            $client->setHeaders($requestHeaders);
            $client->setMethod($method);
            if ($body !== '') {
                $client->setData($body);
            }
            $subscription = $this->scope->cancellation()->subscribe(static function () use ($client): void {
                $client->close();
            });
            // 原生 timeout 约束一次等待；总期限同时防止持续小块响应无限延长请求。
            $timer = Timer::after(max(1, (int) ceil($seconds * 1000.0)), static function () use ($client, &$timedOut): void {
                $timedOut = true;
                $client->close();
            });
            if ($timer === false) {
                throw new TaskException('http_client_timer_failed', '无法登记 HTTP 请求总期限');
            }
            $received = $client->execute($target['path']);
            $this->scope->assertActive();
            if ($timedOut || $client->statusCode === -2) {
                // 原生等待按毫秒结算，可能先于 scope 的截止回调返回；保留实际采用的预算归属。
                if ($scopeDeadline) {
                    throw new TaskException('deadline_exceeded', '执行作用域截止预算已用尽');
                }
                throw new TaskException('http_client_timeout', 'HTTP 请求期限已用尽');
            }
            if (!$received || $client->statusCode < 200 || $client->statusCode > 599) {
                throw new TaskException('http_client_request_failed', 'HTTP 请求未完整完成，errno=' . $client->errCode);
            }
            $factory = new Factory();
            $response = $factory->createResponse((int) $client->statusCode);
            foreach (is_array($client->headers) ? $client->headers : [] as $name => $values) {
                $response = $response->withHeader((string) $name, $values);
            }
            $response->getBody()->write($receivedBody);
            $response->getBody()->rewind();
            return $response;
        } finally {
            if ($timer !== false && Timer::exists($timer)) {
                Timer::clear($timer);
            }
            $this->scope->cancellation()->unsubscribe($subscription);
            $this->requesting = false;
            $this->closeConnection();
        }
    }

    /**
     * 撤销后续请求并关闭连接；跨执行者不能代替其收尾，应通过 scope 的 Cancellation 取消。
     * @throws TaskException 原生连接尚未完成关闭；不会清除仍被持有的资源。
     */
    public function stop(): void
    {
        $this->scope?->assertOwner();
        $this->stopped = true;
        $this->closeConnection();
        if ($this->requesting) {
            throw new TaskException('http_client_cleanup_incomplete', 'HTTP 在途请求尚未返回');
        }
    }

    /** HTTP 资源不能复制或通过序列化转移到其他执行者。 */
    public function __serialize(): array
    {
        throw new TaskException('resource_transfer_forbidden', 'HTTP 客户端不能序列化');
    }

    /** 不从外部数据恢复执行归属。 */
    public function __unserialize(array $data): void
    {
        throw new TaskException('resource_transfer_forbidden', 'HTTP 客户端必须在当前执行环境创建');
    }

    private function __clone(): void
    {
    }

    private function closeConnection(): void
    {
        if ($this->client === null) {
            return;
        }
        $this->client->close();
        if ($this->client->socket !== null) {
            throw new TaskException('http_client_cleanup_incomplete', 'HTTP 原生连接尚未完成关闭');
        }
        $this->client = null;
    }

    /** @return array{host:string,port:int,tls:bool,path:string} */
    private static function target(string $url): array
    {
        if ($url === '' || strlen($url) > 16384 || preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || preg_match('/%(?![0-9a-fA-F]{2})/', $url) === 1) {
            throw new TaskException('http_client_invalid_configuration', 'HTTP URL 含非法字符或超过长度预算');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new TaskException('http_client_invalid_configuration', 'HTTP URL 必须有主机且不携带凭据或片段');
        }
        $tls = $parts['scheme'] === 'https';
        $host = trim($parts['host'], '[]');
        $port = (int) ($parts['port'] ?? ($tls ? 443 : 80));
        if ($port < 1 || $port > 65535 || preg_match('/^[a-zA-Z0-9.:%_-]+$/D', $host) !== 1) {
            throw new TaskException('http_client_invalid_configuration', 'HTTP 主机或端口无效');
        }
        return ['host' => $host, 'port' => $port, 'tls' => $tls,
            'path' => ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '')];
    }

    /** @param array<string,string> $headers @return array<string,string> */
    private static function headers(array $headers): array
    {
        $result = [];
        $bytes = 0;
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1
                || !is_string($value) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
                throw new TaskException('http_client_invalid_configuration', 'HTTP 请求头无效');
            }
            $key = strtolower($name);
            if (isset($result[$key]) || in_array($key, ['host', 'content-length', 'transfer-encoding', 'connection', 'upgrade', 'accept-encoding'], true)) {
                throw new TaskException('http_client_invalid_configuration', 'HTTP 请求头重复或试图覆盖协议字段');
            }
            $bytes += strlen($name) + strlen($value) + 4;
            if ($bytes > 65536) {
                throw new TaskException('http_client_invalid_configuration', 'HTTP 请求头超过字节预算');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private static function tls(string $host, bool $enabled, array $options): array
    {
        if (!$enabled) {
            if ($options !== []) {
                throw new TaskException('http_client_invalid_configuration', 'HTTP 未启用 TLS 却提供了 TLS 选项');
            }
            return [];
        }
        if (array_diff(array_keys($options), ['ssl_cafile', 'ssl_host_name', 'ssl_protocols']) !== []) {
            throw new TaskException('http_client_invalid_configuration', 'HTTP 含未声明的 TLS 选项');
        }
        $protocols = (int) (\SWOOLE_SSL_TLSv1_2 | \SWOOLE_SSL_TLSv1_3);
        $options += ['ssl_host_name' => $host, 'ssl_protocols' => $protocols];
        if (!is_string($options['ssl_host_name']) || $options['ssl_host_name'] === ''
            || preg_match('/[\x00-\x20\x7f]/', $options['ssl_host_name']) === 1
            || !is_int($options['ssl_protocols']) || $options['ssl_protocols'] <= 0 || ($options['ssl_protocols'] & ~$protocols) !== 0
            || (array_key_exists('ssl_cafile', $options) && (!is_string($options['ssl_cafile'])
                || $options['ssl_cafile'] === '' || str_contains($options['ssl_cafile'], "\0") || !is_readable($options['ssl_cafile'])))) {
            throw new TaskException('http_client_invalid_configuration', 'HTTP 的 TLS 信任材料、主机名或协议无效');
        }
        return $options + ['ssl_verify_peer' => true, 'ssl_allow_self_signed' => false];
    }
}
