<?php

declare(strict_types=1);

namespace TypeApp\Release;

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
                $request = curl_init('https://packagist.org/packages/' . $item['package'] . '.json');
                curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-cache'], CURLOPT_FOLLOWLOCATION => false]);
                $body = curl_exec($request);
                $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
                if (is_string($body) && $status === 200) {
                    $document = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
                    foreach ($document['package']['versions'] ?? [] as $package) {
                        if (ltrim($package['version'] ?? '', 'v') !== substr($version, 1)) {
                            continue;
                        }
                        if (($package['source']['reference'] ?? '') !== $item['split']) {
                            throw new \RuntimeException('Packagist同版本来源冲突：' . $item['package']);
                        }
                        $verified[$name] = ['package' => $item['package'], 'version' => $package['version'], 'source' => $item['split']];
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
}
