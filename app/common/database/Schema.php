<?php

declare(strict_types=1);

namespace app\common\database;

use app\common\service\IdentityService;
use app\common\service\MenuService;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use app\platform\mqtt\service\CompatService;
use Type\Core\Http\HttpError;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Driver;
use Type\Orm\Migration\Migrator;
use Type\Orm\Migration\Migration;
use app\generated\Migrations;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

/**
 * Platform/System/Center 全新安装基线；旧安装不能自动迁移或被覆盖。
 */
final class Schema
{
    public const VERSION = 3;

    /**
     * 三库共用同一份 Schema 迁移。
     *
     * @return list<Migration>
     */
    public static function migrations(string $driver): array
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('database_driver_invalid');
        }
        return Migrations::all($driver);
    }


    /**
     * 在任何业务读取前拒绝旧安装，不尝试执行转换。
     */
    public static function ready(Connection $connection): void
    {
        $row = $connection->table('platform_installation')->where('id', '=', 1)->first();
        if ($row === null || (int)$row['schema_version'] !== self::VERSION) {
            throw new HttpError(503, 'installation_incompatible');
        }
    }

    /**
     * 空库只创建平台初始账号；客户由平台显式开通，口令来自受控进程环境。
     */
    public static function install(Driver $driver, array $accounts, string $password, ?DeploymentBudget $budget = null): array
    {
        if (count($accounts) !== 2) {
            throw new HttpError(422, 'installation_input_invalid');
        }
        IdentityService::validateAccount($accounts[0], $accounts[1], $password);
        return CoroutineRuntime::run(static function () use ($driver, $accounts, $password, $budget): array {
            (new Migrator($driver, budget: $budget))->run(self::migrations($driver->name()), true);
            $database = new DatabaseManager(['default' => $driver], 1, 0, $budget);
            Db::configure($database);
            $scope = new ExecutionScope();
            try {
                return $scope->run(static function (ExecutionScope $current) use ($driver, $accounts, $password): array {
                    return Db::transaction(static function () use ($accounts, $password): array {
                        $connection = Db::connection('default', true);
                        $installation = bin2hex(random_bytes(16));
                        $now = time();
                        $connection->table('platform_installation')->insert(['id' => 1, 'installation_id' => $installation, 'schema_version' => self::VERSION, 'created_at' => $now]);
                        $connection->table('platform_setting')->insert(['id' => 1, ...SiteSettings::defaults(), 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                        $connection->table('platform_setting')->insert(['id' => 2, ...SiteSettings::defaults(), 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                        MenuService::initialize();
                        $user = (new IdentityService('platform'))->provision($accounts[0], $accounts[1], $password, false);
                        RoleService::initializeScope('platform', 'platform', $user['id']);
                        CompatService::recordUpgrade($connection);
                        return ['installation_id' => $installation, 'platform' => $user];
                    }, 'default', $driver->name() === 'sqlite' ? 'immediate' : 'default');
                });
            } finally {
                $scope->close();
                $database->close();
            }
        });
    }
}
