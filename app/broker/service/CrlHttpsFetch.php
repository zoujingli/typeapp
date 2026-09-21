<?php

declare(strict_types=1);

namespace app\broker\service;

/**
 * 管理端受控 HTTPS CRL 源：使用 Swoole 协程 HTTP Client；每个 CA 至多一个请求，总在途最多 32 个。
 * 失败不覆盖已接纳列表；不跟随跳转；客户端不能指定地址。
 */
final class CrlHttpsFetch
{
    /** @var array<string, array{client: \Swoole\Coroutine\Http\Client|null, ca:string, done:bool, pem:string}> */
    private array $running = [];

    /**
     * 为到期的 CA 启动一次拉取；同一 CA 已有请求或已达到总上限时跳过。
     */
    public function spawn(string $caId, string $url, string $caPem): void
    {
        if ($caId === '' || isset($this->running[$caId]) || count($this->running) >= 32) {
            return;
        }
        $caFile = tempnam(sys_get_temp_dir(), 'broker-crl-ca-');
        if ($caFile === false || file_put_contents($caFile, $caPem) !== strlen($caPem)) {
            if (is_string($caFile)) {
                @unlink($caFile);
            }
            return;
        }
        $this->running[$caId] = ['client' => null, 'ca' => $caFile, 'done' => false, 'pem' => ''];
        $created = \Swoole\Coroutine::create(function () use ($caId, $url, $caFile): void {
            try {
                $this->running[$caId]['pem'] = $this->download($caId, $url, $caFile);
            } catch (\Throwable) {
                // collect 统一返回网络失败，失败响应不覆盖已接纳列表。
            } finally {
                $this->running[$caId]['client']?->close();
                $this->running[$caId]['client'] = null;
                $this->running[$caId]['done'] = true;
                @unlink($caFile);
            }
        });
        if ($created === false) {
            @unlink($caFile);
            unset($this->running[$caId]);
        }
    }

    /**
     * 收取已真实完成的请求；成功返回 PEM，失败只给原因。
     * @return list<array{ca_id:string,pem?:string,error?:string}>
     */
    public function collect(): array
    {
        $finished = [];
        foreach ($this->running as $caId => $job) {
            if (!$job['done']) {
                continue;
            }
            unset($this->running[$caId]);
            $finished[] = $job['pem'] !== '' ? ['ca_id' => $caId, 'pem' => $job['pem']] : ['ca_id' => $caId, 'error' => 'network'];
        }
        return $finished;
    }

    /** 节点退出先关闭客户端，再等待真实结束；下载任务自行删除其临时 CA 文件。 */
    public function stop(): void
    {
        foreach ($this->running as $job) {
            $job['client']?->close();
        }
        $deadline = new \Type\Runtime\Deadline(11.0);
        while ($this->running !== []) {
            $this->collect();
            if ($this->running === []) {
                return;
            }
            if ($deadline->expired()) {
                throw new \RuntimeException('broker_crl_shutdown_incomplete');
            }
            \Swoole\Coroutine::sleep(0.001);
        }
    }

    /** 使用 Swoole 协程 HTTP Client；校验 HTTPS、不跟随跳转、正文至多 1 MiB。 */
    private function download(string $caId, string $url, string $caFile): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return '';
        }
        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : 443;
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        $client = new \Swoole\Coroutine\Http\Client($parts['host'], $port, true);
        $this->running[$caId]['client'] = $client;
        $body = '';
        $client->set([
            'timeout' => 10,
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_cafile' => $caFile,
            'ssl_host_name' => $parts['host'],
            'follow_location' => false,
            'http_compression' => false,
            'body_decompression' => false,
            'write_func' => static function (\Swoole\Coroutine\Http\Client $http, string $chunk) use (&$body): void {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    throw new \RuntimeException('crl_response_too_large');
                }
                $body .= $chunk;
            },
        ]);
        if (!$client->get($path) || $client->statusCode !== 200 || !is_string($body)
            || $body === '' || strlen($body) > 1048576 || !str_contains($body, 'BEGIN X509 CRL')) {
            return '';
        }
        return $body;
    }
}
