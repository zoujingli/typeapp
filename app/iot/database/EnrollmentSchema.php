<?php

declare(strict_types=1);

namespace app\iot\database;

use Type\Orm\Migration\Migration;

/** 平台注册资格的追加迁移；不修改原设备及身份迁移的声明或摘要。 */
final class EnrollmentSchema
{
    /**
     * 授权码只能增加未占用资格的注册证明，绑定记录与计数不能由重置操作删除。
     * 仅受保护的最高管理员获得新增目录项，普通角色保持原来的显式授权。
     * @return list<Migration>
     */
    public static function migrations(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        $statements = [
            'CREATE TABLE iot_device_enrollments (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, name VARCHAR(100) NOT NULL, capacity INTEGER NOT NULL, registered_count INTEGER NOT NULL DEFAULT 0, register_expires_at BIGINT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, secret_hash VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, CHECK (capacity > 0 AND registered_count >= 0 AND registered_count <= capacity), FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id))' . $suffix,
            'CREATE INDEX iot_enrollments_tenant_time ON iot_device_enrollments (tenant_id, created_at, id)',
            'CREATE TABLE iot_device_enrollment_bindings (ownership_id VARCHAR(32) NOT NULL PRIMARY KEY, device_id VARCHAR(32) NOT NULL, enrollment_id VARCHAR(32) NOT NULL, registered_at BIGINT NOT NULL, UNIQUE (enrollment_id, device_id), FOREIGN KEY (enrollment_id) REFERENCES iot_device_enrollments(id), FOREIGN KEY (ownership_id) REFERENCES iot_device_ownerships(id), FOREIGN KEY (device_id) REFERENCES iot_devices(id))' . $suffix,
            'CREATE INDEX iot_enrollment_bindings_grant ON iot_device_enrollment_bindings (enrollment_id, registered_at, device_id)',
        ];
        foreach (['admin' => ['read', 'issue', 'disable', 'reset'], 'customer' => ['read']] as $realm => $actions) {
            foreach ($actions as $action) {
                $permission = $realm . '.enrollments.' . $action;
                $statements[] = "INSERT INTO {$realm}_role_permissions (role_id, permission) SELECT r.id, '{$permission}' FROM {$realm}_roles r WHERE r.protected = 1 AND NOT EXISTS (SELECT 1 FROM {$realm}_role_permissions p WHERE p.role_id = r.id AND p.permission = '{$permission}')";
            }
            $statements[] = "UPDATE {$realm}_roles SET version = version + 1 WHERE protected = 1";
        }
        return [new Migration('102_iot_device_enrollment', '增加平台签发的设备注册资格与绑定记录', $statements, $driver !== 'mysql')];
    }
}
