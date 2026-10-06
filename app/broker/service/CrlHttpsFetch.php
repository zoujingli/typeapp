<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Core\Http\Client;
use Type\Runtime\Cancellation;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/**
 * 管理端受控 HTTPS CRL 源：使用作用域 HTTP 客户端；每个 CA 至多一个请求，总在途最多 32 个。
 * 失败不覆盖已接纳列表；不跟随跳转；客户端不能指定地址。
 */
final class CrlHttpsFetch
{
    /** @var array<string, array{cancel:Cancellation,scope:?ExecutionScope,ca:string,done:bool,pem:string,cleanup:bool}> */
    private array $running = [];
    private bool $stopping = false;

    /**
     * 为到期的 CA 启动一次拉取；同一 CA 已有请求或已达到总上限时跳过。
     */
    public function spawn(string $caId, string $url, string $caPem): void
    {
        if ($this->stopping || $caId === '' || isset($this->running[$caId]) || count($this->running) >= 32) {
            return;
        }
        $caFile = tempnam(sys_get_temp_dir(), 'broker-crl-ca-');
        if ($caFile === false || file_put_contents($caFile, $caPem) !== strlen($caPem)) {
            if (is_string($caFile)) {
                @unlink($caFile);
            }
            return;
        }
        $cancellation = new Cancellation();
        $this->running[$caId] = ['cancel' => $cancellation, 'scope' => null, 'ca' => $caFile, 'done' => false, 'pem' => '', 'cleanup' => false];
        $created = \Swoole\Coroutine::create(function () use ($caId, $url, $caFile): void {
            $scope = new ExecutionScope(new Deadline(10.0), cancellation: $this->running[$caId]['cancel']);
            $this->running[$caId]['scope'] = $scope;
            try {
                $this->running[$caId]['pem'] = $scope->run(function (ExecutionScope $current) use ($url, $caFile): string {
                    return $this->download($url, $caFile);
                });
            } catch (\Throwable) {
                // collect 统一返回网络失败，失败响应不覆盖已接纳列表。
            } finally {
                try {
                    $scope->close();
                } catch (\Throwable) {
                    $this->running[$caId]['cleanup'] = true;
                    $this->running[$caId]['pem'] = '';
                }
                $this->running[$caId]['done'] = $scope->state() === 'closed';
                if ($this->running[$caId]['done']) {
                    $this->running[$caId]['scope'] = null;
                }
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
            $finished[] = $job['pem'] !== '' ? ['ca_id' => $caId, 'pem' => $job['pem']]
                : ['ca_id' => $caId, 'error' => $job['cleanup'] ? 'cleanup' : 'network'];
        }
        return $finished;
    }

    /** 节点退出先关闭客户端，再等待真实结束；下载任务自行删除其临时 CA 文件。 */
    public function stop(): void
    {
        $this->stopping = true;
        foreach ($this->running as $job) {
            $job['cancel']->cancel();
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

    /** HTTPS 目标和 CRL 内容由业务判断；公共客户端负责 TLS、预算与关闭。 */
    private function download(string $url, string $caFile): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return '';
        }
        $response = (new Client())->request('GET', $url, timeout: 10.0, maxResponseBytes: 1048576, tlsOptions: ['ssl_cafile' => $caFile]);
        try {
            $body = $response->getBody()->getContents();
        } finally {
            $response->getBody()->close();
        }
        if ($response->getStatusCode() !== 200 || $this->stopping || $body === '' || !str_contains($body, 'BEGIN X509 CRL')) {
            return '';
        }
        return $body;
    }
}
