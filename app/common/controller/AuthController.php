<?php

declare(strict_types=1);

namespace app\common\controller;

use app\common\bootstrap\ApplicationContext;
use app\common\bootstrap\Settings;
use app\common\middleware\TenantContext;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use app\platform\service\TenantService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;

/**
 * 双身份域认证及登录前公开配置；客户入口先解析 Tenant 再验证账号。
 */
final class AuthController
{
    /**
     * 保存响应工厂和应用上下文。公开配置从启动设置读取，构造不登录。
     */
    public function __construct(private Factory $messages, private ApplicationContext $context) {}

    /**
     * 主域名只公开启用客户标识和展示名，不提供人员或内部配置。
     */
    #[Route('/public/tenants', name: 'public.tenants')]
    public function tenants(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getAttribute('app.tenant_bound', false)) {
            throw new HttpError(404, 'not_found');
        }
        $query = $request->getQueryParams();
        if (array_diff(array_keys($query), ['search', 'page', 'per_page']) !== []) {
            throw new HttpError(422, 'tenant_query_invalid');
        }
        $data = \_vali([
            'search.default' => '',
            'search.length:0,100' => '搜索长度不正确',
            'page.default' => 1,
            'page.integer' => '页码不正确',
            'page.between:1,100000' => '页码超出范围',
            'per_page.default' => 20,
            'per_page.integer' => '每页数量不正确',
            'per_page.between:1,100' => '每页数量超出范围',
        ], 'query');
        return $this->response(TenantService::publicListing($data));
    }

    /**
     * 配置白名单先于 Vben 挂载；子域名及固定路径只加载自身客户。
     */
    #[Route('/public/context', name: 'public.context')]
    #[Route('/platform/public/context', name: 'platform.public-context')]
    public function publicContext(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = (string)$request->getAttribute('app.tenant_id', '');
        $platform = $request->getAttribute('type.route') === 'platform.public-context';
        $tenant = $tenantId === '' ? null : TenantService::publicSummary($tenantId);
        return $this->response(['data' => ['realm' => $platform ? 'platform' : 'system', 'tenant' => $tenant,
            'bound' => $tenant !== null, 'site' => SiteSettings::publicView($tenant !== null ? 'system' : ($platform ? 'platform' : 'system-default')),
            'environment' => Settings::environment($this->context->settings(), $this->context->development)]]);
    }

    /**
     * 登录字段只有账号与口令；Tenant 来自绑定入口，平台与系统不能混用凭据。
     */
    #[Route('/platform/auth/login', methods: ['POST'], name: 'platform.login', middleware: ['body.json'])]
    #[Route('/system/auth/login', methods: ['POST'], name: 'system.login', middleware: ['body.json'])]
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        if ($realm === 'system') {
            TenantContext::id($request);
        }
        $input = \_vali([
            'login.required' => '账号不能为空',
            'login.length:3,100' => '账号长度不正确',
            'login.matches:/^[a-z0-9][a-z0-9_.@-]{2,99}$/D' => '账号格式不正确',
            'password.required' => '口令不能为空',
            'password.length:1,72' => '口令长度不正确',
        ], 'body');
        return $this->response(['data' => (new IdentityService($realm))->login($input['login'], $input['password'], (string)$request->getAttribute('app.request_id', ''))]);
    }

    /**
     * 当前角色与菜单每次重新解析，不在会话中缓存授权。
     */
    #[Route('/platform/auth/me', name: 'platform.me', middleware: ['platform.auth'])]
    #[Route('/system/auth/me', name: 'system.me', middleware: ['system.auth'])]
    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $identity = $this->identity($request);
        $tenantId = $realm === 'system' ? TenantContext::id($request) : '';
        $permissions = RoleService::permissions($identity, $realm, $tenantId);
        return $this->response(['data' => ['user' => (new IdentityService($realm))->user($identity->subject()),
            'permissions' => $permissions, 'menus' => RoleService::menus($realm, $permissions),
            'identity' => IdentityService::context($identity, $tenantId)]]);
    }

    /**
     * 只退出当前会话；平台会话撤销同时使其模拟来源失效。
     */
    #[Route('/platform/auth/logout', methods: ['POST'], name: 'platform.logout', middleware: ['platform.auth'])]
    #[Route('/system/auth/logout', methods: ['POST'], name: 'system.logout', middleware: ['system.auth'])]
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $token = substr($request->getHeaderLine('Authorization'), 7);
        (new IdentityService($this->realm($request)))->logout($this->identity($request), $token);
        return $this->response(['data' => ['logged_out' => true]]);
    }

    /**
     * 本人资料不依赖业务角色，原密码校验及模拟身份限制由身份服务统一执行。
     */
    #[Route('/platform/profile', methods: ['PATCH'], name: 'platform.profile.update', middleware: ['platform.auth'])]
    #[Route('/system/profile', methods: ['PATCH'], name: 'system.profile.update', middleware: ['system.auth'])]
    #[Route('/platform/profile/password', methods: ['POST'], name: 'platform.profile.password', middleware: ['platform.auth'])]
    #[Route('/system/profile/password', methods: ['POST'], name: 'system.profile.password', middleware: ['system.auth'])]
    public function profile(ServerRequestInterface $request): ResponseInterface
    {
        $password = str_ends_with((string)$request->getAttribute('type.route'), '.password');
        $rules = [
            ..._version(),
            'current_password.required' => '当前口令不能为空',
            'current_password.length:1,72' => '当前口令长度不正确',
        ];
        $rules += $password ? [
            'password.required' => '新口令不能为空',
            'password.length:5,72' => '新口令长度不正确',
        ] : [
            'login.required' => '账号不能为空',
            'login.matches:/^[a-z0-9][a-z0-9_.@-]{2,99}$/D' => '账号格式不正确',
            'name.required' => '姓名不能为空',
            'name.trim' => '姓名需要去除空白',
            'name.length:1,100' => '姓名长度不正确',
        ];
        return $this->response(['data' => (new IdentityService($this->realm($request)))->changeSelf(
            $this->identity($request),
            substr($request->getHeaderLine('Authorization'), 7),
            $password ? 'password' : 'update',
            \_vali($rules, 'body')
        )]);
    }

    private function realm(ServerRequestInterface $request): string
    {
        return str_starts_with((string)$request->getAttribute('type.route'), 'platform.') ? 'platform' : 'system';
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
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
