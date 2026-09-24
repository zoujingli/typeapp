<?php

declare(strict_types=1);

namespace Type\Testing;

use Type\Runtime\Deadline;

/** 集成测试客户端；不跟随重定向，不复用连接，不接受模糊的响应边界。 */
final class HttpClient
{
    private string $endpoint;
    private string $hostHeader;
    private float $seconds;
    private int $maximumBytes;
    /**
     * 配置独立协议测试客户端；仅接受 HTTP(S) 的主机与端口。
     * @param float $seconds 每次请求包含连接、写入和读取的总秒数，范围为 (0, 60]。
     * @param int $maximumBytes 请求和响应正文的字节上限，范围为 1 至 64 MiB。
     * @throws \InvalidArgumentException 地址包含凭据、查询、片段、非根路径或预算无效。
     */
    public function __construct(string $base, float $seconds = 3.0, int $maximumBytes = 2097152)
    {
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host'])
            || preg_match('/[\s\x00-\x1f\x7f]/', $base) || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true) || !is_finite($seconds) || $seconds <= 0 || $seconds > 60 || $maximumBytes < 1 || $maximumBytes > 67108864) {
            throw new \InvalidArgumentException('HTTP 测试地址或预算无效');
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('HTTP 测试端口无效');
        }
        $this->hostHeader = $parts['host'] . ':' . $port;
        $this->endpoint = ($parts['scheme'] === 'https' ? 'tls://' : 'tcp://') . $this->hostHeader;
        $this->seconds = $seconds;
        $this->maximumBytes = $maximumBytes;
    }

    /**
     * 通过独立连接发送一次请求，任何返回或异常路径都会关闭连接。
     * @param array<string, string> $headers 业务请求头；帧长度和连接头由客户端管理。
     * @throws \InvalidArgumentException 请求参数无效、正文超量或覆盖受控请求头。
     * @throws \RuntimeException 连接、截止、响应帧或响应容量检查失败。
     */
    public function request(string $method, string $target, array $headers = [], string $body = ''): HttpResponse
    {
        if (!preg_match('/^[A-Z]+$/D', $method) || !str_starts_with($target, '/') || preg_match('/[\x00-\x20\x7f]/', $target) || strlen($body) > $this->maximumBytes) {
            throw new \InvalidArgumentException('HTTP 测试请求或正文预算无效');
        }
        $wire = $method . ' ' . $target . " HTTP/1.1\r\nHost: " . $this->hostHeader . "\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n";
        $seen = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || !preg_match('/^[A-Za-z0-9-]+$/D', $name) || preg_match('/[\x00-\x1f\x7f]/', $value)
                || in_array(strtolower($name), ['host', 'connection', 'content-length', 'transfer-encoding'], true) || isset($seen[strtolower($name)])) {
                throw new \InvalidArgumentException('HTTP 请求头不合法或覆盖了受控帧字段');
            }
            $seen[strtolower($name)] = true;
            $wire .= $name . ': ' . $value . "\r\n";
        }
        $wire .= "\r\n" . $body;
        $deadline = new Deadline($this->seconds);
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
        $socket = @stream_socket_client($this->endpoint, $errno, $error, $this->seconds, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new \RuntimeException('HTTP 测试连接失败：' . $error);
        }
        try {
            $offset = 0;
            while ($offset < strlen($wire)) {
                $this->timeout($socket, $deadline);
                $written = @fwrite($socket, substr($wire, $offset, 16384));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('HTTP 测试请求写入失败');
                } $offset += $written;
            }
            $response = '';
            while (!feof($socket)) {
                $this->timeout($socket, $deadline);
                $chunk = fread($socket, 16384);
                if ($chunk === false || stream_get_meta_data($socket)['timed_out']) {
                    throw new \RuntimeException('HTTP 测试响应读取超时或失败');
                }
                $response .= $chunk;
                if (strlen($response) > $this->maximumBytes + 65536) {
                    throw new \RuntimeException('HTTP 测试响应超过预算');
                }
            }
            return $this->parse($response, $method);
        } finally {
            fclose($socket);
        }
    }

    private function parse(string $wire, string $method): HttpResponse
    {
        $boundary = strpos($wire, "\r\n\r\n");
        if ($boundary === false || $boundary > 65536) {
            throw new \RuntimeException('HTTP 响应头不完整或超量');
        }
        $lines = explode("\r\n", substr($wire, 0, $boundary));
        $body = substr($wire, $boundary + 4);
        if (!preg_match('/^HTTP\/1\.[01] ([1-5][0-9]{2})(?: [^\r\n]*)?$/D', array_shift($lines), $status)) {
            throw new \RuntimeException('HTTP 状态行无效');
        }
        $headers = [];
        foreach ($lines as $line) {
            if (!preg_match('/^([A-Za-z0-9-]+):[ \t]*([^\x00-\x08\x0a-\x1f\x7f]*)$/D', $line, $match)) {
                throw new \RuntimeException('HTTP 响应头无效');
            }
            $headers[strtolower($match[1])][] = trim($match[2]);
        }
        if (isset($headers['transfer-encoding'], $headers['content-length']) || count($headers['content-length'] ?? []) > 1) {
            throw new \RuntimeException('HTTP 响应帧字段冲突');
        }
        if ($method === 'HEAD' || in_array((int) $status[1], [204, 304], true)) {
            if ($body !== '') {
                throw new \RuntimeException('无正文响应包含了数据');
            }
        } elseif (isset($headers['transfer-encoding'])) {
            if (count($headers['transfer-encoding']) !== 1 || strtolower($headers['transfer-encoding'][0]) !== 'chunked') {
                throw new \RuntimeException('HTTP 测试客户端不支持该传输编码');
            }
            $body = $this->chunks($body);
        } elseif (isset($headers['content-length']) && (!preg_match('/^[0-9]+$/D', $headers['content-length'][0]) || (string) strlen($body) !== (ltrim($headers['content-length'][0], '0') ?: '0'))) {
            throw new \RuntimeException('HTTP 响应正文与声明长度不一致');
        }
        if (strlen($body) > $this->maximumBytes) {
            throw new \RuntimeException('HTTP 正文超过预算');
        }
        return new HttpResponse((int) $status[1], $headers, $body);
    }

    private function chunks(string $body): string
    {
        $offset = 0;
        $decoded = '';
        while (true) {
            $end = strpos($body, "\r\n", $offset);
            if ($end === false || $end - $offset > 1024) {
                throw new \RuntimeException('分块响应长度行缺失');
            }
            $size = explode(';', substr($body, $offset, $end - $offset), 2)[0];
            if (!preg_match('/^[a-fA-F0-9]{1,8}$/D', $size)) {
                throw new \RuntimeException('分块响应长度无效');
            }
            $length = (int) hexdec($size);
            $offset = $end + 2;
            if ($length === 0) {
                if (substr($body, $offset) !== "\r\n") {
                    throw new \RuntimeException('测试客户端不接受额外 trailer 或多余响应');
                }
                return $decoded;
            }
            if ($length > $this->maximumBytes - strlen($decoded) || strlen($body) < $offset + $length + 2 || substr($body, $offset + $length, 2) !== "\r\n") {
                throw new \RuntimeException('分块响应超过预算或提前结束');
            }
            $decoded .= substr($body, $offset, $length);
            $offset += $length + 2;
        }
    }
    private function timeout(mixed $stream, Deadline $deadline): void
    {
        $remaining = $deadline->remaining() ?? 0.0;
        if ($remaining <= 0) {
            throw new \RuntimeException('HTTP 测试总截止预算已用尽');
        }
        $seconds = (int) floor($remaining);
        stream_set_timeout($stream, $seconds, max(1, (int) (($remaining - $seconds) * 1000000)));
    }
}
