<?php

declare(strict_types=1);

namespace app\platform\mqtt\controller;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\platform\mqtt\service\CompatService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;

/** 查询当前应用与管理库的兼容代次及危险回退维护步骤；不含载荷，租户只读。 */
final class CompatController
{
    /** 保存请求处理所需的数据库、身份服务和响应工厂；连接在请求作用域中借用。 */
    public function __construct(private Factory $messages) {}

    /**
     * 读取兼容快照；保存成功不等于允许回退。
     *
     * @throws HttpError 未认证、权限不足或身份范围非法
     */
    #[Route('/platform/broker/compat', methods: ['GET'], name: 'platform.broker-compat', middleware: ['platform.auth'])]
    public function current(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request);
        $connection = $this->connection($request);
        try {
            $result = CompatService::current($connection);
            $this->audit($connection, $request, $context, 'broker.compat.read', 'compat', $result, 'success', 'metadata_read');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.compat.read', 'compat', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * @return array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity}
     */
    private function context(ServerRequestInterface $request): array
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        $access = RoleService::readContext($identity, 'platform', '', 'platform.broker.read');
        return ['tenant_id' => null, 'actor_id' => $identity->subject(), 'actor_realm' => 'platform',
            'permissions' => $access['permissions'], 'identity' => $identity];
    }

    /**
     * @param array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity} $context
     * @param array<string, mixed> $result
     */
    private function audit(Connection $connection, ServerRequestInterface $request, array $context, string $action, string $subject, array $result, string $outcome, string $reason): void
    {
        $requestId = (string)$request->getAttribute('app.request_id', '');
        $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
        $stage = $outcome === 'success' ? 'completed' : 'failed';
        $realm = $context['actor_realm'];
        $required = ($realm . '.') . 'broker.read';
        $payload = [
            'operation_id' => is_string($result['id'] ?? null) ? (string)$result['id'] : bin2hex(random_bytes(16)),
            'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $context['actor_id'], 'tenant_id' => $context['tenant_id'], 'action' => $action, 'subject_id' => $subject,
            'authorization' => ['source' => 'platform',
                'role' => 'rbac', 'permissions' => $outcome === 'denied' ? [] : $context['permissions'],
                'required_action' => $required, 'decision' => $outcome === 'denied' ? 'denied' : 'allowed',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'compat.runtime', 'node_id' => '', 'node_run_id' => '', 'observation_run' => '',
                'generation' => (int)($result['runtime_epoch'] ?? 0)],
            'impact' => ['confirmed' => $outcome === 'success', 'effect' => $reason, 'target_count' => 0, 'proof_hash' => ''],
        ];
        if ($context['identity'] instanceof Identity) {
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
        return Db::connection('default', true);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
