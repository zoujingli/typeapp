<?php

declare(strict_types=1);

namespace Type\Core\Http;

/** 基于规范 URI、方法、正文摘要和到期秒数的 HMAC 请求签名。 */
final class RequestSignature
{
    private string $secret;
    /** 保存至少 32 字节的共享密钥，由部署配置提供并负责轮换。 */
    public function __construct(#[\SensitiveParameter] string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('请求签名密钥至少 32 字节');
        }
        $this->secret = $secret;
    }
    /** 返回十六进制 SHA-256 HMAC；expires 为 Unix 到期秒数，不包含防重放 nonce。 */
    public function sign(CanonicalRequest $request, string $method, string $body, int $expires): string
    {
        return hash_hmac('sha256', json_encode([strtoupper($method), $request->uri(), hash('sha256', $body), $expires], JSON_THROW_ON_ERROR), $this->secret);
    }
    /** 检查有效时间窗及恒时签名相等；now 为 Unix 秒数，maxFuture 为允许的未来秒数。 */
    public function verify(CanonicalRequest $request, string $method, string $body, int $expires, string $signature, int $now, int $maxFuture = 300): bool
    {
        return $expires >= $now && $expires <= $now + $maxFuture && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals($this->sign($request, $method, $body, $expires), $signature);
    }
}
