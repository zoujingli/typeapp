<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Core\Http\HttpError;
use Type\Mqtt\AccessIdentity;
use Type\Mqtt\CertificateRevocationList;
use Type\Mqtt\ConnectPacket;
use Type\Orm\Connection;
use Type\Validate\Field;
use Type\Validate\Input;

/**
 * 接入主体、凭据代次、受信 CA、客户端证书绑定、签名 CRL 与 Topic/QoS 的版本化发布；Broker 核心只消费当前事实，不读人员表。
 *
 * 保存成功不等于集群生效。收紧权限后新认证立即按新事实拒绝，旧连接由撤权意图在约 5 秒内停止收发并断开。
 * 正常换证可给旧证重叠窗口（默认且最长 24 小时，可为零，且不超过任一张证书 not_after）；吊销、停用、收权和到期无宽限。
 * 握手 CA 文件由节点心跳按当前受信 CA 公钥重写；Broker 按文件更新重载 SSL_CTX，已有连接保持到吊销或到期。
 * 外部 CRL 须校验签名并持久接纳后才进入 5 秒执行；已接纳序列号只增不减，平台直接吊销不经 CA。
 */
final class AccessService
{
    /** 正常换证重叠默认且最长 24 小时。 */
    private const CERTIFICATE_OVERLAP_MAX = 86400;

    /** 受控 HTTPS CRL 默认刷新间隔。 */
    private const CRL_FETCH_DEFAULT = 300;

