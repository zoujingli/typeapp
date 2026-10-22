<?php

declare(strict_types=1);

namespace app\iot\database;

use Type\Orm\Migration\Migration;

/** 追加使用期限与可恢复转配账本；既有关系的通信历史按未知处理，不推断从未接入。 */
final class OwnershipSchema
{
    /** @return list<Migration> 新敏感动作只补给受保护管理员，保留旧转移和归属原件。 */
    public static function migrations(string $driver): array
    {
        $statements = [
            'ALTER TABLE iot_device_ownerships ADD COLUMN expires_at BIGINT NULL',
            'ALTER TABLE iot_device_ownerships ADD COLUMN expiry_context TEXT NULL',
            'ALTER TABLE iot_device_ownerships ADD COLUMN access_started INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE iot_device_ownerships ADD COLUMN version INTEGER NOT NULL DEFAULT 1',
            'CREATE INDEX iot_ownership_expiry ON iot_device_ownerships (ended_at, expires_at, id)',
            'CREATE TABLE iot_device_reassignments (id VARCHAR(32) NOT NULL PRIMARY KEY, device_id VARCHAR(32) NOT NULL, device_name VARCHAR(100) NOT NULL, source_tenant_id VARCHAR(32) NOT NULL, target_tenant_id VARCHAR(32) NULL, ownership_id VARCHAR(32) NOT NULL, new_ownership_id VARCHAR(32) NULL, kind VARCHAR(16) NOT NULL, status VARCHAR(24) NOT NULL, request_version INTEGER NOT NULL, source_context TEXT NOT NULL, requires_store INTEGER NOT NULL, isolation_id VARCHAR(32) NULL, access_version INTEGER NULL, retained_json TEXT NOT NULL, accept_id VARCHAR(32) NULL, accept_context TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id), FOREIGN KEY (source_tenant_id) REFERENCES iot_tenants(id), FOREIGN KEY (target_tenant_id) REFERENCES iot_tenants(id), FOREIGN KEY (ownership_id) REFERENCES iot_device_ownerships(id), FOREIGN KEY (new_ownership_id) REFERENCES iot_device_ownerships(id))' . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''),
            'ALTER TABLE iot_device_reassignments ADD COLUMN accept_version INTEGER NULL',
            'CREATE INDEX iot_reassignments_source ON iot_device_reassignments (source_tenant_id, created_at, id)',
            'CREATE INDEX iot_reassignments_target ON iot_device_reassignments (target_tenant_id, created_at, id)',
        ];
        foreach (['admin' => ['devices.expiry', 'devices.end', 'reassignments.read', 'reassignments.create', 'reassignments.advance'],
            'customer' => ['reassignments.read', 'reassignments.accept']] as $realm => $actions) {
            foreach ($actions as $action) {
                $permission = $realm . '.' . $action;
                $statements[] = "INSERT INTO {$realm}_role_permissions (role_id, permission) SELECT r.id, '{$permission}' FROM {$realm}_roles r WHERE r.protected = 1 AND NOT EXISTS (SELECT 1 FROM {$realm}_role_permissions p WHERE p.role_id = r.id AND p.permission = '{$permission}')";
            }
            $statements[] = "UPDATE {$realm}_roles SET version = version + 1 WHERE protected = 1";
        }
        return [new Migration('106_iot_device_ownership_lifecycle', '分离使用期限并建立设备撤权转配账本', $statements, $driver !== 'mysql')];
    }
}
