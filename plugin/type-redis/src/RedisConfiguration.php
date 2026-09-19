<?php

declare(strict_types=1);

namespace Type\Redis;

use InvalidArgumentException;
use Throwable;

final class RedisConfiguration
{
    private string $host;
    private int $port;
    private int $database;
    private ?string $password;
    private ?string $username;
    private float $connectTimeout;
    private float $readTimeout;
    private bool $tls;
    private ?string $caFile;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $database = 0,
        #[\SensitiveParameter] ?string $password = null,
        ?string $username = null,
        float $connectTimeout = 1.0,
        float $readTimeout = 1.0,
        bool $tls = false,
        ?string $caFile = null
    ) {
        if ($host === '' || preg_match('/[\s\x00]/', $host) || str_contains($host, '://') || $port < 1 || $port > 65535 || $database < 0
            || !is_finite($connectTimeout) || !is_finite($readTimeout) || $connectTimeout <= 0 || $readTimeout <= 0
            || $connectTimeout > 60 || $readTimeout > 60 || ($username !== null && ($username === '' || $password === null))
            || ($caFile !== null && (!$tls || !is_file($caFile) || !is_readable($caFile)))) {
            throw new InvalidArgumentException('Redis 地址、认证、超时或 TLS 配置无效');
        }
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->password = $password;
        $this->username = $username;
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout = $readTimeout;
        $this->tls = $tls;
        $this->caFile = $caFile;
    }

    public function database(): int
    {
        return $this->database;
    }

    public function connect(): \Redis
    {
        if (!extension_loaded('redis')) {
            throw new RedisException('missing_extension', 'NOT_STARTED', '缺少原生 phpredis 扩展');
        }
        try {
            $client = new \Redis();
            $client->setOption(\Redis::OPT_MAX_RETRIES, 0);
            $host = $this->host;
            $context = null;
            if ($this->tls) {
                $host = 'tls://' . (str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host);
                $options = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'allow_self_signed' => false];
                if ($this->caFile !== null) {
                    $options['cafile'] = $this->caFile;
                }
                $context = ['stream' => $options];
            }
            // TLS 验证失败可能先发出 PHP warning；连接结果仍统一转换为可捕获异常。
            if (!@$client->connect($host, $this->port, $this->connectTimeout, null, 0, $this->readTimeout, $context)) {
                throw new \RuntimeException('连接未完成');
            }
            if ($this->password !== null && !$client->auth($this->username === null ? $this->password : [$this->username, $this->password])) {
                throw new \RuntimeException('认证未完成');
            }
            $client->setOption(\Redis::OPT_MAX_RETRIES, 0);
            $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            $client->setOption(\Redis::OPT_PREFIX, '');
            if (!$client->select($this->database)) {
                throw new \RuntimeException('数据库选择未完成');
            }
            return $client;
        } catch (Throwable $error) {
            throw new RedisException('connect_failed', 'NOT_STARTED', 'Redis 连接初始化失败', $error);
        }
    }
}
