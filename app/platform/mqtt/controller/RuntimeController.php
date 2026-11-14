<?php

declare(strict_types=1);

namespace app\platform\mqtt\controller;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\platform\mqtt\service\RuntimeService;
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
 * 监听、I/O 与节点证书路径的校验保存；页面不执行重启，生效观察来自节点上报的加载身份。
 */
final class RuntimeController
{
    /**
     * 保存请求处理所需的数据库、身份服务和响应工厂；连接在请求作用域中借用。
     */
    public function __construct(private Factory $messages) {}

    /**
     * 查询已保存运行配置、节点加载身份与握手 CA 摘要。
     *
     * @throws HttpError 未认证、权限不足或尚未引导配置
     */
    #[Route('/platform/broker/runtime', methods: ['GET'], name: 'platform.broker-runtime', middleware: ['platform.auth'])]
    public function current(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'read');
        $connection = $this->connection($request);
        try {
            $result = RuntimeService::current($connection);
            $this->audit($connection, $request, $context, 'broker.runtime.read', 'runtime', $result, 'success', 'metadata_read');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.runtime.read', 'runtime', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 预览拟保存配置；不写入版本，也不重启节点。
     *
     * @throws HttpError 正文非法、版本冲突或权限不足
     */
    #[Route('/platform/broker/runtime/preview', methods: ['POST'], name: 'platform.broker-runtime-preview', middleware: ['platform.auth'])]
    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'read');
        $connection = $this->connection($request);
        try {
            $result = RuntimeService::preview($connection, RuntimeService::changeInput($this->json($request)));
            $this->audit($connection, $request, $context, 'broker.runtime.read', 'runtime', $result, 'success', 'runtime_preview');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.runtime.read', 'runtime', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 明确确认后校验并保存；保存成功不等于运维重启已生效。
     *
     * @throws HttpError 未确认、版本冲突、发布暂停或租户写入
     */
    #[Route('/platform/broker/runtime', methods: ['POST'], name: 'platform.broker-runtime-publish', middleware: ['platform.auth'])]
    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'write');
        $connection = $this->connection($request);
        try {
            $result = RuntimeService::publish($connection, $context['actor_id'], $context['actor_realm'], RuntimeService::changeInput($this->json($request)));
            $this->audit($connection, $request, $context, 'broker.runtime.publish', (string)$result['id'], $result, 'success', 'runtime_published');
            return $this->response(['data' => $result]);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.runtime.publish', 'runtime', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 查询运行配置版本。
     *
     * @throws HttpError 查询非法或版本不存在
     */
    #[Route('/platform/broker/runtime/revisions', name: 'platform.broker-runtime-revisions', middleware: ['platform.auth'])]
    #[Route('/platform/broker/runtime/revisions/{id}', name: 'platform.broker-runtime-revision', middleware: ['platform.auth'])]
    public function revisions(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->context($request, 'read');
        $parameters = $request->getAttribute('type.route.params', []);
        $connection = $this->connection($request);
        try {
            $input = (new Input([]))->withQuery($request->getUri()->getQuery());
            if (array_diff(array_keys($input->source('query')), ['page', 'per_page']) !== []) {
                throw new HttpError(400, 'broker_runtime_query_invalid');
            }
            $query = \_vali([
                'page.default' => 1,
                'page.integer' => '页码不正确',
                'page.between:1,100000' => '页码超出范围',
                'per_page.default' => 20,
                'per_page.integer' => '每页数量不正确',
                'per_page.between:1,100' => '每页数量超出范围',
            ], $input->source('query'));
            $result = RuntimeService::revisions($connection, $parameters['id'] ?? '', $query['page'] ?? 1, $query['per_page'] ?? 20);
            $this->audit($connection, $request, $context, 'broker.runtime.read', $parameters['id'] ?? 'revisions', $result, 'success', 'metadata_read');
            return $this->response($result);
        } catch (HttpError $error) {
            $this->audit($connection, $request, $context, 'broker.runtime.read', $parameters['id'] ?? 'revisions', [], $error->status() === 403 ? 'denied' : 'failed', $error->errorCode());
            throw $error;
        }
    }

    /**
     * 核对写权限后重试运行配置版本，并记录审计；成功响应不代表全部节点已生效。
     */
    #[Route('/platform/broker/runtime/revisions/{id}/retry', methods: ['POST'], name: 'platform.broker-runtime-retry', middleware: ['platform.auth'])]
    public function retry(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, 'broker.runtime.retry', 'runtime_retried', static function (Connection $connection, string $id, array $context): array {
            return RuntimeService::retry($connection, $id);
        });
    }

    /**
     * 将获授权的回退交给运行配置服务并审计，不在 HTTP 请求中重启节点。
     */
    #[Route('/platform/broker/runtime/revisions/{id}/rollback', methods: ['POST'], name: 'platform.broker-runtime-rollback', middleware: ['platform.auth'])]
    public function rollback(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, 'broker.runtime.rollback', 'runtime_rolled_back', static function (Connection $connection, string $id, array $context): array {
            return RuntimeService::rollback($connection, $id, $context['actor_id'], $context['actor_realm']);
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
            throw new HttpError(422, 'broker_runtime_invalid');
        }
        if (!is_array($payload)) {
            throw new HttpError(422, 'broker_runtime_invalid');
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
            'operation_id' => $action === 'broker.runtime.retry' || !is_string($result['id'] ?? null) ? $requestId : (string)$result['id'],
            'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $context['actor_id'], 'tenant_id' => $context['tenant_id'], 'action' => $action, 'subject_id' => $subject,
            'authorization' => ['source' => 'platform',
                'role' => 'rbac', 'permissions' => $outcome === 'denied' ? [] : $context['permissions'],
                'required_action' => $required, 'decision' => $outcome === 'denied' ? 'denied' : 'allowed',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'runtime.revision', 'node_id' => '', 'node_run_id' => '', 'observation_run' => '',
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

    /**
     * @param array<string, mixed> $data
     */
    private function response(array $data, int $status = 200): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
