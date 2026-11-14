<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\platform\service\TenantService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;

/**
 * 客户目录、开通与系统超管管理，平台身份不能直接变成客户身份。
 */
#[Group(prefix: '/platform', namePrefix: 'platform.tenants.', middleware: ['platform.auth'])]
final class TenantController
{
    /**
     * 保存响应工厂。构造不读取客户目录。
     */
    public function __construct(private Factory $messages) {}

    #[Route('/tenants', name: 'read')]
    #[Route('/tenants/{id}', name: 'detail', constraints: ['id' => '[a-f0-9]{32}'])]
    /**
     * 列出客户或读取一个客户。查询键只接受搜索、启用状态和分页。
     */
    public function listing(ServerRequestInterface $request): ResponseInterface
    {
        $this->onlyQuery($request, ['search', 'enabled', 'page', 'per_page'], 'tenant_query_invalid');
        $filters = \_vali([
            'search.default' => '',
            'search.length:0,100' => '搜索长度不正确',
            'enabled.default' => -1,
            'enabled.integer' => '状态不正确',
            'enabled.between:-1,1' => '状态超出范围',
            'page.default' => 1,
            'page.integer' => '页码不正确',
            'page.between:1,100000' => '页码超出范围',
            'per_page.default' => 20,
            'per_page.integer' => '每页数量不正确',
            'per_page.between:1,100' => '每页数量超出范围',
        ], 'query');
        return $this->response(TenantService::listing($this->identity($request), $filters, $request->getAttribute('type.route.params', [])['id'] ?? ''));
    }

    #[Route('/tenants', methods: ['POST'], name: 'create')]
    /**
     * 开通客户并创建系统超管。子域名可以留空。
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $data = \_vali([
            'request_id.required' => '请求标识不能为空',
            'request_id.matches:/^[a-f0-9]{32}$/D' => '请求标识不正确',
            'name.required' => '名称不能为空',
            'name.trim' => '名称需要去除空白',
            'name.length:1,100' => '名称长度不正确',
            'subdomain.nullable' => '子域名可以为空',
            'subdomain.default' => null,
            'subdomain.length:1,63' => '子域名长度不正确',
            'login.required' => '账号不能为空',
            'login.matches:/^[a-z0-9][a-z0-9_.@-]{2,99}$/D' => '账号格式不正确',
            'owner_name.required' => '超管姓名不能为空',
            'owner_name.trim' => '超管姓名需要去除空白',
            'owner_name.length:1,100' => '超管姓名长度不正确',
        ], 'body');
        return $this->response(['data' => TenantService::create($this->identity($request), $data)]);
    }

    #[Route('/tenants/{id}', methods: ['PATCH'], name: 'update', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants/{id}/status', methods: ['POST'], name: 'status', constraints: ['id' => '[a-f0-9]{32}'])]
    /**
     * 修改客户资料或启用状态。两条路由都要求当前版本。
     */
    public function change(string $id, ServerRequestInterface $request): ResponseInterface
    {
        $status = $request->getAttribute('type.route') === 'platform.tenants.status';
        $rules = [
            ..._version(),
        ];
        $rules += $status ? [
            'enabled.required' => '启用状态不能为空',
            'enabled.boolean' => '启用状态不正确',
        ] : [
            'name.required' => '名称不能为空',
            'name.trim' => '名称需要去除空白',
            'name.length:1,100' => '名称长度不正确',
            'subdomain.required' => '子域名不能为空',
            'subdomain.nullable' => '子域名可以为空',
            'subdomain.length:1,63' => '子域名长度不正确',
        ];
        return $this->response(['data' => TenantService::change($this->identity($request), $id, $status ? 'status' : 'update', \_vali($rules, 'body'))]);
    }

    #[Route('/tenants/{id}/administrators', methods: ['POST'], name: 'administrators.add', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants/{id}/administrators/{user}/{action}', methods: ['POST'], name: 'administrators.change', constraints: ['id' => '[a-f0-9]{32}', 'user' => '[a-f0-9]{32}', 'action' => 'password|replace|remove|status'])]
    /**
     * 增加、替换、停用或移除客户超管。动作由路径决定，正文只带该动作需要的字段。
     */
    public function administrator(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute('type.route.params', []);
        $action = $params['action'] ?? 'add';
        $rules = [
            ..._version(),
        ];
        if ($action !== 'add') {
            $rules += [
                ..._version('user_version', 1, '账号版本'),
            ];
        }
        if ($action === 'add' || $action === 'replace') {
            $rules += [
                'login.required' => '账号不能为空',
                'login.matches:/^[a-z0-9][a-z0-9_.@-]{2,99}$/D' => '账号格式不正确',
                'name.required' => '姓名不能为空',
                'name.trim' => '姓名需要去除空白',
                'name.length:1,100' => '姓名长度不正确',
            ];
        }
        if ($action === 'status') {
            $rules += [
                'enabled.required' => '启用状态不能为空',
                'enabled.boolean' => '启用状态不正确',
            ];
        }
        return $this->response(['data' => TenantService::administrator($this->identity($request), $params['id'], $action, $params['user'] ?? '', \_vali($rules, 'body'))]);
    }

    #[Route('/tenants/{id}/administrators', name: 'administrators.read', constraints: ['id' => '[a-f0-9]{32}'])]
    /**
     * 分页读取一个客户的超管。查询键只有页码和每页数量。
     */
    public function administrators(string $id, ServerRequestInterface $request): ResponseInterface
    {
        $this->onlyQuery($request, ['page', 'per_page'], 'tenant_query_invalid');
        $data = \_vali([
            'page.default' => 1,
            'page.integer' => '页码不正确',
            'page.between:1,100000' => '页码超出范围',
            'per_page.default' => 20,
            'per_page.integer' => '每页数量不正确',
            'per_page.between:1,100' => '每页数量超出范围',
        ], 'query');
        return $this->response(TenantService::administratorDirectory($this->identity($request), $id, $data['page'], $data['per_page']));
    }

    /**
     * @param list<string> $allowed
     */
    private function onlyQuery(ServerRequestInterface $request, array $allowed, string $code): void
    {
        if (array_diff(array_keys($request->getQueryParams()), $allowed) !== []) {
            throw new HttpError(422, $code);
        }
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity || ($identity->attributes()['realm'] ?? '') !== 'platform') {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
