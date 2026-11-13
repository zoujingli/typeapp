<?php

declare(strict_types=1);

namespace app\platform\mqtt\database;

use Type\Orm\Migration\Migration;

/** 平台 MQTT 节点与实时资源投影；人员身份由平台 RBAC 管理。 */
final class ResourceSchema
{
    /** @return list<Migration> 由应用全新安装组合，不提供独立人员后台的安装入口。 */
    public static function migrations(string $driver): array
    {
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new \InvalidArgumentException('broker_database_invalid');
        }
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : '';
        return [new Migration('026_broker_resource_observations', '保存有界 Broker 运行、连接和真实订阅观察', [
            'CREATE TABLE platform_mqtt_node (slot INTEGER NOT NULL PRIMARY KEY CHECK (slot >= 0 AND slot < 32), node_id VARCHAR(64) NOT NULL, run_id VARCHAR(32) NOT NULL UNIQUE, observed_at BIGINT NOT NULL, stopped INTEGER NOT NULL, listener_json TEXT NOT NULL, metrics_json TEXT NOT NULL)' . $suffix,
            'CREATE TABLE platform_mqtt_resource_run (slot INTEGER NOT NULL PRIMARY KEY CHECK (slot >= 0 AND slot < 32), node_id VARCHAR(64) NOT NULL UNIQUE, run_id VARCHAR(32) NOT NULL UNIQUE, observed_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, connections INTEGER NOT NULL DEFAULT 0 CHECK (connections >= 0 AND connections <= 10100))' . $suffix,
            'CREATE TABLE platform_mqtt_resource_connection (owner_id VARCHAR(32) NOT NULL PRIMARY KEY, observation_run VARCHAR(32) NOT NULL, client_id TEXT NOT NULL, client_hash VARCHAR(64) NOT NULL, session_id VARCHAR(32) NOT NULL, session_generation BIGINT NOT NULL, node_id VARCHAR(64) NOT NULL, node_run_id VARCHAR(32) NOT NULL, node_generation BIGINT NOT NULL, resource_scope VARCHAR(128) NULL, scope_hash VARCHAR(64) NULL, access_identity TEXT NULL, protocol INTEGER NOT NULL, transport VARCHAR(10) NOT NULL, durable INTEGER NOT NULL, observed_at BIGINT NOT NULL, FOREIGN KEY (observation_run) REFERENCES platform_mqtt_resource_run(run_id) ON DELETE CASCADE)' . $suffix,
            'CREATE INDEX platform_mqtt_resource_connection_scope ON platform_mqtt_resource_connection (scope_hash, owner_id)',
            'CREATE INDEX platform_mqtt_resource_connection_run ON platform_mqtt_resource_connection (observation_run, owner_id)',
            'CREATE INDEX platform_mqtt_resource_connection_client ON platform_mqtt_resource_connection (client_hash, owner_id)',
            'CREATE TABLE platform_mqtt_resource_subscription (id VARCHAR(64) NOT NULL PRIMARY KEY, owner_id VARCHAR(32) NOT NULL, topic TEXT NOT NULL, topic_hash VARCHAR(64) NOT NULL, actual_filter TEXT NOT NULL, options INTEGER NOT NULL, identifier BIGINT NOT NULL, observed_at BIGINT NOT NULL, FOREIGN KEY (owner_id) REFERENCES platform_mqtt_resource_connection(owner_id) ON DELETE CASCADE)' . $suffix,
            'CREATE INDEX platform_mqtt_resource_subscription_owner ON platform_mqtt_resource_subscription (owner_id, id)',
            'CREATE INDEX platform_mqtt_resource_subscription_topic ON platform_mqtt_resource_subscription (topic_hash, id)',
        ], $driver !== 'mysql'), new Migration('032_platform_mqtt_connection_operation', '保存精确连接管理操作及其完成结果', [
            'CREATE TABLE platform_mqtt_connection_operation (id VARCHAR(32) NOT NULL PRIMARY KEY, kind VARCHAR(32) NOT NULL, owner_id VARCHAR(32) NOT NULL, session_id VARCHAR(32) NOT NULL, session_generation BIGINT NOT NULL, node_id VARCHAR(64) NOT NULL, node_run_id VARCHAR(32) NOT NULL, observation_run VARCHAR(32) NOT NULL, actor_id VARCHAR(32) NOT NULL, actor_realm VARCHAR(16) NOT NULL, tenant_id VARCHAR(32) NULL, requested_at BIGINT NOT NULL, completed_at BIGINT NULL, outcome VARCHAR(20) NOT NULL)' . $suffix,
            'CREATE INDEX platform_mqtt_connection_operation_node ON platform_mqtt_connection_operation (node_id, completed_at, requested_at, id)',
            'CREATE INDEX platform_mqtt_connection_operation_owner ON platform_mqtt_connection_operation (owner_id, session_generation, id)',
        ], $driver !== 'mysql')];
    }
}
