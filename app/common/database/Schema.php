<?php

declare(strict_types=1);

namespace app\common\database;

use app\broker\service\CompatService;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use Type\Core\Http\HttpError;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\Migrator;
use Type\Runtime\ExecutionScope;

/** 全新物联应用模式；不读取、转换或覆盖旧应用身份。 */
final class Schema
{
    /**
     * 只返回声明，不连接数据库；MySQL 的非事务 DDL 不能承诺自动回滚。
     *
     * @return list<Migration>
     * @throws \InvalidArgumentException 驱动不属于该应用的支持范围。
     */
    public static function migrations(string $driver): array
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('迁移不支持所选数据库驱动');
        }
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        $statements = [
            'CREATE TABLE app_installation (id INTEGER NOT NULL PRIMARY KEY CHECK (id = 1), installation_id VARCHAR(32) NOT NULL UNIQUE, schema_version INTEGER NOT NULL, created_at BIGINT NOT NULL)' . $suffix,
            'CREATE TABLE app_site_settings (id INTEGER NOT NULL PRIMARY KEY CHECK (id = 1), name VARCHAR(100) NOT NULL, official_url VARCHAR(512) NOT NULL, description VARCHAR(500) NOT NULL, logo_url VARCHAR(512) NOT NULL, timezone VARCHAR(64) NOT NULL, theme_mode VARCHAR(8) NOT NULL, theme_color VARCHAR(16) NOT NULL, theme_radius VARCHAR(8) NOT NULL, layout_mode VARCHAR(32) NOT NULL, sidebar_collapsed INTEGER NOT NULL DEFAULT 0, navigation_style VARCHAR(16) NOT NULL, navigation_split INTEGER NOT NULL DEFAULT 1, breadcrumb_enable INTEGER NOT NULL DEFAULT 1, breadcrumb_show_icon INTEGER NOT NULL DEFAULT 1, breadcrumb_style VARCHAR(16) NOT NULL, tabbar_enable INTEGER NOT NULL DEFAULT 0, tabbar_style VARCHAR(16) NOT NULL, footer_enable INTEGER NOT NULL DEFAULT 0, footer_fixed INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix,
            'CREATE TABLE iot_tenants (id VARCHAR(32) NOT NULL PRIMARY KEY, name VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL)' . $suffix,
        ];
        foreach (['admin', 'customer'] as $realm) {
            $statements[] = 'CREATE TABLE ' . $realm . '_users (id VARCHAR(32) NOT NULL PRIMARY KEY, login VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, failures INTEGER NOT NULL DEFAULT 0, locked_until BIGINT NOT NULL DEFAULT 0, recovery_verified INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL)' . $suffix;
            $originColumns = $realm === 'customer' ? ', actor_id VARCHAR(32) NULL, source_session_id VARCHAR(32) NULL' : '';
            $originConstraints = $realm === 'customer' ? ', FOREIGN KEY (actor_id) REFERENCES admin_users(id), FOREIGN KEY (source_session_id) REFERENCES admin_sessions(id) ON DELETE CASCADE, CHECK ((actor_id IS NULL AND source_session_id IS NULL) OR (actor_id IS NOT NULL AND source_session_id IS NOT NULL))' : '';
            $statements[] = 'CREATE TABLE ' . $realm . '_sessions (token_hash VARCHAR(64) NOT NULL PRIMARY KEY, id VARCHAR(32) NOT NULL UNIQUE, user_id VARCHAR(32) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL' . $originColumns . ', FOREIGN KEY (user_id) REFERENCES ' . $realm . '_users(id)' . $originConstraints . ')' . $suffix;
            if ($realm === 'customer') {
                $statements[] = 'CREATE INDEX customer_sessions_source ON customer_sessions (source_session_id, created_at)';
            }
            $statements[] = 'CREATE INDEX ' . $realm . '_sessions_user ON ' . $realm . '_sessions (user_id, created_at)';
            $statements[] = 'CREATE INDEX ' . $realm . '_sessions_expiry ON ' . $realm . '_sessions (expires_at)';
            $statements[] = 'CREATE TABLE ' . $realm . '_roles (id VARCHAR(32) NOT NULL PRIMARY KEY, scope_id VARCHAR(32) NOT NULL, name VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, protected INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, recovery_verified INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, UNIQUE (scope_id, id), UNIQUE (scope_id, name))' . $suffix;
            $statements[] = 'CREATE TABLE ' . $realm . '_role_permissions (role_id VARCHAR(32) NOT NULL, permission VARCHAR(80) NOT NULL, PRIMARY KEY (role_id, permission), FOREIGN KEY (role_id) REFERENCES ' . $realm . '_roles(id))' . $suffix;
            $statements[] = 'CREATE TABLE ' . $realm . '_audit (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NULL, actor_id VARCHAR(32) NOT NULL, action VARCHAR(80) NOT NULL, subject_id VARCHAR(100) NOT NULL, result VARCHAR(20) NOT NULL, details TEXT NOT NULL, created_at BIGINT NOT NULL)' . $suffix;
            $statements[] = 'CREATE INDEX ' . $realm . '_audit_time ON ' . $realm . '_audit (created_at, id)';
            // 双端Broker动作沿用同一审计和操作账本，不再写入旧IoT身份表。
            $statements[] = 'ALTER TABLE ' . $realm . "_audit ADD COLUMN category VARCHAR(16) NOT NULL DEFAULT 'identity'";
            foreach (['operation_id' => 32, 'event_key' => 16, 'request_id' => 32, 'stage' => 16] as $column => $length) {
                $statements[] = 'ALTER TABLE ' . $realm . '_audit ADD COLUMN ' . $column . ' VARCHAR(' . $length . ') NULL';
            }
            $statements[] = 'CREATE INDEX ' . $realm . '_audit_category_time ON ' . $realm . '_audit (category, created_at, id)';
            $statements[] = 'CREATE INDEX ' . $realm . '_audit_tenant_category_time ON ' . $realm . '_audit (tenant_id, category, created_at, id)';
            $statements[] = 'CREATE INDEX ' . $realm . '_audit_operation_time ON ' . $realm . '_audit (operation_id, created_at, id)';
            $statements[] = 'CREATE UNIQUE INDEX ' . $realm . '_audit_operation_event ON ' . $realm . '_audit (operation_id, event_key)';
            $statements[] = 'CREATE TABLE ' . $realm . '_broker_operations (operation_id VARCHAR(32) NOT NULL PRIMARY KEY, context_json TEXT NOT NULL, context_hash VARCHAR(64) NOT NULL, stage VARCHAR(16) NOT NULL, result VARCHAR(20) NOT NULL, version BIGINT NOT NULL, receipts_json TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix;
        }
        $statements[] = 'CREATE TABLE admin_user_roles (user_id VARCHAR(32) NOT NULL, role_id VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, role_id), FOREIGN KEY (user_id) REFERENCES admin_users(id), FOREIGN KEY (role_id) REFERENCES admin_roles(id))' . $suffix;
        $statements[] = "CREATE TABLE customer_members (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, name VARCHAR(100) NOT NULL DEFAULT '', enabled INTEGER NOT NULL DEFAULT 1, recovery_verified INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, UNIQUE (tenant_id, user_id), UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id), FOREIGN KEY (user_id) REFERENCES customer_users(id))" . $suffix;
        $statements[] = 'CREATE TABLE customer_member_roles (tenant_id VARCHAR(32) NOT NULL, member_id VARCHAR(32) NOT NULL, role_id VARCHAR(32) NOT NULL, PRIMARY KEY (member_id, role_id), FOREIGN KEY (tenant_id, member_id) REFERENCES customer_members(tenant_id, id), FOREIGN KEY (tenant_id, role_id) REFERENCES customer_roles(scope_id, id))' . $suffix;
        return [new Migration('100_app_identity', '创建双账号域、作用域角色与全新安装身份', [...$statements, ...\app\iot\database\Schema::products($driver),
            ...\app\iot\database\Schema::devices($driver), ...\app\iot\database\Schema::ingestion($driver), ...\app\iot\database\Schema::aggregates($driver),
            ...\app\iot\database\Schema::commands($driver), ...\app\iot\database\Schema::modelSwitches($driver), ...\app\iot\database\Schema::transfers($driver),
            ...\app\iot\database\Schema::alarms($driver), ...\app\iot\database\Schema::exports($driver), ...\app\iot\database\Schema::metrics($driver), ...\app\iot\database\Schema::recovery($driver)], $driver !== 'mysql'),
            ...\app\broker\database\ResourceSchema::migrations($driver), ...\app\broker\database\AccessSchema::migrations($driver),
            new Migration('101_app_broker_operation_recovery', '为双端Broker操作账本增加恢复核对标记', [
                'ALTER TABLE admin_broker_operations ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
                'ALTER TABLE customer_broker_operations ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            ], $driver !== 'mysql')];
    }

    /**
     * 显式安装到空库；DDL 后初始人员与全部授权在一个事务内提交。
     * @param list<string> $accounts 管理登录名、姓名、客户登录名、姓名、租户名。
     * @return array<string, mixed> 无凭据的安装回执；失败后的非空库必须人工核对。
     */
    public static function install(Driver $driver, array $accounts, string $adminPassword, string $customerPassword): array
    {
        return \Type\Runtime\CoroutineRuntime::run(static function () use ($driver, $accounts, $adminPassword, $customerPassword): array {
            if (count($accounts) !== 5 || trim($accounts[4]) === '' || strlen($accounts[4]) > 100) {
                throw new HttpError(422, 'installation_input_invalid');
            }
            IdentityService::validateAccount($accounts[0], $accounts[1], $adminPassword);
            IdentityService::validateAccount($accounts[2], $accounts[3], $customerPassword);
            (new Migrator($driver))->run(self::migrations($driver->name()), true);
            $database = new \Type\Orm\DatabaseManager(['default' => $driver], 1, 0);
            \Type\Orm\Db::configure($database);
            $scope = new ExecutionScope();
            try {
                return $scope->run(static function (ExecutionScope $current) use ($driver, $accounts, $adminPassword, $customerPassword): array {
                    return \Type\Orm\Db::transaction(static function () use ($accounts, $adminPassword, $customerPassword): array {
                        $connection = \Type\Orm\Db::connection('default', true);
                        $installation = bin2hex(random_bytes(16));
                        $now = time();
                        $connection->table('app_installation')->insert(['id' => 1, 'installation_id' => $installation, 'schema_version' => 1, 'created_at' => $now]);
                        $connection->table('app_site_settings')->insert(['id' => 1, ...SiteSettings::defaults(), 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                        $admin = (new IdentityService('admin'))->provision($accounts[0], $accounts[1], $adminPassword, false);
                        $customer = (new IdentityService('customer'))->provision($accounts[2], $accounts[3], $customerPassword, false);
                        $tenant = bin2hex(random_bytes(16));
                        $member = bin2hex(random_bytes(16));
                        $connection->table('iot_tenants')->insert(['id' => $tenant, 'name' => trim($accounts[4]), 'created_at' => time()]);
                        $connection->table('customer_members')->insert(['id' => $member, 'tenant_id' => $tenant, 'user_id' => $customer['id'], 'name' => $customer['name'], 'created_at' => time()]);
                        RoleService::initializeScope('admin', 'platform', (string) $admin['id']);
                        RoleService::initializeScope('customer', $tenant, $member);
                        CompatService::recordUpgrade($connection);
                        return ['installation_id' => $installation, 'admin' => $admin, 'customer' => $customer, 'tenant_id' => $tenant];
                    }, 'default', $driver->name() === 'sqlite' ? 'immediate' : 'default');
                });
            } finally {
                $scope->close();
                $database->close();
            }
        });
    }
}
