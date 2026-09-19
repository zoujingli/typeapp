<?php

declare(strict_types=1);

namespace app\iot\service;

use app\broker\service\RecoveryCatalog;
use app\common\service\AuditLog;
use Type\Core\Http\HttpError;
use Type\Orm\Connection;

/** 隔离恢复库的授权核对；操作前须隔离旧系统，当前授权清单来自备份之外的受控来源。 */
final class RecoveryService
{
    private const APP_KINDS = ['admin_user', 'customer_user', 'admin_role', 'customer_role', 'member', 'device', 'credential'];
    private const SESSION_TABLES = ['customer_sessions', 'admin_sessions'];

    /**
     * 生成不含口令/凭据散列原值的身份摘要；来源须在整个导出及核对期间停止授权写入。
     *
     * @return array{version:int,records:list<array{kind:string,id:string,sha256:string,data?:array<string,string>}>}
     * @throws HttpError 清单超过20万主体或存在未结束的恢复核对。
     */
    public static function snapshot(Connection $connection, string $host = 'app'): array
    {
        self::host($host);
        self::ready($connection, $host);
        return $connection->transaction(static function (Connection $transaction) use ($host): array {
            $records = [];
            foreach (self::kinds($host) as $kind) {
                $cursor = '';
                do {
                    $rows = self::batch($transaction, $kind, $cursor, true);
                    foreach ($rows as $row) {
                        if (count($records) >= 200000) {
                            throw new HttpError(409, 'recovery_snapshot_limit');
                        }
                        $cursor = (string) $row['_subject_id'];
                        $record = ['kind' => $kind, 'id' => $cursor, 'sha256' => self::fingerprint($transaction, $kind, $row)];
                        $data = RecoveryCatalog::handles($kind) ? RecoveryCatalog::snapshotData($kind, $row) : [];
                        if ($data !== []) {
                            $record['data'] = $data;
                        }
                        $records[] = $record;
                    }
                } while (count($rows) === 100);
            }
            return ['version' => 1, 'records' => $records];
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 先持久关闭业务启动门，再按有界批次保存原身份并隔离；重复同ID保留进度，不接受换证据。
     *
     * @return array<string,mixed> 不包含秘密的恢复进度。
     */
    public static function begin(Connection $connection, string $id, string $actor, string $proof, string $host = 'app'): array
    {
        self::host($host);
        self::identity($id);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $actor) || trim($proof) === '' || strlen($proof) > 200 || preg_match('/[\x00-\x1f\x7f]/', $proof)) {
            throw new HttpError(422, 'recovery_evidence_invalid');
        }
        return $connection->transaction(static function (Connection $transaction) use ($id, $actor, $proof, $host): array {
            $previous = self::locked($transaction, $host);
            $runTable = self::runTable($host);
            if ($previous !== null) {
                if ($previous['id'] === $id && $previous['actor'] === $actor && $previous['proof'] === $proof) {
                    return self::status($transaction, $host);
                }
                if ($previous['state'] !== 'ready' || $previous['id'] === $id) {
                    throw new HttpError(409, 'recovery_already_started');
                }
                $transaction->table($runTable)->where('id', '=', $previous['id'])->update(['slot' => null]);
            }
            if ($transaction->table($runTable)->where('id', '=', $id)->first() !== null) {
                throw new HttpError(409, 'recovery_identity_conflict');
            }
            $transaction->table($runTable)->insert(['slot' => 1, 'id' => $id, 'actor' => $actor, 'proof' => $proof,
                'state' => 'isolating', 'kind_index' => 0, 'cursor' => '', 'started_at' => time(), 'completed_at' => null,
                'review_sha256' => '', 'reviewed_at' => null]);
            AuditLog::append($transaction, null, $actor, 'recovery.started', $id, 'success', ['context' => 'operator-command'], self::auditRealm($host));
            return self::status($transaction, $host);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 每次最多100主体；原值、隔离状态和游标同事务，崩溃后以原ID继续。 */
    public static function isolate(Connection $connection, string $id, string $host = 'app'): array
    {
        self::host($host);
        self::identity($id);
        return $connection->transaction(static function (Connection $transaction) use ($id, $host): array {
            $recovery = self::requireRun($transaction, $id, $host);
            if ($recovery['state'] !== 'isolating') {
                return self::status($transaction, $host);
            }
            $kinds = self::kinds($host);
            $index = (int) $recovery['kind_index'];
            $kind = $kinds[$index];
            $rows = self::batch($transaction, $kind, $recovery['cursor'], false);
            $cursor = $recovery['cursor'];
            $subjects = self::subjectTable($host);
            foreach ($rows as $row) {
                $cursor = (string) $row['_subject_id'];
                $transaction->table($subjects)->insert(['recovery_id' => $id, 'kind' => $kind, 'subject_id' => $cursor,
                    'sha256' => self::fingerprint($transaction, $kind, $row), 'approved' => 0]);
                self::isolateRow($transaction, $kind, $row);
            }
            if (count($rows) < 100) {
                $index++;
                $cursor = '';
            }
            $transaction->table(self::runTable($host))->where('slot', '=', 1)->update(['kind_index' => $index, 'cursor' => $cursor,
                'state' => $index === count($kinds) ? 'reviewing' : 'isolating']);
            return self::status($transaction, $host);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 当前权威清单须在事故隔离后由维护者核对摘要；文件只包含不可替代身份指纹，不接受任意业务字段。
     * 一次固定清单身份后每批最多100条；缺失、变更和重复冲突均不能恢复旧权限。
     *
     * @param string $contents 精确的UTF-8 JSON文件字节，最多64MiB；摘要须经备份之外的受控渠道核对。
     */
    public static function review(Connection $connection, string $id, string $contents, string $digest, int $offset, string $host = 'app'): array
    {
        self::host($host);
        self::identity($id);
        if (strlen($contents) > 67108864 || !preg_match('/^[a-f0-9]{64}$/D', $digest) || !hash_equals($digest, hash('sha256', $contents))) {
            throw new HttpError(422, 'recovery_snapshot_digest');
        }
        $snapshot = json_decode($contents, true, 8);
        $kinds = self::kinds($host);
        if (!is_array($snapshot) || array_diff(array_keys($snapshot), ['version', 'records']) !== [] || ($snapshot['version'] ?? null) !== 1
            || !is_array($snapshot['records'] ?? null) || !array_is_list($snapshot['records']) || count($snapshot['records']) > 200000
            || $offset < 0 || $offset > count($snapshot['records'])) {
            throw new HttpError(422, 'recovery_snapshot_invalid');
        }
        $seen = [];
        foreach ($snapshot['records'] as $record) {
            if (!is_array($record) || array_diff(array_keys($record), ['kind', 'id', 'sha256', 'data']) !== []
                || !in_array($record['kind'] ?? null, $kinds, true) || !is_string($record['id'] ?? null)
                || !preg_match('/^[a-f0-9]{32}$/D', $record['id']) || !is_string($record['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $record['sha256'])) {
                throw new HttpError(422, 'recovery_snapshot_invalid');
            }
            if (isset($record['data']) && (!is_array($record['data']) || $record['data'] === [] || array_is_list($record['data']))) {
                throw new HttpError(422, 'recovery_snapshot_invalid');
            }
            $key = $record['kind'] . ':' . $record['id'];
            if (isset($seen[$key])) {
                throw new HttpError(422, 'recovery_snapshot_duplicate');
            }
            $seen[$key] = true;
        }
        return $connection->transaction(static function (Connection $transaction) use ($id, $snapshot, $digest, $offset, $host): array {
            $recovery = self::requireRun($transaction, $id, $host);
            $runTable = self::runTable($host);
            $subjects = self::subjectTable($host);
            if ($recovery['review_sha256'] !== '' && $recovery['review_sha256'] !== $digest) {
                throw new HttpError(409, 'recovery_review_conflict');
            }
            if (in_array($recovery['state'], ['restoring', 'ready'], true) && $recovery['review_sha256'] === $digest) {
                return self::status($transaction, $host);
            }
            if ($recovery['state'] !== 'reviewing') {
                throw new HttpError(409, 'recovery_review_conflict');
            }
            $next = (int) $recovery['cursor'];
            if ($offset < $next && $recovery['review_sha256'] === $digest) {
                return self::status($transaction, $host);
            }
            if ($offset > $next) {
                throw new HttpError(409, 'recovery_review_offset');
            }
            foreach (array_slice($snapshot['records'], $offset, 100) as $record) {
                $subject = $transaction->table($subjects)->where('recovery_id', '=', $id)->where('kind', '=', $record['kind'])
                    ->where('subject_id', '=', $record['id'])->first();
                if ($subject !== null && hash_equals($subject['sha256'], $record['sha256'])) {
                    $transaction->table($subjects)->where('recovery_id', '=', $id)->where('kind', '=', $record['kind'])
                        ->where('subject_id', '=', $record['id'])->update(['approved' => 1]);
                } elseif ($subject === null && RecoveryCatalog::handles($record['kind']) && RecoveryCatalog::adopt($transaction, $record)) {
                    $transaction->table($subjects)->insert(['recovery_id' => $id, 'kind' => $record['kind'], 'subject_id' => $record['id'],
                        'sha256' => $record['sha256'], 'approved' => 1]);
                }
            }
            $next = min($offset + 100, count($snapshot['records']));
            $transaction->table($runTable)->where('slot', '=', 1)->update(['review_sha256' => $digest, 'cursor' => (string) $next,
                'state' => $next === count($snapshot['records']) ? 'restoring' : 'reviewing', 'kind_index' => 0,
                'reviewed_at' => time()]);
            if ($next === count($snapshot['records'])) {
                $transaction->table($runTable)->where('slot', '=', 1)->update(['cursor' => '']);
            }
            return self::status($transaction, $host);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 核对完成后仍有界恢复；所有未匹配主体保持隔离，旧登录会话和模拟会话不复活。 */
    public static function restore(Connection $connection, string $id, string $host = 'app'): array
    {
        self::host($host);
        self::identity($id);
        return $connection->transaction(static function (Connection $transaction) use ($id, $host): array {
            $recovery = self::requireRun($transaction, $id, $host);
            if ($recovery['state'] === 'ready') {
                return self::status($transaction, $host);
            }
            if ($recovery['state'] !== 'restoring') {
                throw new HttpError(409, 'recovery_not_reviewed');
            }
            $kinds = self::kinds($host);
            $sessions = self::sessionTables($host);
            $index = (int) $recovery['kind_index'];
            $runTable = self::runTable($host);
            if ($index < count($kinds)) {
                $kind = $kinds[$index];
                $subjects = $transaction->table(self::subjectTable($host))->where('recovery_id', '=', $id)->where('kind', '=', $kind)
                    ->where('subject_id', '>', $recovery['cursor'])->orderBy('subject_id')->limit(100)->get();
                $cursor = $recovery['cursor'];
                foreach ($subjects as $subject) {
                    $cursor = $subject['subject_id'];
                    if ((int) $subject['approved'] === 1) {
                        $current = self::currentRow($transaction, $kind, $cursor);
                        if ($current !== null && hash_equals($subject['sha256'], self::fingerprint($transaction, $kind, $current))) {
                            self::restoreRow($transaction, $kind, $cursor);
                        } else {
                            $transaction->table(self::subjectTable($host))->where('recovery_id', '=', $id)->where('kind', '=', $kind)
                                ->where('subject_id', '=', $cursor)->update(['approved' => 0]);
                            if (RecoveryCatalog::handles($kind)) {
                                RecoveryCatalog::reject($transaction, $kind, $cursor);
                            }
                        }
                    } elseif (RecoveryCatalog::handles($kind)) {
                        RecoveryCatalog::reject($transaction, $kind, $cursor);
                    }
                }
                if (count($subjects) < 100) {
                    $index++;
                    $cursor = '';
                }
                $transaction->table($runTable)->where('slot', '=', 1)->update(['kind_index' => $index, 'cursor' => $cursor]);
            } else {
                $sessionIndex = $index - count($kinds);
                if ($sessionIndex < count($sessions)) {
                    $table = $sessions[$sessionIndex];
                    $rows = $transaction->table($table)->where('token_hash', '>', $recovery['cursor'])->orderBy('token_hash')->limit(100)->get();
                    foreach ($rows as $session) {
                        $transaction->table($table)->where('token_hash', '=', $session['token_hash'])->delete();
                    }
                    $cursor = count($rows) < 100 ? '' : (string) $rows[count($rows) - 1]['token_hash'];
                    $nextIndex = count($rows) < 100 ? $index + 1 : $index;
                    $transaction->table($runTable)->where('slot', '=', 1)->update(['kind_index' => $nextIndex, 'cursor' => $cursor]);
                } else {
                    self::fenceStaleMqttNodes($transaction);
                    $transaction->table($runTable)->where('slot', '=', 1)->update(['state' => 'ready', 'completed_at' => time()]);
                    AuditLog::append($transaction, null, $recovery['actor'], 'recovery.ready', $id, 'success', ['context' => 'operator-command'], self::auditRealm($host));
                }
            }
            return self::status($transaction, $host);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 业务进程启动门；数据库或恢复状态不可确认时拒绝启动，不将异常当作未进入恢复。
     *
     * @throws HttpError 恢复核对尚未结束。
     */
    public static function ready(Connection $connection, string $host = 'app'): void
    {
        self::host($host);
        $run = $connection->table(self::runTable($host))->where('slot', '=', 1)->first();
        if ($run !== null && $run['state'] !== 'ready') {
            throw new HttpError(503, 'recovery_isolated');
        }
    }

    /** @return array<string,mixed> 有界进度及未匹配数，不返回主体指纹或秘密。 */
    public static function status(Connection $connection, string $host = 'app'): array
    {
        self::host($host);
        $run = $connection->table(self::runTable($host))->where('slot', '=', 1)->first();
        if ($run === null) {
            return ['state' => 'none', 'host' => $host];
        }
        $counts = [];
        foreach (self::kinds($host) as $kind) {
            $counts[$kind] = ['total' => (int) $connection->table(self::subjectTable($host))->where('recovery_id', '=', $run['id'])->where('kind', '=', $kind)->aggregate('COUNT'),
                'approved' => (int) $connection->table(self::subjectTable($host))->where('recovery_id', '=', $run['id'])->where('kind', '=', $kind)->where('approved', '=', 1)->aggregate('COUNT')];
        }
        return ['id' => $run['id'], 'state' => $run['state'], 'host' => $host, 'kind_index' => (int) $run['kind_index'], 'cursor' => $run['cursor'],
            'started_at' => (int) $run['started_at'], 'reviewed_at' => $run['reviewed_at'] === null ? null : (int) $run['reviewed_at'],
            'completed_at' => $run['completed_at'] === null ? null : (int) $run['completed_at'], 'subjects' => $counts];
    }

    /** @return list<string> */
    private static function kinds(string $host): array
    {
        return $host === 'broker' ? RecoveryCatalog::kinds('broker') : [...self::APP_KINDS, ...RecoveryCatalog::kinds('app')];
    }

    /** @return list<string> */
    private static function sessionTables(string $host): array
    {
        return $host === 'broker' ? ['broker_sessions'] : self::SESSION_TABLES;
    }

    private static function runTable(string $host): string
    {
        return $host === 'broker' ? 'broker_recovery' : 'iot_recovery';
    }

    private static function subjectTable(string $host): string
    {
        return $host === 'broker' ? 'broker_recovery_subjects' : 'iot_recovery_subjects';
    }

    private static function auditRealm(string $host): string
    {
        return $host === 'broker' ? 'broker' : 'admin';
    }

    /** 源库进程已退出后，旧备份里仍为 active 的 MQTT 节点不得挡住核对后的新运行。 */
    private static function fenceStaleMqttNodes(Connection $transaction): void
    {
        if ($transaction->driverName() !== 'pgsql') {
            return;
        }
        $tables = $transaction->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
            ['type_mqtt_nodes']
        );
        if ($tables === []) {
            return;
        }
        $transaction->execute(
            "UPDATE type_mqtt_nodes SET state = 'fenced', actor = 'recovery', proof_ref = 'recovery_restore_source_stopped', updated_at = clock_timestamp() WHERE state = 'active'"
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function batch(Connection $transaction, string $kind, string $cursor, bool $verifiedOnly): array
    {
        if (RecoveryCatalog::handles($kind)) {
            return RecoveryCatalog::batch($transaction, $kind, $cursor, $verifiedOnly);
        }
        $query = $transaction->table(self::table($kind))->where('id', '>', $cursor)->orderBy('id')->limit(100);
        if ($verifiedOnly) {
            $query = $query->where('recovery_verified', '=', 1);
        }
        $rows = [];
        foreach ($query->get() as $row) {
            $row['_subject_id'] = $row['id'];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isolateRow(Connection $transaction, string $kind, array $row): void
    {
        if (RecoveryCatalog::handles($kind)) {
            RecoveryCatalog::isolateRow($transaction, $kind, $row);
            return;
        }
        $cursor = (string) $row['_subject_id'];
        $transaction->table(self::table($kind))->where('id', '=', $cursor)->update(['recovery_verified' => 0]);
        if ($kind === 'device') {
            $transaction->table('iot_device_connections')->where('device_id', '=', $cursor)->update([
                'status' => 'unknown', 'owner_id' => null, 'node_id' => null, 'run_id' => null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function currentRow(Connection $transaction, string $kind, string $id): ?array
    {
        if (RecoveryCatalog::handles($kind)) {
            if ($kind === 'broker_crl' || $kind === 'broker_pserial') {
                foreach (RecoveryCatalog::batch($transaction, $kind, '', false) as $row) {
                    if ((string) $row['_subject_id'] === $id) {
                        return $row;
                    }
                }
                return null;
            }
            $key = in_array($kind, ['broker_op_admin', 'broker_op_cust', 'broker_op_node'], true) ? 'operation_id' : 'id';
            $row = $transaction->table(RecoveryCatalog::table($kind))->where($key, '=', $id)->first();
            if ($row !== null) {
                $row['_subject_id'] = $id;
            }
            return $row;
        }
        $row = $transaction->table(self::table($kind))->where('id', '=', $id)->first();
        if ($row !== null) {
            $row['_subject_id'] = $id;
        }
        return $row;
    }

    private static function restoreRow(Connection $transaction, string $kind, string $id): void
    {
        if (RecoveryCatalog::handles($kind)) {
            RecoveryCatalog::restoreVerified($transaction, $kind, $id);
            return;
        }
        $transaction->table(self::table($kind))->where('id', '=', $id)->update(['recovery_verified' => 1]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fingerprint(Connection $connection, string $kind, array $row): string
    {
        if (RecoveryCatalog::handles($kind)) {
            return RecoveryCatalog::fingerprint($connection, $kind, $row);
        }
        $fields = match ($kind) {
            'admin_user', 'customer_user' => ['id', 'login', 'password_hash', 'enabled', 'version'],
            'admin_role', 'customer_role' => ['id', 'scope_id', 'name', 'enabled', 'protected', 'version'],
            'member' => ['id', 'tenant_id', 'user_id', 'name', 'enabled', 'version'],
            'device' => ['id', 'tenant_id', 'ownership_id', 'product_id', 'model_version', 'lifecycle', 'version'],
            default => ['id', 'device_id', 'ownership_id', 'secret_hash', 'status'],
        };
        $identity = [];
        foreach ($fields as $field) {
            $identity[$field] = (string) $row[$field];
        }
        if ($kind === 'admin_role' || $kind === 'customer_role') {
            $identity['permissions'] = array_values(array_map(
                static fn (array $permission): string => (string) $permission['permission'],
                $connection->table($kind === 'admin_role' ? 'admin_role_permissions' : 'customer_role_permissions')
                    ->where('role_id', '=', $row['id'])->orderBy('permission')->get()
            ));
        }
        if ($kind === 'admin_user' || $kind === 'member') {
            $bindings = $kind === 'admin_user' ? 'admin_user_roles' : 'customer_member_roles';
            $column = $kind === 'admin_user' ? 'user_id' : 'member_id';
            $query = $connection->table($bindings)->where($column, '=', $row['id'])->orderBy('role_id');
            $identity['roles'] = array_values(array_map(
                static fn (array $binding): string => (string) $binding['role_id'],
                $query->get()
            ));
        }
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function table(string $kind): string
    {
        return match ($kind) {
            'admin_user' => 'admin_users', 'customer_user' => 'customer_users', 'admin_role' => 'admin_roles',
            'customer_role' => 'customer_roles', 'member' => 'customer_members', 'device' => 'iot_devices', 'credential' => 'iot_device_credentials',
            default => RecoveryCatalog::table($kind),
        };
    }

    private static function identity(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new HttpError(422, 'recovery_identity_invalid');
        }
    }

    private static function host(string $host): void
    {
        if ($host !== 'app' && $host !== 'broker') {
            throw new HttpError(422, 'recovery_host_invalid');
        }
    }

    private static function locked(Connection $transaction, string $host): ?array
    {
        $query = $transaction->table(self::runTable($host))->where('slot', '=', 1);
        return ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
    }

    private static function requireRun(Connection $transaction, string $id, string $host): array
    {
        $run = self::locked($transaction, $host);
        if ($run === null || $run['id'] !== $id) {
            throw new HttpError(409, 'recovery_identity_conflict');
        }
        return $run;
    }
}
