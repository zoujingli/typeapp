<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\broker\service\CompatService;
use app\common\database\DatabaseFactory;
use app\common\database\Schema;
use app\iot\service\RecoveryService;
use Type\Core\Config\Repository;
use Type\Orm\Connection;
use Type\Orm\Migration\Migrator;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Runtime\LocalFile;

/** 已安装物联中心的显式升级门；不重装账号，不猜测未知旧库的数据转换。 */
final class ApplicationUpgrade
{
    /**
     * --check 只检查；执行要求 --offline --backup <文件> --sha256 <已核对摘要>。
     * 停止全部写入角色及备份可恢复性由操作人确认，摘要证明仅覆盖备份字节身份。
     * @param list<string> $arguments
     * @return array<string,mixed> 无配置秘密的计划或完成回执。
     */
    public static function run(Repository $settings, string $basePath, array $arguments): array
    {
        $check = $arguments === ['--check'];
        if (!$check && (count($arguments) !== 5 || $arguments[0] !== '--offline' || $arguments[1] !== '--backup'
            || $arguments[3] !== '--sha256' || preg_match('/^[a-f0-9]{64}$/D', $arguments[4]) !== 1)) {
            throw new \InvalidArgumentException('app:upgrade --check 或 --offline --backup <备份文件> --sha256 <SHA256>；先停止全部写入角色并验证备份可恢复');
        }
        DatabaseFactory::requireExisting($settings, $basePath);
        $digest = '';
        if (!$check) {
            $path = LocalFile::path(Settings::absolutePath($arguments[2]) ? $arguments[2] : $basePath . '/' . $arguments[2]);
            $stream = @fopen($path, 'rb');
            if (!is_resource($stream)) {
                throw new \InvalidArgumentException('upgrade_backup_unavailable');
            }
            try {
                LocalFile::assertOpened($path, $stream);
                $stat = fstat($stream);
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                $digest = hash_final($hash);
                LocalFile::assertOpened($path, $stream);
                if ($stat === false || $stat['size'] === 0 || !hash_equals($arguments[4], $digest)) {
                    throw new \InvalidArgumentException('upgrade_backup_digest_mismatch');
                }
            } finally {
                fclose($stream);
            }
        }
        return CoroutineRuntime::run(static function () use ($settings, $basePath, $check, $digest): array {
            $driver = DatabaseFactory::create($settings, $basePath);
            $plan = Schema::migrations($driver->name());
            $migrator = new Migrator($driver, budget: Settings::databaseBudget($settings));
            if ($check) {
                $states = $migrator->status($plan);
                $database = Settings::database($settings, $basePath, 1);
                $scope = new ExecutionScope();
                try {
                    self::verify($database->connect($scope), $states);
                } finally {
                    $scope->close();
                    $database->close();
                }
            } else {
                $states = $migrator->run(
                    $plan,
                    false,
                    static function (Connection $connection, array $states): void {
                        self::verify($connection, $states);
                    },
                    static function (Connection $connection, array $states): void {
                        self::verify($connection, $states);
                        // 不改变已有账号/任务；当前代次无需重复写入兼容记录。
                        $current = CompatService::current($connection);
                        if ($current['runtime_epoch'] !== CompatService::EPOCH || $current['management_head'] !== CompatService::HEAD) {
                            CompatService::recordUpgrade($connection);
                        }
                    }
                );
            }
            return ['status' => $check ? 'upgrade_checked' : 'upgrade_complete', 'schema_version' => 1,
                'migrations' => $states, 'backup_sha256' => $digest === '' ? null : $digest,
                'restart_required' => !$check, 'frontend_command' => 'web:install --force'];
        });
    }

    /** 在写入前复核安装身份、已知迁移、恢复门及二进制兼容；仅 101 可作为已知增量。 */
    private static function verify(Connection $connection, array $states): void
    {
        foreach ($states as $state) {
            if (in_array($state['state'], ['failed', 'running'], true)) {
                throw new \InvalidArgumentException('upgrade_recovery_required：先通过 migrate history/recover 核对迁移实际状态');
            }
            if ($state['state'] !== 'applied' && ($state['version'] !== '101_app_broker_operation_recovery' || $state['state'] !== 'pending')) {
                throw new \InvalidArgumentException('upgrade_schema_unsupported：只支持当前安装谱系，不能从未知旧模式直接升级');
            }
        }
        $installation = $connection->table('app_installation')->where('id', '=', 1)->first();
        if ($installation === null || (int) $installation['schema_version'] !== 1) {
            throw new \InvalidArgumentException('upgrade_schema_unsupported');
        }
        $compatibility = CompatService::current($connection);
        if (!$compatibility['recorded'] || !$compatibility['compatible']) {
            throw new \InvalidArgumentException('upgrade_runtime_incompatible');
        }
        RecoveryService::ready($connection);
    }
}
