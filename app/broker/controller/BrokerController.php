<?php

declare(strict_types=1);

namespace app\broker\controller;

use app\broker\service\ConnectionOperations;
use app\broker\service\ResourceQueries;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Mqtt\PendingCommit;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;

/** 独立管理 HTTP 边界；只接受本宿主人员身份，节点资源不包含消息载荷或凭据。 */
#[Group(prefix: '/broker', namePrefix: 'broker.')]
final class BrokerController
{
    private static bool $storeQuarantined = false;

    /**
     * 请求连接仍归当前执行作用域持有；网络输入不能覆盖宿主配置。
     * @param list<string> $worker 完整存储工作命令；空列表表示没有可靠接收路径。
     */
    public function __construct(private DatabaseManager $database, private IdentityService $identities, private Factory $messages, private array $worker = [])
    {
    }

    /** 登录失败不泄漏账号是否存在；口令与令牌不进入节点采样。 */
    #[Route('/auth/login', methods: ['POST'], name: 'login')]
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 16384), 16384, 12);
        $data = \_vali([
            'login' => Field::text()->required()->length(3, 100)->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D'),
            'password' => Field::text()->required()->length(1, 72)
        ], $input);
        return $this->response(['data' => $this->identities->login($data['login'], $data['password'], (string)$request->getAttribute('app.request_id', ''))]);
    }

    /** 每次请求核对当前账号状态；独立权限不借用 IoT 平台或租户令牌。 */
    #[Route('/auth/me', name: 'me', middleware: ['broker.auth'])]
    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        return $this->response(['data' => ['user' => $this->identities->user($identity->subject()), 'context' => null]]);
    }

    /** 只撤销当前已认证令牌，成功提交后才报告退出。 */
    #[Route('/auth/logout', methods: ['POST'], name: 'logout', middleware: ['broker.auth'])]
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        // 管理权限被撤销后仍允许销毁自己的登录令牌，不能因此被困在无权限页面。
        $identity = $this->identity($request, false);
        $this->identities->logout($identity->subject(), substr($request->getHeaderLine('Authorization'), 7), (string)$request->getAttribute('app.request_id', ''));
        return $this->response(['data' => ['logged_out' => true]]);
    }

    /** 专用探针认证不依赖数据库；只证明管理HTTP进程仍能处理请求。 */
    #[Route('/health/live', name: 'live', middleware: ['broker.probe'])]
    public function live(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity($request, false);
        return $this->response(['data' => ['alive' => true, 'scope' => 'management_http', 'observed_at' => time()]]);
    }

    /** 可靠接收就绪同时需要新鲜节点观察、已配置持久路径及当次同步存储证明。 */
    #[Route('/health/ready', name: 'ready', middleware: ['broker.probe'])]
    public function ready(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity($request, false);
        $health = $this->health($request);
        return $this->response(['data' => $health], $health['ready'] ? 200 : 503);
    }

    /** 固定指标名与32个slot标签；客户端、Topic、运行ID和载荷不会进入时序标签。 */
    #[Route('/metrics', name: 'metrics', middleware: ['broker.probe'])]
    public function metrics(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity($request, false);
        $health = $this->health($request);
        $lines = ['# TYPE typeapp_broker_ready gauge', 'typeapp_broker_ready ' . ($health['ready'] ? '1' : '0'),
            '# TYPE typeapp_broker_store_available gauge', 'typeapp_broker_store_available ' . ($health['store']['state'] === 'available' ? '1' : '0')];
        $allowed = ['connections', 'accepted', 'rejected', 'closed', 'authenticationRefusals', 'observationFailures', 'stopping', 'subscriptions', 'bufferedBytes',
            'incomingExchanges', 'outgoingExchanges', 'pendingCommits', 'durableCommits', 'rejectedCommits', 'unknownCommits', 'quarantinedCommits',
            'connectionQuotaRefusals', 'packetQuotaRefusals', 'subscriptionQuotaRefusals', 'commitQuotaRefusals', 'flushTimeouts', 'handshakeTimeouts',
            'deviceConnections', 'serviceConnections', 'maximumConnections', 'maximumDeviceConnections', 'maximumServiceConnections', 'processMemoryBytes', 'processPeakMemoryBytes'];
        $counters = ['accepted', 'rejected', 'closed', 'authenticationRefusals', 'observationFailures', 'durableCommits', 'rejectedCommits', 'unknownCommits',
            'connectionQuotaRefusals', 'packetQuotaRefusals', 'subscriptionQuotaRefusals', 'commitQuotaRefusals', 'flushTimeouts', 'handshakeTimeouts'];
        $lines[] = '# TYPE typeapp_broker_node_reporting gauge';
        $lines[] = '# TYPE typeapp_broker_node_observed_seconds gauge';
        foreach ($allowed as $key) {
            $counter = in_array($key, $counters, true);
            $name = strtolower((string)preg_replace('/([A-Z])/', '_$1', $key));
            $lines[] = '# TYPE typeapp_broker_' . $name . ($counter ? '_total counter' : ' gauge');
            // 节点加入、退出或观察过期都会改变求和成员，因此观察总和是gauge。
            $lines[] = '# TYPE typeapp_broker_observed_sum_' . $name . ' gauge';
        }
        $totals = [];
        foreach ($health['nodes'] as $node) {
            $labels = '{slot="' . $node['slot'] . '"}';
            $lines[] = 'typeapp_broker_node_reporting' . $labels . ' ' . ($node['reporting'] ? '1' : '0');
            $lines[] = 'typeapp_broker_node_observed_seconds' . $labels . ' ' . $node['observed_at'];
            if (!$node['reporting']) {
                continue;
            }
            foreach ($allowed as $key) {
                $value = $node['metrics'][$key] ?? null;
                if (is_int($value) && $value >= 0) {
                    $name = strtolower((string)preg_replace('/([A-Z])/', '_$1', $key));
                    $lines[] = 'typeapp_broker_' . $name . (in_array($key, $counters, true) ? '_total' : '') . $labels . ' ' . $value;
                    $totals[$name] = ($totals[$name] ?? 0) + $value;
                }
            }
        }
        foreach ($totals as $totalName => $totalValue) {
            $lines[] = 'typeapp_broker_observed_sum_' . $totalName . ' ' . $totalValue;
        }
        foreach ($health['store']['metrics'] ?? [] as $key => $value) {
            $name = strtolower((string)preg_replace('/([A-Z])/', '_$1', $key));
            $lines[] = '# TYPE typeapp_broker_store_' . $name . ' gauge';
            $lines[] = 'typeapp_broker_store_' . $name . ' ' . $value;
        }
        return $this->messages->createResponse($health['state'] === 'management_unavailable' ? 503 : 200)
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(implode("\n", $lines) . "\n"));
    }

    /** 有界稳定分页；十五秒未采样标为不可达，缺失指标维持 null。 */
    #[Route('/nodes', name: 'nodes', middleware: ['broker.auth'])]
    public function nodes(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity($request);
        $query = \_vali(['node_id' => Field::text()->length(0, 64)->from('query'), 'page' => Field::integer()->cast()->range(1, 100000)->from('query'),
                         'per_page' => Field::integer()->cast()->range(1, 100)->from('query')], (new Input([]))->withQuery($request->getUri()->getQuery()));
        $connection = $this->connection($request);
        try {
            $rows = $connection->table('broker_nodes');
            if (($query['node_id'] ?? '') !== '') {
                $rows = $rows->where('node_id', '=', $query['node_id']);
            }
            $page = $rows->orderBy('node_id')->paginate($query['page'] ?? 1, $query['per_page'] ?? 20, 'slot');
        } finally {
            // 页数据已经读取；同步worker等待期间不占住多余的管理库租约。
            $connection->close();
        }
        $now = time();
        $items = [];
        foreach ($page->items() as $row) {
            $items[] = ['node_id' => (string)$row['node_id'], 'run_id' => (string)$row['run_id'],
                        'state' => (int)$row['stopped'] === 2 ? 'isolated' : ((int)$row['stopped'] === 1 ? 'stopped' : ($now - (int)$row['observed_at'] >= 15 ? 'unreachable' : 'reporting')),
                        'observed_at' => (int)$row['observed_at'], 'expires_at' => (int)$row['observed_at'] + 15,
                        'listener' => json_decode((string)$row['listener_json'], true, 8, JSON_THROW_ON_ERROR),
                        'metrics' => json_decode((string)$row['metrics_json'], true, 8, JSON_THROW_ON_ERROR)];
        }
        return $this->response(['items' => $items, 'total' => $page->total(), 'page' => $page->number(), 'per_page' => $page->perPage(),
                                'generated_at' => $now, 'freshness_seconds' => 15, 'coverage' => 'observed_instances_only', 'health' => $this->health($request)]);
    }

    /** 独立人员审计含既有身份事件；每页及详情重新检查当前管理员，不接收租户或支持头。 */
    #[Route('/audit', name: 'audit', middleware: ['broker.auth'])]
    #[Route('/audit/{id}', name: 'audit-detail', middleware: ['broker.auth'])]
    public function audit(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $authorization = ['scope' => 'standalone', 'actor_id' => $identity->subject(), 'role' => 'broker_admin',
                          'permissions' => ['audit.read', 'broker.read'], 'tenant_id' => null, 'support_id' => null, 'support_version' => null, 'support_expires_at' => null];
        $connection = $this->connection($request);
        $result = AuditLog::brokerSearch($connection, 'broker', $authorization, $request->getUri()->getQuery(), $parameters['id'] ?? '');
        if (!$this->identities->user($identity->subject())['platform_admin']) {
            throw new HttpError(403, 'forbidden');
        }
        return $this->response($result);
    }

    /** 独立管理员查询元数据；不会借用 IoT 租户头、载荷权限或人员令牌。 */
    #[Route('/resources/{resource}', name: 'resources', middleware: ['broker.auth'])]
    #[Route('/resources/{resource}/{id}', name: 'resource-detail', middleware: ['broker.auth'])]
    public function resources(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $connection = $this->connection($request);
        try {
            try {
                $result = ResourceQueries::query(
                    $connection,
                    $this->worker,
                    $parameters['resource'],
                    $request->getUri()->getQuery(),
                    $parameters['id'] ?? '',
                    ['all_metadata' => true],
                    'broker:' . $identity->subject()
                );
            } finally {
                // 持久查询会在worker等待前归还租约；重验和审计共用一份新连接。
                $connection->close();
                $connection = $this->connection($request);
            }
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
        } catch (HttpError $error) {
            $this->resourceAudit($connection, $request, $identity, [], [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        $this->resourceAudit($connection, $request, $identity, $parameters, $result, 'success', 'metadata_read');
        return $this->response($result);
    }

    /**
     * 按精确 owner 与会话代次断开当前网络连接，保留仍有效的持久会话。
     *
     * @throws HttpError 目标不存在、代次过期、正文非法或权限不足。
     */
    #[Route('/resources/connections/{id}/disconnect', methods: ['POST'], name: 'connection-disconnect', middleware: ['broker.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function disconnectConnection(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $ownerId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $context = ['tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'broker',
            'permissions' => ['broker.write'], 'identity' => null];
        try {
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'json_required');
            }
            try {
                $payload = json_decode(RequestBody::read($request->getBody(), 4096), true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(422, 'broker_connection_invalid');
            }
            if (!is_array($payload)) {
                throw new HttpError(422, 'broker_connection_invalid');
            }
            $requestId = (string) $request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $result = ConnectionOperations::request($connection, $context, $ownerId, $payload, $requestId);
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
            return $this->response($result);
        } catch (HttpError $error) {
            $this->resourceAudit($connection, $request, $identity, ['resource' => 'connections', 'id' => $ownerId], [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 从真实持久会话详情生成终止预览；不含载荷或遗嘱主题。
     *
     * @throws HttpError 会话不存在、存储不可用或权限不足。
     */
    #[Route('/resources/sessions/{id}/termination-preview', methods: ['GET'], name: 'session-termination-preview', middleware: ['broker.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function sessionTerminationPreview(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $sessionId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $detail = ['found' => false, 'item' => ['id' => $sessionId], 'observed_at' => time(), 'source' => 'durable_store'];
        $subscriptions = ['items' => []];
        try {
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
            try {
                $detail = ResourceQueries::query($connection, $this->worker, 'sessions', '', $sessionId, ['all_metadata' => true], 'broker:' . $identity->subject());
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
            try {
                $subscriptions = ResourceQueries::query(
                    $connection,
                    $this->worker,
                    'subscriptions',
                    'session_id=' . $sessionId . '&limit=20',
                    '',
                    ['all_metadata' => true],
                    'broker:' . $identity->subject()
                );
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
        } catch (HttpError $error) {
            $this->resourceAudit($connection, $request, $identity, ['resource' => 'sessions', 'id' => $sessionId], [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        $item = $detail['item'];
        $item['observed_at'] = $detail['observed_at'];
        $item['source'] = $detail['source'];
        $preview = ConnectionOperations::preview($item, $subscriptions['items'] ?? []);
        if (!$this->identities->user($identity->subject())['platform_admin']) {
            throw new HttpError(403, 'forbidden');
        }
        $this->resourceAudit($connection, $request, $identity, ['resource' => 'sessions', 'id' => $sessionId], ['item' => $item], 'success', 'termination_preview');
        return $this->response($preview);
    }

    /**
     * 明确确认后终止指定持久会话；按 session_id 与代次匹配，放弃该会话未完成交付。
     *
     * @throws HttpError 目标不存在、代次过期、未确认、正文非法或权限不足。
     */
    #[Route('/resources/sessions/{id}/terminate', methods: ['POST'], name: 'session-terminate', middleware: ['broker.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function terminateSession(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $sessionId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $context = ['tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'broker',
            'permissions' => ['broker.write'], 'identity' => null];
        $payload = [];
        $detail = ['found' => false, 'item' => ['id' => $sessionId], 'observed_at' => time(), 'source' => 'durable_store'];
        try {
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'json_required');
            }
            try {
                $payload = json_decode(RequestBody::read($request->getBody(), 4096), true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(422, 'broker_session_invalid');
            }
            if (!is_array($payload)) {
                throw new HttpError(422, 'broker_session_invalid');
            }
            if (($payload['confirmed'] ?? null) !== true) {
                throw new HttpError(422, 'broker_session_confirm_required');
            }
            $operationId = $payload['operation_id'] ?? null;
            if (is_string($operationId) && preg_match('/^[a-f0-9]{32}$/D', $operationId) === 1) {
                $existing = $connection->table('broker_connection_operations')->where('id', '=', $operationId)->first();
                if ($existing !== null) {
                    $requestId = (string) $request->getAttribute('app.request_id', '');
                    $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
                    $result = ConnectionOperations::requestTerminate($connection, $context, $sessionId, [
                        'id' => $sessionId, 'session_generation' => (int) $existing['session_generation'],
                        'node_id' => $existing['node_id'], 'node_run_id' => $existing['node_run_id'] ?? '',
                    ], null, $payload, $requestId);
                    if (!$this->identities->user($identity->subject())['platform_admin']) {
                        throw new HttpError(403, 'forbidden');
                    }
                    return $this->response($result);
                }
            }
            try {
                $detail = ResourceQueries::query($connection, $this->worker, 'sessions', '', $sessionId, ['all_metadata' => true], 'broker:' . $identity->subject());
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
        } catch (HttpError $error) {
            $this->resourceAudit($connection, $request, $identity, ['resource' => 'sessions', 'id' => $sessionId], [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        try {
            $live = $connection->table('broker_resource_connections')->where('session_id', '=', $sessionId)->first();
            $requestId = (string) $request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $item = $detail['item'];
            $item['source'] = $detail['source'];
            $result = ConnectionOperations::requestTerminate($connection, $context, $sessionId, $item, $live, $payload, $requestId);
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
            return $this->response($result);
        } catch (HttpError $error) {
            $this->resourceAudit($connection, $request, $identity, ['resource' => 'sessions', 'id' => $sessionId], [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 从真实保留原件详情生成清除预览；不含载荷或属性。
     *
     * @throws HttpError 原件不存在、存储不可用或权限不足。
     */
    #[Route('/resources/retained/{id}/clearance-preview', methods: ['GET'], name: 'retained-clearance-preview', middleware: ['broker.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function retainedClearancePreview(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $resourceId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $detail = ['found' => false, 'item' => ['id' => $resourceId], 'observed_at' => time(), 'source' => 'durable_store'];
        try {
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
            try {
                $detail = ResourceQueries::query($connection, $this->worker, 'retained', '', $resourceId, ['all_metadata' => true], 'broker:' . $identity->subject());
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
        } catch (HttpError $error) {
            $this->resourceAudit($connection, $request, $identity, ['resource' => 'retained', 'id' => $resourceId], [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        $item = $detail['item'];
        $item['observed_at'] = $detail['observed_at'];
        $item['source'] = $detail['source'];
        $preview = ConnectionOperations::previewRetained($item);
        if (!$this->identities->user($identity->subject())['platform_admin']) {
            throw new HttpError(403, 'forbidden');
        }
        $this->resourceAudit($connection, $request, $identity, ['resource' => 'retained', 'id' => $resourceId], ['item' => $item], 'success', 'clearance_preview');
        return $this->response($preview);
    }

    /**
     * 明确确认后清除指定保留原件；按 resource_id 与代次匹配，不撤回已有交付。
     *
     * @throws HttpError 目标不存在、代次过期、未确认、正文非法或权限不足。
     */
    #[Route('/resources/retained/{id}/clear', methods: ['POST'], name: 'retained-clear', middleware: ['broker.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function clearRetained(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $resourceId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $context = ['tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'broker',
            'permissions' => ['broker.write'], 'identity' => null];
        $payload = [];
        $detail = ['found' => false, 'item' => ['id' => $resourceId], 'observed_at' => time(), 'source' => 'durable_store'];
        try {
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'json_required');
            }
            try {
                $payload = json_decode(RequestBody::read($request->getBody(), 4096), true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(422, 'broker_retained_invalid');
            }
            if (!is_array($payload)) {
                throw new HttpError(422, 'broker_retained_invalid');
            }
            if (($payload['confirmed'] ?? null) !== true) {
                throw new HttpError(422, 'broker_retained_confirm_required');
            }
            $operationId = $payload['operation_id'] ?? null;
            if (is_string($operationId) && preg_match('/^[a-f0-9]{32}$/D', $operationId) === 1) {
                $existing = $connection->table('broker_connection_operations')->where('id', '=', $operationId)->first();
                if ($existing !== null) {
                    $requestId = (string) $request->getAttribute('app.request_id', '');
                    $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
                    $result = ConnectionOperations::requestClear($connection, $context, $resourceId, [
                        'id' => $resourceId, 'generation' => (int) $existing['session_generation'],
                    ], $payload, $requestId);
                    if (!$this->identities->user($identity->subject())['platform_admin']) {
                        throw new HttpError(403, 'forbidden');
                    }
                    return $this->response($result);
                }
            }
            try {
                $detail = ResourceQueries::query($connection, $this->worker, 'retained', '', $resourceId, ['all_metadata' => true], 'broker:' . $identity->subject());
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
        } catch (HttpError $error) {
            $this->resourceAudit($connection, $request, $identity, ['resource' => 'retained', 'id' => $resourceId], [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        try {
            $requestId = (string) $request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $item = $detail['item'];
            $item['source'] = $detail['source'];
            $result = ConnectionOperations::requestClear($connection, $context, $resourceId, $item, $payload, $requestId);
            if (!$this->identities->user($identity->subject())['platform_admin']) {
                throw new HttpError(403, 'forbidden');
            }
            return $this->response($result);
        } catch (HttpError $error) {
            $this->resourceAudit($connection, $request, $identity, ['resource' => 'retained', 'id' => $resourceId], [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /** 只读取原操作冻结结果；不能把待处理当成已断开，也不能跨操作标识重放。 */
    #[Route('/operations/{id}', methods: ['GET'], name: 'operation', middleware: ['broker.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function operation(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $operationId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        if (!$this->identities->user($identity->subject())['platform_admin']) {
            throw new HttpError(403, 'forbidden');
        }
        return $this->response(ConnectionOperations::result($connection, [
            'tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'broker',
            'permissions' => ['broker.read'], 'identity' => null,
        ], $operationId));
    }

    /** 只记录已获准返回的目标元数据；失败时不保存客户端筛选或未经授权的资源标识。 */
    private function resourceAudit(Connection $connection, ServerRequestInterface $request, Identity $identity, array $parameters, array $result, string $outcome, string $reason): void
    {
        $requestId = (string)$request->getAttribute('app.request_id', '');
        $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
        $resource = $parameters['resource'] ?? 'resources';
        $item = $result['item'] ?? [];
        $stage = $outcome === 'success' ? 'completed' : 'failed';
        AuditLog::recordBroker($connection, 'broker', [
            'operation_id' => bin2hex(random_bytes(16)), 'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $identity->subject(), 'tenant_id' => null, 'action' => 'broker.resource.read', 'subject_id' => $item['id'] ?? $resource,
            'authorization' => ['source' => 'broker-admin', 'role' => 'broker_admin', 'permissions' => $outcome === 'denied' ? [] : ['broker.read', 'audit.read'],
                                'required_action' => 'broker.read', 'decision' => $outcome === 'denied' ? 'denied' : 'allowed', 'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'resource.' . $resource, 'node_id' => $item['node_id'] ?? '', 'node_run_id' => $item['node_run_id'] ?? '',
                                'observation_run' => $item['observation_run'] ?? ($resource === 'nodes' ? ($item['run_id'] ?? '') : ''), 'generation' => (int)($item['node_generation'] ?? 0)],
            'impact' => ['confirmed' => false, 'effect' => 'metadata_read', 'target_count' => isset($result['item']) ? 1 : count($result['items'] ?? []), 'proof_hash' => ''],
        ], $stage, ['request_id' => $requestId, 'stage' => $stage, 'result' => $outcome, 'facts' => ['reason' => $reason]]);
    }

    /** 同步存储读取仍经有截止worker；清理未知后该HTTP进程不再创建新worker。 */
    private function health(ServerRequestInterface $request): array
    {
        self::$storeQuarantined = self::$storeQuarantined || ResourceQueries::quarantined();
        $now = time();
        $health = ['ready' => false, 'state' => 'no_reporting_nodes', 'observed_at' => $now, 'scope' => 'observed_instances_only', 'nodes' => [],
                   'store' => ['state' => 'unconfigured', 'quarantined' => self::$storeQuarantined, 'metrics' => null]];
        $stage = 'management';
        try {
            $active = 0;
            $unavailable = false;
            $connection = $this->connection($request);
            try {
                $observedRows = $connection->table('broker_nodes')->orderBy('slot')->limit(32)->get();
            } finally {
                $connection->close();
            }
            foreach ($observedRows as $row) {
                $metrics = json_decode((string)$row['metrics_json'], true, 8, JSON_THROW_ON_ERROR);
                $reporting = (int)$row['stopped'] === 0 && (int)$row['observed_at'] <= $now && $now - (int)$row['observed_at'] < 15;
                $health['nodes'][] = ['slot' => (int)$row['slot'], 'observed_at' => (int)$row['observed_at'], 'reporting' => $reporting, 'metrics' => $metrics];
                if ((int)$row['stopped'] === 0) {
                    $active++;
                    $unavailable = $unavailable || !$reporting || ($metrics['durableConfigured'] ?? 0) !== 1
                        || ($metrics['stopping'] ?? 1) !== 0 || ($metrics['quarantinedCommits'] ?? 1) !== 0;
                }
            }
            $stage = 'store';
            if ($this->worker !== [] && !self::$storeQuarantined) {
                $pending = new PendingCommit($this->worker, ['action' => 'session_statistics', 'operation_id' => bin2hex(random_bytes(16))]);
                do {
                    $result = $pending->poll();
                    if ($result === null) {
                        usleep(10000);
                    }
                } while ($result === null);
                // 迟到的成功结果只能证明自身已释放，不能解除另一并发工作的未知资源隔离。
                if (!$result->released) {
                    self::$storeQuarantined = true;
                    ResourceQueries::quarantine();
                }
                $values = null;
                if ($result->state === 'committed' && $result->released) {
                    $values = [];
                    foreach (['sessions', 'persistentSessions', 'deviceSessions', 'applicationSessions', 'subscriptions', 'pendingMessages', 'pendingBytes',
                                 'devicePendingMessages', 'devicePendingBytes', 'applicationPendingMessages', 'applicationPendingBytes', 'sharedPendingMessages', 'sharedPendingBytes',
                                 'retainedMessages', 'retainedBytes', 'willsMessages', 'willsBytes', 'maximumSessions', 'maximumPendingMessages', 'maximumPendingBytes'] as $key) {
                        $value = $result->value[$key] ?? null;
                        if (!is_int($value) || $value < 0) {
                            throw new \RuntimeException('broker_store_metrics_invalid');
                        }
                        $values[$key] = $value;
                    }
                }
                $health['store'] = ['state' => $values !== null ? 'available' : $result->state, 'quarantined' => self::$storeQuarantined, 'metrics' => $values];
            }
            self::$storeQuarantined = self::$storeQuarantined || ResourceQueries::quarantined();
            if (self::$storeQuarantined) {
                $health['store'] = ['state' => 'quarantined', 'quarantined' => true, 'metrics' => null];
            }
            $capacity = $health['store']['metrics'];
            // worker可能等待同步证明；按响应形成时刻重新判断节点新鲜度，不能延长旧采样寿命。
            $health['observed_at'] = time();
            foreach ($health['nodes'] as $index => $node) {
                if ($node['reporting'] && $health['observed_at'] - $node['observed_at'] >= 15) {
                    $health['nodes'][$index]['reporting'] = false;
                    $unavailable = true;
                }
            }
            $full = $capacity !== null && ($capacity['pendingMessages'] >= $capacity['maximumPendingMessages'] || $capacity['pendingBytes'] >= $capacity['maximumPendingBytes']);
            $health['state'] = $active === 0 ? 'no_reporting_nodes' : ($unavailable ? 'node_unavailable' : ($health['store']['state'] !== 'available' ? 'store_unavailable' : ($full ? 'capacity_exhausted' : 'ready')));
            $health['ready'] = $health['state'] === 'ready';
        } catch (\Throwable) {
            $health['state'] = $stage === 'management' ? 'management_unavailable' : 'store_unavailable';
            $health['observed_at'] = time();
            $health['store'] = ['state' => self::$storeQuarantined ? 'quarantined' : 'unavailable', 'quarantined' => self::$storeQuarantined, 'metrics' => null];
            if ($stage === 'management') {
                $health['nodes'] = [];
            }
        }
        foreach ($health['nodes'] as $index => $node) {
            if ($health['observed_at'] - $node['observed_at'] >= 15) {
                $health['nodes'][$index]['reporting'] = false;
            }
        }
        if (!$health['ready']) {
            $logger = $request->getAttribute('app.logger');
            if ($logger instanceof \Psr\Log\LoggerInterface) {
                $logger->warning('Broker 接收尚未就绪', ['state' => $health['state'], 'store_state' => $health['store']['state']]);
            }
        }
        return $health;
    }

    private function identity(ServerRequestInterface $request, bool $administrator = true): Identity
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        if (($administrator && !in_array('broker_admin', $identity->roles(), true)) || $request->hasHeader('X-Tenant-Id') || $request->hasHeader('X-Support-Id')) {
            throw new HttpError(403, 'forbidden');
        }
        return $identity;
    }

    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('broker_request_scope_required');
        }
        return \Type\Orm\Db::connection('default', true);
    }

    private function response(array $data, int $status = 200): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
