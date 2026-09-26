<?php

declare(strict_types=1);

namespace TypeApp\Release;

use Composer\MetadataMinifier\MetadataMinifier;

/** 等待既有GitHub webhook索引；只接受默认Packagist上的同版本与同提交。 */
final class Packagist
{
    /** @param array<string,array{package:string,split:string}> $items 固定分发计划。 */
    public static function wait(array $items, string $version, int $seconds = 900): array
    {
        Plan::version($version);
        if ($seconds < 1 || $seconds > 1800) {
            throw new \InvalidArgumentException('Packagist等待预算须在1到1800秒');
        }
        $deadline = microtime(true) + $seconds;
        $verified = [];
        do {
            foreach ($items as $name => $item) {
                if (isset($verified[$name])) {
                    continue;
                }
                if (!preg_match('~^zoujingli/type-[a-z0-9-]+$~D', $item['package'])) {
                    throw new \RuntimeException('Packagist包不属于本批次');
                }
                // 包详情API可缓存十二小时；发布门禁读取Composer实际使用的静态版本索引。
                $request = curl_init('https://repo.packagist.org/p2/' . $item['package'] . '.json');
                curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-cache'], CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_USERAGENT => 'TypeApp-release/1.0 (+https://github.com/zoujingli/typeapp)']);
                $body = curl_exec($request);
                $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
                if (is_string($body) && $status === 200) {
                    $document = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
                    $indexed = self::indexedVersion($document, $item['package'], $version, $item['split']);
                    if ($indexed !== null) {
                        $verified[$name] = $indexed;
                    }
                }
            }
            if (count($verified) === count($items)) {
                ksort($verified);
                return $verified;
            }
            fwrite(STDERR, 'Packagist已索引' . count($verified) . '/' . count($items) . "，继续等待既有webhook\n");
            if (microtime(true) < $deadline) {
                sleep(10);
            }
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Packagist索引等待超时：' . implode(', ', array_diff(array_keys($items), array_keys($verified))));
    }

    /**
     * 展开官方差量元数据，核对目标版本的真实来源；旧版本可能继承前项的source字段。
     * @internal 发布等待与回执核验的共同解析边界，不属于应用运行时。
     * @param array<string,mixed> $document Composer v2静态版本索引。
     * @return array{package:string,version:string,source:string}|null 未索引目标版本时返回null。
     * @throws \RuntimeException 元数据格式未知、同版本重复或来源提交不匹配。
     */
    public static function indexedVersion(array $document, string $name, string $version, string $split): ?array
    {
        $versions = $document['packages'][$name] ?? [];
        if (!is_array($document['packages'] ?? null) || !is_array($versions) || !array_is_list($versions)
            || !in_array($document['minified'] ?? null, [null, 'composer/2.0'], true)) {
            throw new \RuntimeException('Packagist版本元数据格式无效');
        }
        foreach ($versions as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('Packagist版本条目无效');
            }
        }
        if (($document['minified'] ?? null) === 'composer/2.0') {
            $versions = MetadataMinifier::expand($versions);
        }
        $verified = null;
        foreach ($versions as $entry) {
            if (ltrim($entry['version'] ?? '', 'v') !== substr($version, 1)) {
                continue;
            }
            if ($verified !== null || ($entry['name'] ?? '') !== $name || ($entry['source']['reference'] ?? '') !== $split) {
                throw new \RuntimeException('Packagist同版本来源冲突：' . $name);
            }
            $verified = ['package' => $name, 'version' => $entry['version'], 'source' => $split];
        }
        return $verified;
    }
}
