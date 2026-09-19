<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 按实际协议分别修正固定 Socket 的空 UDP 来源与 TLS IP 身份校验，不接管原生机制。 */
final class SwooleSocketSource
{
    public const REFERENCE = '0f3bee2f0ed8704ce33a336e7feabb0115411dd7';

    /**
     * 仅适配隔离的上游原文；上游零长度报文也写回地址后撤除此补丁。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws RuntimeException 原文身份、唯一替换或保存失败。
     */
    public function apply(string $directory): array
    {
        $file = 'ext-src/swoole_socket_coro.cc';
        $path = $directory . '/' . $file;
        $before = '0bf446e5d66184507c13e664c165eb9d83e296a105cf5a1e80cf8b994c1a8b4c';
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $before) {
            throw new RuntimeException('Swoole Socket 适配需要固定原文：' . $file);
        }
        $original = <<<'CPP'
    } else if (bytes == 0) {
        zend_string_free(buf);
        RETURN_EMPTY_STRING();
    } else {
        zval_dtor(peername);
        array_init(peername);
        add_assoc_string(peername, "address", (char *) sock->socket->get_addr());
        add_assoc_long(peername, "port", sock->socket->get_port());

        ZSTR_LEN(buf) = bytes;
CPP;
        $replacement = <<<'CPP'
    } else {
        zval_dtor(peername);
        array_init(peername);
        add_assoc_string(peername, "address", (char *) sock->socket->get_addr());
        add_assoc_long(peername, "port", sock->socket->get_port());
        if (bytes == 0) {
            zend_string_free(buf);
            RETURN_EMPTY_STRING();
        }

        ZSTR_LEN(buf) = bytes;
CPP;
        $source = (string) file_get_contents($path);
        if (substr_count($source, $original) !== 1) {
            throw new RuntimeException('Swoole Socket 适配位置不唯一');
        }
        $source = str_replace($original, $replacement, $source);
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('无法保存 Swoole Socket 适配');
        }
        return [$file => ['before' => $before, 'after' => hash('sha256', $source)]];
    }

    /**
     * 数字 IP 交给 OpenSSL 的 IP SAN 校验，域名继续使用原路径；不弱化证书链验证。
     * 上游等价支持 IP SAN 并通过相同双端行为验收后撤除；不强制组合 UDP 适配。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws RuntimeException 固定原文、唯一位置或保存失败。
     */
    public function applyTls(string $directory): array
    {
        $file = 'src/network/socket.cc';
        $path = $directory . '/' . $file;
        $before = 'e6919bae549f08932e6922c118b830d1f9fe6e5318efb874a7909ccd2a3814bd';
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $before) {
            throw new RuntimeException('Swoole TLS 适配需要固定原文：' . $file);
        }
        $original = <<<'CPP'
    if (X509_check_host(cert, tls_host_name, strlen(tls_host_name), 0, nullptr) != 1) {
CPP;
        $replacement = <<<'CPP'
    const bool is_ip = Address::verify_ip(AF_INET, tls_host_name) || Address::verify_ip(AF_INET6, tls_host_name);
    const int identity_matches = is_ip ? X509_check_ip_asc(cert, tls_host_name, 0)
                                       : X509_check_host(cert, tls_host_name, strlen(tls_host_name), 0, nullptr);
    if (identity_matches != 1) {
CPP;
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
