<?php

declare(strict_types=1);

namespace app\iot\database;

use Type\Orm\Migration\Migration;

/** 仅追加旧设备转换进度和原件身份，不改写已发布结构或删除历史业务数据。 */
final class UpgradeSchema
{
    /** @return list<Migration> MySQL DDL 明确非事务；数据转换另在迁移锁内分批提交。 */
    public static function migrations(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [new Migration('107_iot_raw_device_upgrade', '保全旧安装与设备转换来源', [
            'CREATE TABLE iot_architecture_upgrade (id INTEGER NOT NULL PRIMARY KEY CHECK (id = 1), installation_id VARCHAR(32) NOT NULL, state VARCHAR(16) NOT NULL, backup_sha256 VARCHAR(64) NOT NULL, source_json TEXT NOT NULL, preserved_json TEXT NOT NULL, last_device_id VARCHAR(32) NOT NULL, started_at BIGINT NOT NULL, completed_at BIGINT NULL)' . $suffix,
            'CREATE TABLE iot_device_upgrade_records (device_id VARCHAR(32) NOT NULL PRIMARY KEY, installation_id VARCHAR(32) NOT NULL, source_json TEXT NOT NULL, credential_sha256 VARCHAR(64) NOT NULL, ownership_id VARCHAR(32) NOT NULL, decision VARCHAR(16) NOT NULL, reasons_json TEXT NOT NULL, converted_at BIGINT NOT NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id))' . $suffix,
            'CREATE INDEX iot_device_upgrade_decision ON iot_device_upgrade_records (decision, device_id)',
        ], $driver !== 'mysql')];
    }
}
