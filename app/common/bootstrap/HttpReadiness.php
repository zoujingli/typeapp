<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\center\service\RecoveryService;
use app\common\database\Schema;
use app\platform\mqtt\service\CompatService;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Migration\SchemaMigration;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/**
 * 每个 HTTP 执行者缓存必需数据库和恢复门的状态，不探测后台角色的可选 Redis。
 */
final class HttpReadiness
{
    private float $checkedAt = 0.0;

    private bool $healthy = false;

    private bool $checking = false;

    private string $installation = '';

    /**
     * @var array<string,string> 本执行者当前程序声明的迁移摘要。
     */
    private array $migrations = [];

    /**
     * 管理器与业务请求共用额度；实例不能跨线程共享。
     */
    public function __construct(private DatabaseManager $database) {}

    /**
     * 最多每两秒一次真实检查；并发探针只读五秒内的已完成状态，过期关闭就绪。
     * 依赖故障仅撤销就绪，保留进程存活和下次检查，不触发进程重启。
     */
    public function ready(): bool
    {
        $now = hrtime(true) / 1e9;
        if ($this->checking || $now - $this->checkedAt < 2.0) {
            return $this->healthy && $now - $this->checkedAt < 5.0;
        }
        $this->checking = true;
        $scope = new ExecutionScope(new Deadline(1.0));
        try {
            $scope->run(function (): void {
                $connection = Db::connection('default', true);
                if ($this->migrations === []) {
                    $plan = Schema::migrations($connection->driverName());
                    foreach ($plan as $migration) {
                        $compiled = $migration instanceof SchemaMigration ? $migration->compile($connection->driverName()) : $migration;
                        $this->migrations[$compiled->version()] = $compiled->checksum();
                    }
                }
                $applied = $connection->query('SELECT version, checksum, state FROM type_migrations');
                if (count($applied) !== count($this->migrations)) {
                    $this->healthy = false;
                    return;
                }
                foreach ($applied as $record) {
                    if ($record['state'] !== 'applied' || !isset($this->migrations[$record['version']])
                        || !hash_equals($this->migrations[$record['version']], (string)$record['checksum'])) {
                        $this->healthy = false;
                        return;
                    }
                }
                $compatibility = CompatService::current($connection);
                if (!$compatibility['recorded'] || !$compatibility['compatible']) {
                    $this->healthy = false;
                    return;
                }
                RecoveryService::ready($connection);
                Schema::ready($connection);
                $row = $connection->table('platform_installation')->where('id', '=', 1)->first();
                if ($row === null || (int)$row['schema_version'] !== Schema::VERSION) {
                    $this->healthy = false;
                    return;
                }
                $identity = (string)$row['installation_id'];
                if ($this->installation !== '' && $this->installation !== $identity) {
                    $this->healthy = false;
                    return;
                }
                $this->installation = $identity;
                $this->healthy = true;
            });
        } catch (\Throwable) {
            $this->healthy = false;
        } finally {
            try {
                $scope->close();
            } catch (\Throwable) {
                $this->healthy = false;
            }
            $this->checkedAt = hrtime(true) / 1e9;
            $this->checking = false;
        }
        return $this->healthy;
    }
}
