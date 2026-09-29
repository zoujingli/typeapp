<?php

declare(strict_types=1);

namespace TypeApp\Release;

/** 跨平台汇总只接收同一可执行文件的回执；不会把历史目录归档升级为单程序证据。 */
final class Candidate
{
    /** @var list<string> 发布支持的平台；顺序同时定义清单和校验输出顺序。 */
    public const PLATFORMS = ['linux-x64', 'linux-arm64', 'macos-arm64', 'windows-x64'];

    /** @var list<string> 发布支持的数据库 profile。 */
    public const PROFILES = ['sqlite', 'mysql', 'pgsql'];

    /** @return list<string> 12 个平台/profile 候选键。 */
    public static function matrix(): array
    {
        $keys = [];
        foreach (self::PLATFORMS as $platform) {
            foreach (self::PROFILES as $profile) {
                $keys[] = $platform . '-' . $profile;
            }
        }
        return $keys;
    }

    /**
     * 核对候选是否完整覆盖四个平台与三个数据库 profile。
     *
     * 该检查与附件上传分离，恢复候选、生成公开清单和重试入口都必须先经过同一
     * 个矩阵边界，避免少一个 profile 时仍创建部分 Release。
     *
     * @param array<string, mixed> $records
     * @throws \RuntimeException 矩阵缺项、重复或包含未知组合。
     */
    public static function assertMatrix(array $records): void
    {
        $actual = array_keys($records);
        sort($actual);
        $expected = self::matrix();
        sort($expected);
        if ($actual !== $expected) {
            throw new \RuntimeException('候选必须完整覆盖四平台×三 profile 矩阵');
        }
        foreach ($records as $key => $record) {
            if (!is_array($record)) {
                throw new \RuntimeException('候选矩阵回执必须是对象：' . (string) $key);
            }
        }
    }

