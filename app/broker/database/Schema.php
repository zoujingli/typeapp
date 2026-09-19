<?php

declare(strict_types=1);

namespace app\broker\database;

use Type\Orm\Migration\Migration;

/** 独立管理宿主的状态；不创建或引用物联网业务表。 */
final class Schema
{
    /** @return list<Migration> 显式安装时执行，沿用三库迁移与非事务 DDL 恢复规则。 */
    public static function migrations(string $driver): array
    {
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new \InvalidArgumentException('broker_database_invalid');
        }
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return [new Migration('001_broker_management', '创建独立管理账号、会话、审计与有界节点采样', [
            'CREATE TABLE broker_users (id VARCHAR(32) NOT NULL PRIMARY KEY, login VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, platform_admin INTEGER NOT NULL DEFAULT 1, enabled INTEGER NOT NULL DEFAULT 1, recovery_verified INTEGER NOT NULL DEFAULT 1, failures INTEGER NOT NULL DEFAULT 0, locked_until BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL)' . $suffix,
            'CREATE TABLE broker_sessions (token_hash VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(32) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL, FOREIGN KEY (user_id) REFERENCES broker_users(id))' . $suffix,
            'CREATE INDEX broker_sessions_user ON broker_sessions (user_id, created_at)',
            'CREATE INDEX broker_sessions_expiry ON broker_sessions (expires_at)',
            'CREATE TABLE broker_audit (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NULL, actor_id VARCHAR(32) NOT NULL, action VARCHAR(80) NOT NULL, subject_id VARCHAR(100) NOT NULL, result VARCHAR(20) NOT NULL, details TEXT NOT NULL, created_at BIGINT NOT NULL)' . $suffix,
            'CREATE INDEX broker_audit_time ON broker_audit (created_at, id)',
            'CREATE TABLE broker_nodes (slot INTEGER NOT NULL PRIMARY KEY CHECK (slot >= 0 AND slot < 32), node_id VARCHAR(64) NOT NULL, run_id VARCHAR(32) NOT NULL UNIQUE, observed_at BIGINT NOT NULL, stopped INTEGER NOT NULL, listener_json TEXT NOT NULL, metrics_json TEXT NOT NULL)' . $suffix,
        ], $driver !== 'mysql'), ...ResourceSchema::migrations($driver), ...AccessSchema::migrations($driver), new Migration('027_broker_operation_audit', '保存Broker操作上下文、阶段回执与180天事件查询索引', [
            "ALTER TABLE broker_audit ADD COLUMN category VARCHAR(16) NOT NULL DEFAULT 'legacy'",
            'ALTER TABLE broker_audit ADD COLUMN operation_id VARCHAR(32) NULL',
            'ALTER TABLE broker_audit ADD COLUMN event_key VARCHAR(16) NULL',
            'ALTER TABLE broker_audit ADD COLUMN request_id VARCHAR(32) NULL',
            'ALTER TABLE broker_audit ADD COLUMN stage VARCHAR(16) NULL',
            "UPDATE broker_audit SET category = 'broker' WHERE action = 'broker.node_fence'",
            'CREATE INDEX broker_audit_category_time ON broker_audit (category, created_at, id)',
            'CREATE INDEX broker_audit_scope_time ON broker_audit (tenant_id, category, created_at, id)',
            'CREATE INDEX broker_audit_operation_time ON broker_audit (operation_id, created_at, id)',
            'CREATE UNIQUE INDEX broker_audit_operation_event ON broker_audit (operation_id, event_key)',
            'CREATE TABLE broker_broker_operations (operation_id VARCHAR(32) NOT NULL PRIMARY KEY, context_json TEXT NOT NULL, context_hash VARCHAR(64) NOT NULL, stage VARCHAR(16) NOT NULL, result VARCHAR(20) NOT NULL, version BIGINT NOT NULL, receipts_json TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix,
        ], $driver !== 'mysql'), new Migration('027_broker_operation_recovery', '为独立Broker操作账本增加恢复核对标记', [
            'ALTER TABLE broker_broker_operations ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
        ], $driver !== 'mysql')];
    }
}
