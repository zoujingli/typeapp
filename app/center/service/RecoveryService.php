<?php

declare(strict_types=1);

namespace app\center\service;

use app\center\model\CenterRecovery;
use app\common\service\AuditLog;
use app\platform\mqtt\service\RecoveryCatalog;
use Type\Core\Http\HttpError;
use Type\Orm\Connection;

/**
 * 隔离恢复库的授权核对；操作前须隔离旧系统，当前授权清单来自备份之外的受控来源。
 * 离线维护必须遍历所有 Tenant，直接使用固定目录和同一连接，不借用请求级模型范围。
 */
final class RecoveryService
{
    private const APP_KINDS = ['platform_user', 'system_user', 'platform_role', 'system_role', 'device', 'credential', 'enrollment', 'application'];

    private const SESSION_TABLES = ['system_session', 'platform_session'];

    /**
     * 生成不含口令/凭据散列原值的身份摘要；来源须在整个导出及核对期间停止授权写入。
     *
     * @return array{version:int,records:list<array{kind:string,id:string,sha256:string,data?:array<string,string>}>}
     * @throws HttpError 清单超过20万主体或存在未结束的恢复核对
     */
    public static function snapshot(Connection $connection): array
    {
        self::ready($connection);
        return $connection->transaction(static function (Connection $transaction): array {
            $records = [];
            foreach (self::kinds() as $kind) {
                $cursor = '';
                do {
                    $rows = self::batch($transaction, $kind, $cursor, true);
                    foreach ($rows as $row) {
                        if (count($records) >= 200000) {
                            throw new HttpError(409, 'recovery_snapshot_limit');
                        }
                        $cursor = (string)$row['_subject_id'];
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
     * @return array<string,mixed> 不包含秘密的恢复进度
     */
    public static function begin(Connection $connection, string $id, string $actor, string $proof): array
    {
        self::identity($id);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $actor) || trim($proof) === '' || strlen($proof) > 200 || preg_match('/[\x00-\x1f\x7f]/', $proof)) {
            throw new HttpError(422, 'recovery_evidence_invalid');
        }
        return $connection->transaction(static function (Connection $transaction) use ($id, $actor, $proof): array {
            $previous = self::locked($transaction);
            if ($previous !== null) {
                if ($previous->get('id') === $id && $previous->get('actor') === $actor && $previous->get('proof') === $proof) {
                    return self::status($transaction);
                }
                if ($previous->get('phase') !== 'ready' || $previous->get('id') === $id) {
                    throw new HttpError(409, 'recovery_already_started');
                }
                CenterRecovery::query()->master()->onConnection($transaction)->where('id', '=', $previous->get('id'))->update(['slot' => null]);
            }
            if (CenterRecovery::query()->master()->onConnection($transaction)->where('id', '=', $id)->first() !== null) {
                throw new HttpError(409, 'recovery_identity_conflict');
            }
            CenterRecovery::create(['slot' => 1, 'id' => $id, 'actor' => $actor, 'proof' => $proof,
                'phase' => 'isolating', 'kind_index' => 0, 'cursor' => '', 'started_at' => time(), 'completed_at' => null,
                'review_sha256' => '', 'reviewed_at' => null]);
            AuditLog::append($transaction, null, $actor, 'recovery.started', $id, 'success', ['context' => 'operator-command'], 'platform');
            return self::status($transaction);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 每次最多100主体；原值、隔离状态和游标同事务，崩溃后以原ID继续。 */
    public static function isolate(Connection $connection, string $id): array
    {
        self::identity($id);
        return $connection->transaction(static function (Connection $transaction) use ($id): array {
            $recovery = self::requireRun($transaction, $id);
            if ($recovery->get('phase') !== 'isolating') {
                return self::status($transaction);
            }
            $kinds = self::kinds();
            $index = (int)$recovery->get('kind_index');
            $kind = $kinds[$index];
            $rows = self::batch($transaction, $kind, (string)$recovery->get('cursor'), false);
            $cursor = (string)$recovery->get('cursor');
            $subjects = 'center_recovery_subject';
            foreach ($rows as $row) {
                $cursor = (string)$row['_subject_id'];
                $transaction->table($subjects)->insert(['recovery_id' => $id, 'kind' => $kind, 'subject_id' => $cursor,
                    'sha256' => self::fingerprint($transaction, $kind, $row), 'approved' => 0]);
                self::isolateRow($transaction, $kind, $row);
            }
            if (count($rows) < 100) {
                ++$index;
                $cursor = '';
            }
            CenterRecovery::query()->master()->onConnection($transaction)->where('slot', '=', 1)->update(['kind_index' => $index, 'cursor' => $cursor,
                'phase' => $index === count($kinds) ? 'reviewing' : 'isolating']);
            return self::status($transaction);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 当前权威清单须在事故隔离后由维护者核对摘要；文件只包含不可替代身份指纹，不接受任意业务字段。
     * 一次固定清单身份后每批最多100条；缺失、变更和重复冲突均不能恢复旧权限。
     *
     * @param string $contents 精确的UTF-8 JSON文件字节，最多64MiB；摘要须经备份之外的受控渠道核对
     */
    public static function review(Connection $connection, string $id, string $contents, string $digest, int $offset): array
    {
        self::identity($id);
        if (strlen($contents) > 67108864 || !preg_match('/^[a-f0-9]{64}$/D', $digest) || !hash_equals($digest, hash('sha256', $contents))) {
            throw new HttpError(422, 'recovery_snapshot_digest');
        }
        $snapshot = json_decode($contents, true, 8);
        $kinds = self::kinds();
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
        return $connection->transaction(static function (Connection $transaction) use ($id, $snapshot, $digest, $offset): array {
            $recovery = self::requireRun($transaction, $id);
            $subjects = 'center_recovery_subject';
            if ($recovery->get('review_sha256') !== '' && $recovery->get('review_sha256') !== $digest) {
                throw new HttpError(409, 'recovery_review_conflict');
            }
            if (in_array($recovery->get('phase'), ['restoring', 'ready'], true) && $recovery->get('review_sha256') === $digest) {
                return self::status($transaction);
            }
            if ($recovery->get('phase') !== 'reviewing') {
                throw new HttpError(409, 'recovery_review_conflict');
            }
            $next = (int)$recovery->get('cursor');
            if ($offset < $next && $recovery->get('review_sha256') === $digest) {
                return self::status($transaction);
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
            CenterRecovery::query()->master()->onConnection($transaction)->where('slot', '=', 1)->update(['review_sha256' => $digest, 'cursor' => (string)$next,
                'phase' => $next === count($snapshot['records']) ? 'restoring' : 'reviewing', 'kind_index' => 0,
                'reviewed_at' => time()]);
            if ($next === count($snapshot['records'])) {
                CenterRecovery::query()->master()->onConnection($transaction)->where('slot', '=', 1)->update(['cursor' => '']);
            }
            return self::status($transaction);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 核对完成后仍有界恢复；所有未匹配主体保持隔离，旧登录会话和模拟会话不复活。 */
    public static function restore(Connection $connection, string $id): array
    {
        self::identity($id);
        return $connection->transaction(static function (Connection $transaction) use ($id): array {
            $recovery = self::requireRun($transaction, $id);
            if ($recovery->get('phase') === 'ready') {
                return self::status($transaction);
            }
            if ($recovery->get('phase') !== 'restoring') {
                throw new HttpError(409, 'recovery_not_reviewed');
            }
            $kinds = self::kinds();
            $sessions = self::SESSION_TABLES;
            $index = (int)$recovery->get('kind_index');
            if ($index < count($kinds)) {
                $kind = $kinds[$index];
                $subjects = $transaction->table('center_recovery_subject')->where('recovery_id', '=', $id)->where('kind', '=', $kind)
                    ->where('subject_id', '>', (string)$recovery->get('cursor'))->orderBy('subject_id')->limit(100)->get();
                $cursor = (string)$recovery->get('cursor');
                foreach ($subjects as $subject) {
                    $cursor = $subject['subject_id'];
                    if ((int)$subject['approved'] === 1) {
                        $current = self::currentRow($transaction, $kind, $cursor);
                        if ($current !== null && hash_equals($subject['sha256'], self::fingerprint($transaction, $kind, $current))) {
                            self::restoreRow($transaction, $kind, $cursor);
                        } else {
                            $transaction->table('center_recovery_subject')->where('recovery_id', '=', $id)->where('kind', '=', $kind)
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
                    ++$index;
                    $cursor = '';
                }
                CenterRecovery::query()->master()->onConnection($transaction)->where('slot', '=', 1)->update(['kind_index' => $index, 'cursor' => $cursor]);
            } else {
                $sessionIndex = $index - count($kinds);
                if ($sessionIndex < count($sessions)) {
                    $table = $sessions[$sessionIndex];
                    $rows = $transaction->table($table)->where('token_hash', '>', (string)$recovery->get('cursor'))->orderBy('token_hash')->limit(100)->get();
                    foreach ($rows as $session) {
                        $transaction->table($table)->where('token_hash', '=', $session['token_hash'])->delete();
                    }
                    $cursor = count($rows) < 100 ? '' : (string)$rows[count($rows) - 1]['token_hash'];
                    $nextIndex = count($rows) < 100 ? $index + 1 : $index;
                    CenterRecovery::query()->master()->onConnection($transaction)->where('slot', '=', 1)->update(['kind_index' => $nextIndex, 'cursor' => $cursor]);
                } else {
                    self::fenceStaleMqttNodes($transaction);
                    CenterRecovery::query()->master()->onConnection($transaction)->where('slot', '=', 1)->update(['phase' => 'ready', 'completed_at' => time()]);
                    AuditLog::append($transaction, null, (string)$recovery->get('actor'), 'recovery.ready', $id, 'success', ['context' => 'operator-command'], 'platform');
                }
            }
            return self::status($transaction);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 业务进程启动门；数据库或恢复状态不可确认时拒绝启动，不将异常当作未进入恢复。
     *
     * @throws HttpError 恢复核对尚未结束
     */
    public static function ready(Connection $connection): void
    {
        $run = CenterRecovery::query()->master()->onConnection($connection)->where('slot', '=', 1)->first();
        if ($run !== null && $run->get('phase') !== 'ready') {
            throw new HttpError(503, 'recovery_isolated');
        }
    }

    /** @return array<string,mixed> 有界进度及未匹配数，不返回主体指纹或秘密。 */
    public static function status(Connection $connection): array
    {
        $run = CenterRecovery::query()->master()->onConnection($connection)->where('slot', '=', 1)->first();
        if ($run === null) {
            return ['state' => 'none', 'host' => 'app'];
        }
        $counts = [];
        foreach (self::kinds() as $kind) {
            $counts[$kind] = ['total' => (int)$connection->table('center_recovery_subject')->where('recovery_id', '=', $run->get('id'))->where('kind', '=', $kind)->aggregate('COUNT'),
                'approved' => (int)$connection->table('center_recovery_subject')->where('recovery_id', '=', $run->get('id'))->where('kind', '=', $kind)->where('approved', '=', 1)->aggregate('COUNT')];
        }
        return ['id' => $run->get('id'), 'state' => $run->get('phase'), 'host' => 'app', 'kind_index' => (int)$run->get('kind_index'), 'cursor' => $run->get('cursor'),
            'started_at' => (int)$run->get('started_at'), 'reviewed_at' => $run->get('reviewed_at') === null ? null : (int)$run->get('reviewed_at'),
            'completed_at' => $run->get('completed_at') === null ? null : (int)$run->get('completed_at'), 'subjects' => $counts];
    }

    /** @return list<string> 统一应用身份、设备和 MQTT 授权主体。 */
    private static function kinds(): array
    {
        return [...self::APP_KINDS, ...RecoveryCatalog::kinds()];
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
        $cursor = (string)$row['_subject_id'];
        $transaction->table(self::table($kind))->where('id', '=', $cursor)->update(['recovery_verified' => 0]);
        if ($kind === 'device') {
            $transaction->table('center_device_connection')->where('device_id', '=', $cursor)->update([
                'status' => 'unknown', 'owner_id' => null, 'node_id' => null, 'run_id' => null,
            ]);
        }
    }

    /**
     * @return null|array<string, mixed>
     */
    private static function currentRow(Connection $transaction, string $kind, string $id): ?array
    {
        if (RecoveryCatalog::handles($kind)) {
            if ($kind === 'broker_crl' || $kind === 'broker_pserial') {
                foreach (RecoveryCatalog::batch($transaction, $kind, '', false) as $row) {
                    if ((string)$row['_subject_id'] === $id) {
                        return $row;
                    }
                }
                return null;
            }
            $key = in_array($kind, ['broker_op_admin', 'broker_op_cust'], true) ? 'operation_id' : 'id';
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
            'platform_user' => ['id', 'login', 'password_hash', 'enabled', 'version'],
            'system_user' => ['id', 'tenant_id', 'login', 'password_hash', 'enabled', 'version'],
            'platform_role' => ['id', 'scope_id', 'name', 'enabled', 'protected', 'version'],
            'system_role' => ['id', 'tenant_id', 'name', 'enabled', 'protected', 'version'],
            'device' => ['id', 'tenant_id', 'ownership_id', 'product_id', 'lifecycle', 'version'],
            'enrollment' => ['id', 'tenant_id', 'name', 'capacity', 'registered_count', 'register_expires_at', 'enabled', 'secret_hash', 'version'],
            'application' => ['id', 'tenant_id', 'name', 'enabled', 'credential_id', 'key_version', 'access_version', 'version'],
            default => ['id', 'device_id', 'ownership_id', 'secret_hash', 'status'],
        };
        $identity = [];
        foreach ($fields as $field) {
            $identity[$field] = (string)$row[$field];
        }
        if (isset($row['tenant_id'])) {
            $tenant = $connection->table('platform_tenant')->select(['id', 'subdomain', 'enabled', 'version'])
                ->where('id', '=', $row['tenant_id'])->first();
            if ($tenant === null) {
                throw new HttpError(409, 'recovery_tenant_missing');
            }
            $identity['tenant'] = array_map(static fn (mixed $value): string => (string)$value, $tenant);
        }
        if ($kind === 'platform_role' || $kind === 'system_role') {
            $realm = $kind === 'platform_role' ? 'platform' : 'system';
            $query = $connection->table($realm . '_role_permission')->where('role_id', '=', $row['id']);
            if ($realm === 'system') {
                $query = $query->where('tenant_id', '=', $row['tenant_id']);
            }
            $identity['permissions'] = array_values(array_map(
                static fn (array $permission): string => (string)$permission['permission'],
                $query->orderBy('permission')->get()
            ));
            // 菜单停用及祖先目录同样决定能力；超管也不能复活已撤销的公共菜单。
            $identity['menus'] = array_map(static fn (array $menu): array => array_map(
                static fn (mixed $value): string => (string)$value,
                $menu
            ), $connection->table($realm . '_menu')->select(['id', 'parent_id', 'permission', 'enabled'])->orderBy('id')->get());
        }
        if ($kind === 'platform_user' || $kind === 'system_user') {
            $realm = $kind === 'platform_user' ? 'platform' : 'system';
            $query = $connection->table($realm . '_user_role')->where('user_id', '=', $row['id'])->orderBy('role_id');
            if ($realm === 'system') {
                $query = $query->where('tenant_id', '=', $row['tenant_id']);
            }
            $identity['roles'] = array_values(array_map(
                static fn (array $binding): string => (string)$binding['role_id'],
                $query->get()
            ));
        }
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function table(string $kind): string
    {
        return match ($kind) {
            'platform_user' => 'platform_user', 'system_user' => 'system_user', 'platform_role' => 'platform_role',
            'system_role' => 'system_role', 'device' => 'center_device', 'credential' => 'center_device_credential',
            'enrollment' => 'center_device_enrollment', 'application' => 'center_application',
            default => RecoveryCatalog::table($kind),
        };
    }

    private static function identity(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new HttpError(422, 'recovery_identity_invalid');
        }
    }

    private static function locked(Connection $transaction): ?CenterRecovery
    {
        $query = CenterRecovery::query()->master()->onConnection($transaction)->where('slot', '=', 1);
        return ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
    }

    private static function requireRun(Connection $transaction, string $id): CenterRecovery
    {
        $run = self::locked($transaction);
        if ($run === null || $run->get('id') !== $id) {
            throw new HttpError(409, 'recovery_identity_conflict');
        }
        return $run;
    }
}
