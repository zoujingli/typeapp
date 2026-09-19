<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 可选的 mTLS 认证；证书链校验由 Broker 在握手完成，策略只看到已验证指纹。 */
interface CertificateAccessPolicy
{
    /**
     * fingerprint 是已通过配置 CA、用途/有效期及可选 CRL/平台吊销校验的客户端证书 SHA-256（小写十六进制），不含 PEM 或私钥。
     * 换证重叠窗口内的旧指纹由消费者按同一主体接受；吊销与到期没有重叠宽限。
     * 无 CONNECT 用户名/密码时允许仅凭证书；同时提供凭据时由 Broker 再核对同一主体，失败不降级。
     */
    public function authenticateCertificate(ConnectPacket $connect, string $peer, string $fingerprint): ?AccessIdentity;
}