    /**
     * 独立节点首次启动写入环境凭据，作为版本 1 的已生效快照。
     */
    public static function ensureBootstrap(Connection $connection, string $username, string $password, string $topicPrefix): void
    {
        if ($connection->table('broker_access_principals')->whereNull('tenant_id')->first() !== null) {
            return;
        }
        $connection->transaction(static function (Connection $transaction) use ($username, $password, $topicPrefix): void {
            if ($transaction->table('broker_access_principals')->whereNull('tenant_id')->first() !== null) {
                return;
            }
            $now = time();
            $principalId = bin2hex(random_bytes(16));
            $credentialId = bin2hex(random_bytes(16));
            $grantId = bin2hex(random_bytes(16));
            $revisionId = bin2hex(random_bytes(16));
            $transaction->table('broker_access_principals')->insert([
                'id' => $principalId, 'tenant_id' => null, 'name' => $username, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $transaction->table('broker_access_credentials')->insert([
                'id' => $credentialId, 'principal_id' => $principalId, 'login' => $username, 'secret_hash' => hash('sha256', $password),
                'credential_version' => 1, 'status' => 'active', 'created_at' => $now, 'revoked_at' => null,
            ]);
            $transaction->table('broker_access_grants')->insert([
                'id' => $grantId, 'principal_id' => $principalId, 'topic' => $topicPrefix, 'publish' => 1, 'subscribe' => 1, 'max_qos' => 2,
            ]);
            $snapshot = self::snapshot($transaction, null);
            $transaction->table('broker_access_revisions')->insert([
                'id' => $revisionId, 'version' => 1, 'actor_id' => 'bootstrap', 'actor_realm' => 'broker', 'tenant_id' => null,
                'tightening' => 0, 'status' => 'effective', 'snapshot_json' => $snapshot, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 集群共用单调版本；租户只过滤主体，不能各自从 1 重新编号。 */
    public static function currentVersion(Connection $connection, ?string $tenantId = null): int
    {
        $row = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        return $row === null ? 0 : (int) $row['version'];
    }

    /**
     * MQTT 节点上报已加载版本；收紧未执行的节点不能把发布标成完成。
     */
    public static function observeNode(Connection $connection, string $nodeId, int $appliedVersion, bool $isolated): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1 || $appliedVersion < 0) {
            throw new \InvalidArgumentException('broker_access_node_invalid');
        }
        $revision = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($revision === null) {
            return;
        }
        $connection->transaction(static function (Connection $transaction) use ($revision, $nodeId, $appliedVersion, $isolated): void {
            $state = (int) $revision['version'] <= $appliedVersion ? 'applied' : ($isolated ? 'isolated' : 'pending');
            $existing = $transaction->table('broker_access_node_states')->where('revision_id', '=', $revision['id'])->where('node_id', '=', $nodeId)->first();
            $now = time();
            if ($existing === null) {
                $transaction->table('broker_access_node_states')->insert([
                    'revision_id' => $revision['id'], 'node_id' => $nodeId, 'applied_version' => $appliedVersion, 'state' => $state, 'updated_at' => $now,
                ]);
            } else {
                $transaction->table('broker_access_node_states')->where('revision_id', '=', $revision['id'])->where('node_id', '=', $nodeId)->update([
                    'applied_version' => $appliedVersion, 'state' => $state, 'updated_at' => $now,
                ]);
            }
            self::refreshStatus($transaction, (string) $revision['id']);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /**
     * 用 CONNECT 用户名与口令散列核对当前活动凭据；秘密只参与比较，不形成证书身份。
     *
     * @return AccessIdentity|null 登录名对应的当前活动凭据；未知、停用或不匹配返回 null。
     */
    public static function authenticate(Connection $connection, ConnectPacket $connect): ?AccessIdentity
    {
        if ($connect->username === null || $connect->password === null) {
            return null;
        }
        $row = $connection->table('broker_access_credentials')->where('login', '=', $connect->username)->where('status', '=', 'active')->first();
        if ($row !== null) {
            if ((int) ($row['recovery_verified'] ?? 1) !== 1 || !hash_equals((string) $row['secret_hash'], hash('sha256', $connect->password))) {
                return null;
            }
            $principal = $connection->table('broker_access_principals')->where('id', '=', $row['principal_id'])->where('enabled', '=', 1)->first();
            if ($principal === null || (int) ($principal['recovery_verified'] ?? 1) !== 1) {
                return null;
            }
            return new AccessIdentity((string) $principal['id'], (string) $row['id'], (int) $row['credential_version'], 'connect');
        }
        return DebugService::authenticate($connection, $connect);
    }

    /** 已通过握手校验的指纹映射到当前活动证书；不接受未登记、已吊销、过期或重叠已结束的项。 */
    public static function authenticateCertificate(Connection $connection, string $fingerprint): ?AccessIdentity
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return null;
        }
        $row = $connection->table('broker_access_certificates')->where('fingerprint', '=', $fingerprint)->where('status', '=', 'active')->first();
        if ($row === null || (int) ($row['recovery_verified'] ?? 1) !== 1 || !self::certificateAllowed($connection, $row)) {
            return null;
        }
        $principal = $connection->table('broker_access_principals')->where('id', '=', $row['principal_id'])->where('enabled', '=', 1)->first();
        if ($principal === null || (int) ($principal['recovery_verified'] ?? 1) !== 1) {
            return null;
        }
        return new AccessIdentity((string) $principal['id'], (string) $row['id'], (int) $row['certificate_version'], 'mtls');
    }

    /** 证书身份仍有效：主体启用、证书活动、代次一致，且未过重叠截止或 not_after。设备身份前缀 device: 只用于核对，不写入证书表。 */
    public static function certificateCurrent(Connection $connection, AccessIdentity $identity): bool
    {
        if ($identity->authenticationMethod !== 'mtls') {
            return false;
        }
        $principalId = str_starts_with($identity->principalId, 'device:') ? substr($identity->principalId, 7) : $identity->principalId;
        $row = $connection->table('broker_access_certificates')->where('id', '=', $identity->credentialId)
            ->where('principal_id', '=', $principalId)->where('status', '=', 'active')->first();
        return $row !== null && (int) $row['certificate_version'] === $identity->credentialVersion && self::certificateAllowed($connection, $row);
    }

    /** 每次发布/订阅按当前授权重新判断，不沿用握手时的旧快照。 */
    public static function authorize(Connection $connection, AccessIdentity $identity, string $topic, string $action, int $qos): bool
    {
        if ($identity->authenticationMethod === 'debug') {
            return DebugService::authorize($connection, $identity, $topic, $action, $qos);
        }
        $principal = $connection->table('broker_access_principals')->where('id', '=', $identity->principalId)->where('enabled', '=', 1)->first();
        if ($principal === null || (int) ($principal['recovery_verified'] ?? 1) !== 1) {
            return false;
        }
        if ($identity->authenticationMethod === 'mtls') {
            if (!self::certificateCurrent($connection, $identity)) {
                return false;
            }
        } else {
            $credential = $connection->table('broker_access_credentials')->where('id', '=', $identity->credentialId)
                ->where('principal_id', '=', $identity->principalId)->where('status', '=', 'active')->first();
            if ($credential === null || (int) $credential['credential_version'] !== $identity->credentialVersion) {
                return false;
            }
        }
        foreach ($connection->table('broker_access_grants')->where('principal_id', '=', $identity->principalId)->get() as $grant) {
            if (!self::topicMatches((string) $grant['topic'], $topic)) {
                continue;
            }
            if ($action === 'publish' && (int) $grant['publish'] !== 1) {
                continue;
            }
            if ($action === 'subscribe' && (int) $grant['subscribe'] !== 1) {
                continue;
            }
            if ($qos < 0 || $qos > (int) $grant['max_qos']) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** 设备无自定义授权时沿用产品 Topic；有自定义行后必须逐条匹配。 */
    public static function authorizeDevice(Connection $connection, string $deviceId, string $topic, string $action, int $qos, array $defaults): bool
    {
        $grants = $connection->table('broker_access_grants')->where('principal_id', '=', $deviceId)->get();
        if ($grants === []) {
            $expected = $action === 'publish' ? ($defaults['publish'] ?? '') : ($defaults['subscribe'] ?? '');
            return $topic === $expected && in_array($action, ['publish', 'subscribe'], true) && $qos >= 0 && $qos <= 2;
        }
        foreach ($grants as $grant) {
            if (!self::topicMatches((string) $grant['topic'], $topic)) {
                continue;
            }
            if ($action === 'publish' && (int) $grant['publish'] !== 1) {
                continue;
            }
            if ($action === 'subscribe' && (int) $grant['subscribe'] !== 1) {
                continue;
            }
            if ($qos < 0 || $qos > (int) $grant['max_qos']) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** @return array<string, mixed>|null 尚未完成的一项撤权意图。 */
    public static function nextInvalidation(Connection $connection): ?array
    {
        self::expireCertificates($connection);
        self::expireCrls($connection);
        DebugService::expire($connection);
        $pending = $connection->query('SELECT id, client_id, principal, actor, access_identity FROM broker_access_invalidations WHERE completed_at IS NULL ORDER BY requested_at, id LIMIT 1');
        if ($pending === []) {
            return null;
        }
        $identity = json_decode((string) $pending[0]['access_identity'], true, 8, JSON_THROW_ON_ERROR);
        return ['id' => (string) $pending[0]['id'], 'client_id' => (string) $pending[0]['client_id'],
            'principal' => (string) $pending[0]['principal'], 'actor' => (string) $pending[0]['actor'],
            'access_identity' => is_array($identity) ? $identity : null];
    }

    /** Broker 已取得旧会话终止证明后幂等记下完成时间，并刷新当前版本状态。 */
    public static function invalidationCompleted(Connection $connection, string $id, string $nodeId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new \RuntimeException('broker_access_invalidation_invalid');
        }
        $connection->transaction(static function (Connection $transaction) use ($id, $nodeId): void {
            $query = $transaction->table('broker_access_invalidations')->where('id', '=', $id);
            $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($row !== null && $row['completed_at'] === null) {
                $query->update(['completed_at' => time(), 'node_id' => $nodeId]);
            }
            $revision = $transaction->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
            if ($revision !== null) {
                self::refreshStatus($transaction, (string) $revision['id']);
            }
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /**
     * 当前范围的接入主体列表或详情；不含口令散列，证书只返回指纹与状态。
     *
     * @return array<string, mixed> 列表或详情，不含秘密。
     * @throws HttpError 指定主体不存在。
     */
    public static function principals(Connection $connection, ?string $tenantId, string $id, int $page, int $perPage, string $name = ''): array
    {
        $query = $connection->table('broker_access_principals');
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        if ($id !== '') {
            $row = $query->where('id', '=', $id)->first();
            if ($row === null) {
                throw new HttpError(404, 'broker_access_not_found');
            }
            return ['item' => self::project($connection, $row)];
        }
        if ($name !== '') {
            $query = $query->where('name', 'LIKE', '%' . $name . '%');
        }
        $result = $query->orderBy('created_at', 'DESC')->orderBy('id')->paginate($page, $perPage);
        $items = [];
        foreach ($result->items() as $row) {
            $items[] = self::project($connection, $row);
        }
        $latest = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        $status = $latest === null ? '' : (string) $latest['status'];
        return ['items' => $items, 'total' => $result->total(), 'page' => $result->number(), 'per_page' => $result->perPage(),
            'current_version' => self::currentVersion($connection, $tenantId),
            'publish_paused' => in_array($status, ['pending', 'partial'], true)];
    }

    /**
     * 当前范围已登记的受信 CA；只返回指纹与主体，不含 PEM 与私钥。
     *
     * @return array<string, mixed> 列表或详情，并附带当前版本是否暂停后续发布。
     * @throws HttpError 指定 CA 不存在。
     */
    public static function cas(Connection $connection, ?string $tenantId, string $id = ''): array
    {
        $query = $connection->table('broker_access_cas');
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        if ($id !== '') {
            $row = $query->where('id', '=', $id)->first();
            if ($row === null) {
                throw new HttpError(404, 'broker_access_not_found');
            }
            return ['item' => ['id' => $row['id'], 'fingerprint' => $row['fingerprint'], 'subject' => $row['subject'],
                'recovery_verified' => (int) ($row['recovery_verified'] ?? 1),
                'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at'],
                'crl' => self::projectCrl($connection, (string) $row['id'])]];
        }
        $items = [];
        foreach ($query->orderBy('created_at', 'DESC')->orderBy('id')->get() as $row) {
            $items[] = ['id' => $row['id'], 'fingerprint' => $row['fingerprint'], 'subject' => $row['subject'],
                'recovery_verified' => (int) ($row['recovery_verified'] ?? 1),
                'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at'],
                'crl' => self::projectCrl($connection, (string) $row['id'])];
        }
        $latest = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        $status = $latest === null ? '' : (string) $latest['status'];
        return ['items' => $items, 'current_version' => self::currentVersion($connection, $tenantId),
            'publish_paused' => in_array($status, ['pending', 'partial'], true)];
    }

    /**
     * 当前全部受信 CA 公钥按稳定顺序拼接成握手包；没有登记 CA 时返回空串，避免覆盖节点启动文件。
     */
    public static function handshakeBundle(Connection $connection): string
    {
        $chunks = [];
        foreach ($connection->table('broker_access_cas')->orderBy('id')->get() as $row) {
            if ((int) ($row['recovery_verified'] ?? 1) !== 1) {
                continue;
            }
            $pem = trim((string) $row['pem']);
            if ($pem !== '') {
                $chunks[] = $pem;
            }
        }
        return $chunks === [] ? '' : implode("\n", $chunks) . "\n";
    }

    /**
     * 把当前受信 CA 公钥原子写入节点握手文件；内容未变则不改 mtime。不把 PEM 写入日志。
     */
    public static function syncHandshakeCa(Connection $connection, string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || strlen($path) > 4096) {
            return;
        }
        $bundle = self::handshakeBundle($connection);
        if ($bundle === '') {
            return;
        }
        $current = is_file($path) && is_readable($path) ? (string) file_get_contents($path) : '';
        if (hash('sha256', $current) === hash('sha256', $bundle)) {
            return;
        }
        $directory = dirname($path);
        if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
            return;
        }
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, $bundle) === false) {
            return;
        }
        chmod($temporary, 0644);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    /**
     * 换证预览：不写版本。返回新旧指纹、重叠截止和是否会立即收紧；不含 PEM。
     *
     * @param array<string, mixed> $input 新证书公钥与可选 overlap_seconds。
     * @return array<string, mixed>
     * @throws HttpError 主体不存在、PEM 非法、未知 CA 或重叠秒数越界。
     */
    public static function previewCertificateRotation(Connection $connection, ?string $tenantId, string $principalId, array $input): array
    {
        if ($principalId === '' || preg_match('/^[a-f0-9]{32}$/D', $principalId) !== 1) {
            throw new HttpError(404, 'broker_access_not_found');
        }
        $query = $connection->table('broker_access_principals')->where('id', '=', $principalId);
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        $principal = $query->first();
        if ($principal === null) {
            throw new HttpError(404, 'broker_access_not_found');
        }
        $parsed = self::parsePublicCertificate((string) ($input['pem'] ?? ''), false);
        if (!self::issuedByRegisteredCa($connection, $tenantId, $parsed['pem'])) {
            throw new HttpError(422, 'broker_access_unknown_ca');
        }
        $now = time();
        $retiring = [];
        foreach ($connection->table('broker_access_certificates')->where('principal_id', '=', $principalId)->where('status', '=', 'active')->orderBy('created_at')->orderBy('id')->get() as $row) {
            if ((string) $row['fingerprint'] === $parsed['fingerprint'] || !self::certificateAcceptable($row)) {
                continue;
            }
            $retiring[] = $row;
        }
        $seconds = self::overlapSeconds($input, $retiring !== []);
        $items = [];
        $tightening = false;
        $overlapUntil = 0;
        foreach ($retiring as $row) {
            $until = $seconds === 0 ? $now : min($now + $seconds, (int) $row['not_after'], $parsed['not_after']);
            $action = $seconds === 0 || $until <= $now ? 'revoke' : 'overlap';
            if ($action === 'revoke') {
                $tightening = true;
            } elseif ($overlapUntil === 0 || $until < $overlapUntil) {
                $overlapUntil = $until;
            }
            $items[] = ['id' => $row['id'], 'fingerprint' => $row['fingerprint'], 'subject' => $row['subject'],
                'not_after' => (int) $row['not_after'], 'overlap_until' => $action === 'revoke' ? 0 : $until, 'action' => $action];
        }
        return ['principal_id' => $principalId, 'name' => $principal['name'], 'current_version' => self::currentVersion($connection, $tenantId),
            'overlap_seconds' => $seconds, 'overlap_until' => $overlapUntil,
            'incoming' => ['fingerprint' => $parsed['fingerprint'], 'subject' => $parsed['subject'], 'not_after' => $parsed['not_after']],
            'retiring' => $items, 'tightening' => $tightening];
    }

    /**
     * 在乐观版本上写入主体、凭据、授权与证书绑定；收紧时排队撤权，节点上报后才标生效。
     *
     * @param array<string, mixed> $input 主体、凭据、授权、证书与乐观版本。
     * @return array<string, mixed> 新发布版本及节点生效摘要。
     * @throws HttpError 版本冲突、上一版本未完成、内容未变化或字段非法。
     */
    public static function publish(Connection $connection, ?string $tenantId, string $actorId, string $actorRealm, array $input, string $clientIdHint = ''): array
    {
        $expected = (int) ($input['expected_version'] ?? -1);
        $current = self::currentVersion($connection);
        if ($expected !== $current) {
            throw new HttpError(409, 'broker_access_version_conflict');
        }
        $blocking = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($blocking !== null && in_array((string) $blocking['status'], ['pending', 'partial'], true)) {
            throw new HttpError(409, 'broker_access_publish_paused');
        }
        return $connection->transaction(static function (Connection $transaction) use ($tenantId, $actorId, $actorRealm, $input, $current, $clientIdHint): array {
            $before = self::snapshot($transaction, $tenantId);
            self::applyChange($transaction, $tenantId, $input);
            $after = self::snapshot($transaction, $tenantId);
            if ($before === $after) {
                throw new HttpError(409, 'broker_access_unchanged');
            }
            $tightening = self::isTightening($before, $after);
            $now = time();
            $revisionId = bin2hex(random_bytes(16));
            $version = $current + 1;
            $transaction->table('broker_access_revisions')->insert([
                'id' => $revisionId, 'version' => $version, 'actor_id' => $actorId, 'actor_realm' => $actorRealm, 'tenant_id' => $tenantId,
                'tightening' => $tightening ? 1 : 0, 'status' => 'pending', 'snapshot_json' => $after, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($tightening) {
                self::enqueueInvalidations($transaction, $before, $after, $actorId, $clientIdHint);
            }
            self::refreshStatus($transaction, $revisionId);
            return self::revision($transaction, $revisionId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 在乐观版本上登记或吊销受信 CA；吊销前仍被活动客户端证书使用则拒绝。
     *
     * @param array<string, mixed> $input 受信 CA 公钥与乐观版本。
     * @return array<string, mixed> 新发布版本及节点生效摘要。
     * @throws HttpError 版本冲突、上一版本未完成、CA 仍被使用或 PEM 非法。
     */
    public static function publishCa(Connection $connection, ?string $tenantId, string $actorId, string $actorRealm, array $input): array
    {
        $expected = (int) ($input['expected_version'] ?? -1);
        $current = self::currentVersion($connection);
        if ($expected !== $current) {
            throw new HttpError(409, 'broker_access_version_conflict');
        }
        $blocking = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($blocking !== null && in_array((string) $blocking['status'], ['pending', 'partial'], true)) {
            throw new HttpError(409, 'broker_access_publish_paused');
        }
        return $connection->transaction(static function (Connection $transaction) use ($tenantId, $actorId, $actorRealm, $input, $current): array {
            $before = self::snapshot($transaction, $tenantId);
            self::applyCa($transaction, $tenantId, $input);
            $after = self::snapshot($transaction, $tenantId);
            if ($before === $after) {
                throw new HttpError(409, 'broker_access_unchanged');
            }
            $tightening = self::isTightening($before, $after);
            $now = time();
            $revisionId = bin2hex(random_bytes(16));
            $version = $current + 1;
            $transaction->table('broker_access_revisions')->insert([
                'id' => $revisionId, 'version' => $version, 'actor_id' => $actorId, 'actor_realm' => $actorRealm, 'tenant_id' => $tenantId,
                'tightening' => $tightening ? 1 : 0, 'status' => 'pending', 'snapshot_json' => $after, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($tightening) {
                self::enqueueInvalidations($transaction, $before, $after, $actorId, '');
            }
            self::refreshStatus($transaction, $revisionId);
            return self::revision($transaction, $revisionId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 导入签名 CRL 或登记受控 HTTPS 源；校验通过并持久接纳后才排队 5 秒撤权。
     *
     * @param array<string, mixed> $input CA 标识、可选 PEM/URL 与乐观版本。
     * @return array<string, mixed>
     * @throws HttpError 版本冲突、签名无效、旧列表回退或 URL 非法。
     */
    public static function publishCrl(Connection $connection, ?string $tenantId, string $actorId, string $actorRealm, array $input): array
    {
        $expected = (int) ($input['expected_version'] ?? -1);
        $current = self::currentVersion($connection);
        if ($expected !== $current) {
            throw new HttpError(409, 'broker_access_version_conflict');
        }
        $blocking = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($blocking !== null && in_array((string) $blocking['status'], ['pending', 'partial'], true)) {
            throw new HttpError(409, 'broker_access_publish_paused');
        }
        return $connection->transaction(static function (Connection $transaction) use ($tenantId, $actorId, $actorRealm, $input, $current): array {
            $before = self::snapshot($transaction, $tenantId);
            self::applyCrl($transaction, $tenantId, $input, 'import');
            $after = self::snapshot($transaction, $tenantId);
            if ($before === $after) {
                throw new HttpError(409, 'broker_access_unchanged');
            }
            $tightening = self::isTightening($before, $after);
            $now = time();
            $revisionId = bin2hex(random_bytes(16));
            $version = $current + 1;
            $transaction->table('broker_access_revisions')->insert([
                'id' => $revisionId, 'version' => $version, 'actor_id' => $actorId, 'actor_realm' => $actorRealm, 'tenant_id' => $tenantId,
                'tightening' => $tightening ? 1 : 0, 'status' => 'pending', 'snapshot_json' => $after, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($tightening) {
                self::enqueueInvalidations($transaction, $before, $after, $actorId, '');
            }
            self::refreshStatus($transaction, $revisionId);
            return self::revision($transaction, $revisionId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 平台直接吊销证书序列号，不经 CA 签名，已接纳项只增不减。
     *
     * @param array<string, mixed> $input 规范化序列号与乐观版本。
     * @return array<string, mixed>
     * @throws HttpError 版本冲突或序列号非法。
     */
    public static function publishPlatformSerial(Connection $connection, ?string $tenantId, string $actorId, string $actorRealm, array $input): array
    {
        $expected = (int) ($input['expected_version'] ?? -1);
        $current = self::currentVersion($connection);
        if ($expected !== $current) {
            throw new HttpError(409, 'broker_access_version_conflict');
        }
        $blocking = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($blocking !== null && in_array((string) $blocking['status'], ['pending', 'partial'], true)) {
            throw new HttpError(409, 'broker_access_publish_paused');
        }
        return $connection->transaction(static function (Connection $transaction) use ($tenantId, $actorId, $actorRealm, $input, $current): array {
            $before = self::snapshot($transaction, $tenantId);
            self::applyPlatformSerial($transaction, $tenantId, $actorId, (string) $input['serial']);
            $after = self::snapshot($transaction, $tenantId);
            if ($before === $after) {
                throw new HttpError(409, 'broker_access_unchanged');
            }
            $now = time();
            $revisionId = bin2hex(random_bytes(16));
            $version = $current + 1;
            $transaction->table('broker_access_revisions')->insert([
                'id' => $revisionId, 'version' => $version, 'actor_id' => $actorId, 'actor_realm' => $actorRealm, 'tenant_id' => $tenantId,
                'tightening' => 1, 'status' => 'pending', 'snapshot_json' => $after, 'created_at' => $now, 'updated_at' => $now,
            ]);
            self::enqueueInvalidations($transaction, $before, $after, $actorId, '');
            self::refreshStatus($transaction, $revisionId);
            return self::revision($transaction, $revisionId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 当前范围的平台吊销序列号；不含 PEM。
     *
     * @return array<string, mixed>
     */
    public static function platformSerials(Connection $connection, ?string $tenantId): array
    {
        $items = [];
        foreach ($connection->table('broker_access_platform_serials')->where('tenant_key', '=', self::tenantKey($tenantId))->orderBy('serial')->get() as $row) {
            $items[] = ['serial' => $row['serial'], 'created_at' => (int) $row['created_at']];
        }
        $latest = $connection->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        $status = $latest === null ? '' : (string) $latest['status'];
        return ['items' => $items, 'current_version' => self::currentVersion($connection, $tenantId),
            'publish_paused' => in_array($status, ['pending', 'partial'], true)];
    }

    /**
     * 到期需要拉取的受控 HTTPS CRL 源；只给节点进程，不含私钥。
     *
     * @return list<array{ca_id:string,url:string,ca_pem:string,interval:int}>
     */
    public static function dueCrlFetches(Connection $connection): array
    {
        $now = time();
        $jobs = [];
        foreach ($connection->table('broker_access_crls')->where('url', '!=', '')->get() as $row) {
            if ((int) $row['fetch_due_at'] > $now) {
                continue;
            }
            $ca = $connection->table('broker_access_cas')->where('id', '=', $row['ca_id'])->first();
            if ($ca === null || (string) $row['url'] === '') {
                continue;
            }
            $jobs[] = ['ca_id' => (string) $row['ca_id'], 'url' => (string) $row['url'], 'ca_pem' => (string) $ca['pem'],
                'interval' => (int) $row['fetch_interval']];
        }
        return $jobs;
    }

    /** 登记下次拉取时间，避免心跳重复 fork。 */
    public static function scheduleCrlFetch(Connection $connection, string $caId, int $interval): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $caId) !== 1) {
            return;
        }
        $seconds = $interval >= 1 && $interval <= 3600 ? $interval : self::CRL_FETCH_DEFAULT;
        $connection->table('broker_access_crls')->where('ca_id', '=', $caId)->update([
            'fetch_due_at' => time() + $seconds, 'updated_at' => time(),
        ]);
    }

    /**
     * 节点把已下载 PEM 持久接纳；旧列表回退或签名失败只更新获取状态。
     */
    public static function acceptFetchedCrl(Connection $connection, string $caId, string $pem): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $caId) !== 1) {
            return;
        }
        $connection->transaction(static function (Connection $transaction) use ($caId, $pem): void {
            $before = self::snapshot($transaction, self::crlTenant($transaction, $caId));
            try {
                self::applyCrl($transaction, self::crlTenant($transaction, $caId), ['id' => $caId, 'pem' => $pem, 'url' => '', 'fetch_interval' => 0], 'https');
            } catch (HttpError $error) {
                $code = $error->errorCode();
                $reason = $code === 'broker_access_crl_stale' ? 'stale' : ($code === 'broker_access_crl_invalid' ? 'invalid' : 'rejected');
                self::touchCrlFetch($transaction, $caId, 'failed', $reason);
                return;
            }
            $after = self::snapshot($transaction, self::crlTenant($transaction, $caId));
            self::touchCrlFetch($transaction, $caId, 'success', '');
            if (self::isTightening($before, $after)) {
                self::enqueueInvalidations($transaction, $before, $after, 'crl-https', '');
            }
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /** 拉取失败且旧 CRL 仍有效时继续使用；只更新获取状态。 */
    public static function recordCrlFetchFailure(Connection $connection, string $caId, string $reason): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $caId) !== 1) {
            return;
        }
        $error = preg_match('/^[a-z]{1,32}$/D', $reason) === 1 ? $reason : 'network';
        $connection->table('broker_access_crls')->where('ca_id', '=', $caId)->update([
            'fetch_status' => 'failed', 'fetch_error' => $error, 'fetched_at' => time(), 'updated_at' => time(),
        ]);
    }

    /**
     * 同一版本显式重试；不增加新版本，也不把失败节点当成已生效。
     *
     * @return array<string, mixed> 同一版本的最新节点生效摘要。
     * @throws HttpError 版本不存在、不是当前版本或已生效。
     */
    public static function retry(Connection $connection, string $revisionId, ?string $tenantId = null): array
    {
        return $connection->transaction(static function (Connection $transaction) use ($revisionId, $tenantId): array {
            $query = $transaction->table('broker_access_revisions')->where('id', '=', $revisionId);
            $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($row === null || !self::sameTenant($row['tenant_id'] ?? null, $tenantId)) {
                throw new HttpError(404, 'broker_access_not_found');
            }
            if (!in_array((string) $row['status'], ['pending', 'partial', 'failed'], true)) {
                throw new HttpError(409, 'broker_access_retry_unavailable');
            }
            $latest = $transaction->table('broker_access_revisions')->orderBy('version', 'DESC')->limit(1)->first();
            if ($latest === null || (string) $latest['id'] !== $revisionId) {
                throw new HttpError(409, 'broker_access_not_current');
            }
            $query->update(['updated_at' => time()]);
            self::refreshStatus($transaction, $revisionId);
            return self::revision($transaction, $revisionId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 非收紧发布可回退到上一快照并形成新版本；收紧失败不能用回退恢复旧授权。
     *
     * @return array<string, mixed> 新发布的回退版本摘要。
     * @throws HttpError 收紧版本、已生效或没有上一快照。
     */
    public static function rollback(Connection $connection, string $revisionId, string $actorId, string $actorRealm, ?string $tenantId = null): array
    {
        return $connection->transaction(static function (Connection $transaction) use ($revisionId, $actorId, $actorRealm, $tenantId): array {
            $current = $transaction->table('broker_access_revisions')->where('id', '=', $revisionId)->first();
            if ($current === null || !self::sameTenant($current['tenant_id'] ?? null, $tenantId)) {
                throw new HttpError(404, 'broker_access_not_found');
            }
            if ((int) $current['tightening'] === 1) {
                throw new HttpError(409, 'broker_access_tightening_no_rollback');
            }
            if (!in_array((string) $current['status'], ['pending', 'partial', 'failed'], true)) {
                throw new HttpError(409, 'broker_access_rollback_unavailable');
            }
            $previousQuery = $transaction->table('broker_access_revisions')->where('version', '<', (int) $current['version'])->orderBy('version', 'DESC');
            $previousQuery = $tenantId === null ? $previousQuery->whereNull('tenant_id') : $previousQuery->where('tenant_id', '=', $tenantId);
            $previous = $previousQuery->first();
            if ($previous === null) {
                throw new HttpError(409, 'broker_access_no_previous');
            }
            self::restoreSnapshot($transaction, $current['tenant_id'] === null ? null : (string) $current['tenant_id'], (string) $previous['snapshot_json']);
            $now = time();
            $newId = bin2hex(random_bytes(16));
            $transaction->table('broker_access_revisions')->where('id', '=', $revisionId)->update(['status' => 'rolled_back', 'updated_at' => $now]);
            $transaction->table('broker_access_revisions')->insert([
                'id' => $newId, 'version' => self::currentVersion($transaction) + 1, 'actor_id' => $actorId, 'actor_realm' => $actorRealm,
                'tenant_id' => $current['tenant_id'], 'tightening' => 0, 'status' => 'pending', 'snapshot_json' => $previous['snapshot_json'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            self::refreshStatus($transaction, $newId);
            return self::revision($transaction, $newId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 当前范围的授权版本列表或详情，含各节点生效与待撤权数量。
     *
     * @return array<string, mixed> 版本列表，含各节点生效。
     * @throws HttpError 指定版本不存在或不属于当前范围。
     */
    public static function revisions(Connection $connection, ?string $tenantId, string $id, int $page, int $perPage): array
    {
        $query = $connection->table('broker_access_revisions');
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        if ($id !== '') {
            $row = $query->where('id', '=', $id)->first();
            if ($row === null) {
                throw new HttpError(404, 'broker_access_not_found');
            }
            return ['item' => self::revision($connection, $id)];
        }
        $result = $query->orderBy('version', 'DESC')->paginate($page, $perPage);
        $items = [];
        foreach ($result->items() as $row) {
            $items[] = self::revision($connection, (string) $row['id']);
        }
        return ['items' => $items, 'total' => $result->total(), 'page' => $result->number(), 'per_page' => $result->perPage()];
    }

    /**
     * 校验主体发布 JSON：证书最多 8 条、授权最多 32 条；CA PEM 只作公钥。
     *
     * @param array<string, mixed> $source 原始 JSON。
     * @return array<string, mixed>
     * @throws HttpError 字段越界或类型非法。
     */
    public static function changeInput(array $source): array
    {
        $input = new Input(['body' => $source]);
        $data = \_vali([
            'id' => Field::text()->length(0, 32),
            'name' => Field::text()->required()->length(1, 100),
            'login' => Field::text()->length(0, 100),
            'password' => Field::text()->length(0, 72),
            'enabled' => Field::integer()->cast()->range(0, 1),
            'rotate' => Field::integer()->cast()->range(0, 1),
            'revoke' => Field::integer()->cast()->range(0, 1),
            'expected_version' => Field::integer()->cast()->required()->range(0, 2147483646),
        ], $input) + ['id' => '', 'login' => '', 'password' => '', 'enabled' => 1, 'rotate' => 0, 'revoke' => 0];
        $grants = $source['grants'] ?? [];
        if (!is_array($grants) || count($grants) > 32) {
            throw new HttpError(422, 'broker_access_grant_invalid');
        }
        $data['grants'] = array_key_exists('grants', $source) ? $grants : null;
        $certificates = $source['certificates'] ?? null;
        if ($certificates !== null) {
            if (!is_array($certificates) || count($certificates) > 8) {
                throw new HttpError(422, 'broker_access_certificate_invalid');
            }
            $data['certificates'] = $certificates;
        }
        if (array_key_exists('ca', $source)) {
            if (!is_string($source['ca']) || strlen($source['ca']) > 16384) {
                throw new HttpError(422, 'broker_access_ca_invalid');
            }
            $data['ca'] = $source['ca'];
        }
        return $data;
    }

    /**
     * 校验受信 CA 发布 JSON；吊销只认已有 id，登记只接受单张 CA 公钥。
     *
     * @param array<string, mixed> $source 原始 JSON。
     * @return array<string, mixed>
     * @throws HttpError 字段越界或缺少乐观版本。
     */
    public static function caInput(array $source): array
    {
        $input = new Input(['body' => $source]);
        $data = \_vali([
            'id' => Field::text()->length(0, 32),
            'pem' => Field::text()->length(0, 16384),
            'revoke' => Field::integer()->cast()->range(0, 1),
            'expected_version' => Field::integer()->cast()->required()->range(0, 2147483646),
        ], $input) + ['id' => '', 'pem' => '', 'revoke' => 0];
        return $data;
    }

    /**
     * 校验 CRL 导入或 HTTPS 源；PEM 与 URL 至少一项，客户端不能附带任意下载地址。
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     * @throws HttpError 字段未知、CA 标识非法或同时缺少 PEM 与 URL。
     */
    public static function crlInput(array $source): array
    {
        if (array_diff(array_keys($source), ['id', 'pem', 'url', 'fetch_interval', 'expected_version']) !== []) {
            throw new HttpError(422, 'broker_access_invalid');
        }
        $input = new Input(['body' => $source]);
        $data = \_vali([
            'id' => Field::text()->required()->length(32, 32),
            'pem' => Field::text()->length(0, 65536),
            'url' => Field::text()->length(0, 2048),
            'fetch_interval' => Field::integer()->cast()->range(0, 3600),
            'expected_version' => Field::integer()->cast()->required()->range(0, 2147483646),
        ], $input) + ['pem' => '', 'url' => '', 'fetch_interval' => 0];
        if (preg_match('/^[a-f0-9]{32}$/D', (string) $data['id']) !== 1) {
            throw new HttpError(422, 'broker_access_ca_invalid');
        }
        if ((string) $data['pem'] === '' && (string) $data['url'] === '' && (int) $data['fetch_interval'] === 0) {
            throw new HttpError(422, 'broker_access_crl_invalid');
        }
        return $data;
    }

    /**
     * 校验平台直接吊销的序列号。
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     * @throws HttpError 字段未知或序列号无法规范化。
     */
    public static function platformSerialInput(array $source): array
    {
        if (array_diff(array_keys($source), ['serial', 'expected_version']) !== []) {
            throw new HttpError(422, 'broker_access_invalid');
        }
        $input = new Input(['body' => $source]);
        $data = \_vali([
            'serial' => Field::text()->required()->length(1, 64),
            'expected_version' => Field::integer()->cast()->required()->range(0, 2147483646),
        ], $input);
        $serial = CertificateRevocationList::serialHex((string) $data['serial']);
        if ($serial === '') {
            throw new HttpError(422, 'broker_access_platform_serial_invalid');
        }
        $data['serial'] = $serial;
        return $data;
    }

    /**
     * 校验换证预览 JSON；只接受单张客户端公钥和可选重叠秒数。
     *
     * @param array<string, mixed> $source 原始 JSON。
     * @return array<string, mixed>
     * @throws HttpError 字段未知或 PEM 缺失。
     */
    public static function rotationInput(array $source): array
    {
        if (array_diff(array_keys($source), ['pem', 'overlap_seconds']) !== []) {
            throw new HttpError(422, 'broker_access_invalid');
        }
        if (!is_string($source['pem'] ?? null) || (string) $source['pem'] === '') {
            throw new HttpError(422, 'broker_access_certificate_invalid');
        }
        $data = ['pem' => (string) $source['pem']];
        if (array_key_exists('overlap_seconds', $source)) {
            $data['overlap_seconds'] = $source['overlap_seconds'];
        }
        return $data;
    }

    /**
     * 在同一事务写入主体、凭据代次、Topic 授权及可选证书/CA；租户范围可把设备 id 提升为主体。
     *
     * @param array<string, mixed> $input
     * @throws HttpError 主体不存在、轮换缺少新口令或授权非法。
     */
    private static function applyChange(Connection $transaction, ?string $tenantId, array $input): void
    {
        $now = time();
        $principalId = (string) $input['id'];
        if ($principalId === '') {
            if ((string) $input['login'] === '' || (string) $input['password'] === '' || strlen((string) $input['password']) < 12) {
                throw new HttpError(422, 'broker_access_credential_required');
            }
            $principalId = bin2hex(random_bytes(16));
            $transaction->table('broker_access_principals')->insert([
                'id' => $principalId, 'tenant_id' => $tenantId, 'name' => (string) $input['name'], 'enabled' => (int) $input['enabled'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $transaction->table('broker_access_credentials')->insert([
                'id' => bin2hex(random_bytes(16)), 'principal_id' => $principalId, 'login' => (string) $input['login'],
                'secret_hash' => hash('sha256', (string) $input['password']), 'credential_version' => 1, 'status' => 'active',
                'created_at' => $now, 'revoked_at' => null,
            ]);
        } else {
            $query = $transaction->table('broker_access_principals')->where('id', '=', $principalId);
            $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
            $existing = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($existing === null && $tenantId !== null) {
                $device = $transaction->table('iot_devices')->where('id', '=', $principalId)->where('tenant_id', '=', $tenantId)->first();
                if ($device === null) {
                    throw new HttpError(404, 'broker_access_not_found');
                }
                $transaction->table('broker_access_principals')->insert([
                    'id' => $principalId, 'tenant_id' => $tenantId, 'name' => (string) $device['name'], 'enabled' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } elseif ($existing === null) {
                throw new HttpError(404, 'broker_access_not_found');
            }
            $query->update(['name' => (string) $input['name'], 'enabled' => (int) $input['enabled'], 'updated_at' => $now]);
            $credentialQuery = $transaction->table('broker_access_credentials')->where('principal_id', '=', $principalId)->where('status', '=', 'active');
            $credential = ($transaction->driverName() === 'sqlite' ? $credentialQuery : $credentialQuery->lockForUpdate())->first();
            if ((int) $input['revoke'] === 1 && $credential !== null) {
                $credentialQuery->update(['status' => 'revoked', 'revoked_at' => $now]);
            } elseif ((int) $input['rotate'] === 1) {
                if ($credential === null || strlen((string) $input['password']) < 12) {
                    throw new HttpError(422, 'broker_access_rotate_required');
                }
                $credentialQuery->update([
                    'secret_hash' => hash('sha256', (string) $input['password']),
                    'credential_version' => (int) $credential['credential_version'] + 1,
                ]);
            }
        }
        if (isset($input['grants']) && is_array($input['grants'])) {
            $transaction->table('broker_access_grants')->where('principal_id', '=', $principalId)->delete();
            foreach ($input['grants'] as $grant) {
                if (!is_array($grant)) {
                    throw new HttpError(422, 'broker_access_grant_invalid');
                }
                $topic = (string) ($grant['topic'] ?? '');
                $maxQos = (int) ($grant['max_qos'] ?? 0);
                if ($topic === '' || strlen($topic) > 200 || str_contains($topic, "\0") || $maxQos < 0 || $maxQos > 2) {
                    throw new HttpError(422, 'broker_access_grant_invalid');
                }
                $transaction->table('broker_access_grants')->insert([
                    'id' => bin2hex(random_bytes(16)), 'principal_id' => $principalId, 'topic' => $topic,
                    'publish' => self::flag($grant['publish'] ?? 0),
                    'subscribe' => self::flag($grant['subscribe'] ?? 0),
                    'max_qos' => $maxQos,
                ]);
            }
        }
        if (isset($input['ca']) && is_string($input['ca']) && $input['ca'] !== '') {
            self::applyCa($transaction, $tenantId, ['id' => '', 'pem' => $input['ca'], 'revoke' => 0]);
        }
        if (isset($input['certificates']) && is_array($input['certificates'])) {
            self::applyCertificates($transaction, $tenantId, $principalId, $input['certificates']);
        }
    }

    /**
     * 登记一张 CA 公钥或在无活动叶子时删除；指纹全局唯一。
     *
     * @param array<string, mixed> $input
     * @throws HttpError PEM 非 CA、指纹冲突或仍被活动证书使用。
     */
    private static function applyCa(Connection $transaction, ?string $tenantId, array $input): void
    {
        $now = time();
        $caId = (string) $input['id'];
        if ((int) $input['revoke'] === 1) {
            if ($caId === '') {
                throw new HttpError(422, 'broker_access_ca_invalid');
            }
            $query = $transaction->table('broker_access_cas')->where('id', '=', $caId);
            $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
            $existing = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($existing === null) {
                throw new HttpError(404, 'broker_access_not_found');
            }
            foreach ($transaction->table('broker_access_certificates')->where('status', '=', 'active')->get() as $certificate) {
                if (self::issuedByCa((string) $certificate['pem'], (string) $existing['pem'])) {
                    throw new HttpError(409, 'broker_access_ca_in_use');
                }
            }
            $transaction->table('broker_access_crl_serials')->where('ca_id', '=', $caId)->delete();
            $transaction->table('broker_access_crls')->where('ca_id', '=', $caId)->delete();
            $query->delete();
            return;
        }
        $parsed = self::parsePublicCertificate((string) $input['pem'], true);
        $duplicate = $transaction->table('broker_access_cas')->where('fingerprint', '=', $parsed['fingerprint'])->first();
        if ($duplicate !== null) {
            throw new HttpError(409, 'broker_access_certificate_conflict');
        }
        $transaction->table('broker_access_cas')->insert([
            'id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenantId, 'fingerprint' => $parsed['fingerprint'],
            'subject' => $parsed['subject'], 'pem' => $parsed['pem'], 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * 按主体绑定或吊销客户端证书；必须由当前范围已登记 CA 签发，指纹不能绑到其他主体。
     *
     * @param list<mixed> $items 公钥 PEM 或吊销指令。
     * @throws HttpError PEM 非法、未知 CA、指纹冲突或目标不存在。
     */
    private static function applyCertificates(Connection $transaction, ?string $tenantId, string $principalId, array $items): void
    {
        $now = time();
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['pem', 'fingerprint', 'id', 'revoke', 'overlap_seconds']) !== []) {
                throw new HttpError(422, 'broker_access_certificate_invalid');
            }
            if (self::flag($item['revoke'] ?? 0) === 1) {
                if (array_key_exists('overlap_seconds', $item) || array_key_exists('pem', $item)) {
                    throw new HttpError(422, 'broker_access_certificate_invalid');
                }
                $query = $transaction->table('broker_access_certificates')->where('principal_id', '=', $principalId);
                if (is_string($item['id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', (string) $item['id']) === 1) {
                    $query = $query->where('id', '=', (string) $item['id']);
                } elseif (is_string($item['fingerprint'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', (string) $item['fingerprint']) === 1) {
                    $query = $query->where('fingerprint', '=', (string) $item['fingerprint']);
                } else {
                    throw new HttpError(422, 'broker_access_certificate_invalid');
                }
                $existing = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                if ($existing === null) {
                    throw new HttpError(404, 'broker_access_not_found');
                }
                if ((string) $existing['status'] === 'active') {
                    $query->update(['status' => 'revoked', 'revoked_at' => $now, 'overlap_until' => 0]);
                }
                continue;
            }
            $parsed = self::parsePublicCertificate((string) ($item['pem'] ?? ''), false);
            if (!self::issuedByRegisteredCa($transaction, $tenantId, $parsed['pem'])) {
                throw new HttpError(422, 'broker_access_unknown_ca');
            }
            $duplicate = $transaction->table('broker_access_certificates')->where('fingerprint', '=', $parsed['fingerprint'])->first();
            if ($duplicate !== null && (string) $duplicate['principal_id'] !== $principalId) {
                throw new HttpError(409, 'broker_access_certificate_conflict');
            }
            $keepId = '';
            if ($duplicate !== null && (string) $duplicate['principal_id'] === $principalId) {
                $credentialQuery = $transaction->table('broker_access_certificates')->where('id', '=', $duplicate['id']);
                $locked = ($transaction->driverName() === 'sqlite' ? $credentialQuery : $credentialQuery->lockForUpdate())->first();
                if ($locked === null) {
                    throw new HttpError(404, 'broker_access_not_found');
                }
                $version = (int) $locked['certificate_version'];
                if ((string) $locked['status'] !== 'active') {
                    $version++;
                }
                $credentialQuery->update([
                    'subject' => $parsed['subject'], 'pem' => $parsed['pem'], 'not_after' => $parsed['not_after'],
                    'serial' => $parsed['serial'],
                    'certificate_version' => $version, 'status' => 'active', 'revoked_at' => null, 'overlap_until' => 0,
                ]);
                $keepId = (string) $duplicate['id'];
            } else {
                $keepId = bin2hex(random_bytes(16));
                $transaction->table('broker_access_certificates')->insert([
                    'id' => $keepId, 'principal_id' => $principalId, 'fingerprint' => $parsed['fingerprint'],
                    'subject' => $parsed['subject'], 'pem' => $parsed['pem'], 'not_after' => $parsed['not_after'],
                    'serial' => $parsed['serial'],
                    'certificate_version' => 1, 'status' => 'active', 'created_at' => $now, 'revoked_at' => null, 'overlap_until' => 0,
                ]);
            }
            $others = 0;
            foreach ($transaction->table('broker_access_certificates')->where('principal_id', '=', $principalId)->where('status', '=', 'active')->get() as $row) {
                if ((string) $row['id'] !== $keepId) {
                    $others++;
                }
            }
            self::retireOtherCertificates($transaction, $principalId, $keepId, self::overlapSeconds($item, $others > 0), $parsed['not_after'], $now);
        }
    }

    /**
     * 校验并持久接纳签名 CRL 或受控 HTTPS 源；已接纳序列号只增不减。
     *
     * @param array<string, mixed> $input
     * @throws HttpError CA 不存在、签名无效、过期或 thisUpdate 回退。
     */
    private static function applyCrl(Connection $transaction, ?string $tenantId, array $input, string $source): void
    {
        $caId = (string) $input['id'];
        $query = $transaction->table('broker_access_cas')->where('id', '=', $caId);
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        $ca = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($ca === null) {
            throw new HttpError(404, 'broker_access_not_found');
        }
        $now = time();
        $existing = $transaction->table('broker_access_crls')->where('ca_id', '=', $caId)->first();
        $url = $existing !== null ? (string) $existing['url'] : '';
        if ($source !== 'https' && array_key_exists('url', $input) && (string) $input['url'] !== '') {
            $url = self::httpsUrl((string) $input['url']);
            if ($url === '') {
                throw new HttpError(422, 'broker_access_crl_url_invalid');
            }
        }
        $interval = (int) ($input['fetch_interval'] ?? 0);
        if ($interval <= 0) {
            $interval = $existing !== null ? (int) $existing['fetch_interval'] : self::CRL_FETCH_DEFAULT;
        }
        if ($interval < 1 || $interval > 3600) {
            $interval = self::CRL_FETCH_DEFAULT;
        }
        $pem = (string) ($input['pem'] ?? '');
        if ($pem === '' && $url === '' && $existing === null) {
            throw new HttpError(422, 'broker_access_crl_invalid');
        }
        $thisUpdate = $existing !== null ? (int) $existing['this_update'] : 0;
        $nextUpdate = $existing !== null ? (int) $existing['next_update'] : 0;
        $storedPem = $existing !== null ? (string) $existing['pem'] : '';
        $acceptedAt = $existing !== null ? (int) $existing['accepted_at'] : 0;
        if ($pem !== '') {
            try {
                $parsed = CertificateRevocationList::fromPem($pem, (string) $ca['pem']);
            } catch (\RuntimeException) {
                throw new HttpError(422, 'broker_access_crl_invalid');
            }
            if ($thisUpdate > 0 && $parsed->thisUpdate() < $thisUpdate) {
                throw new HttpError(422, 'broker_access_crl_stale');
            }
            if (!$parsed->covers($now)) {
                throw new HttpError(422, 'broker_access_crl_invalid');
            }
            $known = self::crlSerialMap($transaction, $caId);
            foreach ($parsed->serials() as $serial) {
                if (!isset($known[$serial])) {
                    $transaction->table('broker_access_crl_serials')->insert(['ca_id' => $caId, 'serial' => $serial]);
                }
            }
            $thisUpdate = $parsed->thisUpdate();
            $nextUpdate = $parsed->nextUpdate();
            $storedPem = $pem;
            $acceptedAt = $now;
        }
        $fetchStatus = $pem !== '' ? 'success' : ($existing !== null ? (string) $existing['fetch_status'] : 'idle');
        $fetchError = $pem !== '' ? '' : ($existing !== null ? (string) $existing['fetch_error'] : '');
        $fetchedAt = $pem !== '' ? $now : ($existing !== null ? (int) $existing['fetched_at'] : 0);
        $dueAt = $existing !== null ? (int) $existing['fetch_due_at'] : 0;
        if ($url !== '' && $dueAt === 0) {
            $dueAt = $now;
        }
        $values = [
            'pem' => $storedPem, 'this_update' => $thisUpdate, 'next_update' => $nextUpdate, 'url' => $url,
            'fetch_interval' => $interval, 'fetch_status' => $fetchStatus, 'fetch_error' => $fetchError,
            'fetched_at' => $fetchedAt, 'accepted_at' => $acceptedAt, 'fetch_due_at' => $dueAt,
            'configured' => 1, 'updated_at' => $now,
        ];
        if ($existing === null) {
            $transaction->table('broker_access_crls')->insert(['ca_id' => $caId] + $values);
        } else {
            $transaction->table('broker_access_crls')->where('ca_id', '=', $caId)->update($values);
        }
    }

    /** 平台直接吊销一个序列号；重复写入视为无变化。 */
    private static function applyPlatformSerial(Connection $transaction, ?string $tenantId, string $actorId, string $serial): void
    {
        $key = self::tenantKey($tenantId);
        if ($transaction->table('broker_access_platform_serials')->where('tenant_key', '=', $key)->where('serial', '=', $serial)->first() !== null) {
            return;
        }
        $transaction->table('broker_access_platform_serials')->insert([
            'tenant_key' => $key, 'serial' => $serial, 'actor_id' => $actorId, 'created_at' => time(),
        ]);
    }

    /** 当前范围主体、凭据、授权、证书、CA、CRL 与平台吊销的确定性 JSON，供版本比较与回退。 */
    private static function snapshot(Connection $transaction, ?string $tenantId): string
    {
        $query = $transaction->table('broker_access_principals')->orderBy('id');
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        $principals = [];
        foreach ($query->get() as $principal) {
            $credentials = [];
            foreach ($transaction->table('broker_access_credentials')->where('principal_id', '=', $principal['id'])->orderBy('id')->get() as $credential) {
                $credentials[] = ['id' => $credential['id'], 'login' => $credential['login'], 'secret_hash' => $credential['secret_hash'],
                    'credential_version' => (int) $credential['credential_version'], 'status' => $credential['status']];
            }
            $grants = [];
            foreach ($transaction->table('broker_access_grants')->where('principal_id', '=', $principal['id'])->orderBy('topic')->orderBy('id')->get() as $grant) {
                $grants[] = ['topic' => $grant['topic'], 'publish' => (int) $grant['publish'], 'subscribe' => (int) $grant['subscribe'],
                    'max_qos' => (int) $grant['max_qos']];
            }
            $certificates = [];
            foreach ($transaction->table('broker_access_certificates')->where('principal_id', '=', $principal['id'])->orderBy('id')->get() as $certificate) {
                $certificates[] = ['id' => $certificate['id'], 'fingerprint' => $certificate['fingerprint'], 'subject' => $certificate['subject'],
                    'pem' => $certificate['pem'], 'not_after' => (int) $certificate['not_after'],
                    'overlap_until' => (int) ($certificate['overlap_until'] ?? 0),
                    'serial' => self::certificateSerial($certificate),
                    'certificate_version' => (int) $certificate['certificate_version'], 'status' => $certificate['status']];
            }
            $principals[] = ['id' => $principal['id'], 'name' => $principal['name'], 'enabled' => (int) $principal['enabled'],
                'credentials' => $credentials, 'grants' => $grants, 'certificates' => $certificates];
        }
        $cas = [];
        $caQuery = $transaction->table('broker_access_cas')->orderBy('id');
        $caQuery = $tenantId === null ? $caQuery->whereNull('tenant_id') : $caQuery->where('tenant_id', '=', $tenantId);
        foreach ($caQuery->get() as $row) {
            $cas[] = ['id' => $row['id'], 'fingerprint' => $row['fingerprint'], 'subject' => $row['subject'], 'pem' => $row['pem']];
        }
        $crls = [];
        foreach ($cas as $ca) {
            $crl = $transaction->table('broker_access_crls')->where('ca_id', '=', $ca['id'])->first();
            $serials = array_keys(self::crlSerialMap($transaction, (string) $ca['id']));
            sort($serials);
            $crls[] = [
                'ca_id' => $ca['id'], 'url' => $crl === null ? '' : (string) $crl['url'],
                'fetch_interval' => $crl === null ? self::CRL_FETCH_DEFAULT : (int) $crl['fetch_interval'],
                'pem' => $crl === null ? '' : (string) $crl['pem'],
                'this_update' => $crl === null ? 0 : (int) $crl['this_update'],
                'next_update' => $crl === null ? 0 : (int) $crl['next_update'],
                'configured' => $crl !== null && (int) $crl['configured'] === 1 ? 1 : 0,
                'state' => self::crlAvailability($crl, time()),
                'serials' => $serials,
            ];
        }
        $platform = [];
        foreach ($transaction->table('broker_access_platform_serials')->where('tenant_key', '=', self::tenantKey($tenantId))->orderBy('serial')->get() as $row) {
            $platform[] = (string) $row['serial'];
        }
        return json_encode(['principals' => $principals, 'cas' => $cas, 'crls' => $crls, 'platform_serials' => $platform], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** 用上一快照整体替换当前范围事实；只在非收紧回退路径调用。 */
    private static function restoreSnapshot(Connection $transaction, ?string $tenantId, string $encoded): void
    {
        $snapshot = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        $query = $transaction->table('broker_access_principals');
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        foreach ($query->get() as $principal) {
            $transaction->table('broker_access_certificates')->where('principal_id', '=', $principal['id'])->delete();
            $transaction->table('broker_access_grants')->where('principal_id', '=', $principal['id'])->delete();
            $transaction->table('broker_access_credentials')->where('principal_id', '=', $principal['id'])->delete();
        }
        $query->delete();
        $snapshotCas = isset($snapshot['cas']) && is_array($snapshot['cas']) ? $snapshot['cas'] : [];
        $keep = [];
        foreach ($snapshotCas as $row) {
            $keep[(string) $row['id']] = true;
        }
        $caQuery = $transaction->table('broker_access_cas');
        $caQuery = $tenantId === null ? $caQuery->whereNull('tenant_id') : $caQuery->where('tenant_id', '=', $tenantId);
        foreach ($caQuery->get() as $row) {
            $id = (string) $row['id'];
            if (isset($keep[$id]) || $transaction->table('broker_access_crl_serials')->where('ca_id', '=', $id)->first() !== null) {
                continue;
            }
            $transaction->table('broker_access_crls')->where('ca_id', '=', $id)->delete();
            $transaction->table('broker_access_cas')->where('id', '=', $id)->delete();
        }
        $now = time();
        foreach ($snapshotCas as $row) {
            if ($transaction->table('broker_access_cas')->where('id', '=', $row['id'])->first() !== null) {
                $transaction->table('broker_access_cas')->where('id', '=', $row['id'])->update([
                    'fingerprint' => $row['fingerprint'], 'subject' => $row['subject'], 'pem' => $row['pem'], 'updated_at' => $now,
                ]);
                continue;
            }
            $transaction->table('broker_access_cas')->insert([
                'id' => $row['id'], 'tenant_id' => $tenantId, 'fingerprint' => $row['fingerprint'],
                'subject' => $row['subject'], 'pem' => $row['pem'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        self::restoreCrls($transaction, $tenantId, $snapshot, $now);
        foreach ($snapshot['principals'] as $principal) {
            $transaction->table('broker_access_principals')->insert([
                'id' => $principal['id'], 'tenant_id' => $tenantId, 'name' => $principal['name'], 'enabled' => $principal['enabled'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($principal['credentials'] as $credential) {
                $transaction->table('broker_access_credentials')->insert([
                    'id' => $credential['id'], 'principal_id' => $principal['id'], 'login' => $credential['login'],
                    'secret_hash' => $credential['secret_hash'], 'credential_version' => $credential['credential_version'],
                    'status' => $credential['status'], 'created_at' => $now, 'revoked_at' => $credential['status'] === 'revoked' ? $now : null,
                ]);
            }
            foreach ($principal['grants'] as $grant) {
                $transaction->table('broker_access_grants')->insert([
                    'id' => bin2hex(random_bytes(16)), 'principal_id' => $principal['id'], 'topic' => $grant['topic'],
                    'publish' => $grant['publish'], 'subscribe' => $grant['subscribe'], 'max_qos' => $grant['max_qos'],
                ]);
            }
            $certificates = isset($principal['certificates']) && is_array($principal['certificates']) ? $principal['certificates'] : [];
            foreach ($certificates as $certificate) {
                $transaction->table('broker_access_certificates')->insert([
                    'id' => $certificate['id'], 'principal_id' => $principal['id'], 'fingerprint' => $certificate['fingerprint'],
                    'subject' => $certificate['subject'], 'pem' => $certificate['pem'], 'not_after' => $certificate['not_after'],
                    'certificate_version' => $certificate['certificate_version'], 'status' => $certificate['status'],
                    'created_at' => $now, 'revoked_at' => $certificate['status'] === 'revoked' ? $now : null,
                    'overlap_until' => (int) ($certificate['overlap_until'] ?? 0),
                    'serial' => (string) ($certificate['serial'] ?? ''),
                ]);
            }
        }
    }

    /** 停用主体、吊销/轮换凭据、收窄授权或吊销证书都算收紧；收紧后旧连接必须撤权。 */
    private static function isTightening(string $before, string $after): bool
    {
        $left = json_decode($before, true, 16, JSON_THROW_ON_ERROR);
        $right = json_decode($after, true, 16, JSON_THROW_ON_ERROR);
        $previous = [];
        foreach ($left['principals'] as $principal) {
            $previous[$principal['id']] = $principal;
        }
        foreach ($right['principals'] as $principal) {
            $old = $previous[$principal['id']] ?? null;
            if ($old === null) {
                continue;
            }
            if ($old['enabled'] === 1 && $principal['enabled'] === 0) {
                return true;
            }
            $oldActive = array_values(array_filter($old['credentials'], static fn (array $row): bool => $row['status'] === 'active'));
            $newActive = array_values(array_filter($principal['credentials'], static fn (array $row): bool => $row['status'] === 'active'));
            if ($oldActive !== [] && $newActive === []) {
                return true;
            }
            if ($oldActive !== [] && $newActive !== [] && (int) $newActive[0]['credential_version'] > (int) $oldActive[0]['credential_version']) {
                return true;
            }
            if (self::grantsNarrowed($old['grants'], $principal['grants'])) {
                return true;
            }
            $oldCerts = isset($old['certificates']) && is_array($old['certificates']) ? $old['certificates'] : [];
            $newCerts = isset($principal['certificates']) && is_array($principal['certificates']) ? $principal['certificates'] : [];
            $oldActiveCerts = array_values(array_filter($oldCerts, static fn (array $row): bool => $row['status'] === 'active'));
            $newActiveCerts = array_values(array_filter($newCerts, static fn (array $row): bool => $row['status'] === 'active'));
            if (self::certificatesTightened($oldActiveCerts, $newActiveCerts)) {
                return true;
            }
        }
        foreach ($previous as $id => $principal) {
            $found = false;
            foreach ($right['principals'] as $candidate) {
                if ($candidate['id'] === $id) {
                    $found = true;
                    break;
                }
            }
            if (!$found && $principal['enabled'] === 1) {
                return true;
            }
        }
        if (self::crlsTightened($left['crls'] ?? [], $right['crls'] ?? [])) {
            return true;
        }
        $oldPlatform = isset($left['platform_serials']) && is_array($left['platform_serials']) ? $left['platform_serials'] : [];
        $newPlatform = isset($right['platform_serials']) && is_array($right['platform_serials']) ? $right['platform_serials'] : [];
        foreach ($newPlatform as $serial) {
            if (!in_array($serial, $oldPlatform, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 新授权缺少原 Topic，或同一 Topic 的发布、订阅、QoS 变窄。
     *
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     */
    private static function grantsNarrowed(array $old, array $new): bool
    {
        foreach ($old as $grant) {
            $matched = false;
            foreach ($new as $candidate) {
                if ($candidate['topic'] !== $grant['topic']) {
                    continue;
                }
                $matched = true;
                if ((int) $candidate['publish'] < (int) $grant['publish'] || (int) $candidate['subscribe'] < (int) $grant['subscribe']
                    || (int) $candidate['max_qos'] < (int) $grant['max_qos']) {
                    return true;
                }
            }
            if (!$matched) {
                return true;
            }
        }
        return false;
    }

    /**
     * 按收紧前后差集给仍在线连接排队撤权；无观察连接时用 client_id 提示补一条。
     */
    private static function enqueueInvalidations(Connection $transaction, string $before, string $after, string $actor, string $clientIdHint): void
    {
        $left = json_decode($before, true, 16, JSON_THROW_ON_ERROR);
        $right = json_decode($after, true, 16, JSON_THROW_ON_ERROR);
        $rightIds = [];
        foreach ($right['principals'] as $principal) {
            $rightIds[$principal['id']] = $principal;
        }
        foreach ($left['principals'] as $principal) {
            $next = $rightIds[$principal['id']] ?? null;
            $oldActive = array_values(array_filter($principal['credentials'], static fn (array $row): bool => $row['status'] === 'active'));
            $newActive = $next === null ? [] : array_values(array_filter($next['credentials'], static fn (array $row): bool => $row['status'] === 'active'));
            $oldCerts = isset($principal['certificates']) && is_array($principal['certificates'])
                ? array_values(array_filter($principal['certificates'], static fn (array $row): bool => $row['status'] === 'active')) : [];
            $newCerts = $next === null || !isset($next['certificates']) || !is_array($next['certificates']) ? []
                : array_values(array_filter($next['certificates'], static fn (array $row): bool => $row['status'] === 'active'));
            $credential = $oldActive[0] ?? null;
            $revokedCerts = [];
            foreach ($oldCerts as $oldCert) {
                $foundVersion = null;
                foreach ($newCerts as $newCert) {
                    if ((string) $newCert['fingerprint'] === (string) $oldCert['fingerprint']) {
                        $foundVersion = (int) $newCert['certificate_version'];
                        break;
                    }
                }
                if ($foundVersion === null || $foundVersion > (int) $oldCert['certificate_version']) {
                    $revokedCerts[] = $oldCert;
                }
            }
            $grantsTightened = self::grantsNarrowed($principal['grants'], $next['grants'] ?? []);
            $credentialTightened = $credential !== null && ($newActive === [] || (int) $newActive[0]['credential_version'] > (int) $credential['credential_version']);
            $tighten = $next === null || $next['enabled'] === 0 || $grantsTightened || $credentialTightened || $revokedCerts !== [];
            if (!$tighten) {
                continue;
            }
            $matchAny = $next === null || $next['enabled'] === 0 || $grantsTightened;
            $queued = [];
            foreach ($transaction->table('broker_resource_connections')->get() as $connection) {
                $identity = $connection['access_identity'] === null ? null : json_decode((string) $connection['access_identity'], true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($identity) || !self::identityMatchesPrincipal($identity, $principal['id'], null)) {
                    continue;
                }
                $method = (string) ($identity['authentication_method'] ?? 'connect');
                $keep = $matchAny;
                if (!$keep && $credentialTightened && $method !== 'mtls' && self::identityMatchesPrincipal($identity, $principal['id'], $credential)) {
                    $keep = true;
                }
                if (!$keep && $revokedCerts !== [] && $method === 'mtls' && self::identityMatchesCertificates($identity, $revokedCerts)) {
                    $keep = true;
                }
                if (!$keep) {
                    continue;
                }
                $clientId = (string) $connection['client_id'];
                if (isset($queued[$clientId])) {
                    continue;
                }
                $queued[$clientId] = $identity;
            }
            if ($queued === [] && $clientIdHint !== '') {
                if ($revokedCerts !== []) {
                    $queued[$clientIdHint] = [
                        'principal_id' => $principal['id'], 'credential_id' => $revokedCerts[0]['id'],
                        'credential_version' => (int) $revokedCerts[0]['certificate_version'], 'authentication_method' => 'mtls',
                    ];
                } elseif ($credential === null) {
                    $queued[$clientIdHint] = [
                        'principal_id' => 'device:' . $principal['id'], 'credential_id' => $principal['id'],
                        'credential_version' => 1, 'authentication_method' => 'connect',
                    ];
                } else {
                    $queued[$clientIdHint] = [
                        'principal_id' => $principal['id'], 'credential_id' => $credential['id'],
                        'credential_version' => (int) $credential['credential_version'], 'authentication_method' => 'connect',
                    ];
                }
            }
            foreach ($queued as $clientId => $identity) {
                $transaction->table('broker_access_invalidations')->insert([
                    'id' => bin2hex(random_bytes(16)), 'client_id' => $clientId,
                    'principal' => $credential === null ? (string) ($identity['principal_id'] ?? $principal['id']) : (string) $credential['login'],
                    'actor' => $actor, 'access_identity' => json_encode($identity, JSON_THROW_ON_ERROR),
                    'requested_at' => time(), 'completed_at' => null, 'node_id' => null,
                ]);
            }
        }
        self::enqueueCrlInvalidations($transaction, $left, $right, $actor);
    }

    /** 独立范围为 null，租户必须字节相等；不能用空串混入独立主体。 */
    private static function sameTenant(mixed $stored, ?string $tenantId): bool
    {
        return $tenantId === null ? $stored === null : is_string($stored) && $stored === $tenantId;
    }

    /**
     * 连接观察中的身份是否属于该主体；credential 为 null 时匹配该主体全部连接。
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed>|null $credential
     */
    private static function identityMatchesPrincipal(array $identity, string $principalId, ?array $credential): bool
    {
        $subject = (string) ($identity['principal_id'] ?? '');
        if ($subject !== $principalId && $subject !== 'device:' . $principalId) {
            return false;
        }
        if ($credential === null) {
            return true;
        }
        return ($identity['credential_id'] ?? '') === $credential['id']
            && (int) ($identity['credential_version'] ?? 0) === (int) $credential['credential_version'];
    }

    /** 按节点上报与待撤权重算 pending/partial/effective/failed；已回退版本不再改写。 */
    private static function refreshStatus(Connection $transaction, string $revisionId): void
    {
        $revision = $transaction->table('broker_access_revisions')->where('id', '=', $revisionId)->first();
        if ($revision === null || (string) $revision['status'] === 'rolled_back' || (int) ($revision['recovery_verified'] ?? 1) !== 1) {
            return;
        }
        $pendingInvalidations = $transaction->table('broker_access_invalidations')->whereNull('completed_at')->limit(1)->first();
        $nodes = $transaction->table('broker_access_node_states')->where('revision_id', '=', $revisionId)->get();
        $applied = 0;
        $pendingNodes = 0;
        $isolated = 0;
        foreach ($nodes as $node) {
            if ((string) $node['state'] === 'applied') {
                $applied++;
            } elseif ((string) $node['state'] === 'isolated') {
                $isolated++;
            } else {
                $pendingNodes++;
            }
        }
        $pendingWork = $pendingNodes > 0 || $pendingInvalidations !== null;
        $status = 'pending';
        $everObserved = $transaction->table('broker_access_node_states')->limit(1)->first();
        if ($everObserved === null && $pendingInvalidations === null) {
            $status = 'effective';
        } elseif ($applied > 0 && !$pendingWork) {
            $status = 'effective';
        } elseif ($applied > 0 && $pendingWork) {
            $status = 'partial';
        } elseif ((int) $revision['tightening'] === 1 && $isolated > 0 && $applied === 0 && !$pendingWork) {
            $status = 'failed';
        }
        $transaction->table('broker_access_revisions')->where('id', '=', $revisionId)->update(['status' => $status, 'updated_at' => time()]);
    }

    /**
     * 管理页使用的版本投影，不含快照正文。
     *
     * @return array<string, mixed>
     */
    private static function revision(Connection $connection, string $id): array
    {
        $row = $connection->table('broker_access_revisions')->where('id', '=', $id)->first();
        if ($row === null) {
            throw new HttpError(404, 'broker_access_not_found');
        }
        $nodes = [];
        foreach ($connection->table('broker_access_node_states')->where('revision_id', '=', $id)->orderBy('node_id')->get() as $node) {
            $nodes[] = ['node_id' => $node['node_id'], 'applied_version' => (int) $node['applied_version'], 'state' => $node['state'],
                'updated_at' => (int) $node['updated_at']];
        }
        $pending = $connection->table('broker_access_invalidations')->whereNull('completed_at')->limit(32)->get();
        $pendingCount = count($pending);
        return ['id' => $row['id'], 'operation_id' => $row['id'], 'version' => (int) $row['version'], 'actor_id' => $row['actor_id'],
            'actor_realm' => $row['actor_realm'], 'tightening' => (int) $row['tightening'] === 1, 'status' => $row['status'],
            'stage' => self::operationStage((string) $row['status'], $nodes, $pendingCount),
            'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at'], 'nodes' => $nodes,
            'pending_invalidations' => $pendingCount];
    }

    /**
     * 主体列表投影：活动登录名、授权与证书指纹，不含口令散列和 PEM。
     *
     * @param array<string, mixed> $principal
     * @return array<string, mixed>
     */
    private static function project(Connection $connection, array $principal): array
    {
        $credential = $connection->table('broker_access_credentials')->where('principal_id', '=', $principal['id'])->where('status', '=', 'active')->first();
        $grants = [];
        foreach ($connection->table('broker_access_grants')->where('principal_id', '=', $principal['id'])->orderBy('topic')->get() as $grant) {
            $grants[] = ['topic' => $grant['topic'], 'publish' => (int) $grant['publish'] === 1, 'subscribe' => (int) $grant['subscribe'] === 1,
                'max_qos' => (int) $grant['max_qos']];
        }
        $certificates = [];
        foreach ($connection->table('broker_access_certificates')->where('principal_id', '=', $principal['id'])->orderBy('created_at')->orderBy('id')->get() as $certificate) {
            $certificates[] = ['id' => $certificate['id'], 'fingerprint' => $certificate['fingerprint'], 'subject' => $certificate['subject'],
                'not_after' => (int) $certificate['not_after'], 'overlap_until' => (int) ($certificate['overlap_until'] ?? 0),
                'certificate_version' => (int) $certificate['certificate_version'], 'status' => $certificate['status'],
                'recovery_verified' => (int) ($certificate['recovery_verified'] ?? 1)];
        }
        return ['id' => $principal['id'], 'name' => $principal['name'], 'enabled' => (int) $principal['enabled'] === 1,
            'recovery_verified' => (int) ($principal['recovery_verified'] ?? 1),
            'login' => $credential === null ? null : $credential['login'],
            'credential_version' => $credential === null ? null : (int) $credential['credential_version'],
            'grants' => $grants, 'certificates' => $certificates, 'updated_at' => (int) $principal['updated_at']];
    }

    /**
     * 活动证书指纹消失或代次升高即收紧；新增证书不算收紧。
     *
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     */
    private static function certificatesTightened(array $old, array $new): bool
    {
        $next = [];
        foreach ($new as $row) {
            $next[(string) $row['fingerprint']] = (int) $row['certificate_version'];
        }
        foreach ($old as $row) {
            $fingerprint = (string) $row['fingerprint'];
            if (!isset($next[$fingerprint]) || $next[$fingerprint] > (int) $row['certificate_version']) {
                return true;
            }
        }
        return false;
    }

    /**
     * 活动证书仍可认证：未过 not_after，且无重叠截止或截止仍在未来。
     *
     * @param array<string, mixed> $row
     */
    private static function certificateAcceptable(array $row): bool
    {
        if ((string) $row['status'] !== 'active') {
            return false;
        }
        $now = time();
        if ((int) $row['not_after'] <= $now) {
            return false;
        }
        $overlap = (int) ($row['overlap_until'] ?? 0);
        return $overlap === 0 || $overlap > $now;
    }

    /**
     * 活动证书仍可认证，且未被 CRL 缺失/过期、已接纳序列号或平台吊销挡住。
     *
     * @param array<string, mixed> $row
     */
    private static function certificateAllowed(Connection $connection, array $row): bool
    {
        if (!self::certificateAcceptable($row) || (int) ($row['recovery_verified'] ?? 1) !== 1) {
            return false;
        }
        foreach ($connection->table('broker_access_cas')->get() as $ca) {
            if (!self::issuedByCa((string) $row['pem'], (string) $ca['pem'])) {
                continue;
            }
            if ((int) ($ca['recovery_verified'] ?? 1) !== 1) {
                return false;
            }
            break;
        }
        $serial = self::certificateSerial($row);
        $tenantId = self::principalTenant($connection, (string) $row['principal_id']);
        if (self::platformRevokes($connection, $tenantId, $serial)) {
            return false;
        }
        return !self::crlBlocks($connection, $row, $serial);
    }

    /**
     * 配置了 CRL 后缺失或过期则拒绝该 CA 全部叶子；有效列表只拒绝已接纳序列号。
     *
     * @param array<string, mixed> $row
     */
    private static function crlBlocks(Connection $connection, array $row, string $serial): bool
    {
        foreach ($connection->table('broker_access_cas')->get() as $ca) {
            if (!self::issuedByCa((string) $row['pem'], (string) $ca['pem'])) {
                continue;
            }
            $crl = $connection->table('broker_access_crls')->where('ca_id', '=', $ca['id'])->first();
            $state = self::crlAvailability($crl, time());
            if ($state === 'none') {
                return false;
            }
            if ($state === 'missing' || $state === 'expired') {
                return true;
            }
            return $serial !== '' && $connection->table('broker_access_crl_serials')->where('ca_id', '=', $ca['id'])->where('serial', '=', $serial)->first() !== null;
        }
        return false;
    }

    /**
     * 把已到期或重叠结束的活动证书持久吊销，并只给对应证书身份排队撤权。
     */
    private static function expireCertificates(Connection $connection): void
    {
        $now = time();
        $run = static function (Connection $transaction) use ($now): void {
            foreach ($transaction->table('broker_access_certificates')->where('status', '=', 'active')->get() as $row) {
                $overlap = (int) ($row['overlap_until'] ?? 0);
                if ((int) $row['not_after'] > $now && ($overlap === 0 || $overlap > $now)) {
                    continue;
                }
                $changed = $transaction->table('broker_access_certificates')->where('id', '=', $row['id'])->where('status', '=', 'active')
                    ->update(['status' => 'revoked', 'revoked_at' => $now, 'overlap_until' => 0]);
                if ($changed === 1) {
                    self::enqueueCertificateInvalidation($transaction, $row, 'expiry');
                }
            }
        };
        if ($connection->transactionDepth() > 0) {
            $run($connection);
            return;
        }
        $connection->transaction($run, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * CRL 缺失或过期时给该 CA 签发的活动证书排队撤权；不改证书绑定行，恢复有效列表后未列入序列号可再接入。
     */
    private static function expireCrls(Connection $connection): void
    {
        $run = static function (Connection $transaction): void {
            $now = time();
            foreach ($transaction->table('broker_access_crls')->where('configured', '=', 1)->get() as $crl) {
                $state = self::crlAvailability($crl, $now);
                if ($state !== 'missing' && $state !== 'expired') {
                    continue;
                }
                $ca = $transaction->table('broker_access_cas')->where('id', '=', $crl['ca_id'])->first();
                if ($ca === null) {
                    continue;
                }
                foreach ($transaction->table('broker_access_certificates')->where('status', '=', 'active')->get() as $row) {
                    if (self::issuedByCa((string) $row['pem'], (string) $ca['pem'])) {
                        self::enqueueCertificateInvalidation($transaction, $row, 'crl-' . $state);
                    }
                }
            }
        };
        if ($connection->transactionDepth() > 0) {
            $run($connection);
            return;
        }
        $connection->transaction($run, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 新证登记后给同主体其余活动证书写入重叠截止；零秒或已过有效期则立即吊销。
     */
    private static function retireOtherCertificates(Connection $transaction, string $principalId, string $keepId, int $seconds, int $newNotAfter, int $now): void
    {
        foreach ($transaction->table('broker_access_certificates')->where('principal_id', '=', $principalId)->where('status', '=', 'active')->get() as $row) {
            if ((string) $row['id'] === $keepId) {
                continue;
            }
            $until = $seconds === 0 ? $now : min($now + $seconds, (int) $row['not_after'], $newNotAfter);
            if ($seconds === 0 || $until <= $now) {
                $transaction->table('broker_access_certificates')->where('id', '=', $row['id'])
                    ->update(['status' => 'revoked', 'revoked_at' => $now, 'overlap_until' => 0]);
            } else {
                $transaction->table('broker_access_certificates')->where('id', '=', $row['id'])->update(['overlap_until' => $until]);
            }
        }
    }

    /**
     * 只给仍持有该证书身份的观察连接排队撤权，不能扩大到同主体新证会话。
     *
     * @param array<string, mixed> $certificate
     */
    private static function enqueueCertificateInvalidation(Connection $transaction, array $certificate, string $actor): void
    {
        $queued = [];
        $pending = [];
        foreach ($transaction->table('broker_access_invalidations')->whereNull('completed_at')->get() as $row) {
            $existing = $row['access_identity'] === null ? null : json_decode((string) $row['access_identity'], true, 8, JSON_THROW_ON_ERROR);
            if (is_array($existing) && self::identityMatchesCertificates($existing, [$certificate])) {
                $pending[(string) $row['client_id']] = true;
            }
        }
        foreach ($transaction->table('broker_resource_connections')->get() as $connection) {
            $identity = $connection['access_identity'] === null ? null : json_decode((string) $connection['access_identity'], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($identity) || (string) ($identity['authentication_method'] ?? '') !== 'mtls') {
                continue;
            }
            if (!self::identityMatchesCertificates($identity, [$certificate])) {
                continue;
            }
            $clientId = (string) $connection['client_id'];
            if (isset($queued[$clientId]) || isset($pending[$clientId])) {
                continue;
            }
            $queued[$clientId] = $identity;
        }
        foreach ($queued as $clientId => $identity) {
            $transaction->table('broker_access_invalidations')->insert([
                'id' => bin2hex(random_bytes(16)), 'client_id' => $clientId,
                'principal' => (string) ($identity['principal_id'] ?? $certificate['principal_id']),
                'actor' => $actor, 'access_identity' => json_encode($identity, JSON_THROW_ON_ERROR),
                'requested_at' => time(), 'completed_at' => null, 'node_id' => null,
            ]);
        }
    }

    /**
     * 发布状态映射为可对账阶段：受理、执行、完成、失败或未知。
     *
     * @param list<array<string, mixed>> $nodes
     */
    private static function operationStage(string $status, array $nodes, int $pending): string
    {
        if ($status === 'effective' || $status === 'rolled_back') {
            return 'completed';
        }
        if ($status === 'failed') {
            return 'failed';
        }
        if ($status === 'pending' && $nodes === [] && $pending === 0) {
            return 'accepted';
        }
        if ($status === 'pending' || $status === 'partial') {
            return 'executing';
        }
        return 'unknown';
    }

    /**
     * 重叠秒数：缺省在仍有旧证时为 24 小时，显式 0–86400。
     *
     * @param array<string, mixed> $item
     * @throws HttpError 类型或范围非法。
     */
    private static function overlapSeconds(array $item, bool $hasOthers): int
    {
        if (!array_key_exists('overlap_seconds', $item)) {
            return $hasOthers ? self::CERTIFICATE_OVERLAP_MAX : 0;
        }
        $value = $item['overlap_seconds'];
        if (is_int($value)) {
            $seconds = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $value) === 1) {
            $seconds = (int) $value;
        } else {
            throw new HttpError(422, 'broker_access_overlap_invalid');
        }
        if ($seconds < 0 || $seconds > self::CERTIFICATE_OVERLAP_MAX) {
            throw new HttpError(422, 'broker_access_overlap_invalid');
        }
        return $seconds;
    }

    /**
     * mTLS 身份是否属于给定证书集合；按凭据标识和代次精确匹配。
     *
     * @param array<string, mixed> $identity
     * @param list<array<string, mixed>> $certificates
     */
    private static function identityMatchesCertificates(array $identity, array $certificates): bool
    {
        $credentialId = (string) ($identity['credential_id'] ?? '');
        $version = (int) ($identity['credential_version'] ?? 0);
        foreach ($certificates as $certificate) {
            if ($credentialId === (string) $certificate['id'] && $version === (int) $certificate['certificate_version']) {
                return true;
            }
        }
        return false;
    }

    /**
     * 只接受单张公钥证书；拒绝私钥、过期、尚未生效及用途不符的 PEM。
     *
     * @return array{fingerprint:string,subject:string,not_after:int,pem:string,serial:string}
     * @throws HttpError PEM 非法或不是当前要求的 CA/客户端证书。
     */
    private static function parsePublicCertificate(string $pem, bool $requireCa): array
    {
        $pem = str_replace(["\r\n", "\r"], "\n", $pem);
        if ($pem === '' || strlen($pem) < 64 || strlen($pem) > 16384 || str_contains($pem, "\0")
            || str_contains($pem, 'PRIVATE KEY-----')) {
            throw new HttpError(422, 'broker_access_certificate_invalid');
        }
        if (substr_count($pem, '-----BEGIN CERTIFICATE-----') !== 1) {
            throw new HttpError(422, 'broker_access_certificate_invalid');
        }
        $certificate = openssl_x509_read($pem);
        if ($certificate === false) {
            throw new HttpError(422, 'broker_access_certificate_invalid');
        }
        $parsed = openssl_x509_parse($certificate, false);
        if (!is_array($parsed)) {
            $parsed = openssl_x509_parse($pem, false);
        }
        if (!is_array($parsed)) {
            $parsed = openssl_x509_parse($pem);
        }
        if (is_object($parsed)) {
            $encodedObject = json_encode($parsed);
            $parsed = is_string($encodedObject) ? json_decode($encodedObject, true) : false;
        }
        $fingerprint = self::pemSha256($pem);
        if ($fingerprint === '') {
            $raw = openssl_x509_fingerprint($certificate, 'sha256', false);
            if (is_string($raw) && strlen($raw) === 32) {
                $raw = bin2hex($raw);
            } elseif (!is_string($raw)) {
                $raw = openssl_x509_fingerprint($pem, 'sha256', false);
                if (is_string($raw) && strlen($raw) === 32) {
                    $raw = bin2hex($raw);
                }
            }
            $fingerprint = is_string($raw) ? $raw : '';
        }
        $extensions = [];
        $blob = '';
        if (is_array($parsed) && isset($parsed['extensions'])) {
            $rawExt = $parsed['extensions'];
            if (is_array($rawExt)) {
                $extensions = $rawExt;
            } elseif (is_string($rawExt)) {
                $blob = $rawExt;
            } else {
                $encoded = json_encode($rawExt);
                $blob = is_string($encoded) ? $encoded : '';
            }
        }
        $constraints = self::namedExtensionText($extensions, 'basicConstraints');
        $usage = self::namedExtensionText($extensions, 'extendedKeyUsage');
        $keyUsage = self::namedExtensionText($extensions, 'keyUsage');
        if ($constraints === '') {
            $constraints = $blob;
        }
        if ($usage === '') {
            $usage = $blob;
        }
        $encodedParsed = is_array($parsed) ? json_encode($parsed) : '';
        $flat = self::flattenCertificateText($constraints . ',' . $keyUsage . ',' . $blob . ',' . (is_string($encodedParsed) ? $encodedParsed : ''));
        $isCa = str_contains($flat, 'CA:TRUE') || str_contains($flat, 'CA:1')
            || str_contains($flat, 'KEYCERTSIGN') || str_contains($flat, 'CERTIFICATESIGN');
        $isClient = str_contains(strtolower($usage . $blob), 'clientauth') || str_contains($usage . $blob, 'TLS Web Client Authentication');
        if (!is_array($parsed) || $fingerprint === '' || ($requireCa && !$isCa) || (!$requireCa && ($isCa || !$isClient))) {
            throw new HttpError(422, 'broker_access_certificate_invalid');
        }
        $notAfter = self::certificateUnixTime($parsed['validTo_time_t'] ?? null, $parsed['validTo'] ?? null);
        $notBefore = self::certificateUnixTime($parsed['validFrom_time_t'] ?? null, $parsed['validFrom'] ?? null);
        $now = time();
        if ($notAfter <= $now || $notBefore > $now) {
            throw new HttpError(422, 'broker_access_certificate_invalid');
        }
        $subject = (string) ($parsed['name'] ?? '');
        if ($subject === '') {
            $subject = 'unknown';
        }
        if (strlen($subject) > 256) {
            $subject = substr($subject, 0, 256);
        }
        $exported = $pem;
        $normalized = strtolower(str_replace(':', '', $fingerprint));
        if (!self::isSha256Hex($normalized)) {
            throw new HttpError(422, 'broker_access_certificate_invalid');
        }
        $serial = '';
        if (isset($parsed['serialNumberHex']) && is_string($parsed['serialNumberHex'])) {
            $serial = CertificateRevocationList::serialHex($parsed['serialNumberHex']);
        } elseif (isset($parsed['serialNumber']) && (is_int($parsed['serialNumber']) || is_string($parsed['serialNumber']))) {
            $serial = CertificateRevocationList::serialHex(dechex((int) $parsed['serialNumber']));
        }
        if ($serial === '' && !$requireCa) {
            throw new HttpError(422, 'broker_access_certificate_invalid');
        }
        return ['fingerprint' => $normalized, 'subject' => $subject, 'not_after' => $notAfter, 'pem' => $exported, 'serial' => $serial];
    }

    /** 从 PEM 的 DER 计算 SHA-256，不依赖 openssl_x509_fingerprint 的十六进制约定。 */
    private static function pemSha256(string $pem): string
    {
        $begin = strpos($pem, '-----BEGIN CERTIFICATE-----');
        $end = strpos($pem, '-----END CERTIFICATE-----');
        if ($begin === false || $end === false || $end <= $begin) {
            return '';
        }
        $body = substr($pem, $begin + 27, $end - $begin - 27);
        $body = str_replace(["\r", "\n", ' ', "\t"], '', $body);
        $der = base64_decode($body, true);
        if (!is_string($der) || $der === '') {
            return '';
        }
        $hash = hash('sha256', $der);
        return is_string($hash) ? $hash : '';
    }

    /**
     * 按扩展名取值；TypePHP 可能改变大小写或把 CA:TRUE 收成数组。
     *
     * @param array<string, mixed> $extensions
     */
    private static function namedExtensionText(array $extensions, string $name): string
    {
        $wanted = strtolower($name);
        foreach ($extensions as $key => $value) {
            if (is_string($key) && strtolower($key) === $wanted) {
                return self::extensionText($value, $key);
            }
        }
        $text = '';
        foreach ($extensions as $key => $value) {
            $text .= self::extensionText($value, is_string($key) ? $key : '') . ',';
        }
        return $text;
    }

    /** 把 OpenSSL 扩展值收成可检索文本；保留键名和布尔。 */
    private static function extensionText(mixed $value, string $key = ''): string
    {
        $prefix = $key === '' ? '' : $key . ':';
        if (is_bool($value)) {
            return $prefix . ($value ? 'TRUE' : 'FALSE');
        }
        if (is_int($value) || is_float($value)) {
            return $prefix . (string) $value;
        }
        if (is_string($value)) {
            return $prefix . $value;
        }
        if (!is_array($value)) {
            return $prefix;
        }
        $text = $prefix;
        foreach ($value as $name => $item) {
            $text .= self::extensionText($item, is_string($name) ? $name : '') . ',';
        }
        return $text;
    }

    /** 去掉空格、下划线和引号后再判断用途，兼容 JSON 化的扩展表。 */
    private static function flattenCertificateText(string $value): string
    {
        return str_replace([' ', '_', '"', "'"], '', strtoupper($value));
    }

    /** openssl_x509_parse 的 unix 秒或 ASN.1 UTCTime/GeneralizedTime。 */
    private static function certificateUnixTime(mixed $unix, mixed $asn1): int
    {
        if (is_int($unix) && $unix > 0) {
            return $unix;
        }
        if (is_float($unix) && $unix > 0) {
            return (int) $unix;
        }
        if (is_string($unix) && $unix !== '' && strspn($unix, '0123456789') === strlen($unix)) {
            return (int) $unix;
        }
        if (!is_string($asn1) || $asn1 === '') {
            return 0;
        }
        $value = strtoupper(trim($asn1));
        $year = 0;
        $rest = '';
        if (strlen($value) === 13 && str_ends_with($value, 'Z')) {
            $year = (int) substr($value, 0, 2);
            $year += $year < 50 ? 2000 : 1900;
            $rest = substr($value, 2, 10);
        } elseif (strlen($value) === 15 && str_ends_with($value, 'Z')) {
            $year = (int) substr($value, 0, 4);
            $rest = substr($value, 4, 10);
        } else {
            return 0;
        }
        $month = (int) substr($rest, 0, 2);
        $day = (int) substr($rest, 2, 2);
        $hour = (int) substr($rest, 4, 2);
        $minute = (int) substr($rest, 6, 2);
        $second = (int) substr($rest, 8, 2);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return 0;
        }
        return (int) gmmktime($hour, $minute, $second, $month, $day, $year);
    }

    /** SHA-256 指纹必须是 64 位小写十六进制。 */
    private static function isSha256Hex(string $value): bool
    {
        if (strlen($value) !== 64) {
            return false;
        }
        $index = 0;
        while ($index < 64) {
            $byte = $value[$index];
            if (($byte < '0' || $byte > '9') && ($byte < 'a' || $byte > 'f')) {
                return false;
            }
            $index++;
        }
        return true;
    }

    /** 叶子必须由当前范围已登记 CA 的公钥验签通过；节点握手 CA 文件不在此核对。 */
    private static function issuedByRegisteredCa(Connection $transaction, ?string $tenantId, string $pem): bool
    {
        $query = $transaction->table('broker_access_cas');
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        foreach ($query->get() as $row) {
            if (self::issuedByCa($pem, (string) $row['pem'])) {
                return true;
            }
        }
        return false;
    }

    /** 用 CA 公钥验证叶子签名；解析失败视为未签发。 */
    private static function issuedByCa(string $pem, string $caPem): bool
    {
        $certificate = openssl_x509_read($pem);
        $authority = openssl_x509_read($caPem);
        if ($certificate === false || $authority === false) {
            return false;
        }
        $key = openssl_pkey_get_public($authority);
        if ($key === false) {
            return false;
        }
        $verified = openssl_x509_verify($certificate, $key);
        return $verified === 1 || $verified === true;
    }

    /**
     * 回退时恢复 URL/PEM 窗口，已接纳序列号与平台吊销只增不减。
     *
     * @param array<string, mixed> $snapshot
     */
    private static function restoreCrls(Connection $transaction, ?string $tenantId, array $snapshot, int $now): void
    {
        $crls = isset($snapshot['crls']) && is_array($snapshot['crls']) ? $snapshot['crls'] : [];
        foreach ($crls as $row) {
            if (!is_array($row) || preg_match('/^[a-f0-9]{32}$/D', (string) ($row['ca_id'] ?? '')) !== 1) {
                continue;
            }
            $caId = (string) $row['ca_id'];
            if ($transaction->table('broker_access_cas')->where('id', '=', $caId)->first() === null) {
                continue;
            }
            $serials = isset($row['serials']) && is_array($row['serials']) ? $row['serials'] : [];
            $known = self::crlSerialMap($transaction, $caId);
            foreach ($serials as $serial) {
                if (!is_string($serial)) {
                    continue;
                }
                $normalized = CertificateRevocationList::serialHex($serial);
                if ($normalized === '' || isset($known[$normalized])) {
                    continue;
                }
                $transaction->table('broker_access_crl_serials')->insert(['ca_id' => $caId, 'serial' => $normalized]);
                $known[$normalized] = true;
            }
            $existing = $transaction->table('broker_access_crls')->where('ca_id', '=', $caId)->first();
            $values = [
                'pem' => (string) ($row['pem'] ?? ''), 'this_update' => (int) ($row['this_update'] ?? 0),
                'next_update' => (int) ($row['next_update'] ?? 0), 'url' => (string) ($row['url'] ?? ''),
                'fetch_interval' => (int) ($row['fetch_interval'] ?? self::CRL_FETCH_DEFAULT),
                'fetch_status' => $existing !== null ? (string) $existing['fetch_status'] : 'idle',
                'fetch_error' => $existing !== null ? (string) $existing['fetch_error'] : '',
                'fetched_at' => $existing !== null ? (int) $existing['fetched_at'] : 0,
                'accepted_at' => $existing !== null ? (int) $existing['accepted_at'] : 0,
                'fetch_due_at' => $existing !== null ? (int) $existing['fetch_due_at'] : 0,
                'configured' => (int) ($row['configured'] ?? 0) === 1 || $known !== [] ? 1 : 0,
                'updated_at' => $now,
            ];
            if ($existing === null) {
                $transaction->table('broker_access_crls')->insert(['ca_id' => $caId] + $values);
            } else {
                $transaction->table('broker_access_crls')->where('ca_id', '=', $caId)->update($values);
            }
        }
        $platform = isset($snapshot['platform_serials']) && is_array($snapshot['platform_serials']) ? $snapshot['platform_serials'] : [];
        $key = self::tenantKey($tenantId);
        foreach ($platform as $serial) {
            if (!is_string($serial)) {
                continue;
            }
            $normalized = CertificateRevocationList::serialHex($serial);
            if ($normalized === '' || $transaction->table('broker_access_platform_serials')->where('tenant_key', '=', $key)->where('serial', '=', $normalized)->first() !== null) {
                continue;
            }
            $transaction->table('broker_access_platform_serials')->insert([
                'tenant_key' => $key, 'serial' => $normalized, 'actor_id' => 'restore', 'created_at' => $now,
            ]);
        }
    }

    /**
     * 新增已接纳序列号，或从可用变为缺失/过期，都算收紧。
     *
     * @param list<mixed> $old
     * @param list<mixed> $new
     */
    private static function crlsTightened(array $old, array $new): bool
    {
        $previous = [];
        foreach ($old as $row) {
            if (is_array($row) && isset($row['ca_id'])) {
                $previous[(string) $row['ca_id']] = $row;
            }
        }
        foreach ($new as $row) {
            if (!is_array($row) || !isset($row['ca_id'])) {
                continue;
            }
            $before = $previous[(string) $row['ca_id']] ?? ['serials' => [], 'state' => 'none', 'configured' => 0];
            $oldSerials = isset($before['serials']) && is_array($before['serials']) ? $before['serials'] : [];
            $newSerials = isset($row['serials']) && is_array($row['serials']) ? $row['serials'] : [];
            foreach ($newSerials as $serial) {
                if (!in_array($serial, $oldSerials, true)) {
                    return true;
                }
            }
            $oldState = (string) ($before['state'] ?? 'none');
            $newState = (string) ($row['state'] ?? 'none');
            if (($newState === 'missing' || $newState === 'expired') && $oldState !== 'missing' && $oldState !== 'expired') {
                return true;
            }
        }
        return false;
    }

    /**
     * 把新增 CRL 序列号、缺失/过期 CA 以及平台序列号对应的在线证书排队撤权。
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function enqueueCrlInvalidations(Connection $transaction, array $left, array $right, string $actor): void
    {
        $oldCrls = isset($left['crls']) && is_array($left['crls']) ? $left['crls'] : [];
        $newCrls = isset($right['crls']) && is_array($right['crls']) ? $right['crls'] : [];
        $previous = [];
        foreach ($oldCrls as $row) {
            if (is_array($row) && isset($row['ca_id'])) {
                $previous[(string) $row['ca_id']] = $row;
            }
        }
        foreach ($newCrls as $row) {
            if (!is_array($row) || !isset($row['ca_id'])) {
                continue;
            }
            $caId = (string) $row['ca_id'];
            $before = $previous[$caId] ?? ['serials' => [], 'state' => 'none'];
            $oldSerials = isset($before['serials']) && is_array($before['serials']) ? $before['serials'] : [];
            $newSerials = isset($row['serials']) && is_array($row['serials']) ? $row['serials'] : [];
            $added = [];
            foreach ($newSerials as $serial) {
                if (is_string($serial) && !in_array($serial, $oldSerials, true)) {
                    $added[$serial] = true;
                }
            }
            $oldState = (string) ($before['state'] ?? 'none');
            $newState = (string) ($row['state'] ?? 'none');
            $closed = ($newState === 'missing' || $newState === 'expired') && $oldState !== 'missing' && $oldState !== 'expired';
            if ($added === [] && !$closed) {
                continue;
            }
            $ca = $transaction->table('broker_access_cas')->where('id', '=', $caId)->first();
            if ($ca === null) {
                continue;
            }
            foreach ($transaction->table('broker_access_certificates')->where('status', '=', 'active')->get() as $certificate) {
                if (!self::issuedByCa((string) $certificate['pem'], (string) $ca['pem'])) {
                    continue;
                }
                $serial = self::certificateSerial($certificate);
                if ($closed || isset($added[$serial])) {
                    self::enqueueCertificateInvalidation($transaction, $certificate, $actor);
                }
            }
        }
        $oldPlatform = isset($left['platform_serials']) && is_array($left['platform_serials']) ? $left['platform_serials'] : [];
        $newPlatform = isset($right['platform_serials']) && is_array($right['platform_serials']) ? $right['platform_serials'] : [];
        $addedPlatform = [];
        foreach ($newPlatform as $serial) {
            if (is_string($serial) && !in_array($serial, $oldPlatform, true)) {
                $addedPlatform[$serial] = true;
            }
        }
        if ($addedPlatform === []) {
            return;
        }
        foreach ($transaction->table('broker_access_certificates')->where('status', '=', 'active')->get() as $certificate) {
            if (isset($addedPlatform[self::certificateSerial($certificate)])) {
                self::enqueueCertificateInvalidation($transaction, $certificate, $actor);
            }
        }
    }

    /**
     * CA 列表投影：获取状态与执行状态分开，不含 PEM。
     *
     * @return array<string, mixed>
     */
    private static function projectCrl(Connection $connection, string $caId): array
    {
        $row = $connection->table('broker_access_crls')->where('ca_id', '=', $caId)->first();
        $serials = array_keys(self::crlSerialMap($connection, $caId));
        $state = self::crlAvailability($row, time());
        $pending = 0;
        if ($state === 'missing' || $state === 'expired' || $serials !== []) {
            $pending = count($connection->table('broker_access_invalidations')->whereNull('completed_at')->limit(8)->get());
        }
        $recovery = '';
        if ($state === 'missing') {
            $recovery = '导入仍有效的签名 CRL，或等待受控 HTTPS 源刷新成功';
        } elseif ($state === 'expired') {
            $recovery = '导入 thisUpdate 更新且仍在有效期内的签名 CRL，或等待受控 HTTPS 源刷新成功';
        }
        return [
            'configured' => $row !== null && (int) $row['configured'] === 1,
            'source' => $row !== null && (string) $row['url'] !== '' ? 'https' : ($row !== null && (string) $row['pem'] !== '' ? 'import' : ''),
            'url' => $row === null ? '' : (string) $row['url'],
            'fetch_interval' => $row === null ? self::CRL_FETCH_DEFAULT : (int) $row['fetch_interval'],
            'fetch_status' => $row === null ? 'idle' : (string) $row['fetch_status'],
            'fetch_error' => $row === null ? '' : (string) $row['fetch_error'],
            'fetched_at' => $row === null ? 0 : (int) $row['fetched_at'],
            'accepted_at' => $row === null ? 0 : (int) $row['accepted_at'],
            'this_update' => $row === null ? 0 : (int) $row['this_update'],
            'next_update' => $row === null ? 0 : (int) $row['next_update'],
            'serial_count' => count($serials),
            'serials' => $serials,
            'state' => $state,
            'recovery' => $recovery,
            'execute_stage' => $pending > 0 ? 'executing' : ($row !== null && (int) $row['accepted_at'] > 0 ? 'completed' : 'accepted'),
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function crlAvailability(?array $row, int $now): string
    {
        if ($row === null || (int) ($row['configured'] ?? 0) !== 1) {
            return 'none';
        }
        $pem = (string) ($row['pem'] ?? '');
        $thisUpdate = (int) ($row['this_update'] ?? 0);
        $nextUpdate = (int) ($row['next_update'] ?? 0);
        if ($pem === '' || $nextUpdate <= 0) {
            return 'missing';
        }
        if ($now < $thisUpdate || $now > $nextUpdate) {
            return 'expired';
        }
        return 'active';
    }

    /**
     * @return array<string, true>
     */
    private static function crlSerialMap(Connection $connection, string $caId): array
    {
        $map = [];
        foreach ($connection->table('broker_access_crl_serials')->where('ca_id', '=', $caId)->get() as $row) {
            $map[(string) $row['serial']] = true;
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function certificateSerial(array $row): string
    {
        $stored = CertificateRevocationList::serialHex((string) ($row['serial'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        $pem = (string) ($row['pem'] ?? '');
        if ($pem === '') {
            return '';
        }
        $parsed = openssl_x509_parse($pem, false);
        if (!is_array($parsed)) {
            return '';
        }
        if (isset($parsed['serialNumberHex']) && is_string($parsed['serialNumberHex'])) {
            return CertificateRevocationList::serialHex($parsed['serialNumberHex']);
        }
        if (isset($parsed['serialNumber'])) {
            return CertificateRevocationList::serialHex(dechex((int) $parsed['serialNumber']));
        }
        return '';
    }

    private static function platformRevokes(Connection $connection, ?string $tenantId, string $serial): bool
    {
        if ($serial === '') {
            return true;
        }
        return $connection->table('broker_access_platform_serials')->where('tenant_key', '=', self::tenantKey($tenantId))->where('serial', '=', $serial)->first() !== null;
    }

    private static function principalTenant(Connection $connection, string $principalId): ?string
    {
        $row = $connection->table('broker_access_principals')->where('id', '=', $principalId)->first();
        if ($row === null || $row['tenant_id'] === null) {
            return null;
        }
        return (string) $row['tenant_id'];
    }

    private static function tenantKey(?string $tenantId): string
    {
        return $tenantId === null ? '-' : $tenantId;
    }

    private static function crlTenant(Connection $connection, string $caId): ?string
    {
        $ca = $connection->table('broker_access_cas')->where('id', '=', $caId)->first();
        if ($ca === null || $ca['tenant_id'] === null) {
            return null;
        }
        return (string) $ca['tenant_id'];
    }

    private static function touchCrlFetch(Connection $transaction, string $caId, string $status, string $error): void
    {
        $transaction->table('broker_access_crls')->where('ca_id', '=', $caId)->update([
            'fetch_status' => $status, 'fetch_error' => $error, 'fetched_at' => time(), 'updated_at' => time(),
        ]);
    }

    /** 只接受 https 且不含用户信息；空串表示未配置。 */
    private static function httpsUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 2048) {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return '';
        }
        return $value;
    }

    /** 精确相等，或以 `/` 结尾的前缀匹配；不把 `+`/`#` 当通配。 */
    private static function topicMatches(string $grant, string $topic): bool
    {
        if ($grant === $topic) {
            return true;
        }
        return str_ends_with($grant, '/') && str_starts_with($topic, $grant);
    }

    /** JSON 可能是布尔或 0/1；只把明确开通写成授权位。 */
    private static function flag(mixed $value): int
    {
        return $value === true || $value === 1 ? 1 : 0;
    }
}
