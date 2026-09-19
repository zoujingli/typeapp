<?php

declare(strict_types=1);

namespace app\broker\service;

/**
 * 管理端受控 HTTPS CRL 源：复用 Swoole Process 阻塞 GET，不在心跳里等待网络。
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

    /**
     * 子进程内阻塞 GET；校验 HTTPS、不跟随跳转、正文至多 1 MiB。
     */
    private static function download(string $url, string $caFile, string $output): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'cafile' => $caFile,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $parts['host'],
                'disable_compression' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return;
        }
        $status = 0;
        $meta = stream_get_meta_data($handle);
        $headers = $meta['wrapper_data'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $line) {
                if (is_string($line) && preg_match('/^HTTP\/[0-9.]+\s+(\d{3})/', $line, $match) === 1) {
                    $status = (int) $match[1];
                }
            }
        }
        if ($status !== 200) {
            fclose($handle);
            return;
        }
        $body = '';
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > 1048576) {
                fclose($handle);
                return;
            }
        }
        fclose($handle);
        if (!str_contains($body, 'BEGIN X509 CRL')) {
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
