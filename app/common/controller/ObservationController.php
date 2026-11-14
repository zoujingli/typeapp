<?php

declare(strict_types=1);

namespace app\common\controller;

use app\center\service\OperationsService;
use app\common\bootstrap\ApplicationContext;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\platform\mqtt\service\ConnectionOperations;
use app\platform\mqtt\service\ResourceQueries;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;
use Type\Validate\Input;

/**
 * 双端只读观察的HTTP边界；身份、租户及路由来源不能由额外请求头覆盖。
 */
final class ObservationController
{
    private string $mqttCommand;

    /**
     * 响应工厂和持久统计命令来自启动配置，构造不建立连接。
     */
    public function __construct(private Factory $messages, ApplicationContext $context)
    {
        $this->mqttCommand = $context->settings()->text('app.mqtt.command');
    }

    /**
     * 列表与详情共用严格筛选和准确身份；平台详情同时指定来源，防止跨表事件ID歧义。
     */
    #[Route('/platform/audit', name: 'platform.audit', middleware: ['platform.auth'])]
    #[Route('/platform/audit/{source}/{id}', name: 'platform.audit-detail', middleware: ['platform.auth'], constraints: ['source' => 'platform|system', 'id' => '[a-f0-9]{32}'])]
    #[Route('/system/audit', name: 'system.audit', middleware: ['system.auth'])]
    #[Route('/system/audit/{id}', name: 'system.audit-detail', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/broker/audit', name: 'platform.broker-audit', middleware: ['platform.auth'])]
    #[Route('/platform/broker/audit/{source}/{id}', name: 'platform.broker-audit-detail', middleware: ['platform.auth'], constraints: ['source' => 'platform|system', 'id' => '[a-f0-9]{32}'])]
    public function audit(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getAttribute('type.route.params', []);
        $tenant = $this->tenant($request);
        $id = $parameters['id'] ?? '';
        return $this->response(AuditLog::search(
            $this->connection($request),
            $this->identity($request),
            $tenant === '' ? 'platform' : 'system',
            $tenant,
            $request->getUri()->getQuery(),
            $id,
            $id === '' ? '' : ($parameters['source'] ?? 'system'),
            str_contains($request->getUri()->getPath(), '/broker/audit')
        ));
    }

