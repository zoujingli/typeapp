<?php

declare(strict_types=1);

namespace app\platform\mqtt\controller;

use app\common\bootstrap\ApplicationContext;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\platform\mqtt\service\QuotaService;
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
use Type\Validate\Field;
use Type\Validate\Input;

/** 连接、会话与消息额度的版本化发布；保存成功不等于集群生效，降配额不删除已确认积压。 */
final class QuotaController
{
    /** @var list<string> 当前宿主持久存储命令；空列表时预览只含实时连接用量。 */
    private array $worker;

    /** 复用固定身份域和启动配置，不在构造时建立连接。 */
    public function __construct(private Factory $messages, ApplicationContext $context)
    {
        $this->worker = ApplicationContext::quotaWorker($context->settings());
    }

    /**
     * 查询当前额度、用量、超额与节点版本。
     *
     * @throws HttpError 未认证、权限不足或尚未引导额度
     */
    #[Route('/platform/broker/quotas', methods: ['GET'], name: 'platform.broker-quotas', middleware: ['platform.auth'])]
    public function current(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'read');
        $connection = $this->connection($request);
        try {
            $result = QuotaService::current($connection, $this->worker);
            $this->audit($connection, $request, $context, 'broker.quota.read', 'quotas', $result, 'success', 'metadata_read');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.quota.read', 'quotas', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 预览拟发布额度对现有用量的影响，不含载荷。
     *
     * @throws HttpError 正文非法、版本冲突或权限不足
     */
    #[Route('/platform/broker/quotas/preview', methods: ['POST'], name: 'platform.broker-quotas-preview', middleware: ['platform.auth'])]
    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'read');
        $connection = $this->connection($request);
        try {
            $result = QuotaService::preview($connection, $this->worker, QuotaService::changeInput($this->json($request)));
            $this->audit($connection, $request, $context, 'broker.quota.read', 'quotas', $result, 'success', 'quota_preview');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.quota.read', 'quotas', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 明确确认后发布新额度版本；保存成功不等于集群生效。
     *
     * @throws HttpError 未确认、版本冲突、发布暂停或租户写入
     */
    #[Route('/platform/broker/quotas', methods: ['POST'], name: 'platform.broker-quotas-publish', middleware: ['platform.auth'])]
    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'write');
        $connection = $this->connection($request);
        try {
            $result = QuotaService::publish($connection, $context['actor_id'], $context['actor_realm'], QuotaService::changeInput($this->json($request)));
            $this->audit($connection, $request, $context, 'broker.quota.publish', (string)$result['id'], $result, 'success', 'quota_published');
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.quota.publish', 'quotas', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 查询额度发布版本。
     *
     * @throws HttpError 查询非法或版本不存在
     */
    #[Route('/platform/broker/quotas/revisions', name: 'platform.broker-quota-revisions', middleware: ['platform.auth'])]
    #[Route('/platform/broker/quotas/revisions/{id}', name: 'platform.broker-quota-revision', middleware: ['platform.auth'])]
    public function revisions(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'read');
        $parameters = $request->getAttribute('type.route.params', []);
        $connection = $this->connection($request);
        try {
            $input = (new Input([]))->withQuery($request->getUri()->getQuery());
            if (array_diff(array_keys($input->source('query')), ['page', 'per_page']) !== []) {
                throw new HttpError(400, 'broker_quota_query_invalid');
            }
            $query = \_vali(['page' => Field::integer()->cast()->range(1, 100000)->from('query'),
                'per_page' => Field::integer()->cast()->range(1, 100)->from('query')], $input);
            $result = QuotaService::revisions($connection, $parameters['id'] ?? '', $query['page'] ?? 1, $query['per_page'] ?? 20);
            $this->audit($connection, $request, $context, 'broker.quota.read', $parameters['id'] ?? 'revisions', $result, 'success', 'metadata_read');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.quota.read', $parameters['id'] ?? 'revisions', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /** 核对写权限后重试配额版本，并记录审计；成功响应不代表全部节点已生效。 */
    #[Route('/platform/broker/quotas/revisions/{id}/retry', methods: ['POST'], name: 'platform.broker-quota-retry', middleware: ['platform.auth'])]
    public function retry(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, 'broker.quota.retry', 'quota_retried', static function (Connection $connection, string $id, array $context): array {
            return QuotaService::retry($connection, $id);
        });
    }

    /** 将获授权的回退交给配额服务并审计，不在 HTTP 请求中重启节点。 */
    #[Route('/platform/broker/quotas/revisions/{id}/rollback', methods: ['POST'], name: 'platform.broker-quota-rollback', middleware: ['platform.auth'])]
    public function rollback(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, 'broker.quota.rollback', 'quota_rolled_back', static function (Connection $connection, string $id, array $context): array {
            return QuotaService::rollback($connection, $id, $context['actor_id'], $context['actor_realm']);
        });
    }

    /**
     * @param \Closure(Connection, string, array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity}): array<string, mixed> $operation
     */
    private function mutate(ServerRequestInterface $request, string $action, string $reason, \Closure $operation): ResponseInterface
    {
        $context = $this->context($request, 'write');
        $id = $request->getAttribute('type.route.params', [])['id'] ?? '';
        $connection = $this->connection($request);
        try {
            $result = $operation($connection, $id, $context);
            $this->audit($connection, $request, $context, $action, $id, $result, 'success', $reason);
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, $action, $id, [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ServerRequestInterface $request): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        try {
            $payload = json_decode(RequestBody::read($request->getBody(), 8192), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(422, 'broker_quota_invalid');
        }
        if (!is_array($payload)) {
            throw new HttpError(422, 'broker_quota_invalid');
        }
        return $payload;
    }

    /**
     * @return array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?Identity}
     */
    private function context(ServerRequestInterface $request, string $action): array
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        $access = RoleService::readContext($identity, 'platform', '', $action === 'write' ? 'platform.broker.write' : 'platform.broker.read');
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
        $required = ($realm . '.') . (str_ends_with($action, '.read') ? 'broker.read' : 'broker.write');
        $payload = [
            'operation_id' => is_string($result['id'] ?? null) ? (string)$result['id'] : bin2hex(random_bytes(16)),
            'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $context['actor_id'], 'tenant_id' => $context['tenant_id'], 'action' => $action, 'subject_id' => $subject,
            'authorization' => ['source' => 'platform',
                'role' => 'rbac', 'permissions' => $outcome === 'denied' ? [] : $context['permissions'],
                'required_action' => $required, 'decision' => $outcome === 'denied' ? 'denied' : 'allowed',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'quota.revision', 'node_id' => '', 'node_run_id' => '', 'observation_run' => '',
                'generation' => (int)($result['version'] ?? 0)],
            'impact' => ['confirmed' => $outcome === 'success', 'effect' => $reason, 'target_count' => isset($result['nodes']) && is_array($result['nodes']) ? count($result['nodes']) : 0, 'proof_hash' => ''],
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