    /** 对外附件名不带归档后缀；Windows 保留可执行文件后缀。 */
    public static function filename(string $version, string $platform, ?string $profile = null): string
    {
        Plan::version($version);
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new \InvalidArgumentException('单程序候选平台无效');
        }
        if ($profile !== null && !in_array($profile, self::PROFILES, true)) {
            throw new \InvalidArgumentException('单程序数据库 profile 无效');
        }
        return 'typeapp-iot-' . substr($version, 1) . '-' . $platform . ($profile === null ? '' : '-' . $profile)
            . ($platform === 'windows-x64' ? '.exe' : '');
    }

    /**
     * @throws \RuntimeException 平台回执、profile 对应数据库验收或待上传字节不属于同一单程序。
     * @return string 已核对的附件文件名。
     */
    public static function verify(array $record, string $assets, string $source, string $version, string $platform, ?string $profile = null): string
    {
        $name = self::filename($version, $platform, $profile);
        if ($profile !== null && (($record['profile'] ?? null) !== $profile || ($record['database'] ?? null) !== $profile)) {
            throw new \RuntimeException('候选 profile 与附件身份不一致：' . $platform . '/' . $profile);
        }
        if ($profile === null && array_key_exists('database', $record) && $record['database'] !== null) {
            throw new \RuntimeException('历史候选不能携带未校验的数据库 profile：' . $platform);
        }
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
        if ($profile !== null) {
            $facts = $record['profile-facts'] ?? [];
            $features = $facts['features'] ?? null;
            $rejected = $facts['rejected-capabilities'] ?? null;
            $extensions = $record['runtime-extensions'] ?? null;
            $archives = $record['static-archives'] ?? null;
            $libraries = $record['system-libraries'] ?? null;
            $size = $record['size-breakdown'] ?? null;
            $validList = static function (mixed $value): bool {
                return is_array($value) && array_is_list($value)
                    && $value !== []
                    && array_filter($value, static fn (mixed $item): bool => !is_string($item) || $item === '') === [];
            };
            $expectedRejected = is_array($features) ? \Type\Build\BuildProfile::rejectedCapabilities(['database' => $profile, 'features' => $features]) : [];
            if (!is_array($facts) || ($facts['name'] ?? null) !== $profile || ($facts['database'] ?? null) !== $profile
                || !$validList($features) || !is_array($rejected) || !array_is_list($rejected)
                || array_filter($rejected, static fn (mixed $item): bool => !is_string($item) || $item === '') !== []
                || array_values(array_unique($features)) !== $features || $features !== ($record['features'] ?? null)
                || array_values(array_unique($rejected)) !== $rejected || $rejected !== $expectedRejected
                || !$validList($extensions) || !$validList($archives) || !$validList($libraries)
                || !is_array($size) || ($size['protocol'] ?? null) !== 1
                || array_filter(['total', 'code', 'data', 'symbols', 'frontend', 'native'], static function (string $key) use ($size): bool {
                    return !is_int($size[$key] ?? null) || $size[$key] < 0;
                }) !== []
                || ($size['stripped'] ?? false) !== true || ($platform !== 'macos-arm64' && $size['symbols'] !== 0)
                || $size['total'] !== $record['bytes'] || $size['code'] + $size['data'] !== $size['total'] || $size['frontend'] > $size['data']) {
                throw new \RuntimeException('候选缺少可审计的 profile 依赖闭包：' . $platform . '/' . $profile);
            }
            $databaseExtensions = array_values(array_filter($extensions, static fn (string $extension): bool => in_array($extension, ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'], true)));
            if ($databaseExtensions !== ['pdo_' . $profile]) {
                throw new \RuntimeException('候选数据库扩展与 profile 不一致：' . $platform . '/' . $profile);
            }
            \Type\Build\BuildProfile::assertExtensions($extensions, $facts);
        }
        foreach ($profile === null ? ['mysql', 'pgsql', 'sqlite'] : [$profile] as $driver) {
            $test = $record['acceptance'][$driver] ?? [];
            if (($test['status'] ?? '') !== 'passed' || ($test['driver'] ?? '') !== $driver
                || ($test['frontend-source-removed'] ?? false) !== true
                || ($test['single-executable-only'] ?? false) !== true
                || !is_string($test['log-sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $test['log-sha256']) !== 1
                || ($test['artifact-sha256'] ?? '') !== $record['sha256']) {
                throw new \RuntimeException('候选缺少同一单程序的对应数据库验收：' . $platform . '/' . $driver);
            }
        }
        if ($profile !== null) {
            $acceptance = $record['acceptance'][$profile];
            if (($acceptance['runtime-profile-enforced'] ?? false) !== true) {
                throw new \RuntimeException('候选缺少数据库拒绝和能力边界验收');
            }
            foreach (array_intersect(['mqtt', 'alerts', 'exports', 'scheduler'], $record['features']) as $feature) {
                $business = $acceptance['business'][$feature] ?? [];
                if (($business['status'] ?? '') !== 'passed' || ($business['artifact-sha256'] ?? '') !== $record['sha256']
                    || preg_match('/^[a-f0-9]{64}$/D', $business['log-sha256'] ?? '') !== 1) {
                    throw new \RuntimeException('候选缺少同一程序的业务验收：' . $feature);
                }
            }
            if ($platform !== 'windows-x64') {
                $service = $record['service'] ?? [];
                if (($service['status'] ?? '') !== 'passed' || ($service['driver'] ?? '') !== $profile
                    || ($service['artifact-sha256'] ?? '') !== $record['sha256']
                    || preg_match('/^[a-f0-9]{64}$/D', $service['log-sha256'] ?? '') !== 1) {
                    throw new \RuntimeException('候选缺少同一 profile 程序的服务生命周期验收');
                }
            }
        }
        $rebuild = $record['rebuild'] ?? [];
        $material = 'typeapp-rebuild-' . substr($version, 1) . '-' . $platform . ($profile === null ? '' : '-' . $profile) . '.zip';
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
    public static function attachments(array $record, bool $includeRebuild = true): array
    {
        $files = [['file' => self::sealedFilename($record), 'sha256' => $record['sha256'], 'bytes' => $record['bytes']]];
        if ($includeRebuild && isset($record['rebuild'])) {
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
        if (!is_string($name) || preg_match('/^typeapp-iot-[a-zA-Z0-9.-]+(?:\.exe)?$/D', $name) !== 1) {
            throw new \RuntimeException('已封存的附件名无效');
        }
        return $name;
    }
}
