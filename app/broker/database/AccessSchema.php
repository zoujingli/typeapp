<?php

declare(strict_types=1);

namespace app\broker\database;

use Type\Orm\Migration\Migration;

/** 独立与双端共用的接入主体、受信 CA、客户端证书绑定、换证重叠、签名 CRL、平台吊销、Topic 授权、配额、运行配置、调试短期凭据、占用名额与兼容代次。 */
final class AccessSchema
{
    /** @return list<Migration> 与资源观察一样允许两种安装入口安全组合。 */
    public static function migrations(string $driver): array
    {
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new \InvalidArgumentException('broker_database_invalid');
        }
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : '';
        return [new Migration('028_broker_access_policy', '保存接入主体、凭据代次、Topic 授权、发布版本与节点生效', [
            'CREATE TABLE broker_access_principals (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NULL, name VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix,
            'CREATE INDEX broker_access_principals_tenant ON broker_access_principals (tenant_id, id)',
            'CREATE TABLE broker_access_credentials (id VARCHAR(32) NOT NULL PRIMARY KEY, principal_id VARCHAR(32) NOT NULL, login VARCHAR(100) NOT NULL UNIQUE, secret_hash VARCHAR(64) NOT NULL, credential_version INTEGER NOT NULL, status VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, revoked_at BIGINT NULL, FOREIGN KEY (principal_id) REFERENCES broker_access_principals(id))' . $suffix,
            'CREATE INDEX broker_access_credentials_principal ON broker_access_credentials (principal_id, status)',
            'CREATE TABLE broker_access_grants (id VARCHAR(32) NOT NULL PRIMARY KEY, principal_id VARCHAR(32) NOT NULL, topic VARCHAR(200) NOT NULL, publish INTEGER NOT NULL, subscribe INTEGER NOT NULL, max_qos INTEGER NOT NULL, FOREIGN KEY (principal_id) REFERENCES broker_access_principals(id))' . $suffix,
            'CREATE INDEX broker_access_grants_principal ON broker_access_grants (principal_id, topic)',
            'CREATE TABLE broker_access_revisions (id VARCHAR(32) NOT NULL PRIMARY KEY, version BIGINT NOT NULL UNIQUE, actor_id VARCHAR(32) NOT NULL, actor_realm VARCHAR(16) NOT NULL, tenant_id VARCHAR(32) NULL, tightening INTEGER NOT NULL, status VARCHAR(20) NOT NULL, snapshot_json TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix,
            'CREATE INDEX broker_access_revisions_status ON broker_access_revisions (status, version)',
            'CREATE TABLE broker_access_node_states (revision_id VARCHAR(32) NOT NULL, node_id VARCHAR(64) NOT NULL, applied_version BIGINT NOT NULL, state VARCHAR(20) NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY (revision_id, node_id), FOREIGN KEY (revision_id) REFERENCES broker_access_revisions(id))' . $suffix,
            'CREATE TABLE broker_access_invalidations (id VARCHAR(32) NOT NULL PRIMARY KEY, client_id TEXT NOT NULL, principal VARCHAR(100) NOT NULL, actor VARCHAR(32) NOT NULL, access_identity TEXT NOT NULL, requested_at BIGINT NOT NULL, completed_at BIGINT NULL, node_id VARCHAR(64) NULL)' . $suffix,
            'CREATE INDEX broker_access_invalidations_pending ON broker_access_invalidations (completed_at, requested_at, id)',
        ], $driver !== 'mysql'), new Migration('029_broker_access_certificates', '保存受信 CA 与客户端证书到主体的绑定', [
            'CREATE TABLE broker_access_cas (id VARCHAR(32) NOT NULL PRIMARY KEY, tenant_id VARCHAR(32) NULL, fingerprint VARCHAR(64) NOT NULL, subject VARCHAR(256) NOT NULL, pem TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix,
            'CREATE UNIQUE INDEX broker_access_cas_fingerprint ON broker_access_cas (fingerprint)',
            'CREATE INDEX broker_access_cas_tenant ON broker_access_cas (tenant_id, id)',
            'CREATE TABLE broker_access_certificates (id VARCHAR(32) NOT NULL PRIMARY KEY, principal_id VARCHAR(32) NOT NULL, fingerprint VARCHAR(64) NOT NULL, subject VARCHAR(256) NOT NULL, pem TEXT NOT NULL, not_after BIGINT NOT NULL, certificate_version INTEGER NOT NULL, status VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, revoked_at BIGINT NULL, FOREIGN KEY (principal_id) REFERENCES broker_access_principals(id))' . $suffix,
            'CREATE UNIQUE INDEX broker_access_certificates_fingerprint ON broker_access_certificates (fingerprint)',
            'CREATE INDEX broker_access_certificates_principal ON broker_access_certificates (principal_id, status)',
        ], $driver !== 'mysql'), new Migration('030_broker_access_certificate_overlap', '为客户端证书保存换证重叠截止时间', [
            'ALTER TABLE broker_access_certificates ADD COLUMN overlap_until BIGINT NOT NULL DEFAULT 0',
        ], $driver !== 'mysql'), new Migration('031_broker_access_crl', '保存已校验 CRL、粘性序列号与平台直接吊销', [
            'ALTER TABLE broker_access_certificates ADD COLUMN serial VARCHAR(64) NOT NULL DEFAULT \'\'',
            'CREATE TABLE broker_access_crls (ca_id VARCHAR(32) NOT NULL PRIMARY KEY, pem TEXT NOT NULL, this_update BIGINT NOT NULL, next_update BIGINT NOT NULL, url TEXT NOT NULL, fetch_interval INTEGER NOT NULL, fetch_status VARCHAR(20) NOT NULL, fetch_error VARCHAR(100) NOT NULL, fetched_at BIGINT NOT NULL, accepted_at BIGINT NOT NULL, fetch_due_at BIGINT NOT NULL, configured INTEGER NOT NULL, updated_at BIGINT NOT NULL, FOREIGN KEY (ca_id) REFERENCES broker_access_cas(id))' . $suffix,
            'CREATE TABLE broker_access_crl_serials (ca_id VARCHAR(32) NOT NULL, serial VARCHAR(64) NOT NULL, PRIMARY KEY (ca_id, serial), FOREIGN KEY (ca_id) REFERENCES broker_access_cas(id))' . $suffix,
            'CREATE TABLE broker_access_platform_serials (tenant_key VARCHAR(32) NOT NULL, serial VARCHAR(64) NOT NULL, actor_id VARCHAR(32) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY (tenant_key, serial))' . $suffix,
        ], $driver !== 'mysql'), new Migration('032_broker_quota_revisions', '保存可在线调整的连接、会话与消息额度版本及节点生效', [
            'CREATE TABLE broker_quota_revisions (id VARCHAR(32) NOT NULL PRIMARY KEY, version BIGINT NOT NULL UNIQUE, actor_id VARCHAR(32) NOT NULL, actor_realm VARCHAR(16) NOT NULL, lowering INTEGER NOT NULL, status VARCHAR(20) NOT NULL, snapshot_json TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix,
            'CREATE INDEX broker_quota_revisions_status ON broker_quota_revisions (status, version)',
            'CREATE TABLE broker_quota_node_states (revision_id VARCHAR(32) NOT NULL, node_id VARCHAR(64) NOT NULL, applied_version BIGINT NOT NULL, state VARCHAR(20) NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY (revision_id, node_id), FOREIGN KEY (revision_id) REFERENCES broker_quota_revisions(id))' . $suffix,
        ], $driver !== 'mysql'), new Migration('033_broker_runtime_revisions', '保存需运维重启才生效的监听、I/O 与证书路径版本及节点加载身份', [
            'CREATE TABLE broker_runtime_revisions (id VARCHAR(32) NOT NULL PRIMARY KEY, version BIGINT NOT NULL UNIQUE, actor_id VARCHAR(32) NOT NULL, actor_realm VARCHAR(16) NOT NULL, tightening INTEGER NOT NULL, status VARCHAR(20) NOT NULL, snapshot_json TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix,
            'CREATE INDEX broker_runtime_revisions_status ON broker_runtime_revisions (status, version)',
            'CREATE TABLE broker_runtime_node_states (revision_id VARCHAR(32) NOT NULL, node_id VARCHAR(64) NOT NULL, applied_version BIGINT NOT NULL, state VARCHAR(20) NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY (revision_id, node_id), FOREIGN KEY (revision_id) REFERENCES broker_runtime_revisions(id))' . $suffix,
        ], $driver !== 'mysql'), new Migration('034_broker_debug_credentials', '保存登录会话绑定的 MQTT 调试短期凭据，不进入接入快照', [
            'CREATE TABLE broker_debug_credentials (id VARCHAR(32) NOT NULL PRIMARY KEY, principal_id VARCHAR(32) NOT NULL UNIQUE, tenant_id VARCHAR(32) NULL, actor_id VARCHAR(32) NOT NULL, actor_realm VARCHAR(16) NOT NULL, session_id VARCHAR(64) NOT NULL, support_id VARCHAR(32) NULL, login VARCHAR(100) NOT NULL UNIQUE, secret_hash VARCHAR(64) NOT NULL, credential_version INTEGER NOT NULL, client_id VARCHAR(32) NOT NULL, subscribe_topic VARCHAR(200) NOT NULL, publish_topic VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL, revoked_at BIGINT NULL)' . $suffix,
            'CREATE INDEX broker_debug_credentials_session ON broker_debug_credentials (session_id, status)',
            'CREATE INDEX broker_debug_credentials_expiry ON broker_debug_credentials (expires_at, status)',
        ], $driver !== 'mysql'), new Migration('035_broker_debug_occupancy', '按 Client ID 计调试名额，同一身份重连不额外占用', [
            'CREATE TABLE broker_debug_quota_lock (id VARCHAR(32) NOT NULL PRIMARY KEY)' . $suffix,
            'INSERT INTO broker_debug_quota_lock (id) VALUES (\'default\')',
            'CREATE TABLE broker_debug_occupancy (client_id VARCHAR(32) NOT NULL PRIMARY KEY, credential_id VARCHAR(32) NOT NULL, actor_id VARCHAR(32) NOT NULL, node_id VARCHAR(64) NOT NULL, occupied_at BIGINT NOT NULL)' . $suffix,
            'CREATE INDEX broker_debug_occupancy_actor ON broker_debug_occupancy (actor_id, client_id)',
        ], $driver !== 'mysql'), new Migration('036_broker_compatibility', '保存管理库兼容代次与危险回退维护说明', [
            'CREATE TABLE broker_compatibility (id VARCHAR(16) NOT NULL PRIMARY KEY, runtime_epoch INTEGER NOT NULL, min_runtime_epoch INTEGER NOT NULL, management_head VARCHAR(64) NOT NULL, store_ready INTEGER NOT NULL, rollback_allowed INTEGER NOT NULL, rollback_reason TEXT NOT NULL, updated_at BIGINT NOT NULL)' . $suffix,
        ], $driver !== 'mysql'), new Migration('037_broker_recovery', '保存Broker恢复核对进度并为接入对象增加核对标记', [
            'ALTER TABLE broker_access_principals ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE broker_access_credentials ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE broker_access_certificates ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE broker_access_cas ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE broker_debug_credentials ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE broker_access_revisions ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE broker_quota_revisions ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE broker_runtime_revisions ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
            'CREATE TABLE broker_recovery (id VARCHAR(32) NOT NULL PRIMARY KEY, slot INTEGER NULL UNIQUE, actor VARCHAR(32) NOT NULL, proof VARCHAR(200) NOT NULL, state VARCHAR(20) NOT NULL, kind_index INTEGER NOT NULL, ' . ($driver === 'mysql' ? '`cursor`' : 'cursor') . ' VARCHAR(32) NOT NULL, started_at BIGINT NOT NULL, reviewed_at BIGINT NULL, completed_at BIGINT NULL, review_sha256 VARCHAR(64) NOT NULL)' . $suffix,
            'CREATE TABLE broker_recovery_subjects (recovery_id VARCHAR(32) NOT NULL, kind VARCHAR(20) NOT NULL, subject_id VARCHAR(32) NOT NULL, sha256 VARCHAR(64) NOT NULL, approved INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (recovery_id, kind, subject_id), FOREIGN KEY (recovery_id) REFERENCES broker_recovery(id))' . $suffix,
        ], $driver !== 'mysql')];
    }
}
