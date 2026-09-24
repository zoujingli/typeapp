<?php

declare(strict_types=1);

namespace Type\Redis;

/** 启动阶段只读检查实际服务；同实例不同逻辑数据库不构成故障域隔离。 */
final class StoragePolicy
{
    /**
     * 临时连接两个服务并只读检查故障域、容量与持久化策略，所有退出路径关闭连接。
     *
     * @param string $appendFsync 接受 always 或 everysec；不修改 Redis 配置。
     * @return array{reliable: array<string, mixed>, cache: array<string, mixed>, isolated: bool, configured_fsync_window_seconds: int}
     * @throws RedisException 服务相同、存储策略不符或无法取得诊断信息。
     */
    public static function verify(RedisConfiguration $reliable, RedisConfiguration $cache, string $appendFsync = 'always'): array
    {
        if (!in_array($appendFsync, ['always', 'everysec'], true)) {
            throw new \InvalidArgumentException('可靠存储必须明确 always 或 everysec AOF 策略');
        }
        $taskClient = $reliable->connect();
        $cacheClient = null;
        try {
            $cacheClient = $cache->connect();
            $task = self::inspect($taskClient);
            $cached = self::inspect($cacheClient);
            if ($task['instance'] === $cached['instance']) {
                throw new RedisException('shared_failure_domain', 'NOT_STARTED', '缓存与可靠任务使用了同一 Redis 实例');
            }
            if ($task['role'] !== 'master' || $task['eviction'] !== 'noeviction' || $task['maxmemory'] < 1
                || !$task['appendonly'] || $task['appendfsync'] !== $appendFsync || $task['aof_write_status'] !== 'ok') {
                throw new RedisException('unreliable_storage', 'NOT_STARTED', '可靠 Redis 的主库、容量、noeviction 或 AOF 策略不满足约定');
            }
            return ['reliable' => $task, 'cache' => $cached, 'isolated' => true,
                'configured_fsync_window_seconds' => $appendFsync === 'always' ? 0 : 1];
        } catch (\RedisException $error) {
            throw new RedisException('policy_unavailable', 'NOT_STARTED', 'Redis 存储策略只读检查失败，不能确认可靠性配置', $error);
        } finally {
            $taskClient->close();
            $cacheClient?->close();
        }
    }

    private static function inspect(\Redis $client): array
    {
        $server = $client->info('server');
        $replication = $client->info('replication');
        $persistence = $client->info('persistence');
        $settings = $client->config('GET', ['maxmemory', 'maxmemory-policy', 'appendonly', 'appendfsync']);
        if (!is_array($server) || !is_string($server['run_id'] ?? null) || !is_array($settings)) {
            throw new RedisException('policy_unavailable', 'NOT_STARTED', '无法读取 Redis 真实存储策略，请为启动检查提供只读诊断权限');
        }
        return ['instance' => $server['run_id'], 'role' => $replication['role'] ?? '', 'eviction' => $settings['maxmemory-policy'] ?? '',
            'maxmemory' => (int) ($settings['maxmemory'] ?? 0), 'appendonly' => ($settings['appendonly'] ?? '') === 'yes',
            'appendfsync' => $settings['appendfsync'] ?? '', 'aof_write_status' => $persistence['aof_last_write_status'] ?? 'unknown'];
    }
}
