<?php

declare(strict_types=1);

namespace app\common\database;

use app\common\service\IdentityService;
use app\common\service\MenuService;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use app\platform\mqtt\database\AccessSchema;
use app\platform\mqtt\database\ResourceSchema;
use app\platform\mqtt\service\CompatService;
use Type\Core\Http\HttpError;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Driver;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\Migrator;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

/** Platform/System/Center 全新安装基线；旧安装不能自动迁移或被覆盖。 */
final class Schema
{
    public const VERSION = 3;

    /** 三库按各自真实 DDL 语义安装同一业务模型，不执行历史应用的转换链。 */
    public static function migrations(string $driver): array
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('database_driver_invalid');
        }
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        // SYSTEM_USER 是 PostgreSQL 保留关键字；表名保持模块约定，所有 DDL 引用使用相同标识符。
        $systemUser = $driver === 'pgsql' ? '"system_user"' : 'system_user';
        $sql = [
            'CREATE TABLE platform_installation (id INTEGER NOT NULL PRIMARY KEY CHECK (id = 1), installation_id VARCHAR(32) NOT NULL UNIQUE, schema_version INTEGER NOT NULL, created_at BIGINT NOT NULL)' . $suffix,
            'CREATE TABLE platform_tenant (id VARCHAR(32) NOT NULL PRIMARY KEY, name VARCHAR(100) NOT NULL, subdomain VARCHAR(63) NULL UNIQUE, request_id VARCHAR(32) NOT NULL UNIQUE, request_hash VARCHAR(64) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL)' . $suffix,
        ];
        $settings = 'name VARCHAR(100) NOT NULL, official_url VARCHAR(512) NOT NULL, description VARCHAR(500) NOT NULL, logo_url VARCHAR(512) NOT NULL, timezone VARCHAR(64) NOT NULL, theme_mode VARCHAR(8) NOT NULL, theme_color VARCHAR(16) NOT NULL, theme_radius VARCHAR(8) NOT NULL, layout_mode VARCHAR(32) NOT NULL, sidebar_collapsed INTEGER NOT NULL DEFAULT 0, navigation_style VARCHAR(16) NOT NULL, navigation_split INTEGER NOT NULL DEFAULT 1, breadcrumb_enable INTEGER NOT NULL DEFAULT 1, breadcrumb_show_icon INTEGER NOT NULL DEFAULT 1, breadcrumb_style VARCHAR(16) NOT NULL, tabbar_enable INTEGER NOT NULL DEFAULT 0, tabbar_style VARCHAR(16) NOT NULL, footer_enable INTEGER NOT NULL DEFAULT 0, footer_fixed INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL';
        $sql[] = 'CREATE TABLE platform_setting (id INTEGER NOT NULL PRIMARY KEY CHECK (id IN (1, 2)), ' . $settings . ')' . $suffix;
        $sql[] = 'CREATE TABLE system_setting (tenant_id VARCHAR(32) NOT NULL, id INTEGER NOT NULL DEFAULT 1 CHECK (id = 1), ' . $settings . ', PRIMARY KEY (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES platform_tenant(id))' . $suffix;
        foreach (['platform', 'system'] as $realm) {
            $userTable = $realm === 'system' ? $systemUser : 'platform_user';
            $sql[] = 'CREATE TABLE ' . $realm . '_menu (id VARCHAR(32) NOT NULL PRIMARY KEY, realm VARCHAR(16) NOT NULL CHECK (realm = \'' . $realm . '\'), parent_id VARCHAR(32) NULL, kind VARCHAR(16) NOT NULL, name VARCHAR(100) NOT NULL, path VARCHAR(200) NOT NULL, component VARCHAR(100) NOT NULL, permission VARCHAR(120) NULL UNIQUE, icon VARCHAR(100) NOT NULL, order_number INTEGER NOT NULL, enabled INTEGER NOT NULL, hidden INTEGER NOT NULL, guarded INTEGER NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, FOREIGN KEY (parent_id) REFERENCES ' . $realm . '_menu(id))' . $suffix;
            $sql[] = 'CREATE INDEX ' . $realm . '_menu_directory ON ' . $realm . '_menu (order_number, id)';
            $tenantColumn = $realm === 'system' ? ', tenant_id VARCHAR(32) NOT NULL' : '';
            $userConstraint = $realm === 'system'
                ? ', UNIQUE (tenant_id, login), UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES platform_tenant(id)'
                : ', UNIQUE (login)';
            $sql[] = 'CREATE TABLE ' . $userTable . ' (id VARCHAR(32) NOT NULL PRIMARY KEY' . $tenantColumn . ', login VARCHAR(100) NOT NULL, name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, failures INTEGER NOT NULL DEFAULT 0, locked_until BIGINT NOT NULL DEFAULT 0, recovery_verified INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL' . $userConstraint . ')' . $suffix;
            $origin = $realm === 'system' ? ', actor_id VARCHAR(32) NULL, source_session_id VARCHAR(32) NULL, FOREIGN KEY (actor_id) REFERENCES platform_user(id), FOREIGN KEY (source_session_id) REFERENCES platform_session(id) ON DELETE CASCADE, FOREIGN KEY (tenant_id, user_id) REFERENCES ' . $systemUser . '(tenant_id, id)' : '';
            $sql[] = 'CREATE TABLE ' . $realm . '_session (token_hash VARCHAR(64) NOT NULL PRIMARY KEY, id VARCHAR(32) NOT NULL UNIQUE' . $tenantColumn . ', user_id VARCHAR(32) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL' . $origin . ', FOREIGN KEY (user_id) REFERENCES ' . $userTable . '(id))' . $suffix;
            $sql[] = 'CREATE INDEX ' . $realm . '_session_user ON ' . $realm . '_session (user_id, created_at)';
            $sql[] = 'CREATE INDEX ' . $realm . '_session_expiry ON ' . $realm . '_session (expires_at)';
            $roleScope = $realm === 'system' ? 'tenant_id VARCHAR(32) NOT NULL' : 'scope_id VARCHAR(32) NOT NULL';
            $scopeField = $realm === 'system' ? 'tenant_id' : 'scope_id';
            $sql[] = 'CREATE TABLE ' . $realm . '_role (id VARCHAR(32) NOT NULL PRIMARY KEY, ' . $roleScope . ', name VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, protected INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, recovery_verified INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, UNIQUE (' . $scopeField . ', id), UNIQUE (' . $scopeField . ', name))' . $suffix;
            $bindingScope = $realm === 'system' ? ', FOREIGN KEY (tenant_id, user_id) REFERENCES ' . $systemUser . '(tenant_id, id), FOREIGN KEY (tenant_id, role_id) REFERENCES system_role(tenant_id, id)' : '';
            $sql[] = 'CREATE TABLE ' . $realm . '_user_role (user_id VARCHAR(32) NOT NULL, role_id VARCHAR(32) NOT NULL' . $tenantColumn . ', PRIMARY KEY (user_id, role_id), FOREIGN KEY (user_id) REFERENCES ' . $userTable . '(id), FOREIGN KEY (role_id) REFERENCES ' . $realm . '_role(id)' . $bindingScope . ')' . $suffix;
            $sql[] = 'CREATE TABLE ' . $realm . '_role_permission (role_id VARCHAR(32) NOT NULL' . $tenantColumn . ', permission VARCHAR(120) NOT NULL, PRIMARY KEY (role_id, permission), FOREIGN KEY (role_id) REFERENCES ' . $realm . '_role(id), FOREIGN KEY (permission) REFERENCES ' . $realm . '_menu(permission)' . ($realm === 'system' ? ', FOREIGN KEY (tenant_id, role_id) REFERENCES system_role(tenant_id, id)' : '') . ')' . $suffix;
            $sql[] = 'CREATE TABLE ' . $realm . '_audit (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) ' . ($realm === 'system' ? 'NOT NULL' : 'NULL') . ', actor_id VARCHAR(32) NOT NULL, action VARCHAR(80) NOT NULL, subject_id VARCHAR(100) NOT NULL, result VARCHAR(20) NOT NULL, details TEXT NOT NULL, created_at BIGINT NOT NULL, category VARCHAR(16) NOT NULL DEFAULT \'identity\', operation_id VARCHAR(32) NULL, event_key VARCHAR(16) NULL, request_id VARCHAR(32) NULL, stage VARCHAR(16) NULL)' . $suffix;
            $sql[] = 'CREATE INDEX ' . $realm . '_audit_time ON ' . $realm . '_audit (created_at, id)';
            $sql[] = 'CREATE INDEX ' . $realm . '_audit_tenant_category_time ON ' . $realm . '_audit (tenant_id, category, created_at, id)';
            $sql[] = 'CREATE UNIQUE INDEX ' . $realm . '_audit_operation_event ON ' . $realm . '_audit (operation_id, event_key)';
            $sql[] = 'CREATE TABLE ' . $realm . '_mqtt_operation (operation_id VARCHAR(32) NOT NULL PRIMARY KEY' . $tenantColumn . ', context_json TEXT NOT NULL, context_hash VARCHAR(64) NOT NULL, stage VARCHAR(20) NOT NULL, result VARCHAR(20) NOT NULL, version BIGINT NOT NULL, receipts_json TEXT NOT NULL, recovery_verified INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix;
        }
        $sql[] = 'CREATE TABLE platform_handoff (code_hash VARCHAR(64) NOT NULL PRIMARY KEY, target_tenant_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, user_version INTEGER NOT NULL, tenant_version INTEGER NOT NULL, actor_id VARCHAR(32) NOT NULL, source_session_id VARCHAR(32) NOT NULL, target_origin VARCHAR(300) NOT NULL, expires_at BIGINT NOT NULL, consumed_at BIGINT NULL, created_at BIGINT NOT NULL, FOREIGN KEY (target_tenant_id) REFERENCES platform_tenant(id), FOREIGN KEY (target_tenant_id, user_id) REFERENCES ' . $systemUser . '(tenant_id, id) ON DELETE CASCADE, FOREIGN KEY (actor_id) REFERENCES platform_user(id), FOREIGN KEY (source_session_id) REFERENCES platform_session(id) ON DELETE CASCADE)' . $suffix;
        foreach ([...ResourceSchema::migrations($driver), ...AccessSchema::migrations($driver)] as $migration) {
            $sql = [...$sql, ...$migration->statements()];
        }
        $sql = [...$sql, ...\app\center\database\Schema::tables($driver)];
        return [new Migration('300_platform_system_center', '建立独立客户账号、平台菜单与物联业务的新安装基线', $sql, $driver !== 'mysql')];
    }

    /** 在任何业务读取前拒绝旧安装，不尝试执行转换。 */
    public static function ready(Connection $connection): void
    {
        $row = $connection->table('platform_installation')->where('id', '=', 1)->first();
        if ($row === null || (int)$row['schema_version'] !== self::VERSION) {
            throw new HttpError(503, 'installation_incompatible');
        }
    }

    /** 空库只创建平台初始账号；客户由平台显式开通，口令来自受控进程环境。 */
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
