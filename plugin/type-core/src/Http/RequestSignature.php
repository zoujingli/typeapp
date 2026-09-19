<?php

declare(strict_types=1);

namespace Type\Core\Http;

final class RequestSignature
{
    private string $secret;
    public function __construct(#[\SensitiveParameter] string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('请求签名密钥至少 32 字节');
        }
        $this->secret = $secret;
    }
    public function sign(CanonicalRequest $request, string $method, string $body, int $expires): string
    {
        return hash_hmac('sha256', json_encode([strtoupper($method), $request->uri(), hash('sha256', $body), $expires], JSON_THROW_ON_ERROR), $this->secret);
    }
    public function verify(CanonicalRequest $request, string $method, string $body, int $expires, string $signature, int $now, int $maxFuture = 300): bool
    {
        return $expires >= $now && $expires <= $now + $maxFuture && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals($this->sign($request, $method, $body, $expires), $signature);
    }
}
