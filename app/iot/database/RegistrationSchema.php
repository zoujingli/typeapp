<?php

declare(strict_types=1);

namespace app\iot\database;

use Type\Orm\Migration\Migration;

/** 解耦设备身份与物模型；旧结构、数据和历史迁移保持原身份。 */
final class RegistrationSchema
{
    /**
     * SQLite 明确重建原表并恢复原索引；被引用的设备 ID 保持不变。
     * 原表没有删除级联或触发器，完整引用在恢复即时外键检查前核对。
     * @return list<Migration>
     */
    public static function migrations(string $driver): array
    {
        $statements = match ($driver) {
            'mysql' => [
                'ALTER TABLE iot_devices MODIFY product_id VARCHAR(32) NULL, MODIFY model_version INTEGER NULL',
                'ALTER TABLE iot_devices ADD CONSTRAINT iot_devices_tenant_fk FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id)',
                'ALTER TABLE iot_devices ADD CONSTRAINT iot_devices_model_pair CHECK ((product_id IS NULL AND model_version IS NULL) OR (product_id IS NOT NULL AND model_version IS NOT NULL))',
            ],
            'pgsql' => [
                'ALTER TABLE iot_devices ALTER COLUMN product_id DROP NOT NULL, ALTER COLUMN model_version DROP NOT NULL',
                'ALTER TABLE iot_devices ADD CONSTRAINT iot_devices_tenant_fk FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id)',
                'ALTER TABLE iot_devices ADD CONSTRAINT iot_devices_model_pair CHECK ((product_id IS NULL AND model_version IS NULL) OR (product_id IS NOT NULL AND model_version IS NOT NULL))',
            ],
            default => [
                'PRAGMA defer_foreign_keys = ON',
                "CREATE TABLE iot_devices_registration (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NULL, model_version INTEGER NULL, ownership_id VARCHAR(32) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, lifecycle VARCHAR(20) NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, model_switch_id VARCHAR(32) NULL, model_start_sequence VARCHAR(38) NOT NULL DEFAULT '0', transfer_id VARCHAR(32) NULL, transfer_frozen INTEGER NOT NULL DEFAULT 0, recovery_verified INTEGER NOT NULL DEFAULT 1, UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id), FOREIGN KEY (tenant_id, product_id, model_version) REFERENCES iot_models(tenant_id, product_id, model_version), CHECK ((product_id IS NULL AND model_version IS NULL) OR (product_id IS NOT NULL AND model_version IS NOT NULL)))",
                'INSERT INTO iot_devices_registration (id, tenant_id, product_id, model_version, ownership_id, name, lifecycle, version, created_at, updated_at, model_switch_id, model_start_sequence, transfer_id, transfer_frozen, recovery_verified) SELECT id, tenant_id, product_id, model_version, ownership_id, name, lifecycle, version, created_at, updated_at, model_switch_id, model_start_sequence, transfer_id, transfer_frozen, recovery_verified FROM iot_devices',
                'DROP TABLE iot_devices',
                'ALTER TABLE iot_devices_registration RENAME TO iot_devices',
                'CREATE INDEX iot_devices_tenant_time ON iot_devices (tenant_id, created_at, id)',
                'CREATE INDEX iot_devices_global_time ON iot_devices (created_at, id)',
                'CREATE INDEX iot_devices_product ON iot_devices (tenant_id, product_id, model_version)',
                'PRAGMA defer_foreign_keys = OFF',
            ],
        };
        $statements = [...$statements,
            'ALTER TABLE iot_device_enrollment_bindings ADD COLUMN request_id VARCHAR(32) NULL',
            'ALTER TABLE iot_device_enrollment_bindings ADD COLUMN request_hash VARCHAR(64) NULL',
            'CREATE UNIQUE INDEX iot_enrollment_request ON iot_device_enrollment_bindings (request_id)',
            'CREATE TABLE iot_registration_attempts (source_hash VARCHAR(64) NOT NULL PRIMARY KEY, window_started BIGINT NOT NULL, attempts INTEGER NOT NULL, CHECK (attempts >= 1 AND attempts <= 60))' . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''),
            'CREATE INDEX iot_registration_window ON iot_registration_attempts (window_started)',
        ];
        return [new Migration('103_iot_device_registration', '允许无物模型设备消费注册资格并保留幂等身份', $statements, $driver !== 'mysql')];
    }
}
