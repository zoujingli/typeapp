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
        if ($driver === 'mysql') {
            $sql = [...$sql, ...self::mysqlComments()];
        }
        return [new Migration('300_platform_system_center', '建立 Tenant、System 账号、平台菜单与物联业务的新安装基线', $sql, $driver !== 'mysql')];
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

    /**
     * MySQL 的 INFORMATION_SCHEMA 才能提供反向模型同步所需的表/字段说明。
     * SQLite 和 PostgreSQL 没有等价的字段 COMMENT 语义，模型 PHPDoc 仍是它们的权威说明。
     * MODIFY COLUMN 保留完整约束，避免只补 COMMENT 时意外改变默认值、可空性或精度。
     *
     * @return list<string> 只在 MySQL 全新安装中执行的注释语句
     */
    private static function mysqlComments(): array
    {
        return [
            "ALTER TABLE platform_installation COMMENT = '平台安装身份与模式版本'",
            "ALTER TABLE platform_tenant COMMENT = '客户组织、登记域名与开通状态'",
            "ALTER TABLE platform_setting COMMENT = '平台品牌、主题及公共登录模板'",
            "ALTER TABLE system_setting COMMENT = '客户系统品牌、主题及默认界面'",
            "ALTER TABLE platform_menu COMMENT = '平台菜单与共享子系统权限目录'",
            "ALTER TABLE system_menu COMMENT = '客户系统菜单与权限目录'",
            "ALTER TABLE platform_user COMMENT = '平台管理人员账号'",
            "ALTER TABLE system_user COMMENT = '直接归属客户的系统账号'",
            "ALTER TABLE platform_role COMMENT = '平台角色及权限集合'",
            "ALTER TABLE system_role COMMENT = '客户系统角色及权限集合'",
            "ALTER TABLE platform_session COMMENT = '平台登录会话摘要'",
            "ALTER TABLE system_session COMMENT = '客户系统登录会话摘要'",
            "ALTER TABLE platform_handoff COMMENT = '平台模拟登录一次性交接码'",
            "ALTER TABLE center_product COMMENT = '客户设备分类资料'",
            "ALTER TABLE center_device COMMENT = '客户当前使用的原始通信设备'",
            "ALTER TABLE center_device_credential COMMENT = '设备当前接入凭据'",
            "ALTER TABLE center_device_ownership COMMENT = '设备在客户中的使用关系阶段'",
            "ALTER TABLE center_device_connection COMMENT = '设备当前连接观察投影'",
            "ALTER TABLE center_authorization_invalidation COMMENT = '设备凭据撤权及 Broker 隔离回执'",
            "ALTER TABLE center_device_enrollment COMMENT = '平台签发的设备注册资格'",
            "ALTER TABLE center_device_enrollment_binding COMMENT = '注册资格与设备使用关系绑定事实'",
            "ALTER TABLE center_application COMMENT = '客户外部系统接入应用身份'",
            "ALTER TABLE center_application_grant COMMENT = '接入应用对设备使用关系的通信授权'",
            "ALTER TABLE center_device_reassignment COMMENT = '设备跨客户转配流程'",
            "ALTER TABLE center_registration_attempt COMMENT = '公开设备注册入口来源限流窗口'",
            "ALTER TABLE platform_installation MODIFY COLUMN id INTEGER NOT NULL COMMENT '安装互斥槽位，固定为 1'",
            "ALTER TABLE platform_installation MODIFY COLUMN installation_id VARCHAR(32) NOT NULL COMMENT '全新安装生成的稳定身份'",
            "ALTER TABLE platform_installation MODIFY COLUMN schema_version INTEGER NOT NULL COMMENT '当前安装支持的数据结构版本'",
            "ALTER TABLE platform_installation MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE platform_tenant MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '客户不可变的 32 位小写十六进制标识'",
            "ALTER TABLE platform_tenant MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE platform_tenant MODIFY COLUMN subdomain VARCHAR(63) NULL COMMENT '登记的客户子域名前缀；未配置时为空'",
            "ALTER TABLE platform_tenant MODIFY COLUMN request_id VARCHAR(32) NOT NULL COMMENT '创建请求的幂等标识'",
            "ALTER TABLE platform_tenant MODIFY COLUMN request_hash VARCHAR(64) NOT NULL COMMENT '创建请求内容摘要，用于拒绝幂等标识冲突'",
            "ALTER TABLE platform_tenant MODIFY COLUMN enabled INTEGER NOT NULL DEFAULT 1 COMMENT '当前是否启用'",
            "ALTER TABLE platform_tenant MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE platform_tenant MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE platform_user MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE platform_user MODIFY COLUMN login VARCHAR(100) NOT NULL COMMENT '规范化的登录账号'",
            "ALTER TABLE platform_user MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE platform_user MODIFY COLUMN password_hash VARCHAR(255) NOT NULL COMMENT '登录口令散列，不进入公开投影'",
            "ALTER TABLE platform_user MODIFY COLUMN enabled INTEGER NOT NULL DEFAULT 1 COMMENT '当前是否启用'",
            "ALTER TABLE platform_user MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料版本由身份服务在授权锁内维护，登录计数不属于资料变更'",
            "ALTER TABLE platform_user MODIFY COLUMN failures INTEGER NOT NULL DEFAULT 0 COMMENT '当前口令验证连续失败次数'",
            "ALTER TABLE platform_user MODIFY COLUMN locked_until BIGINT NOT NULL DEFAULT 0 COMMENT '口令验证锁定截止时间，UTC Unix 秒；0 表示未锁定'",
            "ALTER TABLE platform_user MODIFY COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1 COMMENT '当前授权事实是否已完成恢复核对'",
            "ALTER TABLE platform_user MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE system_user MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE system_user MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE system_user MODIFY COLUMN login VARCHAR(100) NOT NULL COMMENT '规范化的登录账号'",
            "ALTER TABLE system_user MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE system_user MODIFY COLUMN password_hash VARCHAR(255) NOT NULL COMMENT '登录口令散列，不进入公开投影'",
            "ALTER TABLE system_user MODIFY COLUMN enabled INTEGER NOT NULL DEFAULT 1 COMMENT '当前是否启用'",
            "ALTER TABLE system_user MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料版本由身份服务在授权锁内维护，登录计数不属于资料变更'",
            "ALTER TABLE system_user MODIFY COLUMN failures INTEGER NOT NULL DEFAULT 0 COMMENT '当前口令验证连续失败次数'",
            "ALTER TABLE system_user MODIFY COLUMN locked_until BIGINT NOT NULL DEFAULT 0 COMMENT '口令验证锁定截止时间，UTC Unix 秒；0 表示未锁定'",
            "ALTER TABLE system_user MODIFY COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1 COMMENT '当前授权事实是否已完成恢复核对'",
            "ALTER TABLE system_user MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_product MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '设备分类的稳定标识'",
            "ALTER TABLE center_product MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE center_product MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE center_product MODIFY COLUMN description TEXT NOT NULL COMMENT '供管理人员阅读的说明'",
            "ALTER TABLE center_product MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE center_product MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_product MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE center_device MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '设备的全局稳定标识'",
            "ALTER TABLE center_device MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE center_device MODIFY COLUMN product_id VARCHAR(32) NULL COMMENT '设备所属分类标识；未分类时为空'",
            "ALTER TABLE center_device MODIFY COLUMN ownership_id VARCHAR(32) NOT NULL COMMENT '授权或操作绑定的设备使用关系'",
            "ALTER TABLE center_device MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE center_device MODIFY COLUMN lifecycle VARCHAR(20) NOT NULL COMMENT '设备管理生命周期，与连接观察状态分别维护'",
            "ALTER TABLE center_device MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE center_device MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_device MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE center_device MODIFY COLUMN transfer_id VARCHAR(32) NULL COMMENT '当前转配流程标识；无进行中流程时为空'",
            "ALTER TABLE center_device MODIFY COLUMN transfer_frozen INTEGER NOT NULL DEFAULT 0 COMMENT '当前使用关系是否因转配流程冻结'",
            "ALTER TABLE center_device MODIFY COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1 COMMENT '当前授权事实是否已完成恢复核对'",
            "ALTER TABLE center_device_credential MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '凭据稳定标识'",
            "ALTER TABLE center_device_credential MODIFY COLUMN device_id VARCHAR(32) NOT NULL COMMENT '设备的稳定标识'",
            "ALTER TABLE center_device_credential MODIFY COLUMN ownership_id VARCHAR(32) NOT NULL COMMENT '凭据对应的设备使用关系'",
            "ALTER TABLE center_device_credential MODIFY COLUMN secret_hash VARCHAR(64) NOT NULL COMMENT '凭据验证散列，不进入公开投影'",
            "ALTER TABLE center_device_credential MODIFY COLUMN status VARCHAR(20) NOT NULL COMMENT '凭据状态'",
            "ALTER TABLE center_device_credential MODIFY COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1 COMMENT '凭据事实是否已完成恢复核对'",
            "ALTER TABLE center_device_credential MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_device_credential MODIFY COLUMN revoked_at BIGINT NULL COMMENT '吊销时间，UTC Unix 秒'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '设备一次使用关系的稳定标识'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN device_id VARCHAR(32) NOT NULL COMMENT '设备的稳定标识'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN started_at BIGINT NOT NULL COMMENT '本次设备使用关系的开始时间，UTC Unix 秒'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN ended_at BIGINT NULL COMMENT '使用关系结束时间，UTC Unix 秒；尚未结束时为空'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN expires_at BIGINT NULL COMMENT '有效截止时间，UTC Unix 秒'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN expiry_context TEXT NULL COMMENT '使用期限操作的审计身份快照'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN access_started INTEGER NOT NULL DEFAULT 0 COMMENT '是否已有实际通信接入历史'",
            "ALTER TABLE center_device_ownership MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE center_device_connection MODIFY COLUMN device_id VARCHAR(32) NOT NULL COMMENT '设备的稳定标识，也是本投影主键'",
            "ALTER TABLE center_device_connection MODIFY COLUMN ownership_id VARCHAR(32) NOT NULL COMMENT '观察对应的设备使用关系'",
            "ALTER TABLE center_device_connection MODIFY COLUMN owner_id VARCHAR(32) NULL COMMENT '当前连接资源所有者标识'",
            "ALTER TABLE center_device_connection MODIFY COLUMN node_id VARCHAR(64) NULL COMMENT '产生观察的 MQTT 节点标识'",
            "ALTER TABLE center_device_connection MODIFY COLUMN run_id VARCHAR(32) NULL COMMENT '节点运行代次'",
            "ALTER TABLE center_device_connection MODIFY COLUMN status VARCHAR(20) NOT NULL COMMENT '当前连接观察状态'",
            "ALTER TABLE center_device_connection MODIFY COLUMN observed_at BIGINT NULL COMMENT '最近一次观察时间，UTC Unix 秒'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN device_id VARCHAR(32) NOT NULL COMMENT '设备的稳定标识，也是本记录主键'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '撤权意图的稳定标识'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN principal VARCHAR(65) NOT NULL COMMENT 'Broker 侧待隔离主体标识'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN actor_id VARCHAR(32) NOT NULL COMMENT '发起撤权的真实操作者标识'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN actor_realm VARCHAR(16) NOT NULL COMMENT '发起撤权的身份域'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN context_json TEXT NOT NULL COMMENT '撤权时保存的身份与来源快照，不进入公开投影'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN requested_at BIGINT NOT NULL COMMENT '撤权意图登记时间，UTC Unix 秒'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN completed_at BIGINT NULL COMMENT 'Broker 完成隔离的时间，UTC Unix 秒；尚未完成时为空'",
            "ALTER TABLE center_authorization_invalidation MODIFY COLUMN node_id VARCHAR(64) NULL COMMENT '完成隔离的 Broker 节点标识'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN capacity INTEGER NOT NULL COMMENT '本批注册资格允许注册的设备总数'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN registered_count INTEGER NOT NULL DEFAULT 0 COMMENT '本批注册资格已使用的设备数量'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN register_expires_at BIGINT NOT NULL COMMENT '允许新设备注册的截止时间，UTC Unix 秒'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN enabled INTEGER NOT NULL DEFAULT 1 COMMENT '当前是否启用'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1 COMMENT '当前授权事实是否已完成恢复核对'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN secret_hash VARCHAR(64) NOT NULL COMMENT '注册码散列，原始码仅在签发或重置时交付'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN request_hash VARCHAR(64) NOT NULL COMMENT '创建请求内容摘要，用于拒绝幂等标识冲突'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_device_enrollment MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE center_device_enrollment_binding MODIFY COLUMN ownership_id VARCHAR(32) NOT NULL COMMENT '设备使用关系标识，也是本模型的稳定主键'",
            "ALTER TABLE center_device_enrollment_binding MODIFY COLUMN device_id VARCHAR(32) NOT NULL COMMENT '设备的稳定标识'",
            "ALTER TABLE center_device_enrollment_binding MODIFY COLUMN enrollment_id VARCHAR(32) NOT NULL COMMENT '注册资格标识'",
            "ALTER TABLE center_device_enrollment_binding MODIFY COLUMN registered_at BIGINT NOT NULL COMMENT '注册完成时间，UTC Unix 秒'",
            "ALTER TABLE center_device_enrollment_binding MODIFY COLUMN request_id VARCHAR(32) NULL COMMENT '幂等请求标识'",
            "ALTER TABLE center_device_enrollment_binding MODIFY COLUMN request_hash VARCHAR(64) NULL COMMENT '注册请求摘要'",
            "ALTER TABLE center_application MODIFY COLUMN id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE center_application MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE center_application MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE center_application MODIFY COLUMN enabled INTEGER NOT NULL DEFAULT 1 COMMENT '当前是否启用'",
            "ALTER TABLE center_application MODIFY COLUMN credential_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '当前接入凭据的稳定标识'",
            "ALTER TABLE center_application MODIFY COLUMN key_version INTEGER NOT NULL DEFAULT 1 COMMENT '接入应用密钥代次'",
            "ALTER TABLE center_application MODIFY COLUMN access_version INTEGER NOT NULL COMMENT '通信授权发布代次'",
            "ALTER TABLE center_application MODIFY COLUMN request_hash VARCHAR(64) NOT NULL COMMENT '创建请求内容摘要，用于拒绝幂等标识冲突'",
            "ALTER TABLE center_application MODIFY COLUMN access_started INTEGER NOT NULL DEFAULT 0 COMMENT '已发出有效认证结论，不表示连接在线；防止遗留持久会话被误判为从未使用'",
            "ALTER TABLE center_application MODIFY COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1 COMMENT '当前授权事实是否已完成恢复核对'",
            "ALTER TABLE center_application MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE center_application MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_application MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE center_application_grant MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE center_application_grant MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE center_application_grant MODIFY COLUMN application_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '取得通信授权的接入应用标识'",
            "ALTER TABLE center_application_grant MODIFY COLUMN device_id VARCHAR(32) NOT NULL COMMENT '设备的稳定标识'",
            "ALTER TABLE center_application_grant MODIFY COLUMN ownership_id VARCHAR(32) NOT NULL COMMENT '授权或操作绑定的设备使用关系'",
            "ALTER TABLE center_application_grant MODIFY COLUMN receive INTEGER NOT NULL COMMENT '是否允许接收设备上行消息'",
            "ALTER TABLE center_application_grant MODIFY COLUMN send INTEGER NOT NULL COMMENT '是否允许向设备发送下行消息'",
            "ALTER TABLE center_application_grant MODIFY COLUMN subscribe INTEGER NOT NULL COMMENT '是否允许订阅设备上行 Topic，须同时具有接收资格'",
            "ALTER TABLE center_application_grant MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE center_application_grant MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_application_grant MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE platform_setting MODIFY COLUMN id INTEGER NOT NULL COMMENT '配置槽位：1 为平台设置，2 为公共登录及客户初始模板'",
            "ALTER TABLE platform_setting MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE platform_setting MODIFY COLUMN official_url VARCHAR(512) NOT NULL COMMENT '公开展示的官方网站地址'",
            "ALTER TABLE platform_setting MODIFY COLUMN description VARCHAR(500) NOT NULL COMMENT '供管理人员阅读的说明'",
            "ALTER TABLE platform_setting MODIFY COLUMN logo_url VARCHAR(512) NOT NULL COMMENT '品牌标识资源地址，空值采用缺省品牌'",
            "ALTER TABLE platform_setting MODIFY COLUMN timezone VARCHAR(64) NOT NULL COMMENT '界面默认使用的 IANA 时区名称'",
            "ALTER TABLE platform_setting MODIFY COLUMN theme_mode VARCHAR(8) NOT NULL COMMENT '默认主题模式：light、dark 或 auto'",
            "ALTER TABLE platform_setting MODIFY COLUMN theme_color VARCHAR(16) NOT NULL COMMENT '默认主题主色，六位十六进制颜色'",
            "ALTER TABLE platform_setting MODIFY COLUMN theme_radius VARCHAR(8) NOT NULL COMMENT '默认组件圆角配置'",
            "ALTER TABLE platform_setting MODIFY COLUMN layout_mode VARCHAR(32) NOT NULL COMMENT '默认工作区布局模式'",
            "ALTER TABLE platform_setting MODIFY COLUMN sidebar_collapsed INTEGER NOT NULL DEFAULT 0 COMMENT '侧栏默认折叠状态'",
            "ALTER TABLE platform_setting MODIFY COLUMN navigation_style VARCHAR(16) NOT NULL COMMENT '默认导航外观'",
            "ALTER TABLE platform_setting MODIFY COLUMN navigation_split INTEGER NOT NULL DEFAULT 1 COMMENT '混合导航是否拆分显示'",
            "ALTER TABLE platform_setting MODIFY COLUMN breadcrumb_enable INTEGER NOT NULL DEFAULT 1 COMMENT '是否默认显示面包屑'",
            "ALTER TABLE platform_setting MODIFY COLUMN breadcrumb_show_icon INTEGER NOT NULL DEFAULT 1 COMMENT '面包屑是否默认显示图标'",
            "ALTER TABLE platform_setting MODIFY COLUMN breadcrumb_style VARCHAR(16) NOT NULL COMMENT '默认面包屑样式'",
            "ALTER TABLE platform_setting MODIFY COLUMN tabbar_enable INTEGER NOT NULL DEFAULT 0 COMMENT '是否默认启用标签页'",
            "ALTER TABLE platform_setting MODIFY COLUMN tabbar_style VARCHAR(16) NOT NULL COMMENT '默认标签页样式'",
            "ALTER TABLE platform_setting MODIFY COLUMN footer_enable INTEGER NOT NULL DEFAULT 0 COMMENT '是否默认显示页脚'",
            "ALTER TABLE platform_setting MODIFY COLUMN footer_fixed INTEGER NOT NULL DEFAULT 0 COMMENT '页脚是否默认固定'",
            "ALTER TABLE platform_setting MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE platform_setting MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE platform_setting MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE system_setting MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE system_setting MODIFY COLUMN id INTEGER NOT NULL DEFAULT 1 COMMENT '当前 Tenant 的设置槽位，固定为 1'",
            "ALTER TABLE system_setting MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE system_setting MODIFY COLUMN official_url VARCHAR(512) NOT NULL COMMENT '公开展示的官方网站地址'",
            "ALTER TABLE system_setting MODIFY COLUMN description VARCHAR(500) NOT NULL COMMENT '供管理人员阅读的说明'",
            "ALTER TABLE system_setting MODIFY COLUMN logo_url VARCHAR(512) NOT NULL COMMENT '品牌标识资源地址，空值采用缺省品牌'",
            "ALTER TABLE system_setting MODIFY COLUMN timezone VARCHAR(64) NOT NULL COMMENT '界面默认使用的 IANA 时区名称'",
            "ALTER TABLE system_setting MODIFY COLUMN theme_mode VARCHAR(8) NOT NULL COMMENT '默认主题模式：light、dark 或 auto'",
            "ALTER TABLE system_setting MODIFY COLUMN theme_color VARCHAR(16) NOT NULL COMMENT '默认主题主色，六位十六进制颜色'",
            "ALTER TABLE system_setting MODIFY COLUMN theme_radius VARCHAR(8) NOT NULL COMMENT '默认组件圆角配置'",
            "ALTER TABLE system_setting MODIFY COLUMN layout_mode VARCHAR(32) NOT NULL COMMENT '默认工作区布局模式'",
            "ALTER TABLE system_setting MODIFY COLUMN sidebar_collapsed INTEGER NOT NULL DEFAULT 0 COMMENT '侧栏默认折叠状态'",
            "ALTER TABLE system_setting MODIFY COLUMN navigation_style VARCHAR(16) NOT NULL COMMENT '默认导航外观'",
            "ALTER TABLE system_setting MODIFY COLUMN navigation_split INTEGER NOT NULL DEFAULT 1 COMMENT '混合导航是否拆分显示'",
            "ALTER TABLE system_setting MODIFY COLUMN breadcrumb_enable INTEGER NOT NULL DEFAULT 1 COMMENT '是否默认显示面包屑'",
            "ALTER TABLE system_setting MODIFY COLUMN breadcrumb_show_icon INTEGER NOT NULL DEFAULT 1 COMMENT '面包屑是否默认显示图标'",
            "ALTER TABLE system_setting MODIFY COLUMN breadcrumb_style VARCHAR(16) NOT NULL COMMENT '默认面包屑样式'",
            "ALTER TABLE system_setting MODIFY COLUMN tabbar_enable INTEGER NOT NULL DEFAULT 0 COMMENT '是否默认启用标签页'",
            "ALTER TABLE system_setting MODIFY COLUMN tabbar_style VARCHAR(16) NOT NULL COMMENT '默认标签页样式'",
            "ALTER TABLE system_setting MODIFY COLUMN footer_enable INTEGER NOT NULL DEFAULT 0 COMMENT '是否默认显示页脚'",
            "ALTER TABLE system_setting MODIFY COLUMN footer_fixed INTEGER NOT NULL DEFAULT 0 COMMENT '页脚是否默认固定'",
            "ALTER TABLE system_setting MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE system_setting MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE system_setting MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE platform_menu MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE platform_menu MODIFY COLUMN realm VARCHAR(16) NOT NULL COMMENT '菜单所属身份域'",
            "ALTER TABLE platform_menu MODIFY COLUMN parent_id VARCHAR(32) NULL COMMENT '上级节点标识；根节点为空'",
            "ALTER TABLE platform_menu MODIFY COLUMN kind VARCHAR(16) NOT NULL COMMENT '业务记录类型'",
            "ALTER TABLE platform_menu MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE platform_menu MODIFY COLUMN path VARCHAR(200) NOT NULL COMMENT '页面或目录的路由路径'",
            "ALTER TABLE platform_menu MODIFY COLUMN component VARCHAR(100) NOT NULL COMMENT '构建期登记的页面组件标识'",
            "ALTER TABLE platform_menu MODIFY COLUMN permission VARCHAR(120) NULL COMMENT '构建期登记的权限码；纯目录节点为空'",
            "ALTER TABLE platform_menu MODIFY COLUMN icon VARCHAR(100) NOT NULL COMMENT '菜单图标标识'",
            "ALTER TABLE platform_menu MODIFY COLUMN order_number INTEGER NOT NULL COMMENT '同级节点的显示顺序，数值较小者优先'",
            "ALTER TABLE platform_menu MODIFY COLUMN enabled INTEGER NOT NULL COMMENT '当前是否启用'",
            "ALTER TABLE platform_menu MODIFY COLUMN hidden INTEGER NOT NULL COMMENT '是否从导航中隐藏'",
            "ALTER TABLE platform_menu MODIFY COLUMN guarded INTEGER NOT NULL COMMENT '受保护的系统菜单节点标志'",
            "ALTER TABLE platform_menu MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE platform_menu MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE platform_menu MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE system_menu MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE system_menu MODIFY COLUMN realm VARCHAR(16) NOT NULL COMMENT '菜单所属身份域'",
            "ALTER TABLE system_menu MODIFY COLUMN parent_id VARCHAR(32) NULL COMMENT '上级节点标识；根节点为空'",
            "ALTER TABLE system_menu MODIFY COLUMN kind VARCHAR(16) NOT NULL COMMENT '业务记录类型'",
            "ALTER TABLE system_menu MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE system_menu MODIFY COLUMN path VARCHAR(200) NOT NULL COMMENT '页面或目录的路由路径'",
            "ALTER TABLE system_menu MODIFY COLUMN component VARCHAR(100) NOT NULL COMMENT '构建期登记的页面组件标识'",
            "ALTER TABLE system_menu MODIFY COLUMN permission VARCHAR(120) NULL COMMENT '构建期登记的权限码；纯目录节点为空'",
            "ALTER TABLE system_menu MODIFY COLUMN icon VARCHAR(100) NOT NULL COMMENT '菜单图标标识'",
            "ALTER TABLE system_menu MODIFY COLUMN order_number INTEGER NOT NULL COMMENT '同级节点的显示顺序，数值较小者优先'",
            "ALTER TABLE system_menu MODIFY COLUMN enabled INTEGER NOT NULL COMMENT '当前是否启用'",
            "ALTER TABLE system_menu MODIFY COLUMN hidden INTEGER NOT NULL COMMENT '是否从导航中隐藏'",
            "ALTER TABLE system_menu MODIFY COLUMN guarded INTEGER NOT NULL COMMENT '受保护的系统菜单节点标志'",
            "ALTER TABLE system_menu MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE system_menu MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE system_menu MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE platform_role MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE platform_role MODIFY COLUMN scope_id VARCHAR(32) NOT NULL COMMENT '平台作用域标识，固定为 platform'",
            "ALTER TABLE platform_role MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE platform_role MODIFY COLUMN enabled INTEGER NOT NULL DEFAULT 1 COMMENT '当前是否启用'",
            "ALTER TABLE platform_role MODIFY COLUMN protected INTEGER NOT NULL DEFAULT 0 COMMENT '受保护超级管理员角色标志'",
            "ALTER TABLE platform_role MODIFY COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1 COMMENT '当前授权事实是否已完成恢复核对'",
            "ALTER TABLE platform_role MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE platform_role MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE system_role MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE system_role MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE system_role MODIFY COLUMN name VARCHAR(100) NOT NULL COMMENT '业务显示名称'",
            "ALTER TABLE system_role MODIFY COLUMN enabled INTEGER NOT NULL DEFAULT 1 COMMENT '当前是否启用'",
            "ALTER TABLE system_role MODIFY COLUMN protected INTEGER NOT NULL DEFAULT 0 COMMENT '受保护超级管理员角色标志'",
            "ALTER TABLE system_role MODIFY COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1 COMMENT '当前授权事实是否已完成恢复核对'",
            "ALTER TABLE system_role MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE system_role MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE platform_session MODIFY COLUMN token_hash VARCHAR(64) NOT NULL COMMENT '会话令牌的 SHA-256 摘要，不保存原始令牌'",
            "ALTER TABLE platform_session MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE platform_session MODIFY COLUMN user_id VARCHAR(32) NOT NULL COMMENT '对应人员账号的稳定标识'",
            "ALTER TABLE platform_session MODIFY COLUMN expires_at BIGINT NOT NULL COMMENT '有效截止时间，UTC Unix 秒'",
            "ALTER TABLE platform_session MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE system_session MODIFY COLUMN token_hash VARCHAR(64) NOT NULL COMMENT '会话令牌的 SHA-256 摘要，不保存原始令牌'",
            "ALTER TABLE system_session MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE system_session MODIFY COLUMN tenant_id VARCHAR(32) NOT NULL COMMENT '所属 Tenant 标识，由可信执行上下文约束'",
            "ALTER TABLE system_session MODIFY COLUMN user_id VARCHAR(32) NOT NULL COMMENT '对应人员账号的稳定标识'",
            "ALTER TABLE system_session MODIFY COLUMN expires_at BIGINT NOT NULL COMMENT '有效截止时间，UTC Unix 秒'",
            "ALTER TABLE system_session MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE system_session MODIFY COLUMN actor_id VARCHAR(32) NULL COMMENT '真实平台操作者的账号标识'",
            "ALTER TABLE system_session MODIFY COLUMN source_session_id VARCHAR(32) NULL COMMENT '来源平台会话标识，用于撤销后续模拟访问'",
            "ALTER TABLE platform_handoff MODIFY COLUMN code_hash VARCHAR(64) NOT NULL COMMENT '一次性交接码摘要，不保存原始交接码'",
            "ALTER TABLE platform_handoff MODIFY COLUMN target_tenant_id VARCHAR(32) NOT NULL COMMENT '交接目标客户；平台记录由来源平台会话管理'",
            "ALTER TABLE platform_handoff MODIFY COLUMN user_id VARCHAR(32) NOT NULL COMMENT '对应人员账号的稳定标识'",
            "ALTER TABLE platform_handoff MODIFY COLUMN user_version INTEGER NOT NULL COMMENT '签发时目标账号的资料版本'",
            "ALTER TABLE platform_handoff MODIFY COLUMN tenant_version INTEGER NOT NULL COMMENT '签发时目标客户的资料版本'",
            "ALTER TABLE platform_handoff MODIFY COLUMN actor_id VARCHAR(32) NOT NULL COMMENT '真实平台操作者的账号标识'",
            "ALTER TABLE platform_handoff MODIFY COLUMN source_session_id VARCHAR(32) NOT NULL COMMENT '来源平台会话标识，用于撤销后续模拟访问'",
            "ALTER TABLE platform_handoff MODIFY COLUMN target_origin VARCHAR(300) NOT NULL COMMENT '交接绑定的目标入口地址'",
            "ALTER TABLE platform_handoff MODIFY COLUMN expires_at BIGINT NOT NULL COMMENT '有效截止时间，UTC Unix 秒'",
            "ALTER TABLE platform_handoff MODIFY COLUMN consumed_at BIGINT NULL COMMENT '交接码消费时间，UTC Unix 秒；空值表示尚未消费'",
            "ALTER TABLE platform_handoff MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN id VARCHAR(32) NOT NULL COMMENT '记录的稳定标识'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN device_id VARCHAR(32) NOT NULL COMMENT '设备的稳定标识'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN device_name VARCHAR(100) NOT NULL COMMENT '发起流程时保留的设备名称'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN source_tenant_id VARCHAR(32) NOT NULL COMMENT '流程来源 Tenant 标识'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN target_tenant_id VARCHAR(32) NULL COMMENT '本次操作的目标 Tenant 标识'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN ownership_id VARCHAR(32) NOT NULL COMMENT '授权或操作绑定的设备使用关系'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN new_ownership_id VARCHAR(32) NULL COMMENT '目标客户确认后建立的新使用关系标识'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN kind VARCHAR(16) NOT NULL COMMENT '业务记录类型'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN status VARCHAR(24) NOT NULL COMMENT '流程的实际处理阶段'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN request_version INTEGER NOT NULL COMMENT '发起流程时核对的预期版本'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN source_context TEXT NOT NULL COMMENT '发起操作的身份与授权快照'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN requires_store INTEGER NOT NULL COMMENT '是否必须取得持久存储隔离证明'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN isolation_id VARCHAR(32) NULL COMMENT '关联的通信隔离操作标识'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN access_version INTEGER NULL COMMENT '通信授权发布代次'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN retained_json TEXT NOT NULL COMMENT '旧归属阶段保留消息核验结果的 JSON'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN accept_id VARCHAR(32) NULL COMMENT '目标接收请求的幂等标识'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN accept_version INTEGER NULL COMMENT '接收请求核对的预期版本'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN accept_context TEXT NULL COMMENT '目标客户接收操作的身份快照'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN version INTEGER NOT NULL DEFAULT 1 COMMENT '资料并发版本'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN created_at BIGINT NOT NULL COMMENT '创建时间，UTC Unix 秒'",
            "ALTER TABLE center_device_reassignment MODIFY COLUMN updated_at BIGINT NOT NULL COMMENT '最后更新时间，UTC Unix 秒'",
            "ALTER TABLE center_registration_attempt MODIFY COLUMN source_hash VARCHAR(64) NOT NULL COMMENT '已规范化客户端来源的 SHA-256 摘要'",
            "ALTER TABLE center_registration_attempt MODIFY COLUMN window_started BIGINT NOT NULL COMMENT '当前限流窗口开始时间，UTC Unix 秒'",
            "ALTER TABLE center_registration_attempt MODIFY COLUMN attempts INTEGER NOT NULL COMMENT '当前来源在窗口内的尝试次数'",
        ];
    }
}
