<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Mqtt\AccessIdentity;
use Type\Mqtt\ConnectPacket;
use Type\Orm\Connection;

/**
 * 登录会话绑定的 MQTT 调试短期凭据；口令只在签发响应出现一次，不进入接入快照。
 *
 * 期限最长十分钟，且不超过剩余登录或模拟会话。独立前缀来自引导授权，双端租户使用 `iot/{tenant}/`。
 * 退出、换租户或到期会写入撤权意图，健康节点约 5 秒内断开旧连接。
 */
final class DebugService
{
    /** 调试凭据默认且最长存活秒数。 */
    private const TTL = 600;
    /** 同一人员最多同时占用的调试连接。 */
    public const PERSON_LIMIT = 2;
    /** 集群内调试连接全局上限。 */
    public const GLOBAL_LIMIT = 20;
    /** 未形成观察或节点失联后占用行失效秒数。 */
    private const OCCUPANCY_STALE = 15;

    /**
     * 当前会话在本范围内的活动凭据；不含口令。换租户时先撤销其他范围的同行凭据。
     *
     * @return array{credential:?array<string, mixed>,transport:array<string, mixed>,occupancy:array<string, int>}
     */
    public static function current(Connection $connection, Identity $identity, ?string $tenantId): array
    {
        $sessionId = self::sessionId($identity);
        $actorId = (string) ($identity->attributes()['actor_id'] ?? $identity->subject());
        self::revokeForeign($connection, $sessionId, $tenantId, $actorId);
        $row = self::activeRow($connection, $sessionId, $tenantId);
        $transport = self::transport($connection);
        return ['credential' => $row === null ? null : self::project($row, $transport), 'transport' => $transport,
            'occupancy' => self::occupancy($connection, $actorId)];
    }

