<?php

declare(strict_types=1);

namespace app\broker\controller;

use app\broker\service\DebugService;
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

/** 登录人员签发租户或独立前缀范围内的 MQTT 调试短期凭据；平台管理端不能读取租户载荷。 */
final class DebugController
{
    /** 保存请求处理所需的数据库、身份服务和响应工厂；连接在请求作用域中借用。 */
    public function __construct(private DatabaseManager $database, private IdentityService $identities, private Factory $messages)
    {
    }

    /**
     * 查询当前会话活动凭据与 WSS 入口；不含口令。
     *
     * @throws HttpError 未认证、平台管理端读取或权限不足。
     */
    #[Route('/broker/debug', methods: ['GET'], name: 'broker.debug', middleware: ['broker.auth'])]
    #[Route('/admin/broker/debug', methods: ['GET'], name: 'admin.broker-debug', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/debug', methods: ['GET'], name: 'customer.broker-debug', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function current(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request);
        $connection = $this->connection($request);
        try {
            $result = DebugService::current($connection, $context['identity'], $context['tenant_id']);
            $this->audit($connection, $request, $context, 'broker.debug.read', is_array($result['credential'] ?? null) ? (string) $result['credential']['id'] : 'debug', $result, 'success', 'metadata_read');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.debug.read', 'debug', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 签发新的调试凭据并撤销同行旧凭据；口令只出现在本次响应。
     *
     * @throws HttpError 平台管理端签发、会话不足、WSS 未开或正文非法。
     */
    #[Route('/broker/debug', methods: ['POST'], name: 'broker.debug-issue', middleware: ['broker.auth'])]
    #[Route('/admin/broker/debug', methods: ['POST'], name: 'admin.broker-debug-issue', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/debug', methods: ['POST'], name: 'customer.broker-debug-issue', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request);
        $this->emptyJson($request);
        $connection = $this->connection($request);
        try {
            $result = DebugService::issue($connection, $context['identity'], $context['tenant_id']);
            $recorded = $result;
            unset($recorded['password']);
            $this->audit($connection, $request, $context, 'broker.debug.issue', (string) $result['id'], $recorded, 'success', 'debug_issued');
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.debug.issue', 'debug', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 撤销当前登录会话的活动调试凭据。
     *
     * @throws HttpError 平台管理端撤销或权限不足。
     */
    #[Route('/broker/debug/revoke', methods: ['POST'], name: 'broker.debug-revoke', middleware: ['broker.auth'])]
    #[Route('/admin/broker/debug/revoke', methods: ['POST'], name: 'admin.broker-debug-revoke', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/debug/revoke', methods: ['POST'], name: 'customer.broker-debug-revoke', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request);
        $this->emptyJson($request);
        $connection = $this->connection($request);
        try {
            $result = DebugService::revoke($connection, $context['identity']);
            $this->audit($connection, $request, $context, 'broker.debug.revoke', 'debug', $result, 'success', 'debug_revoked');
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.debug.revoke', 'debug', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * @return array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:Identity}
     */
    private function context(ServerRequestInterface $request): array
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
        if (!isset($parameters['tenant'])) {
            if ($request->hasHeader('X-Tenant-Id')) {
                throw new HttpError(403, 'identity_scope_forbidden');
            }
            if (in_array('broker_admin', $identity->roles(), true)) {
                if (!$this->identities->user($identity->subject())['platform_admin']) {
                    throw new HttpError(403, 'forbidden');
                }
                return ['tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'broker',
                    'permissions' => ['broker.read'], 'identity' => $identity];
            }
            throw new HttpError(403, 'forbidden');
        }
        $headers = $request->getHeader('X-Tenant-Id');
        if (count($headers) !== 1 || $headers[0] !== $parameters['tenant']) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        $access = RoleService::readContext($identity, 'customer', $parameters['tenant'], 'broker.read');
        return ['tenant_id' => $parameters['tenant'], 'actor_id' => $identity->subject(), 'actor_realm' => 'customer',
            'permissions' => $access['permissions'], 'identity' => $identity];
    }

    /**
     * @param array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:Identity} $context
     * @param array<string, mixed> $result
     */
    private function audit(Connection $connection, ServerRequestInterface $request, array $context, string $action, string $subject, array $result, string $outcome, string $reason): void
    {
        $requestId = (string) $request->getAttribute('app.request_id', '');
        $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
        $stage = $outcome === 'success' ? 'completed' : 'failed';
        $realm = $context['actor_realm'];
        $required = ($realm === 'broker' ? '' : $realm . '.') . 'broker.read';
        $payload = [
            'operation_id' => $requestId,
            'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $context['actor_id'], 'tenant_id' => $context['tenant_id'], 'action' => $action, 'subject_id' => $subject,
            'authorization' => ['source' => $realm === 'broker' ? 'broker-admin' : 'customer-member',
                'role' => $realm === 'broker' ? 'broker_admin' : 'rbac', 'permissions' => $outcome === 'denied' ? [] : $context['permissions'],
                'required_action' => $required, 'decision' => $outcome === 'denied' ? 'denied' : 'allowed',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'debug.credential', 'node_id' => '', 'node_run_id' => '', 'observation_run' => '',
                'generation' => (int) ($result['expires_at'] ?? 0)],
            'impact' => ['confirmed' => $outcome === 'success', 'effect' => $reason, 'target_count' => 1, 'proof_hash' => ''],
        ];
        if ($realm !== 'broker') {
            $identity = IdentityService::context($context['identity'], $context['tenant_id'] ?? '');
            if ($identity !== []) {
                unset($identity['actor_name'], $identity['expires_at']);
                $payload['identity'] = $identity;
            }
        }
        AuditLog::recordBroker($connection, $realm, $payload, $stage, ['request_id' => $requestId, 'stage' => $stage, 'result' => $outcome, 'facts' => ['reason' => $reason]]);
    }

    private function emptyJson(ServerRequestInterface $request): void
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        try {
            $payload = json_decode(RequestBody::read($request->getBody(), 8192), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(422, 'broker_debug_invalid');
        }
        if (!is_array($payload) || $payload !== []) {
            throw new HttpError(422, 'broker_debug_invalid');
        }
    }

    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('broker_request_scope_required');
        }
        return \Type\Orm\Db::connection('default', true);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
