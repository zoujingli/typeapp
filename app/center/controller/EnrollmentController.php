<?php

declare(strict_types=1);

namespace app\center\controller;

use app\center\service\EnrollmentService;
use app\common\middleware\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Validate\Input;

/**
 * 双端注册资格入口；只有控制端声明写路由，客户身份不能签发或增额。
 */
final class EnrollmentController
{
    /**
     * 构造只接收已有消息工厂和业务所有者，不建立数据库连接。
     */
    public function __construct(private Factory $messages, private EnrollmentService $enrollments) {}

    /**
     * 控制端可按客户筛选，客户端必须使用当前会话的可信租户。
     */
    #[Route('/platform/enrollments', name: 'platform.enrollments', middleware: ['platform.auth'])]
    #[Route('/center/enrollments', name: 'center.enrollments', middleware: ['system.auth'])]
    public function listing(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $request->getAttribute('type.route') === 'platform.enrollments' ? 'platform' : 'system';
        $rules = [
            'page.default' => 1,
            'page.integer' => '页码不正确',
            'page.between:1,100000' => '页码超出范围',
            'per_page.default' => 20,
            'per_page.integer' => '每页数量不正确',
            'per_page.between:1,100' => '每页数量超出范围',
        ];
        if ($realm === 'platform') {
            $rules += [
                'tenant_id.default' => '',
                'tenant_id.matches:/^([a-f0-9]{32})?$/D' => '客户标识不正确',
            ];
        }
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        $allowed = [];
        foreach ($rules as $key => $message) {
            $allowed[explode('.', (string)$key, 2)[0]] = true;
        }
        if (array_diff(array_keys($input->source('query')), array_keys($allowed)) !== []) {
            throw new HttpError(422, 'enrollment_query_invalid');
        }
        $data = \_vali($rules, $input->source('query')) + ['tenant_id' => ''];
        $tenantId = $realm === 'platform' ? $data['tenant_id'] : TenantContext::id($request);
        return $this->response(200, $this->enrollments->listing($this->identity($request, $realm, $tenantId), $realm, $tenantId, $data['page'], $data['per_page']));
    }

    /**
     * 按资格查看真实绑定；客户路径不能读取另一客户的历史绑定或内部注册码摘要。
     */
    #[Route('/platform/enrollments/{authorization}', name: 'platform.enrollment', middleware: ['platform.auth'], constraints: ['authorization' => '[a-f0-9]{32}'])]
    #[Route('/center/enrollments/{authorization}', name: 'center.enrollment', middleware: ['system.auth'], constraints: ['authorization' => '[a-f0-9]{32}'])]
    public function detail(string $authorization, ServerRequestInterface $request): ResponseInterface
    {
        $realm = $request->getAttribute('type.route') === 'platform.enrollment' ? 'platform' : 'system';
        $tenantId = $realm === 'platform' ? '' : TenantContext::id($request);
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), ['page', 'per_page']) !== []) {
            throw new HttpError(422, 'enrollment_query_invalid');
        }
        $data = \_vali([
            'page.default' => 1,
            'page.integer' => '页码不正确',
            'page.between:1,100000' => '页码超出范围',
            'per_page.default' => 20,
            'per_page.integer' => '每页数量不正确',
            'per_page.between:1,100' => '每页数量超出范围',
        ], $input->source('query'));
        return $this->response(200, ['data' => $this->enrollments->detail($this->identity($request, $realm, $tenantId), $realm, $tenantId, $authorization, $data['page'], $data['per_page'])]);
    }

    /**
     * 一次性注册码响应禁止缓存；请求 ID 可用于确认丢失响应，重试不重复暴露秘密。
     */
    #[Route('/platform/enrollments', methods: ['POST'], name: 'platform.enrollment-issue', middleware: ['platform.auth'])]
    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request, 'platform');
        $data = $this->payload($request, [
            'id.required' => '请求标识不能为空',
            'id.matches:/^[a-f0-9]{32}$/D' => '请求标识不正确',
            'tenant_id.required' => '客户不能为空',
            'tenant_id.matches:/^[a-f0-9]{32}$/D' => '客户标识不正确',
            'name.required' => '名称不能为空',
            'name.trim' => '名称需要去除空白',
            'name.length:1,100' => '名称长度不正确',
            'capacity.required' => '名额不能为空',
            'capacity.integer' => '名额不正确',
            'capacity.between:1,2147483646' => '名额超出范围',
            'register_expires_at.required' => '注册期限不能为空',
            'register_expires_at.integer' => '注册期限不正确',
            'register_expires_at.between:1,253402300799' => '注册期限超出范围',
        ]);
        $result = $this->enrollments->issue($identity, $data);
        return $this->response($result['replayed'] ? 200 : 201, ['data' => $result]);
    }

    /**
     * 操作需要准确的授权、客户及表单版本；不能重置已消耗数量或更换客户。
     */
    #[Route('/platform/enrollments/{authorization}/{action}', methods: ['POST'], name: 'platform.enrollment-change', middleware: ['platform.auth'], constraints: ['authorization' => '[a-f0-9]{32}', 'action' => 'disable|reset'])]
    public function change(string $authorization, string $action, ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request, 'platform');
        $data = $this->payload($request, [
            'tenant_id.required' => '客户不能为空',
            'tenant_id.matches:/^[a-f0-9]{32}$/D' => '客户标识不正确',
            ..._version(),
        ]);
        return $this->response(200, ['data' => $this->enrollments->change($identity, $data['tenant_id'], $authorization, $data['version'], $action)]);
    }

    /**
     * 请求不能携带伪造身份上下文；客户路径与所选租户头必须精确一致。
     */
    private function identity(ServerRequestInterface $request, string $realm, string $tenantId = ''): Identity
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

    /**
     * @param array<string, mixed> $rules 字段.规则；未知字段不参与授权或持久化。
     */
    private function payload(ServerRequestInterface $request, array $rules): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 16384), 16384, 12);
        $allowed = [];
        foreach ($rules as $key => $message) {
            $allowed[explode('.', (string)$key, 2)[0]] = true;
        }
        if (array_diff(array_keys($input->source('body')), array_keys($allowed)) !== []) {
            throw new HttpError(422, 'unexpected_field');
        }
        return \_vali($rules, $input->source('body'));
    }

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
