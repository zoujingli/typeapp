<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Core\Http\HttpError;
use Type\Orm\Connection;

/** Broker 恢复核对的主体种类、指纹与当前撤销事实；不含口令或 PEM 原文输出。 */
final class RecoveryCatalog
{
    /** 独立与双端共用的接入、证书、调试与配置种类。 */
    public const SHARED = ['broker_principal', 'broker_credential', 'broker_cert', 'broker_ca', 'broker_crl', 'broker_pserial', 'broker_debug', 'broker_access', 'broker_quota', 'broker_runtime'];

    /** @return list<string> */
    public static function kinds(string $host): array
    {
        return $host === 'broker'
            ? ['broker_user', ...self::SHARED, 'broker_op_node']
            : [...self::SHARED, 'broker_op_admin', 'broker_op_cust'];
    }

    /** 判断主体是否由 Broker 恢复目录负责；未知种类交给其他恢复处理器。 */
    public static function handles(string $kind): bool
    {
        return in_array($kind, ['broker_user', ...self::SHARED, 'broker_op_admin', 'broker_op_cust', 'broker_op_node'], true);
    }

    /**
     * 将固定主体种类映射到恢复表，不接受调用方直接指定表名。
     * @throws HttpError 种类不在恢复白名单中。
     */
    public static function table(string $kind): string
    {
        return match ($kind) {
            'broker_user' => 'broker_users',
            'broker_principal' => 'broker_access_principals',
            'broker_credential' => 'broker_access_credentials',
            'broker_cert' => 'broker_access_certificates',
            'broker_ca' => 'broker_access_cas',
            'broker_crl' => 'broker_access_crl_serials',
            'broker_pserial' => 'broker_access_platform_serials',
            'broker_debug' => 'broker_debug_credentials',
            'broker_access' => 'broker_access_revisions',
            'broker_quota' => 'broker_quota_revisions',
            'broker_runtime' => 'broker_runtime_revisions',
            'broker_op_admin' => 'admin_broker_operations',
            'broker_op_cust' => 'customer_broker_operations',
            'broker_op_node' => 'broker_broker_operations',
            default => throw new HttpError(422, 'recovery_kind_invalid'),
        };
    }

