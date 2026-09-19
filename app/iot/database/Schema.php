<?php

declare(strict_types=1);

namespace app\iot\database;

/** 物联网业务事实声明；身份、租户成员、角色与会话由 app\common\database\Schema 统一拥有。 */
final class Schema
{
    /** @return list<string> 产品与不可变模型的唯一表声明，由新安装及尚未迁移的业务装置复用。 */
    public static function products(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_products (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, name VARCHAR(100) NOT NULL, description TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, next_model_version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id))' . $suffix,
            'CREATE INDEX iot_products_tenant_time ON iot_products (tenant_id, created_at, id)',
            'CREATE TABLE iot_models (tenant_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, version INTEGER NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL, definition TEXT NOT NULL, created_at BIGINT NOT NULL, published_at BIGINT NULL, PRIMARY KEY (tenant_id, product_id, model_version), FOREIGN KEY (tenant_id, product_id) REFERENCES iot_products(tenant_id, id))' . $suffix,
        ];
    }

    /** @return list<string> 设备资产、凭据代次与接入观察的唯一声明；新安装直接采用最终结构。 */
    public static function devices(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            "CREATE TABLE iot_devices (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, ownership_id VARCHAR(32) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, lifecycle VARCHAR(20) NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, model_switch_id VARCHAR(32) NULL, model_start_sequence VARCHAR(38) NOT NULL DEFAULT '0', transfer_id VARCHAR(32) NULL, transfer_frozen INTEGER NOT NULL DEFAULT 0, recovery_verified INTEGER NOT NULL DEFAULT 1, UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id, product_id, model_version) REFERENCES iot_models(tenant_id, product_id, model_version))" . $suffix,
            'CREATE INDEX iot_devices_tenant_time ON iot_devices (tenant_id, created_at, id)',
            'CREATE INDEX iot_devices_global_time ON iot_devices (created_at, id)',
            'CREATE INDEX iot_devices_product ON iot_devices (tenant_id, product_id, model_version)',
            'CREATE TABLE iot_device_credentials (id VARCHAR(32) NOT NULL PRIMARY KEY, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, secret_hash VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, recovery_verified INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, revoked_at BIGINT NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id))' . $suffix,
            'CREATE INDEX iot_device_credentials_device ON iot_device_credentials (device_id, status)',
            'CREATE TABLE iot_device_ownerships (id VARCHAR(32) NOT NULL PRIMARY KEY, device_id VARCHAR(32) NOT NULL, tenant_id VARCHAR(32) NOT NULL, started_at BIGINT NOT NULL, ended_at BIGINT NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id), FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id))' . $suffix,
            'CREATE INDEX iot_ownership_history ON iot_device_ownerships (tenant_id, device_id, started_at)',
            'CREATE TABLE iot_broker_observations (node_id VARCHAR(64) NOT NULL PRIMARY KEY, run_id VARCHAR(32) NOT NULL, observed_at BIGINT NOT NULL, expires_at BIGINT NOT NULL)' . $suffix,
            'CREATE TABLE iot_device_connections (device_id VARCHAR(32) NOT NULL PRIMARY KEY, ownership_id VARCHAR(32) NOT NULL, owner_id VARCHAR(32) NULL, node_id VARCHAR(64) NULL, run_id VARCHAR(32) NULL, status VARCHAR(20) NOT NULL, observed_at BIGINT NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id))' . $suffix,
            'CREATE TABLE iot_authorization_invalidations (device_id VARCHAR(32) NOT NULL PRIMARY KEY, id VARCHAR(32) NOT NULL UNIQUE, principal VARCHAR(65) NOT NULL, actor_id VARCHAR(32) NOT NULL, tenant_id VARCHAR(32) NOT NULL, actor_realm VARCHAR(16) NOT NULL, context_json TEXT NOT NULL, requested_at BIGINT NOT NULL, completed_at BIGINT NULL, node_id VARCHAR(64) NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id))' . $suffix,
            'CREATE INDEX iot_authorization_pending ON iot_authorization_invalidations (completed_at, requested_at, id)',
        ];
    }

    /** @return list<string> Broker和接收角色共用的有界运行采样，不依赖遥测表。 */
    public static function metrics(string $driver): array
    {
        return ['CREATE TABLE iot_runtime_metrics (slot INTEGER NOT NULL PRIMARY KEY CHECK (slot >= 0 AND slot < 64), kind VARCHAR(16) NOT NULL, node_id VARCHAR(64) NOT NULL, run_id VARCHAR(32) NOT NULL, observed_at BIGINT NOT NULL, metrics_json TEXT NOT NULL, previous_json TEXT NULL, UNIQUE (kind, node_id))' . ($driver === 'mysql' ? ' ENGINE=InnoDB' : '')];
    }

    /** @return list<string> 现有恢复启动门禁与持久进度；表存在不代表新版授权恢复流程已验收。 */
    public static function recovery(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_recovery (id VARCHAR(32) NOT NULL PRIMARY KEY, slot INTEGER NULL UNIQUE, actor VARCHAR(32) NOT NULL, proof VARCHAR(200) NOT NULL, state VARCHAR(20) NOT NULL, kind_index INTEGER NOT NULL, ' . ($driver === 'mysql' ? '`cursor`' : 'cursor') . ' VARCHAR(32) NOT NULL, started_at BIGINT NOT NULL, reviewed_at BIGINT NULL, completed_at BIGINT NULL, review_sha256 VARCHAR(64) NOT NULL)' . $suffix,
            'CREATE TABLE iot_recovery_subjects (recovery_id VARCHAR(32) NOT NULL, kind VARCHAR(20) NOT NULL, subject_id VARCHAR(32) NOT NULL, sha256 VARCHAR(64) NOT NULL, approved INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (recovery_id, kind, subject_id), FOREIGN KEY (recovery_id) REFERENCES iot_recovery(id))' . $suffix,
        ];
    }

    /** @return list<string> 可靠接收账本、原始事实与当前投影的唯一表声明。 */
    public static function ingestion(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_ingestion (message_id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, sequence VARCHAR(38) NOT NULL, content_hash VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, code VARCHAR(64) NOT NULL, received_at BIGINT NOT NULL, receipt_proof_nonce VARCHAR(32) NOT NULL, receipt_requested_at BIGINT NOT NULL, UNIQUE (device_id, ownership_id, sequence))' . $suffix,
            'CREATE INDEX iot_ingestion_device_time ON iot_ingestion (tenant_id, device_id, received_at, message_id)',
            'CREATE INDEX iot_ingestion_expiry ON iot_ingestion (received_at, message_id)',
            'CREATE TABLE iot_ingestion_facts (message_id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, sequence VARCHAR(38) NOT NULL, type VARCHAR(20) NOT NULL, identifier VARCHAR(64) NOT NULL, sampled_at BIGINT NOT NULL, received_at BIGINT NOT NULL, values_json TEXT NOT NULL, current_advanced INTEGER NOT NULL, FOREIGN KEY (message_id) REFERENCES iot_ingestion(message_id))' . $suffix,
            'CREATE INDEX iot_ingestion_facts_device_time ON iot_ingestion_facts (tenant_id, device_id, sampled_at, message_id)',
            'CREATE INDEX iot_ingestion_facts_received ON iot_ingestion_facts (received_at, message_id)',
            'CREATE TABLE iot_current_data (device_id VARCHAR(32) NOT NULL PRIMARY KEY, ownership_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, sequence VARCHAR(38) NOT NULL, sampled_at BIGINT NOT NULL, received_at BIGINT NOT NULL, fields_json TEXT NOT NULL, realtime_sequence VARCHAR(38) NULL, realtime_sampled_at BIGINT NULL, realtime_received_at BIGINT NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id))' . $suffix,
            'CREATE TABLE iot_ingestion_completed (message_id VARCHAR(64) NOT NULL, consumer VARCHAR(64) NOT NULL, completed_at BIGINT NOT NULL, PRIMARY KEY (message_id, consumer), FOREIGN KEY (message_id) REFERENCES iot_ingestion_facts(message_id))' . $suffix,
            'CREATE INDEX iot_history_scope_time ON iot_ingestion_facts (tenant_id, device_id, product_id, model_version, ownership_id, sampled_at, message_id)',
            'CREATE INDEX iot_history_received ON iot_ingestion_facts (tenant_id, device_id, received_at, message_id)',
            'CREATE TABLE iot_device_status (device_id VARCHAR(32) NOT NULL PRIMARY KEY, ownership_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, sequence VARCHAR(38) NOT NULL, sampled_at BIGINT NOT NULL, received_at BIGINT NOT NULL, values_json TEXT NOT NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id))' . $suffix,
            'CREATE INDEX iot_ingestion_tenant_time ON iot_ingestion (tenant_id, received_at)',
        ];
    }

    /** @return list<string> 按原模型及归属阶段保存分钟统计，保留九十天语义。 */
    public static function aggregates(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_minute_aggregates (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, device_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, ownership_id VARCHAR(32) NOT NULL, window_start BIGINT NOT NULL, window_end BIGINT NOT NULL, fields_json TEXT NOT NULL, updated_at BIGINT NOT NULL, FOREIGN KEY (tenant_id, product_id, model_version) REFERENCES iot_models(tenant_id, product_id, model_version))' . $suffix,
            'CREATE INDEX iot_minute_device_time ON iot_minute_aggregates (tenant_id, device_id, window_start, id)',
            'CREATE INDEX iot_minute_scope_time ON iot_minute_aggregates (tenant_id, device_id, product_id, model_version, ownership_id, window_start)',
            'CREATE INDEX iot_minute_expiry ON iot_minute_aggregates (window_end, id)',
        ];
    }

    /** @return list<string> 指令受理、有限对账与未决效果的唯一声明；来源采用双端身份。 */
    public static function commands(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_commands (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, identifier VARCHAR(64) NOT NULL, payload TEXT NOT NULL, content_hash VARCHAR(64) NOT NULL, actor_id VARCHAR(32) NOT NULL, accepted_at BIGINT NOT NULL, deadline_at BIGINT NOT NULL, dispatch_at BIGINT NULL, mqtt_at BIGINT NULL, mqtt_reason INTEGER NULL, device_received_at BIGINT NULL, result_status VARCHAR(20) NULL, result_code VARCHAR(64) NULL, started_at BIGINT NULL, finished_at BIGINT NULL, result_json TEXT NULL, result_hash VARCHAR(64) NULL, result_received_at BIGINT NULL, receipt_nonce VARCHAR(32) NULL, source_context TEXT NOT NULL, request_version INTEGER NOT NULL, schedule_stage INTEGER NOT NULL DEFAULT 0, next_action_at BIGINT NULL, manual_query_id VARCHAR(32) NULL, last_query_code VARCHAR(64) NULL, cancelled_at BIGINT NULL, cancelled_by VARCHAR(32) NULL, cancel_id VARCHAR(32) NULL UNIQUE, cancel_context TEXT NULL, dispatch_stopped_at BIGINT NULL, dispatch_stop_reason VARCHAR(64) NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id))' . $suffix,
            'CREATE INDEX iot_commands_device_time ON iot_commands (tenant_id, device_id, accepted_at, id)',
            'CREATE INDEX iot_commands_pending ON iot_commands (dispatch_at, deadline_at, accepted_at, id)',
            'CREATE INDEX iot_commands_schedule ON iot_commands (next_action_at, id)',
            'CREATE INDEX iot_commands_manual ON iot_commands (manual_query_id, id)',
            'CREATE INDEX iot_commands_expiry ON iot_commands (accepted_at, id)',
            'CREATE TABLE iot_command_attempts (id VARCHAR(32) NOT NULL PRIMARY KEY, command_id VARCHAR(32) NOT NULL, kind VARCHAR(10) NOT NULL, trigger_kind VARCHAR(10) NOT NULL, actor_id VARCHAR(32) NOT NULL, source_context TEXT NOT NULL, scheduled_at BIGINT NOT NULL, claimed_at BIGINT NULL, transport_at BIGINT NULL, mqtt_reason INTEGER NULL, state VARCHAR(32) NOT NULL, response_at BIGINT NULL, response_code VARCHAR(64) NULL, FOREIGN KEY (command_id) REFERENCES iot_commands(id))' . $suffix,
            'CREATE INDEX iot_command_attempts_command ON iot_command_attempts (command_id, scheduled_at, id)',
            'CREATE TABLE iot_command_uncertainties (command_id VARCHAR(32) NOT NULL PRIMARY KEY, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL)' . $suffix,
            'CREATE INDEX iot_command_uncertainties_device ON iot_command_uncertainties (device_id, ownership_id)',
            'CREATE INDEX iot_commands_transfer ON iot_commands (device_id, ownership_id, result_status)',
        ];
    }

    /** @return list<string> 复用设备确认的模型切换与原序号边界。 */
    public static function modelSwitches(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_model_switches (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NOT NULL, source_version INTEGER NOT NULL, target_version INTEGER NOT NULL, source_start VARCHAR(38) NOT NULL, structure_hash VARCHAR(64) NOT NULL, same_structure INTEGER NOT NULL, actor_id VARCHAR(32) NOT NULL, request_version INTEGER NOT NULL, retry_version INTEGER NULL, source_context TEXT NOT NULL, dispatch_context TEXT NOT NULL, created_at BIGINT NOT NULL, next_attempt_at BIGINT NULL, attempt_count INTEGER NOT NULL DEFAULT 0, last_attempt_at BIGINT NULL, status VARCHAR(20) NOT NULL, result_code VARCHAR(64) NULL, boundary_sequence VARCHAR(38) NULL, confirmed_at BIGINT NULL, result_hash VARCHAR(64) NULL, receipt_nonce VARCHAR(32) NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id), FOREIGN KEY (tenant_id, product_id, source_version) REFERENCES iot_models(tenant_id, product_id, model_version), FOREIGN KEY (tenant_id, product_id, target_version) REFERENCES iot_models(tenant_id, product_id, model_version))' . $suffix,
            'CREATE INDEX iot_model_switches_device ON iot_model_switches (tenant_id, device_id, created_at, id)',
            'CREATE INDEX iot_model_switches_dispatch ON iot_model_switches (next_attempt_at, id)',
            'CREATE INDEX iot_model_switches_history ON iot_model_switches (device_id, ownership_id, source_version, status)',
        ];
    }

    /** @return list<string> 双方审批、来源、版本与凭据隔离阶段的唯一声明。 */
    public static function transfers(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_transfers (id VARCHAR(32) NOT NULL PRIMARY KEY, device_id VARCHAR(32) NOT NULL, device_name VARCHAR(100) NOT NULL, source_tenant_id VARCHAR(32) NOT NULL, target_tenant_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, source_product_id VARCHAR(32) NOT NULL, source_model_version INTEGER NOT NULL, source_definition TEXT NOT NULL, structure_hash VARCHAR(64) NOT NULL, source_actor_id VARCHAR(32) NOT NULL, request_version INTEGER NOT NULL, source_context TEXT NOT NULL, created_at BIGINT NOT NULL, status VARCHAR(20) NOT NULL, decision_id VARCHAR(32) NULL UNIQUE, decision_hash VARCHAR(64) NULL, decision_actor_id VARCHAR(32) NULL, decision_context TEXT NULL, decided_at BIGINT NULL, target_product_id VARCHAR(32) NULL, target_model_version INTEGER NULL, next_attempt_at BIGINT NULL, last_attempt_at BIGINT NULL, attempt_count INTEGER NOT NULL DEFAULT 0, device_status TEXT NULL, device_status_at BIGINT NULL, device_status_sequence VARCHAR(38) NULL, receipt_nonce VARCHAR(32) NULL, switch_id VARCHAR(32) NULL, switch_version INTEGER NULL, switch_actor_id VARCHAR(32) NULL, switch_context TEXT NULL, isolation_id VARCHAR(32) NULL, isolation_requested_at BIGINT NULL, isolated_at BIGINT NULL, new_ownership_id VARCHAR(32) NULL, activated_at BIGINT NULL, completed_at BIGINT NULL, FOREIGN KEY (device_id) REFERENCES iot_devices(id), FOREIGN KEY (source_tenant_id) REFERENCES iot_tenants(id), FOREIGN KEY (target_tenant_id) REFERENCES iot_tenants(id))' . $suffix,
            'CREATE INDEX iot_transfers_source ON iot_transfers (source_tenant_id, created_at, id)',
            'CREATE INDEX iot_transfers_target ON iot_transfers (target_tenant_id, created_at, id)',
            'CREATE INDEX iot_transfers_dispatch ON iot_transfers (next_attempt_at, id)',
            'CREATE UNIQUE INDEX iot_transfers_switch_id ON iot_transfers (switch_id)',
            'CREATE UNIQUE INDEX iot_transfers_new_ownership ON iot_transfers (new_ownership_id)',
        ];
    }

    /** @return list<string> 告警、确认与通知唯一声明；直接复用组件Outbox，不迁移旧数据。 */
    public static function alarms(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_alarm_rules (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, field VARCHAR(64) NOT NULL, version INTEGER NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, FOREIGN KEY (tenant_id, product_id, model_version) REFERENCES iot_models(tenant_id, product_id, model_version))' . $suffix,
            'CREATE INDEX iot_alarm_rules_device ON iot_alarm_rules (tenant_id, device_id, ownership_id, product_id, model_version)',
            'CREATE INDEX iot_alarm_rules_time ON iot_alarm_rules (tenant_id, created_at, id)',
            'CREATE TABLE iot_alarm_rule_versions (rule_id VARCHAR(32) NOT NULL, version INTEGER NOT NULL, name VARCHAR(100) NOT NULL, definition TEXT NOT NULL, starts_after VARCHAR(38) NOT NULL, ends_after VARCHAR(38) NULL, published_at BIGINT NOT NULL, actor_id VARCHAR(32) NOT NULL, retired_at BIGINT NULL, end_reason VARCHAR(32) NULL, PRIMARY KEY (rule_id, version), FOREIGN KEY (rule_id) REFERENCES iot_alarm_rules(id))' . $suffix,
            'CREATE TABLE iot_alarm_states (rule_id VARCHAR(32) NOT NULL, rule_version INTEGER NOT NULL, last_sequence VARCHAR(38) NULL, last_sampled_at BIGINT NULL, last_received_at BIGINT NULL, trigger_count INTEGER NOT NULL, recovery_count INTEGER NOT NULL, active_alarm_id VARCHAR(32) NULL, finalized INTEGER NOT NULL, PRIMARY KEY (rule_id, rule_version), FOREIGN KEY (rule_id, rule_version) REFERENCES iot_alarm_rule_versions(rule_id, version))' . $suffix,
            'CREATE TABLE iot_alarms (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, device_id VARCHAR(32) NOT NULL, ownership_id VARCHAR(32) NOT NULL, product_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, field VARCHAR(64) NOT NULL, rule_id VARCHAR(32) NOT NULL, rule_version INTEGER NOT NULL, status VARCHAR(20) NOT NULL, trigger_json TEXT NOT NULL, recovery_json TEXT NULL, created_at BIGINT NOT NULL, ended_at BIGINT NULL, end_reason VARCHAR(32) NULL, acknowledged_by VARCHAR(32) NULL, acknowledged_at BIGINT NULL, trigger_notified INTEGER NOT NULL DEFAULT 0, end_notified INTEGER NOT NULL DEFAULT 0, FOREIGN KEY (rule_id, rule_version) REFERENCES iot_alarm_rule_versions(rule_id, version))' . $suffix,
            'CREATE INDEX iot_alarms_tenant_time ON iot_alarms (tenant_id, created_at, id)',
            'CREATE INDEX iot_alarms_device_status ON iot_alarms (tenant_id, device_id, status, created_at, id)',
            'CREATE INDEX iot_alarms_expiry ON iot_alarms (ended_at, id)',
            'CREATE INDEX iot_alarms_trigger_pending ON iot_alarms (trigger_notified, id)',
            'CREATE INDEX iot_alarms_end_pending ON iot_alarms (end_notified, status, id)',
            'CREATE TABLE iot_notifications (id VARCHAR(128) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, alarm_id VARCHAR(32) NOT NULL, kind VARCHAR(20) NOT NULL, payload TEXT NOT NULL, created_at BIGINT NOT NULL)' . $suffix,
            'CREATE INDEX iot_notifications_tenant_time ON iot_notifications (tenant_id, created_at, id)',
            'CREATE INDEX iot_notifications_expiry ON iot_notifications (created_at, id)',
            ...(new \Type\Orm\Outbox\Store('iot_notice_outbox'))->migration($driver, '017_notice_store')->statements(),
            'CREATE INDEX iot_notice_outbox_recovery ON iot_notice_outbox (state, consumed_at, published_at, id)',
        ];
    }

    /** @return list<string> 私有导出唯一完整声明；快照及文件生命周期仍归ExportService。 */
    public static function exports(string $driver): array
    {
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [
            'CREATE TABLE iot_export_capacity (id INTEGER NOT NULL PRIMARY KEY, version BIGINT NOT NULL)' . $suffix,
            'INSERT INTO iot_export_capacity (id, version) VALUES (1, 1)',
            'CREATE TABLE iot_exports (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, device_id VARCHAR(32) NOT NULL, actor_id VARCHAR(32) NOT NULL, source_context TEXT NOT NULL, scope_key VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, kind VARCHAR(20) NOT NULL, filters_json TEXT NOT NULL, timezone VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, error_code VARCHAR(64) NOT NULL, total_rows INTEGER NOT NULL, completed_rows INTEGER NOT NULL, estimated_bytes BIGINT NOT NULL, file_bytes BIGINT NOT NULL, file_hash VARCHAR(64) NOT NULL, step INTEGER NOT NULL, last_time BIGINT NOT NULL, last_id VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, cleaned_at BIGINT NOT NULL DEFAULT 0, FOREIGN KEY (tenant_id) REFERENCES iot_tenants(id))' . $suffix,
            'CREATE INDEX iot_exports_tenant_time ON iot_exports (tenant_id, scope_key, created_at, id)',
            'CREATE INDEX iot_exports_status ON iot_exports (status, updated_at, id)',
            'CREATE INDEX iot_exports_expiry ON iot_exports (expires_at, id)',
            'CREATE TABLE iot_export_rows (export_id VARCHAR(32) NOT NULL, source_id VARCHAR(64) NOT NULL, position_time BIGINT NOT NULL, product_id VARCHAR(32) NOT NULL, model_version INTEGER NOT NULL, ownership_id VARCHAR(32) NOT NULL, sampled_at BIGINT NOT NULL, received_at BIGINT NOT NULL, ended_at BIGINT NOT NULL, sequence VARCHAR(38) NOT NULL, current_advanced INTEGER NOT NULL, values_json TEXT NOT NULL, model_definition TEXT NOT NULL, PRIMARY KEY (export_id, source_id), FOREIGN KEY (export_id) REFERENCES iot_exports(id))' . $suffix,
            'CREATE INDEX iot_export_rows_position ON iot_export_rows (export_id, position_time, source_id)',
            ...(new \Type\Orm\Outbox\Store('iot_export_outbox'))->migration($driver, '016_iot_export_outbox')->statements(),
        ];
    }

}