    /**
     * 只复用现有Broker元数据查询；每页绑定当前角色、客户和模拟来源，耗时读取后重新授权。
     */
    #[Route('/platform/broker/resources/{resource}', name: 'platform.broker-resources', middleware: ['platform.auth'])]
    #[Route('/platform/broker/resources/{resource}/{id}', name: 'platform.broker-resource-detail', middleware: ['platform.auth'])]
    #[Route('/center/connections', name: 'center.connections', middleware: ['system.auth'])]
    #[Route('/center/connections/{id}', name: 'center.connection', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function brokerResources(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'platform' : 'system';
        $identity = $this->identity($request);
        $parameters = $request->getAttribute('type.route.params', []);
        if ($realm === 'system') {
            $parameters['resource'] = 'connections';
        }
        $connection = $this->connection($request);
        $access = [];
        try {
            $access = RoleService::readContext($identity, $realm, $tenant, $realm === 'system' ? 'center.connections.read' : 'platform.broker.read');
            $authorization = $tenant === '' ? ['all_metadata' => true]
                : ['all_metadata' => false, 'resource_scope' => 'center:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
            $command = $this->mqttCommand === '' ? [] : json_decode($this->mqttCommand, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($command) || !array_is_list($command)) {
                throw new HttpError(503, 'broker_resource_store_unavailable');
            }
            try {
                $result = ResourceQueries::query(
                    $connection,
                    $command === [] ? [] : [...$command, 'center:mqtt-store'],
                    $parameters['resource'],
                    $request->getUri()->getQuery(),
                    $parameters['id'] ?? '',
                    $authorization,
                    json_encode($access, JSON_THROW_ON_ERROR)
                );
            } finally {
                // 持久查询等待前可能已归还租约；重验及审计使用当前作用域重新取得的连接。
                $connection->close();
                $connection = $this->connection($request);
            }
            if ($access !== RoleService::readContext($identity, $realm, $tenant, $realm === 'system' ? 'center.connections.read' : 'platform.broker.read')) {
                throw new HttpError(403, 'broker_authorization_changed');
            }
        } catch (HttpError $error) {
            $this->brokerResourceAudit(
                $connection,
                $request,
                $identity,
                $realm,
                $tenant,
                $access,
                [],
                [],
                in_array($error->status(), [401, 403], true) ? 'denied' : 'failed',
                $error->errorCode()
            );
            throw $error;
        }
        $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, $parameters, $result, 'success', 'metadata_read');
        return $this->response($result);
    }

    /**
     * 按精确 owner 与会话代次断开当前网络连接；跨租户按不存在处理，不能用 Client ID 代替所有者。
     *
     * @throws HttpError 目标不存在、代次过期、正文非法或写权限在途被收回
     */
    #[Route('/platform/broker/resources/connections/{id}/disconnect', methods: ['POST'], name: 'platform.broker-connection-disconnect', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function disconnectConnection(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutateConnection($request, static function (Connection $connection, array $context, string $ownerId, array $payload, string $requestId): array {
            return ConnectionOperations::request($connection, $context, $ownerId, $payload, $requestId);
        });
    }

    /**
     * 从当前授权范围内的真实会话详情生成终止预览；跨租户按不存在处理。
     *
     * @throws HttpError 会话不存在、存储不可用或读权限不足
     */
    #[Route('/platform/broker/resources/sessions/{id}/termination-preview', methods: ['GET'], name: 'platform.broker-session-termination-preview', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function previewSessionTermination(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'platform' : 'system';
        $identity = $this->identity($request);
        $sessionId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        $authorization = $tenant === '' ? ['all_metadata' => true]
            : ['all_metadata' => false, 'resource_scope' => 'center:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
        $bind = '';
        $detail = ['found' => false, 'item' => ['id' => $sessionId], 'observed_at' => time(), 'source' => 'durable_store'];
        $subscriptions = ['items' => []];
        try {
            $access = RoleService::readContext($identity, $realm, $tenant, 'broker.read');
            $bind = json_encode($access, JSON_THROW_ON_ERROR);
            try {
                $detail = ResourceQueries::query($connection, $this->storeWorker(), 'sessions', '', $sessionId, $authorization, $bind);
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
            try {
                $subscriptions = ResourceQueries::query($connection, $this->storeWorker(), 'subscriptions', 'session_id=' . $sessionId . '&limit=20', '', $authorization, $bind);
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
            if ($access !== RoleService::readContext($identity, $realm, $tenant, 'broker.read')) {
                throw new HttpError(403, 'broker_authorization_changed');
            }
        } catch (HttpError $error) {
            $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'sessions', 'id' => $sessionId], [], in_array($error->status(), [401, 403], true) ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        $item = $detail['item'];
        $item['observed_at'] = $detail['observed_at'];
        $item['source'] = $detail['source'];
        $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'sessions', 'id' => $sessionId], ['item' => $item], 'success', 'termination_preview');
        return $this->response(ConnectionOperations::preview($item, $subscriptions['items'] ?? []));
    }

    /**
     * 明确确认后终止指定持久会话；跨租户按不存在处理，不能用 Client ID 代替会话标识。
     *
     * @throws HttpError 目标不存在、代次过期、未确认、正文非法或写权限在途被收回
     */
    #[Route('/platform/broker/resources/sessions/{id}/terminate', methods: ['POST'], name: 'platform.broker-session-terminate', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function terminateSession(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'platform' : 'system';
        $identity = $this->identity($request);
        $sessionId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        $authorization = $tenant === '' ? ['all_metadata' => true]
            : ['all_metadata' => false, 'resource_scope' => 'center:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
        $bind = '';
        $detail = ['found' => false, 'item' => ['id' => $sessionId], 'observed_at' => time(), 'source' => 'durable_store'];
        $payload = [];
        try {
            $access = RoleService::readContext($identity, $realm, $tenant, 'broker.write');
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
            $bind = json_encode($access, JSON_THROW_ON_ERROR);
            $operationId = $payload['operation_id'] ?? null;
            if (is_string($operationId) && preg_match('/^[a-f0-9]{32}$/D', $operationId) === 1) {
                $existing = $connection->table('platform_mqtt_connection_operation')->where('id', '=', $operationId)->first();
                if ($existing !== null) {
                    $origin = IdentityService::context($identity, $tenant);
                    unset($origin['actor_name'], $origin['expires_at']);
                    $context = [
                        'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
                        'permissions' => $access['permissions'],
                        'identity' => [
                            'realm' => $origin['realm'], 'actor_id' => $origin['actor_id'], 'actor_realm' => $origin['actor_realm'],
                            'user_id' => $origin['user_id'], 'session_id' => $origin['session_id'],
                            'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                            'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                        ],
                    ];
                    $requestId = (string)$request->getAttribute('app.request_id', '');
                    $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
                    $result = ConnectionOperations::requestTerminate($connection, $context, $sessionId, [
                        'id' => $sessionId, 'session_generation' => (int)$existing['session_generation'],
                        'node_id' => $existing['node_id'], 'node_run_id' => $existing['node_run_id'] ?? '',
                    ], null, $payload, $requestId);
                    if ($access !== RoleService::readContext($identity, $realm, $tenant, 'broker.write')) {
                        throw new HttpError(403, 'broker_authorization_changed');
                    }
                    return $this->response($result);
                }
            }
            try {
                $detail = ResourceQueries::query($connection, $this->storeWorker(), 'sessions', '', $sessionId, $authorization, $bind);
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
        } catch (HttpError $error) {
            $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'sessions', 'id' => $sessionId], [], in_array($error->status(), [401, 403], true) ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        try {
            $origin = IdentityService::context($identity, $tenant);
            unset($origin['actor_name'], $origin['expires_at']);
            $context = [
                'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
                'permissions' => $access['permissions'],
                'identity' => [
                    'realm' => $origin['realm'], 'actor_id' => $origin['actor_id'], 'actor_realm' => $origin['actor_realm'],
                    'user_id' => $origin['user_id'], 'session_id' => $origin['session_id'],
                    'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                    'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                ],
            ];
            $live = $connection->table('platform_mqtt_resource_connection')->where('session_id', '=', $sessionId)->first();
            $requestId = (string)$request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $item = $detail['item'];
            $item['source'] = $detail['source'];
            $result = ConnectionOperations::requestTerminate($connection, $context, $sessionId, $item, $live, $payload, $requestId);
            if ($access !== RoleService::readContext($identity, $realm, $tenant, 'broker.write')) {
                throw new HttpError(403, 'broker_authorization_changed');
            }
            return $this->response($result);
        } catch (HttpError $error) {
            $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'sessions', 'id' => $sessionId], [], in_array($error->status(), [401, 403], true) ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 从当前授权范围内的真实保留原件生成清除预览；跨租户按不存在处理。
     *
     * @throws HttpError 原件不存在、存储不可用或读权限不足
     */
    #[Route('/platform/broker/resources/retained/{id}/clearance-preview', methods: ['GET'], name: 'platform.broker-retained-clearance-preview', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function previewRetainedClearance(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'platform' : 'system';
        $identity = $this->identity($request);
        $resourceId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        $authorization = $tenant === '' ? ['all_metadata' => true]
            : ['all_metadata' => false, 'resource_scope' => 'center:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
        $bind = '';
        $detail = ['found' => false, 'item' => ['id' => $resourceId], 'observed_at' => time(), 'source' => 'durable_store'];
        try {
            $access = RoleService::readContext($identity, $realm, $tenant, 'broker.read');
            $bind = json_encode($access, JSON_THROW_ON_ERROR);
            try {
                $detail = ResourceQueries::query($connection, $this->storeWorker(), 'retained', '', $resourceId, $authorization, $bind);
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
            if ($access !== RoleService::readContext($identity, $realm, $tenant, 'broker.read')) {
                throw new HttpError(403, 'broker_authorization_changed');
            }
        } catch (HttpError $error) {
            $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'retained', 'id' => $resourceId], [], in_array($error->status(), [401, 403], true) ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        $item = $detail['item'];
        $item['observed_at'] = $detail['observed_at'];
        $item['source'] = $detail['source'];
        $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'retained', 'id' => $resourceId], ['item' => $item], 'success', 'clearance_preview');
        return $this->response(ConnectionOperations::previewRetained($item));
    }

    /**
     * 明确确认后清除指定保留原件；跨租户按不存在处理，不能用 Topic 通配代替原件标识。
     *
     * @throws HttpError 目标不存在、代次过期、未确认、正文非法或写权限在途被收回
     */
    #[Route('/platform/broker/resources/retained/{id}/clear', methods: ['POST'], name: 'platform.broker-retained-clear', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function clearRetained(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'platform' : 'system';
        $identity = $this->identity($request);
        $resourceId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        $authorization = $tenant === '' ? ['all_metadata' => true]
            : ['all_metadata' => false, 'resource_scope' => 'center:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
        $bind = '';
        $detail = ['found' => false, 'item' => ['id' => $resourceId], 'observed_at' => time(), 'source' => 'durable_store'];
        $payload = [];
        try {
            $access = RoleService::readContext($identity, $realm, $tenant, 'broker.write');
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
            $bind = json_encode($access, JSON_THROW_ON_ERROR);
            $operationId = $payload['operation_id'] ?? null;
            if (is_string($operationId) && preg_match('/^[a-f0-9]{32}$/D', $operationId) === 1) {
                $existing = $connection->table('platform_mqtt_connection_operation')->where('id', '=', $operationId)->first();
                if ($existing !== null) {
                    $origin = IdentityService::context($identity, $tenant);
                    unset($origin['actor_name'], $origin['expires_at']);
                    $context = [
                        'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
                        'permissions' => $access['permissions'],
                        'identity' => [
                            'realm' => $origin['realm'], 'actor_id' => $origin['actor_id'], 'actor_realm' => $origin['actor_realm'],
                            'user_id' => $origin['user_id'], 'session_id' => $origin['session_id'],
                            'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                            'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                        ],
                    ];
                    $requestId = (string)$request->getAttribute('app.request_id', '');
                    $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
                    $result = ConnectionOperations::requestClear($connection, $context, $resourceId, [
                        'id' => $resourceId, 'generation' => (int)$existing['session_generation'],
                    ], $payload, $requestId);
                    if ($access !== RoleService::readContext($identity, $realm, $tenant, 'broker.write')) {
                        throw new HttpError(403, 'broker_authorization_changed');
                    }
                    return $this->response($result);
                }
            }
            try {
                $detail = ResourceQueries::query($connection, $this->storeWorker(), 'retained', '', $resourceId, $authorization, $bind);
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
        } catch (HttpError $error) {
            $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'retained', 'id' => $resourceId], [], in_array($error->status(), [401, 403], true) ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
        try {
            $origin = IdentityService::context($identity, $tenant);
            unset($origin['actor_name'], $origin['expires_at']);
            $context = [
                'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
                'permissions' => $access['permissions'],
                'identity' => [
                    'realm' => $origin['realm'], 'actor_id' => $origin['actor_id'], 'actor_realm' => $origin['actor_realm'],
                    'user_id' => $origin['user_id'], 'session_id' => $origin['session_id'],
                    'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                    'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                ],
            ];
            $requestId = (string)$request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $item = $detail['item'];
            $item['source'] = $detail['source'];
            $result = ConnectionOperations::requestClear($connection, $context, $resourceId, $item, $payload, $requestId);
            if ($access !== RoleService::readContext($identity, $realm, $tenant, 'broker.write')) {
                throw new HttpError(403, 'broker_authorization_changed');
            }
            return $this->response($result);
        } catch (HttpError $error) {
            $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'retained', 'id' => $resourceId], [], in_array($error->status(), [401, 403], true) ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 原操作只读对账；待处理不能当成已断开，跨租户按不存在处理。
     */
    #[Route('/platform/broker/operations/{id}', methods: ['GET'], name: 'platform.broker-operation', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function connectionOperation(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'platform' : 'system';
        $identity = $this->identity($request);
        $operationId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = RoleService::readContext($identity, $realm, $tenant, 'broker.read');
        $origin = IdentityService::context($identity, $tenant);
        unset($origin['actor_name'], $origin['expires_at']);
        return $this->response(ConnectionOperations::result($connection, [
            'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
            'permissions' => $access['permissions'], 'identity' => $origin,
        ], $operationId));
    }

    /**
     * 平台观察基础设施，客户观察当前租户设备元数据；均使用独立运行读取权限。
     */
    #[Route('/platform/operations', name: 'platform.operations', middleware: ['platform.auth'])]
    public function operations(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $query = $request->getUri()->getQuery();
        if (strlen($query) > 1024 || ($tenant === '' && $query !== '')) {
            throw new HttpError(422, 'operations_filter_invalid');
        }
        $input = (new Input([]))->withQuery($query);
        if (array_diff(array_keys($input->source('query')), ['name', 'page', 'per_page']) !== []) {
            throw new HttpError(422, 'operations_filter_invalid');
        }
        $data = \_vali([
            'name.default' => '',
            'name.length:0,100' => '名称长度不正确',
            'page.default' => 1,
            'page.integer' => '页码不正确',
            'page.between:1,100000' => '页码超出范围',
            'per_page.default' => 20,
            'per_page.integer' => '每页数量不正确',
            'per_page.between:1,100' => '每页数量超出范围',
        ], $input->source('query'));
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        return $this->response($tenant === '' ? OperationsService::platform($connection, $identity, $this->mqttCommand)
            : OperationsService::tenant($connection, $identity, $tenant, $data['page'], $data['per_page'], $data));
    }

    /**
     * 写操作共用授权复核与 JSON 解析；成功后再核写权限，模拟退出不能继续尚未执行的新动作。
     *
     * @param \Closure(Connection,array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?array<string,mixed>},string,array<string,mixed>,string): array<string, mixed> $operation
     */
    private function mutateConnection(ServerRequestInterface $request, \Closure $operation): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'platform' : 'system';
        $identity = $this->identity($request);
        $ownerId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        try {
            $access = RoleService::readContext($identity, $realm, $tenant, 'broker.write');
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
            $origin = IdentityService::context($identity, $tenant);
            unset($origin['actor_name'], $origin['expires_at']);
            $context = [
                'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
                'permissions' => $access['permissions'],
                'identity' => [
                    'realm' => $origin['realm'], 'actor_id' => $origin['actor_id'], 'actor_realm' => $origin['actor_realm'],
                    'user_id' => $origin['user_id'], 'session_id' => $origin['session_id'],
                    'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                    'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                ],
            ];
            $requestId = (string)$request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $result = $operation($connection, $context, $ownerId, $payload, $requestId);
            if ($access !== RoleService::readContext($identity, $realm, $tenant, 'broker.write')) {
                throw new HttpError(403, 'broker_authorization_changed');
            }
            return $this->response($result);
        } catch (HttpError $error) {
            $this->brokerResourceAudit(
                $connection,
                $request,
                $identity,
                $realm,
                $tenant,
                $access,
                ['resource' => 'connections', 'id' => $ownerId],
                [],
                in_array($error->status(), [401, 403], true) ? 'denied' : 'failed',
                $error->errorCode()
            );
            throw $error;
        }
    }

    /**
     * 拒绝路径不保留未经授权的资源标识；准确身份来自服务器会话，不从头或查询参数取得。
     */
    private function brokerResourceAudit(Connection $connection, ServerRequestInterface $request, Identity $identity, string $realm, string $tenant, array $access, array $parameters, array $result, string $outcome, string $reason): void
    {
        $origin = IdentityService::context($identity, $tenant);
        unset($origin['actor_name'], $origin['expires_at']);
        $requestId = (string)$request->getAttribute('app.request_id', '');
        $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
        $resource = $parameters['resource'] ?? 'resources';
        $item = $result['item'] ?? [];
        $stage = $outcome === 'success' ? 'completed' : 'failed';
        AuditLog::recordBroker($connection, $realm, [
            'operation_id' => bin2hex(random_bytes(16)), 'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $origin['actor_id'], 'tenant_id' => $tenant === '' ? null : $tenant, 'identity' => $origin,
            'action' => 'broker.resource.read', 'subject_id' => $item['id'] ?? $resource,
            'authorization' => ['source' => $realm === 'platform' ? 'platform' : ($origin['impersonation_id'] === '' ? 'system-member' : 'system-impersonation'),
                'role' => 'rbac', 'permissions' => $outcome === 'denied' ? [] : ($access['permissions'] ?? []),
                'required_action' => $realm === 'system' ? 'center.connections.read' : 'platform.broker.read', 'decision' => $outcome === 'denied' ? 'denied' : 'allowed',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'resource.' . $resource, 'node_id' => $item['node_id'] ?? '', 'node_run_id' => $item['node_run_id'] ?? '',
                'observation_run' => $item['observation_run'] ?? ($resource === 'nodes' ? ($item['run_id'] ?? '') : ''), 'generation' => (int)($item['node_generation'] ?? 0)],
            'impact' => ['confirmed' => false, 'effect' => 'metadata_read', 'target_count' => isset($result['item']) ? 1 : count($result['items'] ?? []), 'proof_hash' => ''],
        ], $stage, ['request_id' => $requestId, 'stage' => $stage, 'result' => $outcome, 'facts' => ['reason' => $reason]]);
    }

    private function tenant(ServerRequestInterface $request): string
    {
        return (string)$request->getAttribute('app.tenant_id', '');
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
            if ($request->hasHeader($header)) {
                throw new HttpError(403, 'identity_context_invalid');
            }
        }
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('观察接口需要受管请求作用域');
        }
        return Db::connection('default', true);
    }

    /**
     * @return list<string> 当前宿主的持久存储命令；未配置时为空列表，会话查询返回 503
     */
    private function storeWorker(): array
    {
        return ApplicationContext::centerStoreWorker($this->mqttCommand);
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