    /** CRL 与平台证书序列号表没有逐行核验标记；调用前须确认种类受支持。 */
    public static function hasVerifiedColumn(string $kind): bool
    {
        return !in_array($kind, ['broker_crl', 'broker_pserial'], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function subjectId(string $kind, array $row): string
    {
        if ($kind === 'broker_crl') {
            return self::serialId((string) $row['ca_id'], (string) $row['serial']);
        }
        if ($kind === 'broker_pserial') {
            return self::serialId((string) $row['tenant_key'], (string) $row['serial']);
        }
        if (in_array($kind, ['broker_op_admin', 'broker_op_cust', 'broker_op_node'], true)) {
            return (string) $row['operation_id'];
        }
        return (string) $row['id'];
    }

    /** 用带分隔的 CA/租户标识与序列号生成稳定游标，避免不同命名空间的序列号碰撞。 */
    public static function serialId(string $left, string $serial): string
    {
        return substr(hash('sha256', $left . "\0" . $serial), 0, 32);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function batch(Connection $transaction, string $kind, string $cursor, bool $verifiedOnly): array
    {
        if ($kind === 'broker_crl' || $kind === 'broker_pserial') {
            $order = $kind === 'broker_crl' ? 'ca_id' : 'tenant_key';
            $selected = [];
            foreach ($transaction->table(self::table($kind))->orderBy($order)->orderBy('serial')->get() as $row) {
                $id = self::subjectId($kind, $row);
                if ($id > $cursor) {
                    $row['_subject_id'] = $id;
                    $selected[] = $row;
                }
            }
            usort($selected, static fn (array $left, array $right): int => strcmp((string) $left['_subject_id'], (string) $right['_subject_id']));
            return array_slice($selected, 0, 100);
        }
        $key = in_array($kind, ['broker_op_admin', 'broker_op_cust', 'broker_op_node'], true) ? 'operation_id' : 'id';
        $query = $transaction->table(self::table($kind))->where($key, '>', $cursor)->orderBy($key)->limit(100);
        if ($verifiedOnly && self::hasVerifiedColumn($kind)) {
            $query = $query->where('recovery_verified', '=', 1);
        }
        $rows = [];
        foreach ($query->get() as $row) {
            $row['_subject_id'] = self::subjectId($kind, $row);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fingerprint(Connection $connection, string $kind, array $row): string
    {
        $identity = match ($kind) {
            'broker_user' => [
                'id' => (string) $row['id'], 'login' => (string) $row['login'], 'password_hash' => (string) $row['password_hash'],
                'enabled' => (string) $row['enabled'], 'platform_admin' => (string) ($row['platform_admin'] ?? '1'),
            ],
            'broker_principal' => [
                'id' => (string) $row['id'], 'tenant_id' => (string) ($row['tenant_id'] ?? ''), 'name' => (string) $row['name'],
                'enabled' => (string) $row['enabled'],
                'grants' => self::grants($connection, (string) $row['id']),
                'credentials' => self::credentials($connection, (string) $row['id']),
            ],
            'broker_credential' => [
                'id' => (string) $row['id'], 'principal_id' => (string) $row['principal_id'], 'login' => (string) $row['login'],
                'secret_hash' => (string) $row['secret_hash'], 'credential_version' => (string) $row['credential_version'],
                'status' => (string) $row['status'],
            ],
            'broker_cert' => [
                'id' => (string) $row['id'], 'principal_id' => (string) $row['principal_id'], 'fingerprint' => (string) $row['fingerprint'],
                'serial' => (string) ($row['serial'] ?? ''), 'status' => (string) $row['status'],
                'certificate_version' => (string) $row['certificate_version'], 'not_after' => (string) $row['not_after'],
                'overlap_until' => (string) ($row['overlap_until'] ?? '0'),
            ],
            'broker_ca' => [
                'id' => (string) $row['id'], 'fingerprint' => (string) $row['fingerprint'], 'subject' => (string) $row['subject'],
                'pem_sha256' => hash('sha256', (string) $row['pem']),
            ],
            'broker_crl' => ['ca_id' => (string) $row['ca_id'], 'serial' => (string) $row['serial']],
            'broker_pserial' => ['tenant_key' => (string) $row['tenant_key'], 'serial' => (string) $row['serial']],
            'broker_debug' => [
                'id' => (string) $row['id'], 'principal_id' => (string) $row['principal_id'], 'login' => (string) $row['login'],
                'secret_hash' => (string) $row['secret_hash'], 'status' => (string) $row['status'],
                'expires_at' => (string) $row['expires_at'], 'session_id' => (string) $row['session_id'],
                'credential_version' => (string) $row['credential_version'],
            ],
            'broker_access', 'broker_quota', 'broker_runtime' => [
                'id' => (string) $row['id'], 'version' => (string) $row['version'], 'status' => (string) $row['status'],
                'snapshot_sha256' => hash('sha256', (string) $row['snapshot_json']),
                'flag' => (string) ($row['tightening'] ?? $row['lowering'] ?? '0'),
            ],
            default => [
                'operation_id' => (string) $row['operation_id'], 'stage' => (string) $row['stage'],
                'result' => (string) $row['result'], 'context_hash' => (string) $row['context_hash'],
                'version' => (string) $row['version'],
            ],
        };
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function snapshotData(string $kind, array $row): array
    {
        if ($kind === 'broker_crl') {
            return ['ca_id' => (string) $row['ca_id'], 'serial' => (string) $row['serial']];
        }
        if ($kind === 'broker_pserial') {
            return ['tenant_key' => (string) $row['tenant_key'], 'serial' => (string) $row['serial']];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isolateRow(Connection $transaction, string $kind, array $row): void
    {
        if (!self::hasVerifiedColumn($kind)) {
            return;
        }
        $key = in_array($kind, ['broker_op_admin', 'broker_op_cust', 'broker_op_node'], true) ? 'operation_id' : 'id';
        $transaction->table(self::table($kind))->where($key, '=', self::subjectId($kind, $row))->update(['recovery_verified' => 0]);
    }

    /**
     * @param array{kind:string,id:string,sha256:string,data?:array<string, mixed>} $record
     */
    public static function adopt(Connection $transaction, array $record): bool
    {
        $kind = $record['kind'];
        $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : [];
        if ($kind === 'broker_crl') {
            $caId = (string) ($data['ca_id'] ?? '');
            $serial = (string) ($data['serial'] ?? '');
            if (preg_match('/^[a-f0-9]{32}$/D', $caId) !== 1 || $serial === '' || strlen($serial) > 64) {
                return false;
            }
            if ($transaction->table('broker_access_cas')->where('id', '=', $caId)->first() === null) {
                return false;
            }
            if ($transaction->table('broker_access_crl_serials')->where('ca_id', '=', $caId)->where('serial', '=', $serial)->first() === null) {
                $transaction->table('broker_access_crl_serials')->insert(['ca_id' => $caId, 'serial' => $serial]);
            }
            return true;
        }
        if ($kind === 'broker_pserial') {
            $tenantKey = (string) ($data['tenant_key'] ?? '');
            $serial = (string) ($data['serial'] ?? '');
            if ($tenantKey !== '-' && preg_match('/^[a-f0-9]{32}$/D', $tenantKey) !== 1) {
                return false;
            }
            if ($serial === '' || strlen($serial) > 64) {
                return false;
            }
            if ($transaction->table('broker_access_platform_serials')->where('tenant_key', '=', $tenantKey)->where('serial', '=', $serial)->first() === null) {
                $transaction->table('broker_access_platform_serials')->insert([
                    'tenant_key' => $tenantKey, 'serial' => $serial, 'actor_id' => 'recovery', 'created_at' => time(),
                ]);
            }
            return true;
        }
        return false;
    }

    /** 核验拒绝仅将未生效、未回退的配置版本标为失败；其他主体保留其原状态。 */
    public static function reject(Connection $transaction, string $kind, string $id): void
    {
        if (in_array($kind, ['broker_access', 'broker_quota', 'broker_runtime'], true)) {
            $row = $transaction->table(self::table($kind))->where('id', '=', $id)->first();
            if ($row !== null && (string) $row['status'] !== 'rolled_back' && (string) $row['status'] !== 'effective') {
                $transaction->table(self::table($kind))->where('id', '=', $id)->update(['status' => 'failed', 'updated_at' => time()]);
            }
        }
    }

    /** 在调用方事务中恢复主体核验标记；不含核验列的序列号表无需更新。 */
    public static function restoreVerified(Connection $transaction, string $kind, string $id): void
    {
        if (!self::hasVerifiedColumn($kind)) {
            return;
        }
        $key = in_array($kind, ['broker_op_admin', 'broker_op_cust', 'broker_op_node'], true) ? 'operation_id' : 'id';
        $transaction->table(self::table($kind))->where($key, '=', $id)->update(['recovery_verified' => 1]);
    }

    /**
     * @return list<array{id:string,login:string,secret_hash:string,credential_version:string,status:string}>
     */
    private static function credentials(Connection $connection, string $principalId): array
    {
        $credentials = [];
        foreach ($connection->table('broker_access_credentials')->where('principal_id', '=', $principalId)->orderBy('id')->get() as $credential) {
            $credentials[] = [
                'id' => (string) $credential['id'], 'login' => (string) $credential['login'],
                'secret_hash' => (string) $credential['secret_hash'],
                'credential_version' => (string) $credential['credential_version'],
                'status' => (string) $credential['status'],
            ];
        }
        return $credentials;
    }

    /**
     * @return list<array{topic:string,publish:int,subscribe:int,max_qos:int}>
     */
    private static function grants(Connection $connection, string $principalId): array
    {
        $grants = [];
        foreach ($connection->table('broker_access_grants')->where('principal_id', '=', $principalId)->orderBy('topic')->orderBy('id')->get() as $grant) {
            $grants[] = [
                'topic' => (string) $grant['topic'], 'publish' => (int) $grant['publish'],
                'subscribe' => (int) $grant['subscribe'], 'max_qos' => (int) $grant['max_qos'],
            ];
        }
        return $grants;
    }
}
