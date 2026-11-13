<?php

declare(strict_types=1);

namespace app\center\controller;

use app\center\service\TransferService;
use app\common\bootstrap\ApplicationContext;
use app\common\middleware\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Validate\Field;
use Type\Validate\Input;

/** 平台发起隔离、目标客户确认接收。 */
final class TransferController
{
    /** 保存受管连接与消息工厂；持久流程仍由TransferService拥有。 */
    public function __construct(private Factory $messages, private ApplicationContext $application) {}

    /** 平台设置期限，或以确定请求 ID 结束/重新分配当前关系；身份不接受客户头覆盖。 */
    #[Route('/platform/devices/{device}/usage/{operation}', methods: ['POST'], name: 'platform.device-usage', middleware: ['platform.auth'], constraints: ['device' => '[a-f0-9]{32}', 'operation' => 'expiry|end|reassign'])]
    public function usage(string $device, string $operation, ServerRequestInterface $request): ResponseInterface
    {
        if ($request->hasHeader('X-Tenant-Id')) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        $identity = $this->identity($request);
        $fields = ['version' => Field::integer()->required()->range(1, 2147483645)];
        if ($operation === 'expiry') {
            $fields['expires_at'] = Field::integer()->nullable()->required()->range(1, 4102444800);
            $data = $this->payload($request, $fields);
            return $this->response(200, ['data' => TransferService::expiry($identity, $device, $data['version'], $data['expires_at'])]);
        }
        $fields['id'] = Field::text()->required()->matches('/^[a-f0-9]{32}$/D');
        if ($operation === 'reassign') {
            $fields['target_tenant_id'] = Field::text()->required()->matches('/^[a-f0-9]{32}$/D');
        }
        return $this->response(202, ['data' => TransferService::reassign($identity, $device, $this->payload($request, $fields))]);
    }

    /** 平台查看全部、客户仅查看来源或目标为本客户的转配；GET 不推进外部工作。 */
    #[Route('/platform/reassignments', name: 'platform.reassignments', middleware: ['platform.auth'])]
    #[Route('/center/reassignments', name: 'center.reassignments', middleware: ['system.auth'])]
    public function reassignments(ServerRequestInterface $request): ResponseInterface
    {
        $realm = str_starts_with((string)$request->getAttribute('type.route'), 'platform.') ? 'platform' : 'system';
        $tenant = $realm === 'platform' ? '' : $this->tenant($request);
        if ($realm === 'platform' && $request->hasHeader('X-Tenant-Id')) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), ['page', 'per_page']) !== []) {
            throw new HttpError(422, 'reassignment_query_invalid');
        }
        $query = \_vali(['page' => Field::integer()->cast()->range(1, 100000)->from('query'), 'per_page' => Field::integer()->cast()->range(1, 100)->from('query')], $input) + ['page' => 1, 'per_page' => 20];
        return $this->response(200, TransferService::reassignments($this->identity($request), $realm, $tenant, $query['page'], $query['per_page']));
    }

    /** 推进必须由平台明确请求，接收必须由目标客户明确确认；返回实际阶段和一次性凭据。 */
    #[Route('/platform/reassignments/{transfer}', methods: ['GET', 'POST'], name: 'platform.reassignment', middleware: ['platform.auth'], constraints: ['transfer' => '[a-f0-9]{32}'])]
    #[Route('/center/reassignments/{transfer}', methods: ['GET', 'POST'], name: 'center.reassignment', middleware: ['system.auth'], constraints: ['transfer' => '[a-f0-9]{32}'])]
    public function reassignment(string $transfer, ServerRequestInterface $request): ResponseInterface
    {
        $realm = str_starts_with((string)$request->getAttribute('type.route'), 'platform.') ? 'platform' : 'system';
        $tenant = $realm === 'platform' ? '' : $this->tenant($request);
        if ($realm === 'platform' && $request->hasHeader('X-Tenant-Id')) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        $identity = $this->identity($request);
        if ($request->getMethod() === 'GET') {
            return $this->response(200, TransferService::reassignments($identity, $realm, $tenant, 1, 20, $transfer));
        }
        $fields = ['version' => Field::integer()->required()->range(1, 2147483645),
            'action' => Field::text()->required()->oneOf([$realm === 'platform' ? 'advance' : 'accept'])];
        if ($realm === 'system') {
            $fields['id'] = Field::text()->required()->matches('/^[a-f0-9]{32}$/D');
        }
        $data = $this->payload($request, $fields);
        $result = $realm === 'platform'
            ? TransferService::advanceRelationship($identity, $transfer, $data['version'], ApplicationContext::centerStoreWorker($this->application->settings()->text('app.mqtt.command')))
            : TransferService::acceptRelationship($identity, $tenant, $transfer, $data['version'], $data['id']);
        return $this->response(200, ['data' => $result]);
    }

    private function tenant(ServerRequestInterface $request): string
    {
        return TenantContext::id($request);
    }

    /** @param array<string, Field> $fields 有界对象白名单；拒绝伪造归属及状态。 */
    private function payload(ServerRequestInterface $request, array $fields): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 4096), 4096, 4);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'unexpected_field');
        }
        return \_vali($fields, $input);
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

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
