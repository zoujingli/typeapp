<?php

declare(strict_types=1);

namespace app\broker\service;

/**
 * 管理端受控 HTTPS CRL 源：由 Swoole Process 隔离任务，并在子进程内使用协程 HTTP Client。
 * 失败不覆盖已接纳列表；不跟随跳转；客户端不能指定地址。
 */
final class CrlHttpsFetch
{
    /** @var array<string, array{pid:int,path:string}> */
    private array $running = [];

    /**
     * 为到期的 CA 启动一次拉取；同一 CA 已有进程时跳过。
     */
    public function spawn(string $caId, string $url, string $caPem): void
    {
        if ($caId === '' || isset($this->running[$caId]) || !class_exists(\Swoole\Process::class)) {
            return;
        }
        $path = sys_get_temp_dir() . '/broker-crl-' . $caId . '-' . bin2hex(random_bytes(4));
        $caFile = $path . '.ca';
        if (file_put_contents($caFile, $caPem) !== strlen($caPem)) {
            @unlink($caFile);
            return;
        }
        $process = new \Swoole\Process(static function (\Swoole\Process $worker) use ($url, $caFile, $path): void {
            self::download($url, $caFile, $path);
            @unlink($caFile);
        }, false, 0);
        $pid = $process->start();
        if (!is_int($pid) || $pid <= 0) {
            @unlink($caFile);
            return;
        }
        $this->running[$caId] = ['pid' => $pid, 'path' => $path];
    }

    /**
     * 回收已退出的拉取进程；成功返回 PEM，失败只给原因。
     *
     * @return list<array{ca_id:string,pem?:string,error?:string}>
     */
    public function collect(): array
    {
        if ($this->running === [] || !class_exists(\Swoole\Process::class)) {
            return [];
        }
        $finished = [];
        $reaped = [];
        while (true) {
            $waited = \Swoole\Process::wait(false);
            if (!is_array($waited)) {
                break;
            }
            $reaped[(int) ($waited['pid'] ?? 0)] = true;
        }
        foreach ($this->running as $caId => $job) {
            $alive = !isset($reaped[$job['pid']]) && @\Swoole\Process::kill($job['pid'], 0);
            if ($alive) {
                continue;
            }
            $pem = is_file($job['path']) ? (string) file_get_contents($job['path']) : '';
            @unlink($job['path']);
            @unlink($job['path'] . '.ca');
            unset($this->running[$caId]);
            if (str_contains($pem, 'BEGIN X509 CRL')) {
                $finished[] = ['ca_id' => $caId, 'pem' => $pem];
            } else {
                $finished[] = ['ca_id' => $caId, 'error' => 'network'];
            }
        }
        return $finished;
    }

    /** 节点退出时结束未完成的拉取，并删除临时文件。 */
    public function stop(): void
    {
        foreach ($this->running as $job) {
            if (class_exists(\Swoole\Process::class)) {
                @\Swoole\Process::kill($job['pid'], SIGTERM);
                \Swoole\Process::wait(false);
            }
            @unlink($job['path']);
            @unlink($job['path'] . '.ca');
        }
        $this->running = [];
    }

    /** 使用 Swoole 协程 HTTP Client；校验 HTTPS、不跟随跳转、正文至多 1 MiB。 */
    private static function download(string $url, string $caFile, string $output): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return;
        }
        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : 443;
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        $body = '';
        $status = 0;
        \Swoole\Coroutine\run(static function () use ($parts, $port, $path, $caFile, &$body, &$status): void {
            $client = new \Swoole\Coroutine\Http\Client($parts['host'], $port, true);
            $client->set([
                'timeout' => 10,
                'ssl_verify_peer' => true,
                'ssl_allow_self_signed' => false,
                'ssl_cafile' => $caFile,
                'ssl_host_name' => $parts['host'],
                'follow_location' => false,
                'body_max_len' => 1048576,
            ]);
            if ($client->get($path)) {
                $status = (int) $client->statusCode;
                $body = is_string($client->body) ? $client->body : '';
            }
            $client->close();
        });
        if ($status !== 200 || $body === '' || strlen($body) > 1048576 || !str_contains($body, 'BEGIN X509 CRL')) {
            return;
        }
        $temporary = $output . '.tmp';
        if (file_put_contents($temporary, $body) !== strlen($body)) {
            @unlink($temporary);
            return;
        }
        if (!@rename($temporary, $output)) {
            @unlink($temporary);
        }
    }
}
