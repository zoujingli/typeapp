<?php

declare(strict_types=1);

namespace app\broker\controller;

use app\broker\service\RecoveryService;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Runtime\ExecutionScope;

/** 查询当前恢复核对进度；不含主体指纹，租户只读，写入由维护命令完成。 */
final class RecoveryController
{
    public function __construct(private DatabaseManager $database, private IdentityService $identities, private Factory $messages)
    {
    }

    /**
     * 读取恢复进度；状态不是 ready 时管理 HTTP 本身不会启动。
     *
     * @throws HttpError 未认证、权限不足或身份范围非法。
     */
    #[Route('/broker/recovery', methods: ['GET'], name: 'broker.recovery', middleware: ['broker.auth'])]
    #[Route('/admin/broker/recovery', methods: ['GET'], name: 'admin.broker-recovery', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/broker/recovery', methods: ['GET'], name: 'customer.broker-recovery', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function current(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request);
        $connection = $this->connection($request);
        try {
            $host = $context['actor_realm'] === 'broker' ? 'broker' : 'app';
            $result = RecoveryService::status($connection, $host);
            $this->audit($connection, $request, $context, 'broker.recovery.read', 'recovery', $result, 'success', 'metadata_read');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.recovery.read', 'recovery', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * @return array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity}
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
            if (!in_array('realm:admin', $identity->roles(), true)) {
                throw new HttpError(403, 'forbidden');
            }
            $access = RoleService::readContext($identity, 'admin', '', 'broker.read');
            return ['tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'admin',
                'permissions' => $access['permissions'], 'identity' => $identity];
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
     * @param array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity} $context
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
            'operation_id' => bin2hex(random_bytes(16)),
            'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $context['actor_id'], 'tenant_id' => $context['tenant_id'],
            'action' => $action, 'subject_id' => is_string($result['id'] ?? null) ? (string) $result['id'] : $subject,
            'authorization' => ['source' => $realm === 'broker' ? 'broker-admin' : ($realm === 'admin' ? 'platform' : 'customer-member'),
                'role' => $realm === 'broker' ? 'broker_admin' : 'rbac', 'permissions' => $outcome === 'denied' ? [] : $context['permissions'],
                'required_action' => $required, 'decision' => $outcome === 'denied' ? 'denied' : 'allowed',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'recovery.runtime', 'node_id' => '', 'node_run_id' => '', 'observation_run' => '',
                'generation' => 0],
            'impact' => ['confirmed' => $outcome === 'success', 'effect' => $reason, 'target_count' => 0, 'proof_hash' => ''],
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
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
