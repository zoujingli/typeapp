<?php

declare(strict_types=1);

namespace TypeApp\Release;

/** 跨平台汇总只接收同一可执行文件的回执；不会把历史目录归档升级为单程序证据。 */
final class Candidate
{
    /** 对外附件名不带归档后缀；Windows 保留可执行文件后缀。 */
    public static function filename(string $version, string $platform): string
    {
        Plan::version($version);
        if (!in_array($platform, ['linux-x64', 'linux-arm64', 'macos-arm64', 'windows-x64'], true)) {
            throw new \InvalidArgumentException('单程序候选平台无效');
        }
        return 'typeapp-iot-' . substr($version, 1) . '-' . $platform . ($platform === 'windows-x64' ? '.exe' : '');
    }

    /**
     * @throws \RuntimeException 平台回执、三库验收或待上传字节不属于同一单程序。
     * @return string 已核对的附件文件名。
     */
    public static function verify(array $record, string $assets, string $source, string $version, string $platform): string
    {
        $name = self::filename($version, $platform);
        foreach (['build-id', 'frontend-manifest-sha256'] as $field) {
            if (!is_string($record[$field] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record[$field]) !== 1) {
                throw new \RuntimeException('候选缺少构建或前端身份：' . $field);
            }
        }
        if (($record['protocol'] ?? null) !== 2 || ($record['delivery'] ?? '') !== 'single-executable'
            || ($record['status'] ?? '') !== 'passed' || ($record['source'] ?? '') !== $source
            || ($record['version'] ?? '') !== $version || ($record['platform'] ?? '') !== $platform
            || ($record['file'] ?? '') !== $name || !is_file($assets . '/' . $name) || is_link($assets . '/' . $name)
            || ($record['sha256'] ?? '') !== hash_file('sha256', $assets . '/' . $name)
            || ($record['bytes'] ?? null) !== filesize($assets . '/' . $name)
            || ($record['artifact-sha256'] ?? '') !== $record['sha256']) {
            throw new \RuntimeException('候选不是经过验收的同一单程序：' . $platform);
        }
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            $test = $record['acceptance'][$driver] ?? [];
            if (($test['status'] ?? '') !== 'passed' || ($test['driver'] ?? '') !== $driver
                || ($test['frontend-source-removed'] ?? false) !== true
                || ($test['single-executable-only'] ?? false) !== true
                || !is_string($test['log-sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $test['log-sha256']) !== 1
                || ($test['artifact-sha256'] ?? '') !== $record['sha256']) {
                throw new \RuntimeException('候选缺少同一单程序三库验收：' . $platform . '/' . $driver);
            }
        }
        $rebuild = $record['rebuild'] ?? [];
        $material = 'typeapp-rebuild-' . substr($version, 1) . '-' . $platform . '.zip';
        if (($rebuild['protocol'] ?? null) !== 1 || ($rebuild['source'] ?? '') !== $source
            || ($rebuild['version'] ?? '') !== $version || ($rebuild['platform'] ?? '') !== $platform
            || ($rebuild['file'] ?? '') !== $material || !is_file($assets . '/' . $material) || is_link($assets . '/' . $material)
            || ($rebuild['sha256'] ?? '') !== hash_file('sha256', $assets . '/' . $material)
            || ($rebuild['bytes'] ?? null) !== filesize($assets . '/' . $material)
            || preg_match('/^[a-f0-9]{64}$/D', $record['sdk-manifest-sha256'] ?? '') !== 1
            || ($rebuild['sdk-manifest-sha256'] ?? '') !== $record['sdk-manifest-sha256']) {
            throw new \RuntimeException('候选缺少与源码及 SDK 对应的重建材料：' . $platform);
        }
        return $name;
    }

    /** @return list<array{file: string, sha256: string, bytes: int}> 已封存附件；旧版本保持原有清单。 */
    public static function attachments(array $record): array
    {
        $files = [['file' => self::sealedFilename($record), 'sha256' => $record['sha256'], 'bytes' => $record['bytes']]];
        if (isset($record['rebuild'])) {
            $item = $record['rebuild'];
            if (preg_match('/^typeapp-rebuild-[a-zA-Z0-9.-]+\.zip$/D', $item['file'] ?? '') !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $item['sha256'] ?? '') !== 1 || !is_int($item['bytes'] ?? null) || $item['bytes'] <= 0) {
                throw new \RuntimeException('已封存重建材料身份无效');
            }
            $files[] = ['file' => $item['file'], 'sha256' => $item['sha256'], 'bytes' => $item['bytes']];
        }
        return $files;
    }

    /** 原样恢复已封存的旧版本；兼容读取不能赋予旧目录包单程序身份。 */
    public static function sealedFilename(array $record): string
    {
        $name = ($record['protocol'] ?? null) === 2 ? ($record['file'] ?? '') : ($record['archive'] ?? '');
        if (!is_string($name) || preg_match('/^typeapp-iot-[a-zA-Z0-9.-]+$/D', $name) !== 1) {
            throw new \RuntimeException('已封存的附件名无效');
        }
        return $name;
    }
}
