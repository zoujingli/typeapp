<?php

declare(strict_types=1);

namespace app\iot\controller;

use app\iot\service\EnrollmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Validate\Field;
use Type\Validate\Input;

/** 双端注册资格入口；只有控制端声明写路由，客户身份不能签发或增额。 */
final class EnrollmentController
{
    /** 构造只接收已有消息工厂和业务所有者，不建立数据库连接。 */
    public function __construct(private Factory $messages, private EnrollmentService $enrollments)
    {
    }

    /** 控制端可按客户筛选，客户端必须使用当前会话的可信租户。 */
    #[Route('/admin/device-authorizations', name: 'admin.enrollments', middleware: ['admin.auth'])]
    #[Route('/customer/tenants/{tenant}/device-authorizations', name: 'customer.enrollments', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function listing(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $request->getAttribute('type.route') === 'admin.enrollments' ? 'admin' : 'customer';
        $fields = ['page' => Field::integer()->cast()->range(1, 100000)->from('query'), 'per_page' => Field::integer()->cast()->range(1, 100)->from('query')];
        if ($realm === 'admin') {
            $fields['tenant_id'] = Field::text()->matches('/^([a-f0-9]{32})?$/D')->from('query');
        }
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'enrollment_query_invalid');
        }
        $data = \_vali($fields, $input) + ['page' => 1, 'per_page' => 20, 'tenant_id' => ''];
        $tenantId = $realm === 'admin' ? $data['tenant_id'] : (string) $request->getAttribute('type.route.params', [])['tenant'];
        return $this->response(200, $this->enrollments->listing($this->identity($request, $realm, $tenantId), $realm, $tenantId, $data['page'], $data['per_page']));
    }

    /** 按资格查看真实绑定；客户路径不能读取另一客户的历史绑定或内部注册码摘要。 */
    #[Route('/admin/device-authorizations/{authorization}', name: 'admin.enrollment', middleware: ['admin.auth'], constraints: ['authorization' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/device-authorizations/{authorization}', name: 'customer.enrollment', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'authorization' => '[a-f0-9]{32}'])]
    public function detail(string $authorization, ServerRequestInterface $request): ResponseInterface
    {
        $realm = $request->getAttribute('type.route') === 'admin.enrollment' ? 'admin' : 'customer';
        $tenantId = $realm === 'admin' ? '' : (string) $request->getAttribute('type.route.params', [])['tenant'];
        $fields = ['page' => Field::integer()->cast()->range(1, 100000)->from('query'), 'per_page' => Field::integer()->cast()->range(1, 100)->from('query')];
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'enrollment_query_invalid');
        }
        $data = \_vali($fields, $input) + ['page' => 1, 'per_page' => 20];
        return $this->response(200, ['data' => $this->enrollments->detail($this->identity($request, $realm, $tenantId), $realm, $tenantId, $authorization, $data['page'], $data['per_page'])]);
    }

    /** 一次性注册码响应禁止缓存；请求 ID 可用于确认丢失响应，重试不重复暴露秘密。 */
    #[Route('/admin/device-authorizations', methods: ['POST'], name: 'admin.enrollment-issue', middleware: ['admin.auth'])]
    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request, 'admin');
        $data = $this->payload($request, [
            'id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'),
            'tenant_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'),
            'name' => Field::text()->required()->trim()->length(1, 100),
            'capacity' => Field::integer()->required()->range(1, 2147483646),
            'register_expires_at' => Field::integer()->required()->range(1, 253402300799),
        ]);
        $result = $this->enrollments->issue($identity, $data);
        return $this->response($result['replayed'] ? 200 : 201, ['data' => $result]);
    }

    /** 操作需要准确的授权、客户及表单版本；不能重置已消耗数量或更换客户。 */
    #[Route('/admin/device-authorizations/{authorization}/{action}', methods: ['POST'], name: 'admin.enrollment-change', middleware: ['admin.auth'], constraints: ['authorization' => '[a-f0-9]{32}', 'action' => 'disable|reset'])]
    public function change(string $authorization, string $action, ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request, 'admin');
        $data = $this->payload($request, ['tenant_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'), 'version' => Field::integer()->required()->range(1, 2147483646)]);
        return $this->response(200, ['data' => $this->enrollments->change($identity, $data['tenant_id'], $authorization, $data['version'], $action)]);
    }

    /** 请求不能携带伪造身份上下文；客户路径与所选租户头必须精确一致。 */
    private function identity(ServerRequestInterface $request, string $realm, string $tenantId = ''): Identity
    {
        if ($request->hasHeader('X-Support-Id') || $request->hasHeader('X-Impersonation-Id') || $request->hasHeader('X-Identity-Realm')
            || ($realm === 'admin' && $request->hasHeader('X-Tenant-Id'))) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        if ($realm === 'customer' && (count($request->getHeader('X-Tenant-Id')) !== 1 || $request->getHeaderLine('X-Tenant-Id') !== $tenantId)) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    /** @param array<string, Field> $fields 严格输入白名单；未知字段不参与授权或持久化。 */
    private function payload(ServerRequestInterface $request, array $fields): array
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
