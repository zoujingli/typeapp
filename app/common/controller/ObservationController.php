<?php

declare(strict_types=1);

namespace app\common\controller;

use app\broker\service\ConnectionOperations;
use app\broker\service\ResourceQueries;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\iot\service\OperationsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;

/** 双端只读观察的HTTP边界；身份、租户及路由来源不能由额外请求头覆盖。 */
final class ObservationController
{
    /** 注入受管数据库、响应工厂和既有持久统计命令，构造不建立连接。 */
    public function __construct(private DatabaseManager $database, private Factory $messages, private string $mqttCommand = '[]')
    {
    }

    /** 列表与详情共用严格筛选和准确身份；平台详情同时指定来源，防止跨表事件ID歧义。 */
    #[Route('/admin/audit', name: 'admin.audit', middleware: ['admin.auth'])]
    #[Route('/admin/audit/{source}/{id}', name: 'admin.audit-detail', middleware: ['admin.auth'], constraints: ['source' => 'admin|customer', 'id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/audit', name: 'customer.audit', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/audit/{id}', name: 'customer.audit-detail', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    #[Route('/admin/broker/audit', name: 'admin.broker-audit', middleware: ['admin.auth'])]
    #[Route('/admin/broker/audit/{source}/{id}', name: 'admin.broker-audit-detail', middleware: ['admin.auth'], constraints: ['source' => 'admin|customer', 'id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/audit', name: 'customer.broker-audit', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/audit/{id}', name: 'customer.broker-audit-detail', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    public function audit(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getAttribute('type.route.params', []);
        $tenant = $this->tenant($request);
        $id = $parameters['id'] ?? '';
        return $this->response(AuditLog::search(
            $this->connection($request),
            $this->identity($request),
            $tenant === '' ? 'admin' : 'customer',
            $tenant,
            $request->getUri()->getQuery(),
            $id,
            $id === '' ? '' : ($parameters['source'] ?? 'customer'),
            str_contains($request->getUri()->getPath(), '/broker/audit')
        ));
    }

    /** 只复用现有Broker元数据查询；每页绑定当前角色、客户和模拟来源，耗时读取后重新授权。 */
    #[Route('/admin/broker/resources/{resource}', name: 'admin.broker-resources', middleware: ['admin.auth'])]
    #[Route('/admin/broker/resources/{resource}/{id}', name: 'admin.broker-resource-detail', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/resources/{resource}', name: 'customer.broker-resources', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/resources/{resource}/{id}', name: 'customer.broker-resource-detail', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function brokerResources(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'admin' : 'customer';
        $identity = $this->identity($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $connection = $this->connection($request);
        $access = [];
        try {
            $access = RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.read');
            $authorization = $tenant === '' ? ['all_metadata' => true]
                : ['all_metadata' => false, 'resource_scope' => 'iot:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
            $command = $this->mqttCommand === '' ? [] : json_decode($this->mqttCommand, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($command) || !array_is_list($command)) {
                throw new HttpError(503, 'broker_resource_store_unavailable');
            }
            try {
                $result = ResourceQueries::query(
                    $connection,
                    $command === [] ? [] : [...$command, 'iot:mqtt-store'],
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
            if ($access !== RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.read')) {
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
     * @throws HttpError 目标不存在、代次过期、正文非法或写权限在途被收回。
     */
    #[Route('/admin/broker/resources/connections/{id}/disconnect', methods: ['POST'], name: 'admin.broker-connection-disconnect', middleware: ['admin.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/resources/connections/{id}/disconnect', methods: ['POST'], name: 'customer.broker-connection-disconnect', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    public function disconnectConnection(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutateConnection($request, static function (Connection $connection, array $context, string $ownerId, array $payload, string $requestId): array {
            return ConnectionOperations::request($connection, $context, $ownerId, $payload, $requestId);
        });
    }

    /**
     * 从当前授权范围内的真实会话详情生成终止预览；跨租户按不存在处理。
     *
     * @throws HttpError 会话不存在、存储不可用或读权限不足。
     */
    #[Route('/admin/broker/resources/sessions/{id}/termination-preview', methods: ['GET'], name: 'admin.broker-session-termination-preview', middleware: ['admin.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/resources/sessions/{id}/termination-preview', methods: ['GET'], name: 'customer.broker-session-termination-preview', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    public function previewSessionTermination(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'admin' : 'customer';
        $identity = $this->identity($request);
        $sessionId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        $authorization = $tenant === '' ? ['all_metadata' => true]
            : ['all_metadata' => false, 'resource_scope' => 'iot:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
        $bind = '';
        $detail = ['found' => false, 'item' => ['id' => $sessionId], 'observed_at' => time(), 'source' => 'durable_store'];
        $subscriptions = ['items' => []];
        try {
            $access = RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.read');
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
            if ($access !== RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.read')) {
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
     * @throws HttpError 目标不存在、代次过期、未确认、正文非法或写权限在途被收回。
     */
    #[Route('/admin/broker/resources/sessions/{id}/terminate', methods: ['POST'], name: 'admin.broker-session-terminate', middleware: ['admin.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/resources/sessions/{id}/terminate', methods: ['POST'], name: 'customer.broker-session-terminate', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    public function terminateSession(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'admin' : 'customer';
        $identity = $this->identity($request);
        $sessionId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        $authorization = $tenant === '' ? ['all_metadata' => true]
            : ['all_metadata' => false, 'resource_scope' => 'iot:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
        $bind = '';
        $detail = ['found' => false, 'item' => ['id' => $sessionId], 'observed_at' => time(), 'source' => 'durable_store'];
        $payload = [];
        try {
            $access = RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.write');
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
                $existing = $connection->table('broker_connection_operations')->where('id', '=', $operationId)->first();
                if ($existing !== null) {
                    $origin = IdentityService::context($identity, $tenant);
                    unset($origin['actor_name'], $origin['expires_at']);
                    $context = [
                        'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
                        'permissions' => $access['permissions'],
                        'identity' => [
                            'realm' => $origin['realm'], 'actor_id' => $origin['actor_id'], 'actor_realm' => $origin['actor_realm'],
                            'customer_id' => $origin['customer_id'], 'session_id' => $origin['session_id'],
                            'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                            'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                        ],
                    ];
                    $requestId = (string) $request->getAttribute('app.request_id', '');
                    $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
                    $result = ConnectionOperations::requestTerminate($connection, $context, $sessionId, [
                        'id' => $sessionId, 'session_generation' => (int) $existing['session_generation'],
                        'node_id' => $existing['node_id'], 'node_run_id' => $existing['node_run_id'] ?? '',
                    ], null, $payload, $requestId);
                    if ($access !== RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.write')) {
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
                    'customer_id' => $origin['customer_id'], 'session_id' => $origin['session_id'],
                    'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                    'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                ],
            ];
            $live = $connection->table('broker_resource_connections')->where('session_id', '=', $sessionId)->first();
            $requestId = (string) $request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $item = $detail['item'];
            $item['source'] = $detail['source'];
            $result = ConnectionOperations::requestTerminate($connection, $context, $sessionId, $item, $live, $payload, $requestId);
            if ($access !== RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.write')) {
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
     * @throws HttpError 原件不存在、存储不可用或读权限不足。
     */
    #[Route('/admin/broker/resources/retained/{id}/clearance-preview', methods: ['GET'], name: 'admin.broker-retained-clearance-preview', middleware: ['admin.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/resources/retained/{id}/clearance-preview', methods: ['GET'], name: 'customer.broker-retained-clearance-preview', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    public function previewRetainedClearance(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'admin' : 'customer';
        $identity = $this->identity($request);
        $resourceId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        $authorization = $tenant === '' ? ['all_metadata' => true]
            : ['all_metadata' => false, 'resource_scope' => 'iot:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
        $bind = '';
        $detail = ['found' => false, 'item' => ['id' => $resourceId], 'observed_at' => time(), 'source' => 'durable_store'];
        try {
            $access = RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.read');
            $bind = json_encode($access, JSON_THROW_ON_ERROR);
            try {
                $detail = ResourceQueries::query($connection, $this->storeWorker(), 'retained', '', $resourceId, $authorization, $bind);
            } finally {
                $connection->close();
                $connection = $this->connection($request);
            }
            if ($access !== RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.read')) {
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
     * @throws HttpError 目标不存在、代次过期、未确认、正文非法或写权限在途被收回。
     */
    #[Route('/admin/broker/resources/retained/{id}/clear', methods: ['POST'], name: 'admin.broker-retained-clear', middleware: ['admin.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/resources/retained/{id}/clear', methods: ['POST'], name: 'customer.broker-retained-clear', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    public function clearRetained(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'admin' : 'customer';
        $identity = $this->identity($request);
        $resourceId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        $authorization = $tenant === '' ? ['all_metadata' => true]
            : ['all_metadata' => false, 'resource_scope' => 'iot:' . $tenant, 'topic_namespace' => 'iot/' . $tenant];
        $bind = '';
        $detail = ['found' => false, 'item' => ['id' => $resourceId], 'observed_at' => time(), 'source' => 'durable_store'];
        $payload = [];
        try {
            $access = RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.write');
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
                $existing = $connection->table('broker_connection_operations')->where('id', '=', $operationId)->first();
                if ($existing !== null) {
                    $origin = IdentityService::context($identity, $tenant);
                    unset($origin['actor_name'], $origin['expires_at']);
                    $context = [
                        'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
                        'permissions' => $access['permissions'],
                        'identity' => [
                            'realm' => $origin['realm'], 'actor_id' => $origin['actor_id'], 'actor_realm' => $origin['actor_realm'],
                            'customer_id' => $origin['customer_id'], 'session_id' => $origin['session_id'],
                            'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                            'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                        ],
                    ];
                    $requestId = (string) $request->getAttribute('app.request_id', '');
                    $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
                    $result = ConnectionOperations::requestClear($connection, $context, $resourceId, [
                        'id' => $resourceId, 'generation' => (int) $existing['session_generation'],
                    ], $payload, $requestId);
                    if ($access !== RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.write')) {
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
                    'customer_id' => $origin['customer_id'], 'session_id' => $origin['session_id'],
                    'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                    'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                ],
            ];
            $requestId = (string) $request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $item = $detail['item'];
            $item['source'] = $detail['source'];
            $result = ConnectionOperations::requestClear($connection, $context, $resourceId, $item, $payload, $requestId);
            if ($access !== RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.write')) {
                throw new HttpError(403, 'broker_authorization_changed');
            }
            return $this->response($result);
        } catch (HttpError $error) {
            $this->brokerResourceAudit($connection, $request, $identity, $realm, $tenant, $access, ['resource' => 'retained', 'id' => $resourceId], [], in_array($error->status(), [401, 403], true) ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /** 原操作只读对账；待处理不能当成已断开，跨租户按不存在处理。 */
    #[Route('/admin/broker/operations/{id}', methods: ['GET'], name: 'admin.broker-operation', middleware: ['admin.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/operations/{id}', methods: ['GET'], name: 'customer.broker-operation', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    public function connectionOperation(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'admin' : 'customer';
        $identity = $this->identity($request);
        $operationId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.read');
        $origin = IdentityService::context($identity, $tenant);
        unset($origin['actor_name'], $origin['expires_at']);
        return $this->response(ConnectionOperations::result($connection, [
            'tenant_id' => $tenant === '' ? null : $tenant, 'actor_id' => $origin['actor_id'], 'actor_realm' => $realm,
            'permissions' => $access['permissions'], 'identity' => $origin,
        ], $operationId));
    }

    /**
     * 写操作共用授权复核与 JSON 解析；成功后再核写权限，模拟退出不能继续尚未执行的新动作。
     *
     * @param \Closure(Connection,array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?array<string,mixed>},string,array<string,mixed>,string): array<string, mixed> $operation
     */
    private function mutateConnection(ServerRequestInterface $request, \Closure $operation): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $realm = $tenant === '' ? 'admin' : 'customer';
        $identity = $this->identity($request);
        $ownerId = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        $access = [];
        try {
            $access = RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.write');
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
                    'customer_id' => $origin['customer_id'], 'session_id' => $origin['session_id'],
                    'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'],
                    'tenant_id' => $origin['tenant_id'], 'key' => $origin['key'],
                ],
            ];
            $requestId = (string) $request->getAttribute('app.request_id', '');
            $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
            $result = $operation($connection, $context, $ownerId, $payload, $requestId);
            if ($access !== RoleService::readContext($connection, $identity, $realm, $tenant, 'broker.write')) {
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

    /** 拒绝路径不保留未经授权的资源标识；准确身份来自服务器会话，不从头或查询参数取得。 */
    private function brokerResourceAudit(Connection $connection, ServerRequestInterface $request, Identity $identity, string $realm, string $tenant, array $access, array $parameters, array $result, string $outcome, string $reason): void
    {
        $origin = IdentityService::context($identity, $tenant);
        unset($origin['actor_name'], $origin['expires_at']);
        $requestId = (string) $request->getAttribute('app.request_id', '');
        $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
        $resource = $parameters['resource'] ?? 'resources';
        $item = $result['item'] ?? [];
        $stage = $outcome === 'success' ? 'completed' : 'failed';
        AuditLog::recordBroker($connection, $realm, [
            'operation_id' => bin2hex(random_bytes(16)), 'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $origin['actor_id'], 'tenant_id' => $tenant === '' ? null : $tenant, 'identity' => $origin,
            'action' => 'broker.resource.read', 'subject_id' => $item['id'] ?? $resource,
            'authorization' => ['source' => $realm === 'admin' ? 'platform' : ($origin['impersonation_id'] === '' ? 'customer-member' : 'customer-impersonation'),
                'role' => 'rbac', 'permissions' => $outcome === 'denied' ? [] : ($access['permissions'] ?? []),
                'required_action' => $realm . '.broker.read', 'decision' => $outcome === 'denied' ? 'denied' : 'allowed',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'resource.' . $resource, 'node_id' => $item['node_id'] ?? '', 'node_run_id' => $item['node_run_id'] ?? '',
                'observation_run' => $item['observation_run'] ?? ($resource === 'nodes' ? ($item['run_id'] ?? '') : ''), 'generation' => (int) ($item['node_generation'] ?? 0)],
            'impact' => ['confirmed' => false, 'effect' => 'metadata_read', 'target_count' => isset($result['item']) ? 1 : count($result['items'] ?? []), 'proof_hash' => ''],
        ], $stage, ['request_id' => $requestId, 'stage' => $stage, 'result' => $outcome, 'facts' => ['reason' => $reason]]);
    }

    /** 平台观察基础设施，客户观察当前租户设备元数据；均使用独立运行读取权限。 */
    #[Route('/admin/operations', name: 'admin.operations', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/operations', name: 'customer.operations', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
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
        $data = \_vali(['name' => Field::text()->length(0, 100)->from('query'), 'page' => Field::integer()->cast()->range(1, 100000)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->from('query')], $input) + ['page' => 1, 'per_page' => 20];
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        return $this->response($tenant === '' ? OperationsService::platform($connection, $identity, $this->mqttCommand)
            : OperationsService::tenant($connection, $identity, $tenant, $data['page'], $data['per_page'], $data));
    }

    private function tenant(ServerRequestInterface $request): string
    {
        $parameters = $request->getAttribute('type.route.params', []);
        $headers = $request->getHeader('X-Tenant-Id');
        if (!isset($parameters['tenant'])) {
            if ($headers !== []) {
                throw new HttpError(403, 'identity_scope_forbidden');
            }
            return '';
        }
        if (count($headers) !== 1 || $headers[0] !== $parameters['tenant']) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        return $headers[0];
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
        return $this->database->connect($scope);
    }

    /**
     * @return list<string> 当前宿主的持久存储命令；未配置时为空列表，会话查询返回 503。
     */
    private function storeWorker(): array
    {
        $command = $this->mqttCommand === '' ? [] : json_decode($this->mqttCommand, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($command) || !array_is_list($command)) {
            throw new HttpError(503, 'broker_resource_store_unavailable');
        }
        return $command === [] ? [] : [...$command, 'iot:mqtt-store'];
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
