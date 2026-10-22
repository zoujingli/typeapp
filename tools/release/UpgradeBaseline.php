<?php

declare(strict_types=1);

namespace TypeApp\Release;

/** 升级只接受固定公开版本的原始字节；源码夹具与自行重建不能代替已发布旧程序。 */
final class UpgradeBaseline
{
    /** @return array<string,mixed> 经版本化固定的公开清单与十二个程序身份。 */
    public static function definition(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/.github/upgrade-baseline.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array{version:string,source:string,manifest-sha256:string,file:string,sha256:string,bytes:int} */
    public static function expected(string $platform, string $profile): array
    {
        $definition = self::definition();
        $program = $definition['programs'][$platform . '-' . $profile] ?? null;
        if (!is_array($program) || basename($program['file'] ?? '') !== ($program['file'] ?? null)) {
            throw new \RuntimeException('公开升级基线没有对应的平台与profile');
        }
        return ['version' => $definition['version'], 'source' => $definition['source'],
            'manifest-sha256' => $definition['manifest-sha256'], ...$program];
    }

    /**
     * @return array<string,mixed> 可写入候选回执的固定基线身份。
     * @throws \RuntimeException 清单或程序摘要、大小、来源、平台不符。
     */
    public static function verify(string $directory, string $platform, string $profile): array
    {
        $expected = self::expected($platform, $profile);
        $manifest = $directory . '/release-manifest.json';
        if (!is_file($manifest) || is_link($manifest) || hash_file('sha256', $manifest) !== $expected['manifest-sha256']) {
            throw new \RuntimeException('公开升级基线清单摘要不匹配');
        }
        $actual = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $program = $actual['programs'][$platform . '-' . $profile] ?? [];
        $path = $directory . '/' . $expected['file'];
        if (($actual['version'] ?? null) !== $expected['version'] || ($actual['source'] ?? null) !== $expected['source']
            || ($program['file'] ?? null) !== $expected['file'] || ($program['sha256'] ?? null) !== $expected['sha256']
            || ($program['bytes'] ?? null) !== $expected['bytes'] || !is_file($path) || is_link($path)
            || filesize($path) !== $expected['bytes'] || hash_file('sha256', $path) !== $expected['sha256']) {
            throw new \RuntimeException('公开升级基线程序身份不匹配');
        }
        (new \Type\Build\BuildPlatform())->assertArtifact($path);
        return $expected;
    }
}
