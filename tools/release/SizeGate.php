<?php

declare(strict_types=1);

namespace TypeApp\Release;

/** 比较同平台、同数据库的最终部署字节；历史全驱动产物不冒充 profile 基线。 */
final class SizeGate
{
    /** @return array<string,mixed> 可随候选封存的基线身份、比较值和已审核的增长原因。 */
    public static function verify(array $programs, ?array $baseline, array $policy, string $version): array
    {
        Candidate::assertMatrix($programs);
        if (($policy['protocol'] ?? null) !== 1 || !is_array($policy['explanations'] ?? null)) {
            throw new \RuntimeException('体积增长说明配置无效');
        }
        $reasons = $policy['explanations'][$version] ?? [];
        if (!is_array($reasons) || array_diff(array_keys($reasons), Candidate::matrix()) !== []) {
            throw new \RuntimeException('体积增长说明包含未知平台/profile');
        }
        if ($baseline !== null) {
            if (($baseline['protocol'] ?? null) !== 3 || !is_string($baseline['version'] ?? null)
                || !version_compare(ltrim($baseline['version'], 'v'), ltrim($version, 'v'), '<')
                || preg_match('/^[a-f0-9]{40}$/D', $baseline['source'] ?? '') !== 1) {
                throw new \RuntimeException('体积基线版本或源码身份无效');
            }
            Candidate::assertMatrix($baseline['programs'] ?? []);
        }
        $comparisons = [];
        foreach ($programs as $key => $record) {
            $bytes = $record['bytes'] ?? null;
            $previous = $baseline === null ? null : ($baseline['programs'][$key]['bytes'] ?? null);
            if (!is_int($bytes) || $bytes < 1 || ($baseline !== null && (!is_int($previous) || $previous < 1))) {
                throw new \RuntimeException('体积比较缺少最终程序字节数：' . $key);
            }
            $reason = $reasons[$key] ?? null;
            if ($reason !== null && (!is_string($reason) || strlen(trim($reason)) < 12 || strlen($reason) > 4096)) {
                throw new \RuntimeException('体积增长说明必须描述实际依赖或功能变化：' . $key);
            }
            if ($previous !== null && $bytes > $previous * 1.05 && $reason === null) {
                throw new \RuntimeException('发布程序体积增长超过 5%，必须在 .github/release-size-policy.json 说明原因：' . $key);
            }
            $comparisons[$key] = ['bytes' => $bytes, 'baseline-bytes' => $previous, 'explanation' => $reason];
        }
        return ['protocol' => 1, 'threshold-percent' => 5, 'baseline-version' => $baseline['version'] ?? null,
            'baseline-source' => $baseline['source'] ?? null, 'comparisons' => $comparisons];
    }
}
