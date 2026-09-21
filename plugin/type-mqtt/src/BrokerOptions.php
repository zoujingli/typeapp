<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 一次启动的明确资源预算；TLS 为默认，明文只能通过 allowPlaintext 显式开启。 */
final class BrokerOptions
{
    /**
     * @param string $certificate PEM 证书链路径，TLS 启动必填；第一份为叶证书，其后可跟中间 CA。
     * @param string $privateKey PEM 私钥路径，TLS 启动必填；口令由启动环境提供。
     * @param bool $allowPlaintext 仅用于独立客户端调试，生产设备配置保持 false。
     * @param bool $clustered 多Broker共享存储必选；以旧socket关闭或受控基础设施硬隔离事实批准接管。
     * @param int $wsPort 明文 MQTT over WebSocket 端口；0 表示关闭，且必须同时允许明文。
     * @param int $wssPort MQTT over WebSocket TLS 端口；0 表示关闭，需要证书。
     * @param list<string> $allowedOrigins 浏览器 Origin 精确白名单，须预先小写；空列表表示不按 Origin 拒绝。
     * @param int $mtlsPort 专用 mTLS 端口；0 表示关闭。与凭据 TLS 入口分开，需要可读客户端 CA。
     * @param string $clientCa 校验客户端证书的 CA 文件，可为含根与签发中间 CA 的 PEM 包；mTLS TCP 或 WSS 入口使用。
     * @param string $clientCrl 可选 PEM CRL；须由 clientCa 包内某份 CA 签发。Swoole 不核吊销列表，Broker 在取指纹前拒绝已列入的证书。
     * @param string $clientCrlUrl 可选管理员受控 HTTPS CRL 源；只从启动配置读取，不接受客户端指定。拉取由 Swoole 协程 HTTP 客户端完成，写入 clientCrl 后沿用文件重载。
     * @param int $clientCrlInterval HTTPS 刷新间隔秒，默认 300，范围 1–3600。
     * @param string $clientRevoke 可选平台吊销名单，每行一个证书序列号十六进制；不经 CA 签名，已接纳项本进程内只增不减。
     * @param string $clientOverlap 可选换证重叠名单，每行指纹与截止 Unix 时间；缺截止默认 24 小时，上限 24 小时，零或过去立即结束。吊销与到期无重叠宽限。
     * @param string $sniHost 可选 SNI 主机名，须小写域名；与 sniCertificate、sniPrivateKey 同时提供。
     * @param string $sniCertificate SNI 主机对应的 PEM 证书链；第一份为叶证书。
     * @param string $sniPrivateKey SNI 主机对应的 PEM 私钥；本切片不解密口令，须为明文钥。
     * @param float $callbackSeconds 单次原生事件排队与业务回调的共同截止秒数；清理完成前仍占用事件额度。
     */
    public function __construct(
        public readonly string $certificate = '',
        public readonly string $privateKey = '',
        public readonly string $privateKeyPassphrase = '',
        public readonly bool $allowPlaintext = false,
        public readonly int $maximumConnections = 256,
        public readonly int $maximumPacketBytes = 1048576,
        public readonly float $handshakeSeconds = 10.0,
        public readonly float $partialPacketSeconds = 15.0,
        public readonly bool $handleSignals = true,
        public readonly int $maximumDeviceConnections = 10000,
        public readonly int $maximumServiceConnections = 100,
        public readonly bool $clustered = false,
        public readonly int $wsPort = 0,
        public readonly int $wssPort = 0,
        public readonly array $allowedOrigins = [],
        public readonly int $mtlsPort = 0,
        public readonly string $clientCa = '',
        public readonly string $clientCrl = '',
        public readonly string $clientCrlUrl = '',
        public readonly int $clientCrlInterval = 300,
        public readonly string $clientRevoke = '',
        public readonly string $clientOverlap = '',
        public readonly string $sniHost = '',
        public readonly string $sniCertificate = '',
        public readonly string $sniPrivateKey = '',
        public readonly float $callbackSeconds = 30.0
    ) {
        if ($maximumConnections < 1 || $maximumConnections > 10100
            || $maximumPacketBytes < 128 || $maximumPacketBytes > 1048576
            || !is_finite($handshakeSeconds) || $handshakeSeconds <= 0 || $handshakeSeconds > 60
            || !is_finite($partialPacketSeconds) || $partialPacketSeconds <= 0 || $partialPacketSeconds > 60
            || $maximumDeviceConnections < 1 || $maximumDeviceConnections > 10000
            || $maximumServiceConnections < 0 || $maximumServiceConnections > 100
            || $wsPort < 0 || $wsPort > 65535 || $wssPort < 0 || $wssPort > 65535
            || $mtlsPort < 0 || $mtlsPort > 65535
            || !is_finite($callbackSeconds) || $callbackSeconds <= 0 || $callbackSeconds > 60) {
            throw new \InvalidArgumentException('MQTT 连接、报文或等待预算无效');
        }
        if ($wsPort > 0 && $wssPort > 0) {
            throw new \InvalidArgumentException('MQTT 明文 WS 与 WSS 不能在同一进程同时开启');
        }
        if ($wsPort > 0 && !$allowPlaintext) {
            throw new \InvalidArgumentException('明文 MQTT WebSocket 只在显式本地开发配置开启');
        }
        if (($wssPort > 0 || $mtlsPort > 0) && $allowPlaintext) {
            throw new \InvalidArgumentException('MQTT WSS 与 mTLS 不能与明文调试配置混用');
        }
        if ($mtlsPort > 0 && $clientCa === '') {
            throw new \InvalidArgumentException('MQTT mTLS 端口必须同时配置客户端 CA');
        }
        if ($clientCa !== '' && $mtlsPort === 0 && $wssPort === 0) {
            throw new \InvalidArgumentException('MQTT 客户端 CA 需要 mTLS 或 WSS 入口');
        }
        if ($clientCa !== '' && (!is_file($clientCa) || !is_readable($clientCa))) {
            throw new \InvalidArgumentException('MQTT mTLS 需要可读的客户端 CA');
        }
        if ($clientCrl !== '' && $clientCa === '') {
            throw new \InvalidArgumentException('MQTT 客户端 CRL 需要同时配置客户端 CA');
        }
        if ($clientCrl !== '' && (!is_file($clientCrl) || !is_readable($clientCrl))) {
            throw new \InvalidArgumentException('MQTT mTLS 需要可读的客户端 CRL');
        }
        if ($clientCrlInterval < 1 || $clientCrlInterval > 3600) {
            throw new \InvalidArgumentException('MQTT 客户端 CRL 刷新间隔必须在 1 到 3600 秒之间');
        }
        if ($clientCrlUrl !== '') {
            if ($clientCrl === '' || $clientCa === '') {
                throw new \InvalidArgumentException('MQTT HTTPS CRL 刷新需要同时配置客户端 CA 与本地 CRL 文件');
            }
            self::requireHttpsCrlUrl($clientCrlUrl);
        }
        if ($clientRevoke !== '' && $clientCa === '') {
            throw new \InvalidArgumentException('MQTT 平台吊销名单需要同时配置客户端 CA');
        }
        if ($clientRevoke !== '' && (!is_file($clientRevoke) || !is_readable($clientRevoke))) {
            throw new \InvalidArgumentException('MQTT 平台吊销名单需要可读文件');
        }
        if ($clientOverlap !== '' && $clientCa === '') {
            throw new \InvalidArgumentException('MQTT 换证重叠名单需要同时配置客户端 CA');
        }
        if ($clientOverlap !== '' && (!is_file($clientOverlap) || !is_readable($clientOverlap))) {
            throw new \InvalidArgumentException('MQTT 换证重叠名单需要可读文件');
        }
        if ($mtlsPort > 0 && ($mtlsPort === $wsPort || $mtlsPort === $wssPort)) {
            throw new \InvalidArgumentException('MQTT mTLS 端口不能与 WebSocket 端口相同');
        }
        foreach ($allowedOrigins as $origin) {
            if (!is_string($origin) || $origin === '' || $origin !== strtolower($origin)) {
                throw new \InvalidArgumentException('MQTT Origin 白名单必须是非空的小写字符串');
            }
        }
        if (!$allowPlaintext && (!is_file($certificate) || !is_readable($certificate) || !is_file($privateKey) || !is_readable($privateKey))) {
            throw new \InvalidArgumentException('MQTT TLS 需要可读证书链与私钥');
        }
        if ($allowPlaintext && ($certificate !== '' || $privateKey !== '' || $privateKeyPassphrase !== ''
            || $sniHost !== '' || $sniCertificate !== '' || $sniPrivateKey !== '')) {
            throw new \InvalidArgumentException('MQTT 明文调试配置不能混用 TLS 秘钥');
        }
        $sniSet = [$sniHost !== '', $sniCertificate !== '', $sniPrivateKey !== ''];
        if (in_array(true, $sniSet, true) && in_array(false, $sniSet, true)) {
            throw new \InvalidArgumentException('MQTT SNI 需要同时配置主机名、证书链与私钥');
        }
        if ($sniHost !== '') {
            if ($sniHost !== strtolower($sniHost) || strlen($sniHost) > 253
                || filter_var($sniHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || filter_var($sniHost, FILTER_VALIDATE_IP) !== false) {
                throw new \InvalidArgumentException('MQTT SNI 主机名必须是小写域名');
            }
            if (!is_file($sniCertificate) || !is_readable($sniCertificate) || !is_file($sniPrivateKey) || !is_readable($sniPrivateKey)) {
                throw new \InvalidArgumentException('MQTT SNI 需要可读证书链与私钥');
            }
        }
    }

    /** HTTPS CRL 源必须是不含用户信息的 https URL，由管理员在启动期配置。 */
    private static function requireHttpsCrlUrl(string $url): void
    {
        if (strlen($url) > 2048) {
            throw new \InvalidArgumentException('MQTT 客户端 CRL 地址过长');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('MQTT 客户端 CRL 地址必须是不含用户信息的 https URL');
        }
        if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) {
            throw new \InvalidArgumentException('MQTT 客户端 CRL 地址端口无效');
        }
    }

    /**
     * 读取换证重叠窗口。无效正文返回 null。
     *
     * @return array<string, int>|null 指纹到截止 Unix 时间；缺截止按 now+86400，超过 24 小时则截断。
     */
    public static function overlapWindows(string $path, int $now): ?array
    {
        $content = @file_get_contents($path);
        if (!is_string($content) || strlen($content) > 1048576) {
            return null;
        }
        $lines = preg_split('/\R/', $content);
        if (!is_array($lines)) {
            return null;
        }
        $limit = $now + 86400;
        $windows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (!is_array($parts) || $parts === [] || $parts[0] === '' || count($parts) > 2) {
                return null;
            }
            $fingerprint = strtolower(str_replace(':', '', $parts[0]));
            if (preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1) {
                return null;
            }
            if (count($parts) === 1) {
                $until = $limit;
            } elseif (preg_match('/^[0-9]{1,10}$/D', $parts[1]) !== 1) {
                return null;
            } else {
                $until = (int) $parts[1];
                if ($until > $limit) {
                    $until = $limit;
                }
            }
            $windows[$fingerprint] = $until;
        }
        return $windows;
    }
}