    /**
     * 签发一对只返回一次的 CONNECT 口令，并撤销同一会话仍活动的旧凭据。
     *
     * @return array<string, mixed>
     * @throws HttpError 剩余会话不足、WSS 未监听或没有可订阅前缀。
     */
    public static function issue(Connection $connection, Identity $identity, ?string $tenantId): array
    {
        $sessionId = self::sessionId($identity);
        $attributes = $identity->attributes();
        $remaining = (int) ($attributes['expires_at'] ?? 0) - time();
        if ($remaining < 1) {
            throw new HttpError(403, 'broker_debug_session_expired');
        }
        $transport = self::transport($connection);
        if ($transport['available'] !== true) {
            throw new HttpError(409, 'broker_debug_wss_unavailable');
        }
        $topics = self::topics($connection, $tenantId);
        $actorId = (string) ($attributes['actor_id'] ?? $identity->subject());
        $actorRealm = (string) ($attributes['actor_realm'] ?? ($tenantId === null ? 'broker' : 'customer'));
        $supportId = (string) ($attributes['impersonation_id'] ?? '');
        $ttl = min(self::TTL, $remaining);
        $password = bin2hex(random_bytes(16));
        $row = $connection->transaction(static function (Connection $transaction) use ($sessionId, $tenantId, $actorId, $actorRealm, $supportId, $topics, $ttl, $password): array {
            self::revokeActive($transaction, $sessionId, $actorId);
            $now = time();
            $id = bin2hex(random_bytes(16));
            $values = [
                'id' => $id, 'principal_id' => $id, 'tenant_id' => $tenantId, 'actor_id' => $actorId, 'actor_realm' => $actorRealm,
                'session_id' => $sessionId, 'support_id' => $supportId === '' ? null : $supportId, 'login' => 'debug:' . bin2hex(random_bytes(16)),
                'secret_hash' => hash('sha256', $password), 'credential_version' => 1, 'client_id' => bin2hex(random_bytes(16)),
                'subscribe_topic' => $topics['subscribe'], 'publish_topic' => $topics['publish'], 'status' => 'active',
                'expires_at' => $now + $ttl, 'created_at' => $now, 'revoked_at' => null,
            ];
            $transaction->table('broker_debug_credentials')->insert($values);
            return $values;
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        $projection = self::project($row, $transport);
        $projection['password'] = $password;
        $projection['occupancy'] = self::occupancy($connection, $actorId);
        return $projection;
    }

    /**
     * 撤销当前会话全部活动调试凭据；退出登录必须在删除会话前调用。
     */
    public static function revokeSession(Connection $connection, string $sessionId, string $actorId): void
    {
        if ($sessionId === '' || preg_match('/^[a-f0-9]{32,64}$/D', $sessionId) !== 1) {
            return;
        }
        if ($connection->transactionDepth() > 0) {
            self::revokeActive($connection, $sessionId, $actorId);
            return;
        }
        $connection->transaction(static function (Connection $transaction) use ($sessionId, $actorId): void {
            self::revokeActive($transaction, $sessionId, $actorId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * HTTP 撤销入口：当前登录会话的活动凭据全部失效。
     *
     * @return array{revoked:int}
     */
    public static function revoke(Connection $connection, Identity $identity): array
    {
        $sessionId = self::sessionId($identity);
        $before = $connection->table('broker_debug_credentials')->where('session_id', '=', $sessionId)->where('status', '=', 'active')->get();
        self::revokeSession($connection, $sessionId, (string) ($identity->attributes()['actor_id'] ?? $identity->subject()));
        return ['revoked' => count($before)];
    }

    /**
     * 到期活动凭据改为吊销并排队撤权，供节点约 5 秒内断开。
     */
    public static function expire(Connection $connection): void
    {
        $now = time();
        $rows = $connection->table('broker_debug_credentials')->where('status', '=', 'active')->where('expires_at', '<=', $now)->get();
        if ($rows === []) {
            return;
        }
        $connection->transaction(static function (Connection $transaction) use ($rows, $now): void {
            foreach ($rows as $row) {
                if ((string) $row['status'] !== 'active') {
                    continue;
                }
                $transaction->table('broker_debug_credentials')->where('id', '=', $row['id'])->update([
                    'status' => 'revoked', 'revoked_at' => $now,
                ]);
                self::enqueue($transaction, $row, 'expiry');
            }
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /**
     * CONNECT 用户名口令核对活动调试凭据；人员令牌散列不会匹配。
     */
    public static function authenticate(Connection $connection, ConnectPacket $connect): ?AccessIdentity
    {
        if ($connect->username === null || $connect->password === null || !str_starts_with($connect->username, 'debug:')) {
            return null;
        }
        return self::match($connection, $connect->username, hash('sha256', $connect->password), $connect->clientId, $connect->cleanStart, $connect->will, $connect->version, $connect->keepAlive, (int) ($connect->properties[0x11] ?? 0));
    }

    /**
     * 设备接入工作进程只传递口令散列；契约与独立节点相同。
     *
     * @param array<string, mixed> $request
     */
    public static function authenticateHashed(Connection $connection, array $request): ?AccessIdentity
    {
        return self::match(
            $connection,
            (string) ($request['username'] ?? ''),
            (string) ($request['verifier'] ?? ''),
            (string) ($request['client_id'] ?? ''),
            ($request['clean_start'] ?? false) === true,
            ($request['will'] ?? false) === true,
            (int) ($request['protocol'] ?? 0),
            (int) ($request['keep_alive'] ?? 0),
            (int) ($request['expiry'] ?? -1)
        );
    }

    /** 每次发布/订阅按当前行重判；过期、吊销或越权前缀都拒绝。 */
    public static function authorize(Connection $connection, AccessIdentity $identity, string $topic, string $action, int $qos): bool
    {
        if ($identity->authenticationMethod !== 'debug') {
            return false;
        }
        $row = $connection->table('broker_debug_credentials')->where('id', '=', $identity->credentialId)
            ->where('principal_id', '=', $identity->principalId)->where('status', '=', 'active')->first();
        if ($row === null || (int) ($row['recovery_verified'] ?? 1) !== 1
            || (int) $row['credential_version'] !== $identity->credentialVersion || (int) $row['expires_at'] <= time()) {
            return false;
        }
        if ($qos < 0 || $qos > 1) {
            return false;
        }
        $grant = $action === 'publish' ? (string) $row['publish_topic'] : ($action === 'subscribe' ? (string) $row['subscribe_topic'] : '');
        return $grant !== '' && self::topicMatches($grant, $topic);
    }

    /**
     * 双端设备工作进程的调试分支：认证、授权与无设备行的连接观察。
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function deviceAccess(Connection $connection, array $request): array
    {
        $action = (string) ($request['action'] ?? '');
        $identity = isset($request['access_identity']) && is_array($request['access_identity'])
            ? AccessIdentity::fromData($request['access_identity']) : null;
        if ($action === 'authenticate') {
            if (($request['secure'] ?? false) !== true) {
                return ['allowed' => false];
            }
            $matched = self::authenticateHashed($connection, $request);
            if ($matched === null) {
                return ['allowed' => false];
            }
            if (!self::admit($connection, $matched, (string) ($request['client_id'] ?? ''), (string) ($request['node_id'] ?? ''))) {
                return ['allowed' => false, 'reason' => 'quota'];
            }
            $row = $connection->table('broker_debug_credentials')->where('id', '=', $matched->credentialId)->first();
            $tenantId = $row === null ? '' : (string) ($row['tenant_id'] ?? '');
            if ($row === null || preg_match('/^[a-f0-9]{32}$/D', $tenantId) !== 1) {
                return ['allowed' => false];
            }
            return ['allowed' => true, 'access_identity' => $matched->data(), 'resource_scope' => 'iot:' . $tenantId];
        }
        if ($identity === null || $identity->authenticationMethod !== 'debug') {
            return ['allowed' => false];
        }
        if ($action === 'authorize') {
            return ['allowed' => self::authorize($connection, $identity, (string) ($request['topic'] ?? ''), (string) ($request['operation'] ?? ''), (int) ($request['qos'] ?? -1))];
        }
        if ($action === 'connected') {
            if (isset($request['resource']) && is_array($request['resource'])) {
                ResourceObservations::connected($connection, (string) $request['node_id'], (string) $request['run_id'], $request['resource'], (int) $request['observed_at']);
                self::confirm($connection, $request['resource'], (string) $request['node_id']);
            }
            return ['allowed' => true];
        }
        if ($action === 'disconnected') {
            ResourceObservations::disconnected($connection, (string) $request['node_id'], (string) $request['run_id'], (string) $request['owner_id']);
            self::release($connection, (string) ($request['client_id'] ?? ''), (int) ($request['observed_at'] ?? time()));
            return ['allowed' => true];
        }
        return ['allowed' => false];
    }

    /**
     * 当前节点已加载或已保存的 WSS 入口；未开端口时页面可展示原因，签发返回 409。
     *
     * @return array{available:bool,url:string,protocol:string,clean_start:bool,session_expiry:int,keep_alive:int,listen:string,wss_port:int}
     */
    public static function transport(Connection $connection): array
    {
        $listen = '127.0.0.1';
        $port = 0;
        $snapshot = self::runtimeSnapshot($connection);
        $loaded = is_array($snapshot['loaded'] ?? null) ? $snapshot['loaded'] : [];
        $config = is_array($snapshot['config'] ?? null) ? $snapshot['config'] : [];
        $first = isset($loaded[0]) && is_array($loaded[0]) ? $loaded[0] : [];
        $nodeConfig = is_array($first['config'] ?? null) ? $first['config'] : [];
        if ($nodeConfig !== []) {
            $listen = (string) ($nodeConfig['listen'] ?? $listen);
            $port = (int) ($nodeConfig['wss_port'] ?? 0);
        }
        if ($port === 0) {
            $listen = (string) ($config['listen'] ?? $listen);
            $port = (int) ($config['wss_port'] ?? 0);
        }
        if ($listen === '0.0.0.0' || $listen === '::' || $listen === '[::]') {
            $listen = '127.0.0.1';
        }
        $host = str_contains($listen, ':') && !str_starts_with($listen, '[') ? '[' . $listen . ']' : $listen;
        return [
            'available' => $port > 0, 'url' => $port > 0 ? 'wss://' . $host . ':' . $port . '/mqtt' : '',
            'protocol' => 'mqtt', 'clean_start' => true, 'session_expiry' => 0, 'keep_alive' => 30,
            'listen' => $listen, 'wss_port' => $port,
        ];
    }

    /** @return array<string, mixed> */
    private static function runtimeSnapshot(Connection $connection): array
    {
        try {
            return RuntimeService::current($connection);
        } catch (HttpError $error) {
            if ($error->errorCode() !== 'broker_runtime_not_found') {
                throw $error;
            }
            return [];
        }
    }

    /**
     * @return array{subscribe:string,publish:string}
     * @throws HttpError 没有以 `/` 结尾的可订阅前缀。
     */
    private static function topics(Connection $connection, ?string $tenantId): array
    {
        if ($tenantId !== null) {
            $prefix = 'iot/' . $tenantId . '/';
            return ['subscribe' => $prefix, 'publish' => $prefix . 'debug/'];
        }
        $principal = $connection->table('broker_access_principals')->whereNull('tenant_id')->where('enabled', '=', 1)->orderBy('id')->first();
        $grant = $principal === null ? null : $connection->table('broker_access_grants')->where('principal_id', '=', $principal['id'])->orderBy('id')->first();
        $prefix = $grant === null ? '' : (string) $grant['topic'];
        if ($prefix === '' || !str_ends_with($prefix, '/') || str_contains($prefix, '#') || str_contains($prefix, '+')) {
            throw new HttpError(422, 'broker_debug_topic_unavailable');
        }
        return ['subscribe' => $prefix, 'publish' => $prefix . 'debug/'];
    }

    /** @param array<string, mixed> $row */
    private static function match(Connection $connection, string $username, string $secretHash, string $clientId, bool $cleanStart, bool $will, int $protocol, int $keepAlive, int $expiry): ?AccessIdentity
    {
        if (preg_match('/^debug:[a-f0-9]{32}$/D', $username) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $secretHash) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $clientId) !== 1 || !$cleanStart || $will || $keepAlive !== 30
            || !in_array($protocol, [4, 5], true) || ($protocol >= 5 && $expiry !== 0)) {
            return null;
        }
        $row = $connection->table('broker_debug_credentials')->where('login', '=', $username)->where('status', '=', 'active')->first();
        if ($row === null || (int) ($row['recovery_verified'] ?? 1) !== 1 || (int) $row['expires_at'] <= time()
            || (string) $row['client_id'] !== $clientId || !hash_equals((string) $row['secret_hash'], $secretHash)) {
            return null;
        }
        return new AccessIdentity((string) $row['principal_id'], (string) $row['id'], (int) $row['credential_version'], 'debug');
    }

    /** @return array<string, mixed>|null */
    private static function activeRow(Connection $connection, string $sessionId, ?string $tenantId): ?array
    {
        $query = $connection->table('broker_debug_credentials')->where('session_id', '=', $sessionId)
            ->where('status', '=', 'active')->where('expires_at', '>', time());
        $query = $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', '=', $tenantId);
        return $query->orderBy('created_at', 'DESC')->orderBy('id')->first();
    }

    private static function revokeForeign(Connection $connection, string $sessionId, ?string $tenantId, string $actorId): void
    {
        $rows = $connection->table('broker_debug_credentials')->where('session_id', '=', $sessionId)->where('status', '=', 'active')->get();
        $now = time();
        $connection->transaction(static function (Connection $transaction) use ($rows, $tenantId, $actorId, $now): void {
            foreach ($rows as $row) {
                $current = $row['tenant_id'] === null ? null : (string) $row['tenant_id'];
                if ($current === $tenantId) {
                    continue;
                }
                $transaction->table('broker_debug_credentials')->where('id', '=', $row['id'])->update([
                    'status' => 'revoked', 'revoked_at' => $now,
                ]);
                self::enqueue($transaction, $row, $actorId);
            }
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    private static function revokeActive(Connection $transaction, string $sessionId, string $actorId): void
    {
        $now = time();
        foreach ($transaction->table('broker_debug_credentials')->where('session_id', '=', $sessionId)->where('status', '=', 'active')->get() as $row) {
            $transaction->table('broker_debug_credentials')->where('id', '=', $row['id'])->update([
                'status' => 'revoked', 'revoked_at' => $now,
            ]);
            self::enqueue($transaction, $row, $actorId);
        }
    }

    /** @param array<string, mixed> $row */
    private static function enqueue(Connection $transaction, array $row, string $actor): void
    {
        $identity = new AccessIdentity((string) $row['principal_id'], (string) $row['id'], (int) $row['credential_version'], 'debug');
        $transaction->table('broker_access_invalidations')->insert([
            'id' => bin2hex(random_bytes(16)), 'client_id' => (string) $row['client_id'], 'principal' => (string) $row['login'],
            'actor' => $actor, 'access_identity' => json_encode($identity->data(), JSON_THROW_ON_ERROR),
            'requested_at' => time(), 'completed_at' => null, 'node_id' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $transport
     * @return array<string, mixed>
     */
    private static function project(array $row, array $transport): array
    {
        return [
            'id' => $row['id'], 'username' => $row['login'], 'client_id' => $row['client_id'],
            'expires_at' => (int) $row['expires_at'], 'subscribe_topic' => $row['subscribe_topic'],
            'publish_topic' => $row['publish_topic'], 'status' => $row['status'],
            'recovery_verified' => (int) ($row['recovery_verified'] ?? 1), 'transport' => $transport,
        ];
    }

    private static function sessionId(Identity $identity): string
    {
        $sessionId = (string) ($identity->attributes()['session_id'] ?? '');
        if (preg_match('/^[a-f0-9]{32,64}$/D', $sessionId) !== 1) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $sessionId;
    }

    /**
     * 当前人员与全局调试占用；重连同一 Client ID 不计额外名额。
     *
     * @return array{person_used:int,person_limit:int,global_used:int,global_limit:int}
     */
    public static function occupancy(Connection $connection, string $actorId): array
    {
        self::reap($connection);
        $slots = self::occupiedSlots($connection, '');
        $person = 0;
        foreach ($slots as $owner) {
            if ($owner === $actorId) {
                $person++;
            }
        }
        return [
            'person_used' => $person, 'person_limit' => self::PERSON_LIMIT,
            'global_used' => count($slots), 'global_limit' => self::GLOBAL_LIMIT,
        ];
    }

    /**
     * CONNECT 在认证成功后占用名额；同一 Client ID 覆盖原行，第三人/第 21 路拒绝。
     */
    public static function admit(Connection $connection, AccessIdentity $identity, string $clientId, string $nodeId): bool
    {
        if ($identity->authenticationMethod !== 'debug' || preg_match('/^[a-f0-9]{32}$/D', $clientId) !== 1) {
            return false;
        }
        $row = $connection->table('broker_debug_credentials')->where('id', '=', $identity->credentialId)
            ->where('principal_id', '=', $identity->principalId)->where('status', '=', 'active')->first();
        if ($row === null || (int) ($row['recovery_verified'] ?? 1) !== 1 || (int) $row['expires_at'] <= time()) {
            return false;
        }
        $actorId = (string) $row['actor_id'];
        $node = preg_match('/^[a-zA-Z0-9_-]{0,64}$/D', $nodeId) === 1 ? $nodeId : '';
        return $connection->transaction(static function (Connection $transaction) use ($clientId, $actorId, $identity, $node): bool {
            $lockQuery = $transaction->table('broker_debug_quota_lock')->where('id', '=', 'default');
            $lock = ($transaction->driverName() === 'sqlite' ? $lockQuery : $lockQuery->lockForUpdate())->first();
            if ($lock === null) {
                $transaction->table('broker_debug_quota_lock')->insert(['id' => 'default']);
            }
            self::reap($transaction);
            $existing = $transaction->table('broker_debug_occupancy')->where('client_id', '=', $clientId)->first();
            if ($existing !== null) {
                $transaction->table('broker_debug_occupancy')->where('client_id', '=', $clientId)->update([
                    'credential_id' => $identity->credentialId, 'actor_id' => $actorId, 'node_id' => $node, 'occupied_at' => time(),
                ]);
                return true;
            }
            $slots = self::occupiedSlots($transaction, $clientId);
            $person = 0;
            foreach ($slots as $owner) {
                if ($owner === $actorId) {
                    $person++;
                }
            }
            if ($person >= self::PERSON_LIMIT || count($slots) >= self::GLOBAL_LIMIT) {
                return false;
            }
            $transaction->table('broker_debug_occupancy')->insert([
                'client_id' => $clientId, 'credential_id' => $identity->credentialId, 'actor_id' => $actorId,
                'node_id' => $node, 'occupied_at' => time(),
            ]);
            return true;
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /**
     * 成功 CONNACK 观察后刷新占用时间，避免心跳空窗把活连接当成过期。
     *
     * @param array<string, mixed> $resource
     */
    public static function confirm(Connection $connection, array $resource, string $nodeId): void
    {
        $identity = isset($resource['access_identity']) && is_array($resource['access_identity'])
            ? AccessIdentity::fromData($resource['access_identity']) : null;
        if ($identity === null || $identity->authenticationMethod !== 'debug') {
            return;
        }
        $clientId = (string) ($resource['client_id'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/D', $clientId) !== 1) {
            return;
        }
        self::admit($connection, $identity, $clientId, $nodeId);
    }

    /**
     * 观察行消失后释放占用；同 Client ID 仍有未过期观察时保留，避免接管误删新连接。
     */
    public static function release(Connection $connection, string $clientId, int $observedAt): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $clientId) !== 1) {
            return;
        }
        if (self::hasLiveObservation($connection, $clientId)) {
            return;
        }
        $connection->table('broker_debug_occupancy')->where('client_id', '=', $clientId)->delete();
    }

    /** 无活观察且超过空窗的占用可删；CONNECT 尚未形成观察的短窗保留。 */
    public static function reap(Connection $connection): void
    {
        $cutoff = time() - self::OCCUPANCY_STALE;
        foreach ($connection->table('broker_debug_occupancy')->where('occupied_at', '<', $cutoff)->get() as $row) {
            $clientId = (string) $row['client_id'];
            if (self::hasLiveObservation($connection, $clientId)) {
                continue;
            }
            $connection->table('broker_debug_occupancy')->where('client_id', '=', $clientId)->where('occupied_at', '<', $cutoff)->delete();
        }
    }

    /** 只刷新本节点仍有资源观察的调试占用，避免已断开的幽灵行被心跳续命。 */
    public static function touch(Connection $connection, string $nodeId): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1) {
            return;
        }
        $now = time();
        foreach ($connection->table('broker_debug_occupancy')->where('node_id', '=', $nodeId)->get() as $row) {
            $clientId = (string) $row['client_id'];
            if (!self::hasLiveObservation($connection, $clientId)) {
                continue;
            }
            $connection->table('broker_debug_occupancy')->where('client_id', '=', $clientId)->update(['occupied_at' => $now]);
        }
        self::reap($connection);
    }

    /** 未过期资源观察中仍有该 Client ID 时视为活占用。 */
    private static function hasLiveObservation(Connection $connection, string $clientId): bool
    {
        return $connection->table('broker_resource_connections', 'c')
            ->join('broker_resource_runs', 'r.run_id', '=', 'c.observation_run', 'r')
            ->where('c.client_hash', '=', hash('sha256', $clientId))
            ->where('c.client_id', '=', $clientId)
            ->where('r.expires_at', '>', time())
            ->select(['c.owner_id'])
            ->first() !== null;
    }

    /**
     * @return array<string, string> Client ID → actor_id
     */
    private static function occupiedSlots(Connection $connection, string $excludeClientId): array
    {
        $slots = [];
        foreach ($connection->table('broker_debug_occupancy')->get() as $row) {
            $clientId = (string) $row['client_id'];
            if ($clientId === $excludeClientId) {
                continue;
            }
            $slots[$clientId] = (string) $row['actor_id'];
        }
        return $slots;
    }

    private static function topicMatches(string $grant, string $topic): bool
    {
        if ($grant === $topic) {
            return true;
        }
        return str_ends_with($grant, '/') && str_starts_with($topic, $grant);
    }
}
