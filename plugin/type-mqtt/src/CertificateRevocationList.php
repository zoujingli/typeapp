<?php

declare(strict_types=1);

namespace Type\Mqtt;

/**
 * 签名 CRL：启动导入，并在 Swoole tick 中按文件更新重载。
 * CA 文件可为含根与中间 CA 的 PEM 包；验签使用其中能核对 CRL 的那份证书。
 * 已接纳的序列号只增不减；HTTPS 拉取由 Broker 的 Swoole Process 完成。断开已建立连接由 Broker 执行。
 */
final class CertificateRevocationList
{
    /**
     * @param list<string> $serials 已规范化的证书序列号十六进制。
     */
    private function __construct(private array $serials, private int $thisUpdate, private int $nextUpdate)
    {
    }

    /**
     * 读取 PEM CRL 文件，并用配置 CA 文件中任一份证书的公钥核对签名与有效期窗口。
     * @throws \RuntimeException PEM、签名、算法或时间无效。
     */
    public static function fromFiles(string $path, string $caPath): self
    {
        return self::fromPem((string) file_get_contents($path), (string) file_get_contents($caPath));
    }

    /**
     * 解析 PEM CRL，并用 CA PEM 包中任一份证书的公钥核对签名。
     * @throws \RuntimeException PEM、签名、算法或时间无效。
     */
    public static function fromPem(string $pem, string $caPem): self
    {
        $der = self::derFromPem($pem);
        $limit = strlen($der);
        [$outerTag, $outerStart, $outerLength, $outerEnd] = self::readTlv($der, 0, $limit);
        if ($outerTag !== 0x30 || $outerEnd !== $limit) {
            throw new \RuntimeException('MQTT 客户端 CRL 外层编码无效');
        }
        $tbsAt = $outerStart;
        [$tbsTag, $tbsStart, $tbsLength, $afterTbs] = self::readTlv($der, $outerStart, $outerEnd);
        if ($tbsTag !== 0x30) {
            throw new \RuntimeException('MQTT 客户端 CRL TBS 编码无效');
        }
        $tbs = substr($der, $tbsAt, $afterTbs - $tbsAt);
        [$algTag, $algStart, $algLength, $afterAlg] = self::readTlv($der, $afterTbs, $outerEnd);
        if ($algTag !== 0x30) {
            throw new \RuntimeException('MQTT 客户端 CRL 签名算法编码无效');
        }
        [$sigTag, $sigStart, $sigLength, $afterSig] = self::readTlv($der, $afterAlg, $outerEnd);
        if ($sigTag !== 0x03 || $sigLength < 2 || ord($der[$sigStart]) !== 0 || $afterSig !== $outerEnd) {
            throw new \RuntimeException('MQTT 客户端 CRL 签名编码无效');
        }
        $digest = self::digest(self::algorithmOid($der, $algStart, $algStart + $algLength));
        $signature = substr($der, $sigStart + 1, $sigLength - 1);
        $verified = false;
        foreach (self::certificatePems($caPem) as $block) {
            $certificate = @openssl_x509_read($block);
            $key = $certificate === false ? false : openssl_pkey_get_public($certificate);
            if ($key !== false && openssl_verify($tbs, $signature, $key, $digest) === 1) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            throw new \RuntimeException('MQTT 客户端 CRL 签名无效');
        }
        return self::fromTbs($der, $tbsStart, $tbsStart + $tbsLength);
    }

    /** thisUpdate 到 nextUpdate（含）之内视为当前可用。 */
    public function covers(int $now): bool
    {
        return $now >= $this->thisUpdate && $now <= $this->nextUpdate;
    }

    public function thisUpdate(): int
    {
        return $this->thisUpdate;
    }

    public function nextUpdate(): int
    {
        return $this->nextUpdate;
    }

    /**
     * 已接纳的规范化序列号；合并后只增不减。
     *
     * @return list<string>
     */
    public function serials(): array
    {
        return $this->serials;
    }

