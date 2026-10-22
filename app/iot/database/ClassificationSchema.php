<?php

declare(strict_types=1);

namespace app\iot\database;

use Type\Orm\Migration\Migration;

/** 分类成为独立资料关系；追加迁移，保留旧物模型引用及其历史迁移身份。 */
final class ClassificationSchema
{
    /**
     * 原始设备可有分类而无物模型；外键继续拒绝跨客户及不存在的分类。
     * SQLite 沿用受限表重建，复制全部设备字段并复核被引用身份。
     * @return list<Migration>
     */
    public static function migrations(string $driver): array
    {
        $statements = match ($driver) {
            'mysql' => [
                'ALTER TABLE iot_devices DROP CHECK iot_devices_model_pair',
                'ALTER TABLE iot_devices ADD CONSTRAINT iot_devices_model_pair CHECK (model_version IS NULL OR product_id IS NOT NULL)',
                'ALTER TABLE iot_devices ADD CONSTRAINT iot_devices_product_fk FOREIGN KEY (tenant_id, product_id) REFERENCES iot_products(tenant_id, id)',
            ],
            'pgsql' => [
                'ALTER TABLE iot_devices DROP CONSTRAINT iot_devices_model_pair',
                'ALTER TABLE iot_devices ADD CONSTRAINT iot_devices_model_pair CHECK (model_version IS NULL OR product_id IS NOT NULL)',
                'ALTER TABLE iot_devices ADD CONSTRAINT iot_devices_product_fk FOREIGN KEY (tenant_id, product_id) REFERENCES iot_products(tenant_id, id)',
            ],
            default => [
                'PRAGMA defer_foreign_keys = ON',
                "CREATE TABLE iot_devices_classification (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NULL, model_version INTEGER NULL, ownership_id VARCHAR(32) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, lifecycle VARCHAR(20) NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, model_switch_id VARCHAR(32) NULL, model_start_sequence VARCHAR(38) NOT NULL DEFAULT '0', transfer_id VARCHAR(32) NULL, transfer_frozen INTEGER NOT NULL DEFAULT 0, recovery_verified INTEGER NOT NULL DEFAULT 1, UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id), FOREIGN KEY (tenant_id, product_id) REFERENCES iot_products(tenant_id, id), FOREIGN KEY (tenant_id, product_id, model_version) REFERENCES iot_models(tenant_id, product_id, model_version), CHECK (model_version IS NULL OR product_id IS NOT NULL))",
                'INSERT INTO iot_devices_classification (id, tenant_id, product_id, model_version, ownership_id, name, lifecycle, version, created_at, updated_at, model_switch_id, model_start_sequence, transfer_id, transfer_frozen, recovery_verified) SELECT id, tenant_id, product_id, model_version, ownership_id, name, lifecycle, version, created_at, updated_at, model_switch_id, model_start_sequence, transfer_id, transfer_frozen, recovery_verified FROM iot_devices',
                'DROP TABLE iot_devices',
                'ALTER TABLE iot_devices_classification RENAME TO iot_devices',
                'CREATE INDEX iot_devices_tenant_time ON iot_devices (tenant_id, created_at, id)',
                'CREATE INDEX iot_devices_global_time ON iot_devices (created_at, id)',
                'CREATE INDEX iot_devices_product ON iot_devices (tenant_id, product_id, model_version)',
                'PRAGMA defer_foreign_keys = OFF',
            ],
        };
        return [new Migration('108_iot_device_classification', '允许原始通信设备关联同客户分类', $statements, $driver !== 'mysql')];
    }
}
