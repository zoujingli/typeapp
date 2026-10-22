<?php

declare(strict_types=1);

namespace app\iot\database;

use Type\Orm\Migration\Migration;

/** 接入应用归属与独立凭据代次；秘密继续由既有 Broker 凭据账本持有。 */
final class ApplicationSchema
{
    /** @return list<Migration> 仅 protected 最高管理员追加新权限，普通角色不隐式增权。 */
    public static function migrations(string $driver): array
    {
        // Broker 标识明确使用二进制排序；租户标识沿用应用库排序，外键两端必须一致。
        $brokerId = $driver === 'mysql' ? 'VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : 'VARCHAR(32)';
        $statements = [
            'CREATE TABLE iot_applications (id ' . $brokerId . ' NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, name VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, credential_id ' . $brokerId . ' NOT NULL UNIQUE, key_version INTEGER NOT NULL DEFAULT 1, access_version INTEGER NOT NULL, request_hash VARCHAR(64) NOT NULL, access_started INTEGER NOT NULL DEFAULT 0, recovery_verified INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id), FOREIGN KEY (id) REFERENCES broker_access_principals(id), FOREIGN KEY (credential_id) REFERENCES broker_access_credentials(id))' . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''),
            'CREATE INDEX iot_applications_tenant_time ON iot_applications (tenant_id, created_at, id)',
        ];
        foreach (['admin', 'customer'] as $realm) {
            foreach (['read', 'create', 'update', 'enable', 'disable', 'keys'] as $action) {
                $permission = $realm . '.applications.' . $action;
                $statements[] = "INSERT INTO {$realm}_role_permissions (role_id, permission) SELECT r.id, '{$permission}' FROM {$realm}_roles r WHERE r.protected = 1 AND NOT EXISTS (SELECT 1 FROM {$realm}_role_permissions p WHERE p.role_id = r.id AND p.permission = '{$permission}')";
            }
            $statements[] = "UPDATE {$realm}_roles SET version = version + 1 WHERE protected = 1";
        }
        $grants = [
            'CREATE TABLE iot_application_grants (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, application_id ' . $brokerId . ' NOT NULL, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, receive INTEGER NOT NULL, send INTEGER NOT NULL, subscribe INTEGER NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE (application_id, ownership_id), FOREIGN KEY (tenant_id, application_id) REFERENCES iot_applications(tenant_id, id), FOREIGN KEY (device_id) REFERENCES iot_devices(id), FOREIGN KEY (ownership_id) REFERENCES iot_device_ownerships(id))' . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''),
            'CREATE INDEX iot_application_grants_scope ON iot_application_grants (tenant_id, application_id, created_at, id)',
            'CREATE INDEX iot_application_grants_device ON iot_application_grants (device_id, ownership_id)',
        ];
        foreach (['admin', 'customer'] as $realm) {
            $permission = $realm . '.applications.authorize';
            $grants[] = "INSERT INTO {$realm}_role_permissions (role_id, permission) SELECT r.id, '{$permission}' FROM {$realm}_roles r WHERE r.protected = 1 AND NOT EXISTS (SELECT 1 FROM {$realm}_role_permissions p WHERE p.role_id = r.id AND p.permission = '{$permission}')";
            $grants[] = "UPDATE {$realm}_roles SET version = version + 1 WHERE protected = 1";
        }
        return [new Migration('104_iot_client_applications', '建立客户接入应用及独立密钥生命周期', $statements, $driver !== 'mysql'),
            new Migration('105_iot_application_device_grants', '按设备当前使用关系授予应用通信权限', $grants, $driver !== 'mysql')];
    }
}
