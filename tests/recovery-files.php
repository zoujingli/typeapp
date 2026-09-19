<?php

declare(strict_types=1);

use Type\Build\BuildLock;

/**
 * 维护端验证完整备份字节，不把摘要当作来源真实性或加密证明。
 *
 * @param array{driver:string,'release-sha256':string,'build-id':string}|array{} $expected 恢复操作提供来自当前受信发布的身份；空数组仅检查备份字节。
 * @return array<string,mixed> 已检查协议、字节及明确提供的恢复身份。
 * @throws RuntimeException 路径、协议或文件摘要不符。
 * @throws JsonException 清单不是有效JSON。
 */
function recoveryVerify(string $directory, string $trusted, array $expected = []): array
{
    BuildLock::path($directory . '/backup.json');
    expect(hash_file('sha256', $directory . '/backup.json') === $trusted, '备份清单与外部受信摘要不一致');
    $manifest = json_decode(file_get_contents($directory . '/backup.json'), true, 32, JSON_THROW_ON_ERROR);
    expect(($manifest['protocol'] ?? null) === 1 && is_array($manifest['files'] ?? null), '备份清单结构无效');
    if ($expected !== []) {
        expect(count($expected) === 3 && isset($expected['driver'], $expected['release-sha256'], $expected['build-id'])
            && in_array($expected['driver'], ['mysql', 'pgsql', 'sqlite'], true)
            && preg_match('/^[a-f0-9]{64}$/D', $expected['release-sha256']) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $expected['build-id']) === 1, '恢复上下文身份无效');
        foreach ($expected as $name => $value) {
            expect(($manifest[$name] ?? null) === $value, '备份身份与恢复上下文不一致：' . $name);
        }
    }
    foreach ($manifest['files'] as $file => $entry) {
        expect(is_string($file) && preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $file) === 1, '备份条目路径无效');
        BuildLock::path($directory . '/' . $file);
        expect(is_file($directory . '/' . $file) && filesize($directory . '/' . $file) === $entry['bytes']
            && hash_file('sha256', $directory . '/' . $file) === $entry['sha256'], '备份文件摘要不一致');
    }
    return $manifest;
}

/**
 * 只向不存在的新数据根恢复配置与资源，不覆盖任何既有目标。
 *
 * @param array{driver:string,'release-sha256':string,'build-id':string}|array{} $expected 当前受信发布及驱动身份。
 * @throws RuntimeException 备份无效、目标已存在或明确资源无法恢复。
 */
function recoveryData(string $backup, string $trusted, string $destination, int $uid, array $expected = []): void
{
    $manifest = recoveryVerify($backup, $trusted, $expected);
    expect(!file_exists($destination) && !is_link($destination), '恢复拒绝覆盖已有数据根');
    expect(mkdir($destination, 0700) && mkdir($destination . '/uploads', 0700), '无法建立隔离恢复数据根');
    foreach (['application.env' => '.env', 'asset.bin' => 'uploads/example.bin'] as $file => $target) {
        expect(isset($manifest['files'][$file]) && copy($backup . '/' . $file, $destination . '/' . $target), '恢复缺少明确配置或资源');
        chmod($destination . '/' . $target, 0600);
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        foreach ([$destination, $destination . '/uploads', $destination . '/.env', $destination . '/uploads/example.bin'] as $file) {
            chown($file, $uid);
        }
    }
}

/**
 * 只读检查用户对象；恢复不通过DROP/CLEAN清空既有数据库。
 *
 * @throws RuntimeException 驱动不是MySQL或PostgreSQL。
 */
function recoveryEmptyQuery(string $driver): string
{
    expect(in_array($driver, ['mysql', 'pgsql'], true), '需要支持的服务端数据库驱动');
    return $driver === 'mysql'
        ? 'SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()) + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()) + (SELECT COUNT(*) FROM information_schema.events WHERE event_schema = DATABASE())'
        : "SELECT (SELECT COUNT(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%') + (SELECT COUNT(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname NOT IN ('pg_catalog','information_schema')) + (SELECT COUNT(*) FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%')";
}
