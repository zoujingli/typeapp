<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 为未接入严格身份选项的 stream hook 延续 IP SAN 校验；匹配仍由官方 OpenSSL 分支承担。 */
final class SwooleSocketSource
{
    public const REFERENCE = 'c9745c443265db59d0a9f32310e1f27e1873739f';

    /**
     * 官方 stream hook 尚未把 verify_peer_name 映射到 ssl_verify_host，Redis/mysqlnd 仍走旧入口。
     * 只解除 IP SAN 分支的严格模式前提，不改变证书链或显式关闭主机名验证的原生入口。
     * 上游补齐调用者映射并通过相同协程 Redis/MySQL TLS 回归后撤除。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws RuntimeException 固定原文、唯一替换或保存失败。
     */
    public function applyTls(string $directory): array
    {
        $file = 'src/network/socket.cc';
        $path = $directory . '/' . $file;
        $before = 'e5238743808338fab3430d562b2ea49187cedd91370c4de548b14b8757d148be';
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $before) {
            throw new RuntimeException('Swoole TLS 适配需要固定原文：' . $file);
        }
        $original = '    if ((strict && is_ip ? X509_check_ip_asc(cert, tls_host_name, 0)';
        $replacement = '    if ((is_ip ? X509_check_ip_asc(cert, tls_host_name, 0)';
        $source = (string) file_get_contents($path);
        if (substr_count($source, $original) !== 1) {
            throw new RuntimeException('Swoole TLS 适配位置不唯一');
        }
        $source = str_replace($original, $replacement, $source);
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('无法保存 Swoole TLS 适配');
        }
        return [$file => ['before' => $before, 'after' => hash('sha256', $source)]];
    }
}
