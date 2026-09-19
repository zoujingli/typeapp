<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Orm\Connection;

/**
 * 独立与双端共用的管理库兼容代次。升级后记录当前应用能维持的最低语义，启动时拒绝更旧的二进制。
 * 回退不是删除 occupancy、CRL 粘性序列或兼容行；不能维持新信任与状态的旧产物必须停止，并由备份恢复任务处理。
 */
final class CompatService
{
    /** 本应用能维持的管理/会话语义代次；与 037 恢复核对及占用、CRL 粘性等表同时生效。 */
    public const EPOCH = 19;
    /** 写入兼容行的管理迁移版本；与 Schema 中的 037 保持同一字面量。 */
    public const HEAD = '037_broker_recovery';

    /**
     * 安装或升级完成后记录当前代次。最低代次只升不降，避免用更旧二进制启动已升级的库。
     *
     * @throws \RuntimeException 兼容表尚未建立。
     */
    public static function recordUpgrade(Connection $connection, bool $storeReady = false): array
    {
        if (!self::tableExists($connection)) {
            throw new \RuntimeException('broker_compat_schema_missing');
        }
        $existing = $connection->table('broker_compatibility')->where('id', '=', 'default')->first();
        $now = time();
        $store = $storeReady ? 1 : ($existing === null ? 0 : (int) $existing['store_ready']);
        $min = max($existing === null ? 0 : (int) $existing['min_runtime_epoch'], self::EPOCH);
        $row = [
            'runtime_epoch' => self::EPOCH,
            'min_runtime_epoch' => $min,
            'management_head' => self::HEAD,
            'store_ready' => $store,
            'rollback_allowed' => 0,
            'rollback_reason' => self::rollbackReason(),
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $connection->table('broker_compatibility')->insert(['id' => 'default'] + $row);
        } else {
            $connection->table('broker_compatibility')->where('id', '=', 'default')->update($row);
        }

        return self::current($connection);
    }

    /**
     * 持久存储安装取得同步证明后标记 store_ready；表或行尚未建立时先写入升级契约。
     *
     * @throws \RuntimeException 兼容表尚未建立。
     */
    public static function recordStore(Connection $connection): array
    {
        if (!self::tableExists($connection)) {
            return self::current($connection);
        }
        if ($connection->table('broker_compatibility')->where('id', '=', 'default')->first() === null) {
            return self::recordUpgrade($connection, true);
        }
        $connection->table('broker_compatibility')->where('id', '=', 'default')->update([
            'store_ready' => 1,
            'updated_at' => time(),
        ]);

        return self::current($connection);
    }

    /**
     * 管理 HTTP 与 MQTT 节点启动前核对库代次。缺少兼容表视为尚未执行本票安装，允许启动以便升级路径先写入真实会话。
     *
     * @throws \InvalidArgumentException 库要求比本二进制更高的代次。
     */
    public static function assertRuntime(Connection $connection): void
    {
        if (!self::tableExists($connection)) {
            return;
        }
        $row = $connection->table('broker_compatibility')->where('id', '=', 'default')->first();
        if ($row === null) {
            return;
        }
        if ((int) $row['min_runtime_epoch'] > self::EPOCH || (int) $row['runtime_epoch'] > self::EPOCH) {
            throw new \InvalidArgumentException('broker_compat_runtime_stale');
        }
    }

    /**
     * 供管理查询的兼容快照；不含口令、路径或载荷。
     *
     * @return array{
     *     binary_epoch:int,runtime_epoch:int,min_runtime_epoch:int,management_head:string,store_ready:int,
     *     recorded:bool,compatible:bool,rollback_allowed:bool,rollback_reason:string,
     *     maintenance:array{keep_current_artifact:string,restore_via_b19:string,do_not_drop_state:string},
     *     updated_at:int
     * }
     */
    public static function current(Connection $connection): array
    {
        if (!self::tableExists($connection)) {
            return self::snapshot(null);
        }

        return self::snapshot($connection->table('broker_compatibility')->where('id', '=', 'default')->first());
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{
     *     binary_epoch:int,runtime_epoch:int,min_runtime_epoch:int,management_head:string,store_ready:int,
     *     recorded:bool,compatible:bool,rollback_allowed:bool,rollback_reason:string,
     *     maintenance:array{keep_current_artifact:string,restore_via_b19:string,do_not_drop_state:string},
     *     updated_at:int
     * }
     */
    private static function snapshot(?array $row): array
    {
        $runtime = $row === null ? 0 : (int) $row['runtime_epoch'];
        $min = $row === null ? 0 : (int) $row['min_runtime_epoch'];
        $compatible = $row === null || ($min <= self::EPOCH && $runtime <= self::EPOCH);
        $reason = $row === null
            ? '尚未记录兼容代次，须先完成当前版本的 broker:install 或 app:install。'
            : (string) $row['rollback_reason'];
        if ($reason === '') {
            $reason = self::rollbackReason();
        }

        return [
            'binary_epoch' => self::EPOCH,
            'runtime_epoch' => $runtime,
            'min_runtime_epoch' => $min,
            'management_head' => $row === null ? '' : (string) $row['management_head'],
            'store_ready' => $row === null ? 0 : (int) $row['store_ready'],
            'recorded' => $row !== null,
            'compatible' => $compatible,
            'rollback_allowed' => $row !== null && (int) $row['rollback_allowed'] === 1 && $compatible,
            'rollback_reason' => $reason,
            'maintenance' => self::maintenance(),
            'updated_at' => $row === null ? 0 : (int) $row['updated_at'],
        ];
    }

    private static function tableExists(Connection $connection): bool
    {
        $driver = $connection->driverName();
        if ($driver === 'pgsql') {
            return $connection->query(
                'SELECT 1 AS present FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                ['broker_compatibility']
            ) !== [];
        }
        if ($driver === 'mysql') {
            return $connection->query(
                'SELECT 1 AS present FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                ['broker_compatibility']
            ) !== [];
        }

        return $connection->query("SELECT 1 AS present FROM sqlite_master WHERE type = 'table' AND name = ?", ['broker_compatibility']) !== [];
    }

    private static function rollbackReason(): string
    {
        return '当前库含占用名额、CRL 粘性序列与会话语义，旧应用不能维持新的信任与状态；继续使用当前原生产物。'
            . '若必须回到旧应用，先按备份恢复任务从已核对撤销事实的备份恢复，不能删除状态表假装兼容。';
    }

    /**
     * @return array{keep_current_artifact:string,restore_via_b19:string,do_not_drop_state:string}
     */
    private static function maintenance(): array
    {
        return [
            'keep_current_artifact' => '继续使用当前已安装的原生产物，不要用更旧的二进制启动已升级的库。',
            'restore_via_b19' => '若必须回到历史应用，先按备份恢复任务从已核对撤销事实的备份恢复，而不是降级当前库。',
            'do_not_drop_state' => '不能删除 broker_debug_occupancy、broker_access_crl_serials、broker_access_platform_serials 或 broker_compatibility 来假装兼容。',
        ];
    }
}
