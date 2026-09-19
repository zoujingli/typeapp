<?php

declare(strict_types=1);

namespace app\broker\database;

use Type\Orm\Migration\Migration;

/** 两种管理宿主共用的实时资源投影；不创建人员账号或引用 IoT 业务表。 */
final class ResourceSchema
{
    /** @return list<Migration> 同一迁移版本允许独立 Broker 与 IoT 安装入口安全组合。 */
    public static function migrations(string $driver): array
    {
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new \InvalidArgumentException('broker_database_invalid');
        }
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : '';
        return [new Migration('026_broker_resource_observations', '保存有界 Broker 运行、连接和真实订阅观察', [
            'CREATE TABLE broker_resource_runs (slot INTEGER NOT NULL PRIMARY KEY CHECK (slot >= 0 AND slot < 32), node_id VARCHAR(64) NOT NULL UNIQUE, run_id VARCHAR(32) NOT NULL UNIQUE, observed_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, connections INTEGER NOT NULL DEFAULT 0 CHECK (connections >= 0 AND connections <= 10100))' . $suffix,
            'CREATE TABLE broker_resource_connections (owner_id VARCHAR(32) NOT NULL PRIMARY KEY, observation_run VARCHAR(32) NOT NULL, client_id TEXT NOT NULL, client_hash VARCHAR(64) NOT NULL, session_id VARCHAR(32) NOT NULL, session_generation BIGINT NOT NULL, node_id VARCHAR(64) NOT NULL, node_run_id VARCHAR(32) NOT NULL, node_generation BIGINT NOT NULL, resource_scope VARCHAR(128) NULL, scope_hash VARCHAR(64) NULL, access_identity TEXT NULL, protocol INTEGER NOT NULL, transport VARCHAR(10) NOT NULL, durable INTEGER NOT NULL, observed_at BIGINT NOT NULL, FOREIGN KEY (observation_run) REFERENCES broker_resource_runs(run_id) ON DELETE CASCADE)' . $suffix,
            'CREATE INDEX broker_resource_connections_scope ON broker_resource_connections (scope_hash, owner_id)',
            'CREATE INDEX broker_resource_connections_run ON broker_resource_connections (observation_run, owner_id)',
            'CREATE INDEX broker_resource_connections_client ON broker_resource_connections (client_hash, owner_id)',
            'CREATE TABLE broker_resource_subscriptions (id VARCHAR(64) NOT NULL PRIMARY KEY, owner_id VARCHAR(32) NOT NULL, topic TEXT NOT NULL, topic_hash VARCHAR(64) NOT NULL, actual_filter TEXT NOT NULL, options INTEGER NOT NULL, identifier BIGINT NOT NULL, observed_at BIGINT NOT NULL, FOREIGN KEY (owner_id) REFERENCES broker_resource_connections(owner_id) ON DELETE CASCADE)' . $suffix,
            'CREATE INDEX broker_resource_subscriptions_owner ON broker_resource_subscriptions (owner_id, id)',
            'CREATE INDEX broker_resource_subscriptions_topic ON broker_resource_subscriptions (topic_hash, id)',
        ], $driver !== 'mysql'), new Migration('032_broker_connection_operations', '保存精确连接管理操作及其完成结果', [
            'CREATE TABLE broker_connection_operations (id VARCHAR(32) NOT NULL PRIMARY KEY, kind VARCHAR(32) NOT NULL, owner_id VARCHAR(32) NOT NULL, session_id VARCHAR(32) NOT NULL, session_generation BIGINT NOT NULL, node_id VARCHAR(64) NOT NULL, node_run_id VARCHAR(32) NOT NULL, observation_run VARCHAR(32) NOT NULL, actor_id VARCHAR(32) NOT NULL, actor_realm VARCHAR(16) NOT NULL, tenant_id VARCHAR(32) NULL, requested_at BIGINT NOT NULL, completed_at BIGINT NULL, outcome VARCHAR(20) NOT NULL)' . $suffix,
            'CREATE INDEX broker_connection_operations_node ON broker_connection_operations (node_id, completed_at, requested_at, id)',
            'CREATE INDEX broker_connection_operations_owner ON broker_connection_operations (owner_id, session_generation, id)',
        ], $driver !== 'mysql')];
    }
}
