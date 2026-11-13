<?php

declare(strict_types=1);

namespace app\common\controller;

use app\common\bootstrap\ApplicationContext;
use app\common\bootstrap\Settings;
use app\common\input\InputFields;
use app\common\input\LoginInput;
use app\common\middleware\TenantContext;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use app\platform\model\Tenant;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Validate\Field;
use Type\Validate\Input;

/** 双身份域认证及登录前公开配置；客户入口先解析 Tenant 再验证账号。 */
final class AuthController
{
    public function __construct(private Factory $messages, private ApplicationContext $context) {}

    /** 主域名只公开启用客户标识和展示名，不提供人员或内部配置。 */
    #[Route('/public/tenants', name: 'public.tenants')]
    public function tenants(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getAttribute('app.tenant_bound', false)) {
            throw new HttpError(404, 'not_found');
        }
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), ['search', 'page', 'per_page']) !== []) {
            throw new HttpError(422, 'tenant_query_invalid');
        }
        $data = \_vali(['search' => Field::text()->length(0, 100)->defaultValue('')->from('query'),
            'page' => Field::integer()->cast()->range(1, 100000)->defaultValue(1)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->defaultValue(20)->from('query')], $input);
        $query = Tenant::query()->master()->where('enabled', '=', true);
        if ($data['search'] !== '') {
            $query = $query->where('name', 'LIKE', '%' . $data['search'] . '%');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate($data['page'], $data['per_page']);
        $items = [];
        foreach ($page->items() as $tenant) {
            $items[] = $tenant->project(['id', 'name']);
        }
        return $this->response(['items' => $items, 'total' => $page->total(), 'page' => $page->number(), 'per_page' => $page->perPage()]);
    }

    /** 配置白名单先于 Vben 挂载；子域名及固定路径只加载自身客户。 */
    #[Route('/public/context', name: 'public.context')]
    #[Route('/platform/public/context', name: 'platform.public-context')]
    public function publicContext(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = (string)$request->getAttribute('app.tenant_id', '');
        $platform = $request->getAttribute('type.route') === 'platform.public-context';
        $tenant = $tenantId === '' ? null : Tenant::query()->master()->findOrFail($tenantId)->project(['id', 'name']);
        return $this->response(['data' => ['realm' => $platform ? 'platform' : 'system', 'tenant' => $tenant,
            'bound' => $tenant !== null, 'site' => SiteSettings::publicView($tenant !== null ? 'system' : ($platform ? 'platform' : 'system-default')),
            'environment' => Settings::environment($this->context->settings(), $this->context->development)]]);
    }

    /** 登录字段只有账号与口令；Tenant 来自绑定入口，平台与系统不能混用凭据。 */
    #[Route('/platform/auth/login', methods: ['POST'], name: 'platform.login', middleware: ['body.json'], input: ['maxBytes' => 16384, 'maxDepth' => 12])]
    #[Route('/system/auth/login', methods: ['POST'], name: 'system.login', middleware: ['body.json'], input: ['maxBytes' => 16384, 'maxDepth' => 12])]
    public function login(LoginInput $input, ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        if ($realm === 'system') {
            TenantContext::id($request);
        }
        return $this->response(['data' => (new IdentityService($realm))->login($input->login, $input->password, (string)$request->getAttribute('app.request_id', ''))]);
    }

    /** 当前角色与菜单每次重新解析，不在会话中缓存授权。 */
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

    /** 只退出当前会话；平台会话撤销同时使其模拟来源失效。 */
    #[Route('/platform/auth/logout', methods: ['POST'], name: 'platform.logout', middleware: ['platform.auth'])]
    #[Route('/system/auth/logout', methods: ['POST'], name: 'system.logout', middleware: ['system.auth'])]
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $token = substr($request->getHeaderLine('Authorization'), 7);
        (new IdentityService($this->realm($request)))->logout($this->identity($request), $token);
        return $this->response(['data' => ['logged_out' => true]]);
    }

    /** 本人资料不依赖业务角色，原密码校验及模拟身份限制由身份服务统一执行。 */
    #[Route('/platform/profile', methods: ['PATCH'], name: 'platform.profile.update', middleware: ['platform.auth'])]
    #[Route('/system/profile', methods: ['PATCH'], name: 'system.profile.update', middleware: ['system.auth'])]
    #[Route('/platform/profile/password', methods: ['POST'], name: 'platform.profile.password', middleware: ['platform.auth'])]
    #[Route('/system/profile/password', methods: ['POST'], name: 'system.profile.password', middleware: ['system.auth'])]
    public function profile(ServerRequestInterface $request): ResponseInterface
    {
        $password = str_ends_with((string)$request->getAttribute('type.route'), '.password');
        $fields = ['version' => Field::integer()->required()->range(1, 2147483646),
            'current_password' => Field::text()->required()->length(1, 72)];
        $fields += $password ? ['password' => Field::text()->required()->length(5, 72)]
            : ['login' => Field::text()->required()->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D'), 'name' => Field::text()->required()->trim()->length(1, 100)];
        return $this->response(['data' => (new IdentityService($this->realm($request)))->changeSelf(
            $this->identity($request),
            substr($request->getHeaderLine('Authorization'), 7),
            $password ? 'password' : 'update',
            InputFields::body($request, $fields)
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
