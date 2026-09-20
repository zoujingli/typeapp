<?php

declare(strict_types=1);

namespace app\common\controller;

use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use app\iot\service\TenantService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;

/** 双端固定认证入口；账号域由编译路由决定，身份只来自各域的会话认证。 */
final class AuthController
{
    /** 只保存受管连接入口，不在路由构造时建立数据库连接。 */
    public function __construct(private DatabaseManager $database, private Factory $messages)
    {
    }

    /** 口令只接受有界 JSON 字段，令牌仅在成功响应中返回。 */
    #[Route('/admin/auth/login', methods: ['POST'], name: 'admin.login')]
    #[Route('/customer/auth/login', methods: ['POST'], name: 'customer.login')]
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 16384), 16384, 12);
        if (array_diff(array_keys($input->source('body')), ['login', 'password']) !== []) {
            throw new HttpError(422, 'identity_input_invalid');
        }
        $data = \_vali([
            'login' => Field::text()->required()->length(3, 100)->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D'),
            'password' => Field::text()->required()->length(1, 72),
        ], $input);
        return $this->response((new IdentityService($realm))->login($data['login'], $data['password'], (string) $request->getAttribute('app.request_id', '')));
    }

    /** 登录页只读取公开品牌和主题白名单，不需要也不接受任何身份凭据。 */
    #[Route('/public/site', name: 'public.site')]
    public function publicSite(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(SiteSettings::publicView($this->connection($request)));
    }

    /** 每次读取当前角色及菜单；客户只选择本人有效成员所在租户。 */
    #[Route('/admin/auth/me', name: 'admin.me', middleware: ['admin.auth'])]
    #[Route('/customer/auth/me', name: 'customer.me', middleware: ['customer.auth'])]
    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        $tenantId = $request->getHeaderLine('X-Tenant-Id');
        $tenants = [];
        $selected = [];
        if ($realm === 'customer') {
            $tenants = $connection->query('SELECT t.id, t.name, t.version FROM iot_tenants t JOIN customer_members m ON m.tenant_id = t.id WHERE m.user_id = ? AND m.enabled = 1 AND m.recovery_verified = 1 AND t.enabled = 1 ORDER BY t.id LIMIT 100', [$identity->subject()]);
            if ($tenantId === '' && $tenants !== []) {
                $tenantId = (string) $tenants[0]['id'];
            }
            if ($tenantId !== '') {
                $selection = $connection->query('SELECT t.id, t.name, t.version FROM iot_tenants t JOIN customer_members m ON m.tenant_id = t.id WHERE t.id = ? AND m.user_id = ? AND m.enabled = 1 AND m.recovery_verified = 1 AND t.enabled = 1', [$tenantId, $identity->subject()]);
                if ($selection === []) {
                    throw new HttpError(403, 'identity_scope_forbidden');
                }
                $selected = $selection[0];
            }
        }
        $permissions = RoleService::permissions($identity, $realm, $tenantId);
        return $this->response(['user' => (new IdentityService($realm))->user($identity->subject()),
            'permissions' => $permissions, 'menus' => RoleService::menus($realm, $permissions),
            'catalog' => RoleService::catalog($realm), 'tenants' => $tenants, 'tenant_id' => $tenantId, 'tenant' => $selected === [] ? null : $selected,
            'identity' => IdentityService::context($identity, $tenantId)]);
    }

    /** 本人工作区目录使用服务端分页；账号有效即可选择成员关系，不隐含任何业务节点。 */
    #[Route('/customer/tenants', name: 'customer.tenants', middleware: ['customer.auth'])]
    public function tenants(ServerRequestInterface $request): ResponseInterface
    {
        $this->realm($request);
        if ($request->hasHeader('X-Tenant-Id')) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), ['page', 'per_page', 'search']) !== []) {
            throw new HttpError(422, 'tenant_query_invalid');
        }
        $filters = \_vali([
            'page' => Field::integer()->cast()->range(1, 100000)->defaultValue(1)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->defaultValue(20)->from('query'),
            'search' => Field::text()->length(0, 100)->defaultValue('')->from('query'),
        ], $input);
        return $this->response((new TenantService())->tenants($this->identity($request), $filters + ['enabled' => 1], false));
    }

    /** 当前租户成员与角色列表共用固定筛选，详情仍按当前权限和归属查询。 */
    #[Route('/customer/members', name: 'customer.members.read', middleware: ['customer.auth'])]
    #[Route('/customer/members/{id}', name: 'customer.members.detail', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/roles', name: 'customer.roles.read', middleware: ['customer.auth'])]
    #[Route('/customer/roles/{id}', name: 'customer.roles.detail', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function directory(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), ['page', 'per_page', 'search', 'enabled']) !== []) {
            throw new HttpError(422, 'member_query_invalid');
        }
        $filters = \_vali([
            'page' => Field::integer()->cast()->range(1, 100000)->defaultValue(1)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->defaultValue(20)->from('query'),
            'search' => Field::text()->length(0, 100)->defaultValue('')->from('query'),
            'enabled' => Field::integer()->cast()->oneOf([-1, 0, 1])->defaultValue(-1)->from('query'),
        ], $input);
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        $parameters = $request->getAttribute('type.route.params', []);
        return $this->response(str_starts_with((string) $request->getAttribute('type.route'), 'customer.roles.')
            ? RoleService::directory($identity, 'roles', (string) ($parameters['id'] ?? ''), $filters, 'customer', $tenantId)
            : (new TenantService())->members($identity, $tenantId, (string) ($parameters['id'] ?? ''), $filters));
    }

    /** 成员和角色只接受固定动作字段；写入共用授权事务，不接受全局身份变更。 */
    #[Route('/customer/members', methods: ['POST'], name: 'customer.members.create', middleware: ['customer.auth'])]
    #[Route('/customer/members/{id}', methods: ['PATCH'], name: 'customer.members.update', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/members/{id}/status', methods: ['POST'], name: 'customer.members.status', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/members/{id}', methods: ['DELETE'], name: 'customer.members.delete', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/members/roles', methods: ['PUT'], name: 'customer.roles.assign', middleware: ['customer.auth'])]
    #[Route('/customer/roles', methods: ['POST'], name: 'customer.roles.create', middleware: ['customer.auth'])]
    #[Route('/customer/roles/{id}/copy', methods: ['POST'], name: 'customer.roles.copy', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/roles/{id}', methods: ['PATCH'], name: 'customer.roles.update', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/roles/{id}/status', methods: ['POST'], name: 'customer.roles.status', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/roles/{id}', methods: ['DELETE'], name: 'customer.roles.delete', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customer/roles/{id}/permissions', methods: ['PUT'], name: 'customer.roles.permissions', middleware: ['customer.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function change(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $action = (string) $request->getAttribute('type.route', '');
        $version = ['version' => Field::integer()->required()->range(1, 2147483646)];
        $name = ['name' => Field::text()->required()->length(1, 100)];
        $binding = Field::object(new Schema(['id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D')] + $version));
        $roles = ['roles' => Field::listOf($binding)->required()->length(0, 64)];
        $grants = ['permissions' => Field::listOf(Field::text()->oneOf(array_keys(RoleService::catalog('customer'))))->required()->length(0, 128)];
        $fields = match ($action) {
            'customer.members.create' => $name + $roles + ['login' => Field::text()->required()->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D'),
                'new_customer' => Field::boolean()->required(), 'account_name' => Field::text()->length(1, 100), 'password' => Field::text()->length(12, 72)],
            'customer.members.update', 'customer.roles.update', 'customer.roles.copy' => $version + $name,
            'customer.members.status', 'customer.roles.status' => $version + ['enabled' => Field::boolean()->required()],
            'customer.members.delete', 'customer.roles.delete' => $version,
            'customer.roles.create' => $name + $grants,
            'customer.roles.permissions' => $version + $grants,
            'customer.roles.assign' => $roles + ['members' => Field::listOf($binding)->required()->length(1, 100)],
            default => throw new HttpError(404, 'not_found'),
        };
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 32768), 32768, 12);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'member_input_invalid');
        }
        $data = \_vali($fields, $input);
        $parameters = $request->getAttribute('type.route.params', []);
        return $this->response(RoleService::change($this->identity($request), substr($request->getHeaderLine('Authorization'), 7), $action, (string) ($parameters['id'] ?? ''), $data, 'customer', $tenantId));
    }

    /** 返回受权限保护的个人工作区，直接访问同样检查当前角色。 */
    #[Route('/admin/profile', name: 'admin.profile', middleware: ['admin.auth'])]
    #[Route('/customer/profile', name: 'customer.profile', middleware: ['customer.auth'])]
    public function profile(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        if (!in_array('identity.read', RoleService::permissions($identity, $realm, $request->getHeaderLine('X-Tenant-Id')), true)) {
            throw new HttpError(403, 'permission_denied');
        }
        return $this->response(['user' => (new IdentityService($realm))->user($identity->subject())]);
    }

    /** 本人账号维护与租户业务授权分开；拒绝目标 ID、租户和模拟来源字段。 */
    #[Route('/customer/account', methods: ['PATCH'], name: 'customer.account.update', middleware: ['customer.auth'])]
    #[Route('/customer/account/password', methods: ['POST'], name: 'customer.account.password', middleware: ['customer.auth'])]
    public function account(ServerRequestInterface $request): ResponseInterface
    {
        $this->realm($request);
        if ($request->hasHeader('X-Tenant-Id')) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $action = $request->getAttribute('type.route') === 'customer.account.password' ? 'password' : 'update';
        $fields = ['version' => Field::integer()->required()->range(1, 2147483646), 'current_password' => Field::text()->required()->length(1, 72)]
            + ($action === 'password' ? ['password' => Field::text()->required()->length(12, 72)]
                : ['login' => Field::text()->required()->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D'), 'name' => Field::text()->required()->length(1, 100)]);
        $input = Input::json(RequestBody::read($request->getBody(), 16384), 16384, 12);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'identity_input_invalid');
        }
        $data = \_vali($fields, $input);
        return $this->response((new IdentityService('customer'))->changeSelf($this->identity($request), substr($request->getHeaderLine('Authorization'), 7), $action, $data));
    }

    /** 无角色账号仍可主动退出；只撤销请求携带的本域当前令牌。 */
    #[Route('/admin/auth/logout', methods: ['POST'], name: 'admin.logout', middleware: ['admin.auth'])]
    #[Route('/customer/auth/logout', methods: ['POST'], name: 'customer.logout', middleware: ['customer.auth'])]
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        (new IdentityService($this->realm($request)))->logout($this->identity($request), substr($request->getHeaderLine('Authorization'), 7), (string) $request->getAttribute('app.request_id', ''));
        return $this->response(['logged_out' => true]);
    }

    private function realm(ServerRequestInterface $request): string
    {
        if ($request->hasHeader('X-Support-Id') || $request->hasHeader('X-Impersonation-Id') || $request->hasHeader('X-Identity-Realm')) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        $route = (string) $request->getAttribute('type.route', '');
        if (str_starts_with($route, 'admin.')) {
            if ($request->hasHeader('X-Tenant-Id')) {
                throw new HttpError(403, 'identity_scope_forbidden');
            }
            return 'admin';
        }
        if (str_starts_with($route, 'customer.')) {
            return 'customer';
        }
        throw new HttpError(404, 'not_found');
    }

    private function tenant(ServerRequestInterface $request): string
    {
        $this->realm($request);
        $tenantId = $request->getHeaderLine('X-Tenant-Id');
        if (preg_match('/^[a-f0-9]{32}$/D', $tenantId) !== 1) {
            throw new HttpError(403, 'identity_scope_forbidden');
        }
        return $tenantId;
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('人员接口需要受管请求作用域');
        }
        $connection = \Type\Orm\Db::connection('default', true);
        $installation = $connection->table('app_installation')->where('id', '=', 1)->first();
        if ($installation === null || (int) $installation['schema_version'] !== 1) {
            throw new HttpError(503, 'installation_incomplete');
        }
        return $connection;
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
