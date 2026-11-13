<?php

declare(strict_types=1);

namespace app\center\controller;

use app\center\service\ApplicationGrantService;
use app\center\service\ClientApplicationService;
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

/** 双端应用管理的严格输入边界；平台显式选择客户，客户只能使用当前工作区。 */
final class ClientApplicationController
{
    /** 构造只接收无资源服务，连接归当前请求执行作用域所有。 */
    public function __construct(private Factory $messages, private ClientApplicationService $applications) {}

    /** 普通分页不携带凭据；列表与查询均按实时 RBAC 校验。 */
    #[Route('/platform/applications', name: 'platform.applications', middleware: ['platform.auth'])]
    #[Route('/center/applications', name: 'center.applications', middleware: ['system.auth'])]
    public function listing(ServerRequestInterface $request): ResponseInterface
    {
        $realm = self::realm($request);
        $tenant = self::tenant($request, $realm);
        $identity = self::identity($request, $realm, $tenant);
        $fields = ['page' => Field::integer()->cast()->range(1, 100000)->from('query'), 'per_page' => Field::integer()->cast()->range(1, 100)->from('query')];
        if ($realm === 'platform') {
            $fields['tenant_id'] = Field::text()->matches('/^([a-f0-9]{32})?$/D')->from('query');
        }
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'application_query_invalid');
        }
        $data = \_vali($fields, $input) + ['page' => 1, 'per_page' => 20, 'tenant_id' => ''];
        return $this->response(200, $this->applications->listing($identity, $realm, $realm === 'platform' ? $data['tenant_id'] : $tenant, $data['page'], $data['per_page']));
    }

    /** 详情只返回当前应用与密钥状态，不支持从历史发布快照读取秘密。 */
    #[Route('/platform/applications/{application}', name: 'platform.application-detail', middleware: ['platform.auth'], constraints: ['application' => '[a-f0-9]{32}'])]
    #[Route('/center/applications/{application}', name: 'center.application-detail', middleware: ['system.auth'], constraints: ['application' => '[a-f0-9]{32}'])]
    public function detail(string $application, ServerRequestInterface $request): ResponseInterface
    {
        $realm = self::realm($request);
        $tenant = self::tenant($request, $realm);
        if ($request->getUri()->getQuery() !== '') {
            throw new HttpError(422, 'application_query_invalid');
        }
        return $this->response(200, ['data' => $this->applications->detail(self::identity($request, $realm, $tenant), $realm, $tenant, $application)]);
    }

    /** 创建请求标识由调用方保留，重试仅确认原身份；应用密钥只在首次成功响应交付。 */
    #[Route('/platform/applications', methods: ['POST'], name: 'platform.application-create', middleware: ['platform.auth'])]
    #[Route('/center/applications', methods: ['POST'], name: 'center.application-create', middleware: ['system.auth'])]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $realm = self::realm($request);
        $tenant = self::tenant($request, $realm);
        $identity = self::identity($request, $realm, $tenant);
        $fields = ['id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'), 'name' => Field::text()->required()->trim()->length(1, 100)];
        if ($realm === 'platform') {
            $fields['tenant_id'] = Field::text()->required()->matches('/^[a-f0-9]{32}$/D');
        }
        $data = self::payload($request, $fields);
        $result = $this->applications->create($identity, $realm, $realm === 'platform' ? $data['tenant_id'] : $tenant, $data);
        return $this->response($result['replayed'] ? 200 : 201, ['data' => $result]);
    }

    /** 变更不接受客户端自定代次或密钥；轮换及重新签发均使用服务端随机秘密。 */
    #[Route('/platform/applications/{application}/{action}', methods: ['POST'], name: 'platform.application-change', middleware: ['platform.auth'], constraints: ['application' => '[a-f0-9]{32}', 'action' => 'update|enable|disable|rotate|revoke|issue'])]
    #[Route('/center/applications/{application}/{action}', methods: ['POST'], name: 'center.application-change', middleware: ['system.auth'], constraints: ['application' => '[a-f0-9]{32}', 'action' => 'update|enable|disable|rotate|revoke|issue'])]
    public function change(string $application, string $action, ServerRequestInterface $request): ResponseInterface
    {
        $realm = self::realm($request);
        $tenant = self::tenant($request, $realm);
        $identity = self::identity($request, $realm, $tenant);
        $fields = ['version' => Field::integer()->required()->range(1, 2147483646)];
        if ($realm === 'platform') {
            $fields['tenant_id'] = Field::text()->required()->matches('/^[a-f0-9]{32}$/D');
        }
        if ($action === 'update') {
            $fields['name'] = Field::text()->required()->trim()->length(1, 100);
        }
        $data = self::payload($request, $fields);
        return $this->response(200, ['data' => $this->applications->change($identity, $realm, $realm === 'platform' ? $data['tenant_id'] : $tenant, $application, $action, $data)]);
    }

    /** 授权列表同时投影当前使用关系和真实发布状态；读取不推进授权版本。 */
    #[Route('/platform/applications/{application}/devices', name: 'platform.application-devices', middleware: ['platform.auth'], constraints: ['application' => '[a-f0-9]{32}'])]
    #[Route('/center/applications/{application}/devices', name: 'center.application-devices', middleware: ['system.auth'], constraints: ['application' => '[a-f0-9]{32}'])]
    public function devices(string $application, ServerRequestInterface $request): ResponseInterface
    {
        $realm = self::realm($request);
        $tenant = self::tenant($request, $realm);
        $identity = self::identity($request, $realm, $tenant);
        $fields = ['page' => Field::integer()->cast()->range(1, 100000)->from('query'), 'per_page' => Field::integer()->cast()->range(1, 100)->from('query')];
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'application_query_invalid');
        }
        $data = \_vali($fields, $input) + ['page' => 1, 'per_page' => 20];
        return $this->response(200, (new ApplicationGrantService())->listing($identity, $realm, $tenant, $application, $data['page'], $data['per_page']));
    }

    /** 只接受明确当前关系和三项独立能力，不接受通配 Topic 或客户端自定租户来源。 */
    #[Route('/platform/applications/{application}/devices/{device}', methods: ['POST'], name: 'platform.application-device-authorize', middleware: ['platform.auth'], constraints: ['application' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}'])]
    #[Route('/center/applications/{application}/devices/{device}', methods: ['POST'], name: 'center.application-device-authorize', middleware: ['system.auth'], constraints: ['application' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}'])]
    public function authorizeDevice(string $application, string $device, ServerRequestInterface $request): ResponseInterface
    {
        $realm = self::realm($request);
        $tenant = self::tenant($request, $realm);
        $identity = self::identity($request, $realm, $tenant);
        $fields = ['ownership_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'), 'version' => Field::integer()->required()->range(0, 2147483646),
            'receive' => Field::boolean()->required(), 'send' => Field::boolean()->required(), 'subscribe' => Field::boolean()->required()];
        if ($realm === 'platform') {
            $fields['tenant_id'] = Field::text()->required()->matches('/^[a-f0-9]{32}$/D');
        }
        $data = self::payload($request, $fields);
        return $this->response(200, ['data' => (new ApplicationGrantService())->save($identity, $realm, $realm === 'platform' ? $data['tenant_id'] : $tenant, $application, $device, $data)]);
    }

    private static function realm(ServerRequestInterface $request): string
    {
        return str_starts_with((string)$request->getAttribute('type.route'), 'platform.') ? 'platform' : 'system';
    }

    private static function tenant(ServerRequestInterface $request, string $realm): string
    {
        return $realm === 'platform' ? '' : TenantContext::id($request);
    }

    /** 入口域与租户头均来自显式路由合同，不接受客户端注入人员来源。 */
    private static function identity(ServerRequestInterface $request, string $realm, string $tenant): Identity
    {
        if ($request->hasHeader('X-Support-Id') || $request->hasHeader('X-Impersonation-Id') || $request->hasHeader('X-Identity-Realm')
            || ($realm === 'platform' && $request->hasHeader('X-Tenant-Id'))) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    /** @param array<string,Field> $fields 严格表单字段，不把额外属性交给 Model。 */
    private static function payload(ServerRequestInterface $request, array $fields): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 16384), 16384, 12);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'unexpected_field');
        }
        return \_vali($fields, $input);
    }

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