    /**
     * 合并较新 CRL 的序列号与有效期窗口；已接纳吊销不因新列表缺项而恢复。
     */
    public function merge(self $newer): self
    {
        $serials = $this->serials;
        foreach ($newer->serials as $serial) {
            $found = false;
            foreach ($serials as $existing) {
                if (hash_equals($existing, $serial)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $serials[] = $serial;
            }
        }
        return new self($serials, $newer->thisUpdate, $newer->nextUpdate);
    }

    public static function serialHex(string $hex): string
    {
        return self::normalizeSerial($hex);
    }

    /** 序列号无法规范化时按已吊销处理，避免漏检。 */
    public function revokes(string $serialHex): bool
    {
        $serial = self::normalizeSerial($serialHex);
        if ($serial === '') {
            return true;
        }
        foreach ($this->serials as $revoked) {
            if (hash_equals($revoked, $serial)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:int,1:int,2:int,3:int} 标签、正文起点、正文长度、下一偏移。 */
    private static function readTlv(string $bytes, int $offset, int $limit): array
    {
        if ($offset >= $limit) {
            throw new \RuntimeException('MQTT 客户端 CRL 编码截断');
        }
        $tag = ord($bytes[$offset]);
        $cursor = $offset + 1;
        if ($cursor >= $limit) {
            throw new \RuntimeException('MQTT 客户端 CRL 编码截断');
        }
        $first = ord($bytes[$cursor]);
        $cursor++;
        $length = $first;
        if (($first & 0x80) !== 0) {
            $count = $first & 0x7f;
            if ($count < 1 || $count > 4 || $cursor + $count > $limit) {
                throw new \RuntimeException('MQTT 客户端 CRL 长度非法');
            }
            $length = 0;
            $index = 0;
            while ($index < $count) {
                $length = ($length << 8) | ord($bytes[$cursor]);
                $cursor++;
                $index++;
            }
        }
        if ($length < 0 || $cursor + $length > $limit) {
            throw new \RuntimeException('MQTT 客户端 CRL 长度越界');
        }
        return [$tag, $cursor, $length, $cursor + $length];
    }

    /** @return list<string> */
    private static function certificatePems(string $pem): array
    {
        $blocks = [];
        $cursor = 0;
        $length = strlen($pem);
        $beginMark = '-----BEGIN CERTIFICATE-----';
        $endMark = '-----END CERTIFICATE-----';
        while ($cursor < $length) {
            $begin = strpos($pem, $beginMark, $cursor);
            if ($begin === false) {
                break;
            }
            $end = strpos($pem, $endMark, $begin);
            if ($end === false) {
                break;
            }
            $end += strlen($endMark);
            $blocks[] = substr($pem, $begin, $end - $begin);
            $cursor = $end;
        }
        return $blocks;
    }

    private static function derFromPem(string $pem): string
    {
        if (preg_match('/-----BEGIN X509 CRL-----\r?\n([A-Za-z0-9\/+=\r\n]+)-----END X509 CRL-----/', $pem, $match) !== 1) {
            throw new \RuntimeException('MQTT 客户端 CRL 需要 PEM 编码');
        }
        $body = preg_replace('/\s+/', '', $match[1]);
        $der = is_string($body) ? base64_decode($body, true) : false;
        if (!is_string($der) || $der === '') {
            throw new \RuntimeException('MQTT 客户端 CRL PEM 解码失败');
        }
        return $der;
    }

    private static function fromTbs(string $bytes, int $start, int $limit): self
    {
        $cursor = $start;
        [$firstTag] = self::readTlv($bytes, $cursor, $limit);
        if ($firstTag === 0x02) {
            [, , , $cursor] = self::readTlv($bytes, $cursor, $limit);
        }
        [$algTag, , , $cursor] = self::readTlv($bytes, $cursor, $limit);
        if ($algTag !== 0x30) {
            throw new \RuntimeException('MQTT 客户端 CRL TBS 算法编码无效');
        }
        [$issuerTag, , , $cursor] = self::readTlv($bytes, $cursor, $limit);
        if ($issuerTag !== 0x30) {
            throw new \RuntimeException('MQTT 客户端 CRL 颁发者编码无效');
        }
        [$thisTag, $thisStart, $thisLength, $cursor] = self::readTlv($bytes, $cursor, $limit);
        $thisUpdate = self::time($thisTag, substr($bytes, $thisStart, $thisLength));
        if ($cursor >= $limit) {
            throw new \RuntimeException('MQTT 客户端 CRL 缺少 nextUpdate');
        }
        [$nextTag, $nextStart, $nextLength, $cursor] = self::readTlv($bytes, $cursor, $limit);
        $nextUpdate = self::time($nextTag, substr($bytes, $nextStart, $nextLength));
        if ($thisUpdate > $nextUpdate) {
            throw new \RuntimeException('MQTT 客户端 CRL 有效期窗口无效');
        }
        $serials = [];
        if ($cursor < $limit) {
            [$tag, $bodyStart, $bodyLength, $cursor] = self::readTlv($bytes, $cursor, $limit);
            if ($tag === 0x30) {
                $entry = $bodyStart;
                $entryLimit = $bodyStart + $bodyLength;
                while ($entry < $entryLimit) {
                    [$entryTag, $entryStart, $entryLength, $entry] = self::readTlv($bytes, $entry, $entryLimit);
                    if ($entryTag !== 0x30) {
                        throw new \RuntimeException('MQTT 客户端 CRL 吊销项编码无效');
                    }
                    [$serialTag, $serialStart, $serialLength] = self::readTlv($bytes, $entryStart, $entryStart + $entryLength);
                    if ($serialTag !== 0x02) {
                        throw new \RuntimeException('MQTT 客户端 CRL 证书序列号编码无效');
                    }
                    $serials[] = self::integerHex(substr($bytes, $serialStart, $serialLength));
                }
            } elseif ($tag !== 0xa0) {
                throw new \RuntimeException('MQTT 客户端 CRL TBS 尾部编码无效');
            }
        }
        return new self($serials, $thisUpdate, $nextUpdate);
    }

    private static function algorithmOid(string $bytes, int $start, int $limit): string
    {
        [$tag, $oidStart, $oidLength] = self::readTlv($bytes, $start, $limit);
        if ($tag !== 0x06 || $oidLength < 1) {
            throw new \RuntimeException('MQTT 客户端 CRL 签名算法标识无效');
        }
        $oid = substr($bytes, $oidStart, $oidLength);
        $first = ord($oid[0]);
        $parts = [(string) intdiv($first, 40), (string) ($first % 40)];
        $value = 0;
        $index = 1;
        $length = strlen($oid);
        while ($index < $length) {
            $byte = ord($oid[$index]);
            $value = ($value << 7) | ($byte & 0x7f);
            if (($byte & 0x80) === 0) {
                $parts[] = (string) $value;
                $value = 0;
            } elseif ($index === $length - 1) {
                throw new \RuntimeException('MQTT 客户端 CRL 签名算法标识截断');
            }
            $index++;
        }
        return implode('.', $parts);
    }

    private static function digest(string $oid): int
    {
        return match ($oid) {
            '1.2.840.113549.1.1.11' => OPENSSL_ALGO_SHA256,
            '1.2.840.113549.1.1.12' => OPENSSL_ALGO_SHA384,
            '1.2.840.113549.1.1.13' => OPENSSL_ALGO_SHA512,
            default => throw new \RuntimeException('MQTT 客户端 CRL 签名算法不受支持'),
        };
    }

    private static function time(int $tag, string $value): int
    {
        if ($tag === 0x17 && preg_match('/^([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})Z$/D', $value, $match) === 1) {
            $year = (int) $match[1];
            $stamp = gmmktime((int) $match[4], (int) $match[5], (int) $match[6], (int) $match[2], (int) $match[3], $year >= 50 ? $year + 1900 : $year + 2000);
            if ($stamp !== false) {
                return $stamp;
            }
        }
        if ($tag === 0x18 && preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})Z$/D', $value, $match) === 1) {
            $stamp = gmmktime((int) $match[4], (int) $match[5], (int) $match[6], (int) $match[2], (int) $match[3], (int) $match[1]);
            if ($stamp !== false) {
                return $stamp;
            }
        }
        throw new \RuntimeException('MQTT 客户端 CRL 时间编码无效');
    }

    private static function integerHex(string $bytes): string
    {
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            throw new \RuntimeException('MQTT 客户端 CRL 证书序列号无效');
        }
        $serial = self::normalizeSerial(bin2hex($bytes));
        if ($serial === '') {
            throw new \RuntimeException('MQTT 客户端 CRL 证书序列号无效');
        }
        return $serial;
    }

    private static function normalizeSerial(string $hex): string
    {
        $hex = strtolower(str_replace(':', '', $hex));
        if (preg_match('/^[0-9a-f]+$/D', $hex) !== 1) {
            return '';
        }
        $hex = ltrim($hex, '0');
        return $hex === '' ? '00' : (strlen($hex) % 2 === 1 ? '0' . $hex : $hex);
    }
}
