<?php

declare(strict_types=1);

namespace app\broker\controller;

use app\broker\service\AccessService;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
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

/** 接入主体、受信 CA、客户端证书绑定、签名 CRL、平台吊销、换证预览与 Topic 授权的版本化发布；保存成功不等于集群生效。 */
final class AccessController
{
    /** 请求连接仍归当前执行作用域持有；身份服务只用于核对平台管理员。 */
    public function __construct(private DatabaseManager $database, private IdentityService $identities, private Factory $messages)
    {
    }

    /**
     * 查询或发布接入主体。GET 不含秘密；POST 可附带客户端证书与受信 CA，保存成功不等于集群生效。
     *
     * @throws HttpError 查询非法、正文非 JSON、字段越界、未认证或权限不足。
     */
    #[Route('/broker/access/principals', methods: ['GET', 'POST'], name: 'broker.access-principals', middleware: ['broker.auth'])]
    #[Route('/broker/access/principals/{id}', methods: ['GET', 'POST'], name: 'broker.access-principal', middleware: ['broker.auth'])]
    #[Route('/admin/broker/access/principals', methods: ['GET', 'POST'], name: 'admin.broker-access-principals', middleware: ['admin.auth'])]
    #[Route('/admin/broker/access/principals/{id}', methods: ['GET', 'POST'], name: 'admin.broker-access-principal', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/access/principals', methods: ['GET', 'POST'], name: 'customer.broker-access-principals', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/access/principals/{id}', methods: ['GET', 'POST'], name: 'customer.broker-access-principal', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function principals(ServerRequestInterface $request): ResponseInterface
    {
        $write = $request->getMethod() === 'POST';
        $context = $this->context($request, $write ? 'write' : 'read');
        $parameters = $request->getAttribute('type.route.params', []);
        $connection = $this->connection($request);
        try {
            if (!$write) {
                $input = (new Input([]))->withQuery($request->getUri()->getQuery());
                if (array_diff(array_keys($input->source('query')), ['name', 'page', 'per_page']) !== []) {
                    throw new HttpError(400, 'broker_access_query_invalid');
                }
                $query = \_vali(['name' => Field::text()->length(0, 100)->from('query'),
                    'page' => Field::integer()->cast()->range(1, 100000)->from('query'),
                    'per_page' => Field::integer()->cast()->range(1, 100)->from('query')], $input);
                $result = AccessService::principals($connection, $context['tenant_id'], $parameters['id'] ?? '', $query['page'] ?? 1, $query['per_page'] ?? 20, $query['name'] ?? '');
                $this->audit($connection, $request, $context, 'broker.access.read', $parameters['id'] ?? 'principals', $result, 'success', 'metadata_read');
                return $this->response($result);
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'json_required');
            }
            try {
                $payload = json_decode(RequestBody::read($request->getBody(), 32768), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            if (!is_array($payload) || array_diff(array_keys($payload), ['id', 'name', 'login', 'password', 'enabled', 'rotate', 'revoke', 'expected_version', 'grants', 'client_id', 'certificates', 'ca']) !== []) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            if (($parameters['id'] ?? '') !== '' && ($payload['id'] ?? '') === '') {
                $payload['id'] = $parameters['id'];
            }
            $clientId = is_string($payload['client_id'] ?? null) ? (string) $payload['client_id'] : '';
            unset($payload['client_id']);
            $result = AccessService::publish($connection, $context['tenant_id'], $context['actor_id'], $context['actor_realm'], AccessService::changeInput($payload), $clientId);
            if ($context['actor_realm'] !== 'broker') {
                $this->context($request, 'write');
            }
            $this->audit($connection, $request, $context, 'broker.access.publish', (string) $result['id'], $result, 'success', 'access_published');
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, $write ? 'broker.access.publish' : 'broker.access.read', $parameters['id'] ?? 'principals', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 查询或登记/吊销受信 CA。节点心跳按当前受信 CA 重写握手文件，Broker 按文件更新重载新连接。
     *
     * @throws HttpError 查询带未知参数、PEM 非法、CA 仍被活动证书使用或版本冲突。
     */
    #[Route('/broker/access/cas', methods: ['GET', 'POST'], name: 'broker.access-cas', middleware: ['broker.auth'])]
    #[Route('/broker/access/cas/{id}', methods: ['GET', 'POST'], name: 'broker.access-ca', middleware: ['broker.auth'])]
    #[Route('/admin/broker/access/cas', methods: ['GET', 'POST'], name: 'admin.broker-access-cas', middleware: ['admin.auth'])]
    #[Route('/admin/broker/access/cas/{id}', methods: ['GET', 'POST'], name: 'admin.broker-access-ca', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/access/cas', methods: ['GET', 'POST'], name: 'customer.broker-access-cas', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/access/cas/{id}', methods: ['GET', 'POST'], name: 'customer.broker-access-ca', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function cas(ServerRequestInterface $request): ResponseInterface
    {
        $write = $request->getMethod() === 'POST';
        $context = $this->context($request, $write ? 'write' : 'read');
        $parameters = $request->getAttribute('type.route.params', []);
        $connection = $this->connection($request);
        try {
            if (!$write) {
                $input = (new Input([]))->withQuery($request->getUri()->getQuery());
                if (array_keys($input->source('query')) !== []) {
                    throw new HttpError(400, 'broker_access_query_invalid');
                }
                $result = AccessService::cas($connection, $context['tenant_id'], $parameters['id'] ?? '');
                $this->audit($connection, $request, $context, 'broker.access.read', $parameters['id'] ?? 'cas', $result, 'success', 'metadata_read');
                return $this->response($result);
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'json_required');
            }
            try {
                $payload = json_decode(RequestBody::read($request->getBody(), 32768), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            if (!is_array($payload) || array_diff(array_keys($payload), ['id', 'pem', 'revoke', 'expected_version']) !== []) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            if (($parameters['id'] ?? '') !== '' && ($payload['id'] ?? '') === '') {
                $payload['id'] = $parameters['id'];
            }
            $result = AccessService::publishCa($connection, $context['tenant_id'], $context['actor_id'], $context['actor_realm'], AccessService::caInput($payload));
            if ($context['actor_realm'] !== 'broker') {
                $this->context($request, 'write');
            }
            $this->audit($connection, $request, $context, 'broker.access.publish', (string) $result['id'], $result, 'success', 'ca_published');
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, $write ? 'broker.access.publish' : 'broker.access.read', $parameters['id'] ?? 'cas', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 导入签名 CRL 或登记受控 HTTPS 源；获取状态与执行状态分开，响应不含 PEM。
     *
     * @throws HttpError 查询带未知参数、签名无效、旧列表回退、URL 非法或版本冲突。
     */
    #[Route('/broker/access/cas/{id}/crl', methods: ['GET', 'POST'], name: 'broker.access-crl', middleware: ['broker.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/admin/broker/access/cas/{id}/crl', methods: ['GET', 'POST'], name: 'admin.broker-access-crl', middleware: ['admin.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/access/cas/{id}/crl', methods: ['GET', 'POST'], name: 'customer.broker-access-crl', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'id' => '[a-f0-9]{32}'])]
    public function crl(ServerRequestInterface $request): ResponseInterface
    {
        $write = $request->getMethod() === 'POST';
        $context = $this->context($request, $write ? 'write' : 'read');
        $id = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        try {
            if (!$write) {
                $input = (new Input([]))->withQuery($request->getUri()->getQuery());
                if (array_keys($input->source('query')) !== []) {
                    throw new HttpError(400, 'broker_access_query_invalid');
                }
                $result = AccessService::cas($connection, $context['tenant_id'], $id);
                $this->audit($connection, $request, $context, 'broker.access.read', $id, $result, 'success', 'crl_read');
                return $this->response($result);
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'json_required');
            }
            try {
                $payload = json_decode(RequestBody::read($request->getBody(), 65536), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            if (!is_array($payload)) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            $payload['id'] = $id;
            $result = AccessService::publishCrl($connection, $context['tenant_id'], $context['actor_id'], $context['actor_realm'], AccessService::crlInput($payload));
            if ($context['actor_realm'] !== 'broker') {
                $this->context($request, 'write');
            }
            $this->audit($connection, $request, $context, 'broker.access.publish', $id, $result, 'success', 'crl_published');
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, $write ? 'broker.access.publish' : 'broker.access.read', $id, [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 查询或写入平台直接吊销的序列号；不经 CA 签名，已接纳只增不减。
     *
     * @throws HttpError 查询带未知参数、序列号非法或版本冲突。
     */
    #[Route('/broker/access/revocations', methods: ['GET', 'POST'], name: 'broker.access-revocations', middleware: ['broker.auth'])]
    #[Route('/admin/broker/access/revocations', methods: ['GET', 'POST'], name: 'admin.broker-access-revocations', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/access/revocations', methods: ['GET', 'POST'], name: 'customer.broker-access-revocations', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function revocations(ServerRequestInterface $request): ResponseInterface
    {
        $write = $request->getMethod() === 'POST';
        $context = $this->context($request, $write ? 'write' : 'read');
        $connection = $this->connection($request);
        try {
            if (!$write) {
                $input = (new Input([]))->withQuery($request->getUri()->getQuery());
                if (array_keys($input->source('query')) !== []) {
                    throw new HttpError(400, 'broker_access_query_invalid');
                }
                $result = AccessService::platformSerials($connection, $context['tenant_id']);
                $this->audit($connection, $request, $context, 'broker.access.read', 'revocations', $result, 'success', 'revocation_read');
                return $this->response($result);
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'json_required');
            }
            try {
                $payload = json_decode(RequestBody::read($request->getBody(), 4096), true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            if (!is_array($payload)) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            $result = AccessService::publishPlatformSerial($connection, $context['tenant_id'], $context['actor_id'], $context['actor_realm'], AccessService::platformSerialInput($payload));
            if ($context['actor_realm'] !== 'broker') {
                $this->context($request, 'write');
            }
            $this->audit($connection, $request, $context, 'broker.access.publish', (string) $result['id'], $result, 'success', 'platform_serial_published');
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, $write ? 'broker.access.publish' : 'broker.access.read', 'revocations', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 换证预览：不形成版本。返回新旧指纹、重叠截止和是否立即收紧；不含 PEM。
     *
     * @throws HttpError 主体不存在、正文非 JSON、PEM 非法或重叠秒数越界。
     */
    #[Route('/broker/access/principals/{id}/certificate-preview', methods: ['POST'], name: 'broker.access-certificate-preview', middleware: ['broker.auth'])]
    #[Route('/admin/broker/access/principals/{id}/certificate-preview', methods: ['POST'], name: 'admin.broker-access-certificate-preview', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/access/principals/{id}/certificate-preview', methods: ['POST'], name: 'customer.broker-access-certificate-preview', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function certificatePreview(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'read');
        $id = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        try {
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'json_required');
            }
            try {
                $payload = json_decode(RequestBody::read($request->getBody(), 32768), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            if (!is_array($payload)) {
                throw new HttpError(422, 'broker_access_invalid');
            }
            $result = AccessService::previewCertificateRotation($connection, $context['tenant_id'], $id, AccessService::rotationInput($payload));
            $this->audit($connection, $request, $context, 'broker.access.read', $id, $result, 'success', 'certificate_preview');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.access.read', $id, [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /** 只读版本列表与节点生效摘要；不含快照秘密。 */
    #[Route('/broker/access/revisions', name: 'broker.access-revisions', middleware: ['broker.auth'])]
    #[Route('/broker/access/revisions/{id}', name: 'broker.access-revision', middleware: ['broker.auth'])]
    #[Route('/admin/broker/access/revisions', name: 'admin.broker-access-revisions', middleware: ['admin.auth'])]
    #[Route('/admin/broker/access/revisions/{id}', name: 'admin.broker-access-revision', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/access/revisions', name: 'customer.broker-access-revisions', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/broker/access/revisions/{id}', name: 'customer.broker-access-revision', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function revisions(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'read');
        $parameters = $request->getAttribute('type.route.params', []);
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), ['page', 'per_page']) !== []) {
            throw new HttpError(400, 'broker_access_query_invalid');
        }
        $query = \_vali(['page' => Field::integer()->cast()->range(1, 100000)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->from('query')], $input);
        return $this->response(AccessService::revisions($this->connection($request), $context['tenant_id'], $parameters['id'] ?? '', $query['page'] ?? 1, $query['per_page'] ?? 20));
    }

    /** 对当前未完成版本显式重试；不增加新版本，也不把失败节点标成已生效。 */
    #[Route('/broker/access/revisions/{id}/retry', methods: ['POST'], name: 'broker.access-retry', middleware: ['broker.auth'])]
    #[Route('/admin/broker/access/revisions/{id}/retry', methods: ['POST'], name: 'admin.broker-access-retry', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/access/revisions/{id}/retry', methods: ['POST'], name: 'customer.broker-access-retry', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function retry(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, 'broker.access.retry', 'access_retried', static function (Connection $connection, string $id, array $context): array {
            return AccessService::retry($connection, $id, $context['tenant_id']);
        });
    }

    /** 非收紧发布回退到上一快照并形成新版本；收紧失败不能用回退恢复旧授权。 */
    #[Route('/broker/access/revisions/{id}/rollback', methods: ['POST'], name: 'broker.access-rollback', middleware: ['broker.auth'])]
    #[Route('/admin/broker/access/revisions/{id}/rollback', methods: ['POST'], name: 'admin.broker-access-rollback', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/access/revisions/{id}/rollback', methods: ['POST'], name: 'customer.broker-access-rollback', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function rollback(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, 'broker.access.rollback', 'access_rolled_back', static function (Connection $connection, string $id, array $context): array {
            return AccessService::rollback($connection, $id, $context['actor_id'], $context['actor_realm'], $context['tenant_id']);
        });
    }

    /**
     * 写操作共用授权复核、审计与 JSON 响应；成功后再核写权限，避免权限在途被收回。
     *
     * @param \Closure(Connection,string,array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity}): array<string, mixed> $operation
     */
    private function mutate(ServerRequestInterface $request, string $action, string $reason, \Closure $operation): ResponseInterface
    {
        $context = $this->context($request, 'write');
        $id = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        try {
            $result = $operation($connection, $id, $context);
            if ($context['actor_realm'] !== 'broker') {
                $this->context($request, 'write');
            }
            $this->audit($connection, $request, $context, $action, $id, $result, 'success', $reason);
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, $action, $id, [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 从路由与身份解析租户范围。独立管理员、平台与租户令牌不能互换。
     *
     * @return array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity}
     */
    private function context(ServerRequestInterface $request, string $action): array
    {
        foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
            if ($request->hasHeader($header)) {
                throw new HttpError(403, 'identity_scope_forbidden');
            }
        }
        $parameters = $request->getAttribute('type.route.params', []);
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permission = $action === 'write' ? 'broker.write' : 'broker.read';
        if (!isset($parameters['tenant'])) {
            if ($request->hasHeader('X-Tenant-Id')) {
                throw new HttpError(403, 'identity_scope_forbidden');
            }
            if (in_array('broker_admin', $identity->roles(), true)) {
                if (!$this->identities->user($identity->subject())['platform_admin']) {
                    throw new HttpError(403, 'forbidden');
                }
                return ['tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'broker',
                    'permissions' => [$permission], 'identity' => $identity];
            }
            if (!in_array('realm:admin', $identity->roles(), true)) {
                throw new HttpError(403, 'forbidden');
            }
            $access = RoleService::readContext($identity, 'admin', '', $permission);
            return ['tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'admin',
                'permissions' => $access['permissions'], 'identity' => $identity];
        }
        $headers = $request->getHeader('X-Tenant-Id');
        if (count($headers) !== 1 || $headers[0] !== $parameters['tenant']) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        $access = RoleService::readContext($identity, 'customer', $parameters['tenant'], $permission);
        return ['tenant_id' => $parameters['tenant'], 'actor_id' => $identity->subject(), 'actor_realm' => 'customer',
            'permissions' => $access['permissions'], 'identity' => $identity];
    }

    /**
     * 写入 Broker 审计账本；失败与拒绝路径也记录，不把口令、PEM 或私钥写入 facts。
     *
     * @param array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity} $context
     * @param array<string, mixed> $result
     */
    private function audit(Connection $connection, ServerRequestInterface $request, array $context, string $action, string $subject, array $result, string $outcome, string $reason): void
    {
        $requestId = (string) $request->getAttribute('app.request_id', '');
        $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
        $stage = $outcome === 'success' ? 'completed' : 'failed';
        $realm = $context['actor_realm'];
        $required = ($realm === 'broker' ? '' : $realm . '.') . (str_ends_with($action, '.read') ? 'broker.read' : 'broker.write');
        $payload = [
            'operation_id' => bin2hex(random_bytes(16)), 'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $context['actor_id'], 'tenant_id' => $context['tenant_id'], 'action' => $action, 'subject_id' => $subject,
            'authorization' => ['source' => $realm === 'broker' ? 'broker-admin' : ($realm === 'admin' ? 'platform' : 'customer-member'),
                'role' => $realm === 'broker' ? 'broker_admin' : 'rbac', 'permissions' => $outcome === 'denied' ? [] : $context['permissions'],
                'required_action' => $required, 'decision' => $outcome === 'denied' ? 'denied' : 'allowed',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'access.revision', 'node_id' => '', 'node_run_id' => '', 'observation_run' => '', 'generation' => (int) ($result['version'] ?? 0)],
            'impact' => ['confirmed' => $outcome === 'success', 'effect' => $reason, 'target_count' => isset($result['nodes']) && is_array($result['nodes']) ? count($result['nodes']) : 0, 'proof_hash' => ''],
        ];
        if ($realm !== 'broker' && $context['identity'] instanceof Identity) {
            $identity = IdentityService::context($context['identity'], $context['tenant_id'] ?? '');
            if ($identity !== []) {
                unset($identity['actor_name'], $identity['expires_at']);
                $payload['identity'] = $identity;
            }
        }
        AuditLog::recordBroker($connection, $realm, $payload, $stage, ['request_id' => $requestId, 'stage' => $stage, 'result' => $outcome, 'facts' => ['reason' => $reason]]);
    }

    /** 请求作用域内的数据库连接；缺少作用域不能私建连接。 */
    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('broker_request_scope_required');
        }
        return \Type\Orm\Db::connection('default', true);
    }

    /**
     * JSON 响应且禁止缓存。
     *
     * @param array<string, mixed> $data
     */
    private function response(array $data, int $status = 200): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
