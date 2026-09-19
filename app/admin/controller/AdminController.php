<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\bootstrap\Settings;
use app\common\service\AuditLog;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use app\iot\service\TenantService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Type\Core\Http\Attribute\Group;
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

/** 平台人员和角色的固定 HTTP 边界；客户端不能选择账号域或提交额外授权字段。 */
#[Group(prefix: '/admin', namePrefix: 'admin.', middleware: ['admin.auth'])]
final class AdminController
{
    /** 复用现有数据库管理器、响应工厂和启动根，构造不建立连接。 */
    public function __construct(private DatabaseManager $database, private Factory $messages, private string $basePath)
    {
    }

    /** 查询权限按实际目录分别检查，详情也从同一明确投影返回。 */
    #[Route('/users', name: 'users.read')]
    #[Route('/users/{id}', name: 'users.detail', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customers', name: 'customers.read')]
    #[Route('/customers/{id}', name: 'customers.detail', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/roles', name: 'roles.read')]
    #[Route('/roles/{id}', name: 'roles.detail', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants', name: 'tenants.read')]
    #[Route('/tenants/{id}', name: 'tenants.detail', constraints: ['id' => '[a-f0-9]{32}'])]
    public function read(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $route = (string) $request->getAttribute('type.route', '');
        $kind = match ($route) {
            'admin.users.read', 'admin.users.detail' => 'users',
            'admin.customers.read', 'admin.customers.detail' => 'customers',
            'admin.roles.read', 'admin.roles.detail' => 'roles',
            'admin.tenants.read', 'admin.tenants.detail' => 'tenants',
            default => throw new HttpError(404, 'not_found'),
        };
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), ['page', 'per_page', 'search', 'enabled']) !== []) {
            throw new HttpError(422, 'admin_query_invalid');
        }
        $filters = \_vali([
            'page' => Field::integer()->cast()->range(1, 100000)->defaultValue(1)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->defaultValue(20)->from('query'),
            'search' => Field::text()->length(0, 100)->defaultValue('')->from('query'),
            'enabled' => Field::integer()->cast()->oneOf([-1, 0, 1])->defaultValue(-1)->from('query'),
        ], $input);
        $parameters = $request->getAttribute('type.route.params', []);
        if ($kind === 'tenants') {
            return $this->response((new TenantService())->tenants($this->connection($request), $identity, $filters, true, (string) ($parameters['id'] ?? '')));
        }
        return $this->response(RoleService::directory($this->connection($request), $identity, $kind, (string) ($parameters['id'] ?? ''), $filters));
    }

    /** 返回脱敏后的启动配置目录及当前权限；配置值来源和版本来自同一 .env 快照。 */
    #[Route('/configuration', name: 'configuration.read')]
    public function configuration(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $context = RoleService::readContext($this->connection($request), $identity, 'admin', '', 'config.read');
        try {
            $view = Settings::configurationView($this->basePath);
        } catch (InvalidArgumentException|RuntimeException) {
            throw new HttpError(503, 'configuration_invalid');
        }
        return $this->response($view + ['permissions' => $context['permissions'], 'catalog' => RoleService::catalog('admin'), 'menus' => RoleService::menus('admin', $context['permissions'])]);
    }

    /** 读取站点业务设置；与运行时配置分开，避免把品牌信息误当作进程配置。 */
    #[Route('/site', name: 'site.read')]
    public function site(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $connection = $this->connection($request);
        $context = RoleService::readContext($connection, $identity, 'admin', '', 'site.read');
        return $this->response(SiteSettings::adminView($connection) + ['permissions' => $context['permissions'], 'catalog' => RoleService::catalog('admin'), 'menus' => RoleService::menus('admin', $context['permissions'])]);
    }

    /** 站点设置采用固定字段、版本号和授权事务，失败时不覆盖上一份品牌配置。 */
    #[Route('/site', methods: ['PUT'], name: 'site.manage')]
    public function updateSite(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 16384), 16384, 8);
        $body = $input->source('body');
        $changes = $body['changes'] ?? null;
        if ($changes instanceof \stdClass) {
            $changes = get_object_vars($changes);
        }
        if (!is_array($body) || array_diff(array_keys($body), ['version', 'changes']) !== []
            || !is_int($body['version'] ?? null) || !is_array($changes)) {
            throw new HttpError(422, 'site_settings_input_invalid');
        }
        $token = substr($request->getHeaderLine('Authorization'), 7);
        $result = RoleService::authorized($this->connection($request), $identity, $token, 'admin.site.manage', function (Connection $transaction, Identity $current, array $permissions) use ($body, $changes): array {
            $result = SiteSettings::update($transaction, (int) $body['version'], $changes);
            AuditLog::append($transaction, null, $current, 'admin.site.update', 'site_settings', 'success', [
                'reason' => implode(',', $result['changed']), 'version' => $result['version'],
            ], 'admin');
            return $result + ['permissions' => $permissions, 'catalog' => RoleService::catalog('admin'), 'menus' => RoleService::menus('admin', $permissions)];
        }, 'admin', 'platform');
        return $this->response($result);
    }

    /** 配置写入复用现有授权锁、文件锁和原子替换；成功后仅报告待重启，不伪装当前进程已变更。 */
    #[Route('/configuration', methods: ['PUT'], name: 'configuration.manage')]
    public function updateConfiguration(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 32768), 32768, 12);
        $body = $input->source('body');
        $changes = $body['changes'] ?? null;
        if ($changes instanceof \stdClass) {
            $changes = get_object_vars($changes);
        }
        if (!is_array($body) || array_diff(array_keys($body), ['version', 'changes']) !== []
            || !is_string($body['version'] ?? null) || !is_array($changes)) {
            throw new HttpError(422, 'configuration_input_invalid');
        }
        $token = substr($request->getHeaderLine('Authorization'), 7);
        $basePath = $this->basePath;
        try {
            $result = RoleService::authorized($this->connection($request), $identity, $token, 'admin.config.manage', function (Connection $transaction, Identity $current, array $permissions) use ($body, $changes, $basePath): array {
                $result = Settings::configurationUpdate($basePath, (string) $body['version'], $changes);
                AuditLog::append($transaction, null, $current, 'admin.configuration.update', 'configuration', 'success', [
                    'reason' => implode(',', $result['changed']), 'version' => $result['version'],
                ], 'admin');
                return $result;
            }, 'admin', 'platform');
        } catch (InvalidArgumentException $error) {
            throw new HttpError(422, $error->getMessage());
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'config_version_conflict') {
                throw new HttpError(409, 'stale_version');
            }
            throw new HttpError(503, 'configuration_write_failed');
        }
        return $this->response($result);
    }

    /** 各动作明确对应固定节点；角色或账号敏感写入在服务的同一事务内重验。 */
    #[Route('/users', methods: ['POST'], name: 'users.create')]
    #[Route('/users/{id}', methods: ['PATCH'], name: 'users.update', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/users/{id}/status', methods: ['POST'], name: 'users.status', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/users/{id}/password', methods: ['POST'], name: 'users.password', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/users/{id}/sessions', methods: ['DELETE'], name: 'users.sessions', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/users/roles', methods: ['PUT'], name: 'roles.assign')]
    #[Route('/customers', methods: ['POST'], name: 'customers.create')]
    #[Route('/customers/{id}', methods: ['PATCH'], name: 'customers.update', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customers/{id}/status', methods: ['POST'], name: 'customers.status', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customers/{id}/password', methods: ['POST'], name: 'customers.password', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customers/{id}/sessions', methods: ['DELETE'], name: 'customers.sessions', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/customers/{id}/impersonate', methods: ['POST'], name: 'customers.impersonate', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/roles', methods: ['POST'], name: 'roles.create')]
    #[Route('/roles/{id}/copy', methods: ['POST'], name: 'roles.copy', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/roles/{id}', methods: ['PATCH'], name: 'roles.update', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/roles/{id}/status', methods: ['POST'], name: 'roles.status', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/roles/{id}', methods: ['DELETE'], name: 'roles.delete', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/roles/{id}/permissions', methods: ['PUT'], name: 'roles.permissions', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants', methods: ['POST'], name: 'tenants.create')]
    #[Route('/tenants/{id}', methods: ['PATCH'], name: 'tenants.update', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants/{id}/status', methods: ['POST'], name: 'tenants.status', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants/{id}/administrators', methods: ['POST'], name: 'tenants.administrators.add', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants/{id}/administrators', methods: ['PUT'], name: 'tenants.administrators.replace', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants/{id}/administrators/{member_id}', methods: ['DELETE'], name: 'tenants.administrators.remove', constraints: ['id' => '[a-f0-9]{32}', 'member_id' => '[a-f0-9]{32}'])]
    public function write(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $action = (string) $request->getAttribute('type.route', '');
        $version = ['version' => Field::integer()->required()->range(1, 2147483646)];
        $name = ['name' => Field::text()->required()->length(1, 100)];
        $login = ['login' => Field::text()->required()->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D')];
        $password = ['password' => Field::text()->required()->length(12, 72)];
        $grants = ['permissions' => Field::listOf(Field::text()->oneOf(array_keys(RoleService::catalog('admin'))))->required()->length(0, 128)];
        $binding = Field::object(new Schema(['id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D')] + $version));
        $fields = match ($action) {
            'admin.users.create', 'admin.customers.create' => $login + $name + $password,
            'admin.users.update', 'admin.customers.update' => $version + $login + $name,
            'admin.users.password', 'admin.customers.password' => $version + $password,
            'admin.users.status', 'admin.customers.status', 'admin.roles.status', 'admin.tenants.status' => $version + ['enabled' => Field::boolean()->required()],
            'admin.users.sessions', 'admin.customers.sessions', 'admin.customers.impersonate', 'admin.roles.delete' => $version,
            'admin.roles.create' => $name + $grants,
            'admin.roles.copy', 'admin.roles.update' => $version + $name,
            'admin.tenants.update' => $version + $name,
            'admin.tenants.create' => $name + ['id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'),
                'owner_login' => Field::text()->required()->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D'),
                'new_customer' => Field::boolean()->required(),
                'owner_name' => Field::text()->length(1, 100), 'owner_password' => Field::text()->length(12, 72)],
            'admin.tenants.administrators.add' => $version + $login + ['new_customer' => Field::boolean()->required(),
                'owner_name' => Field::text()->length(1, 100), 'owner_password' => Field::text()->length(12, 72)],
            'admin.tenants.administrators.replace' => $version + $login + ['new_customer' => Field::boolean()->required(),
                'owner_name' => Field::text()->length(1, 100), 'owner_password' => Field::text()->length(12, 72),
                'replace_member_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'), 'replace_member_version' => Field::integer()->cast()->required()->range(1, 2147483646)],
            'admin.tenants.administrators.remove' => $version + ['member_version' => Field::integer()->cast()->required()->range(1, 2147483646)],
            'admin.roles.permissions' => $version + $grants,
            'admin.roles.assign' => ['users' => Field::listOf($binding)->required()->length(1, 100), 'roles' => Field::listOf($binding)->required()->length(0, 64)],
            default => throw new HttpError(404, 'not_found'),
        };
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 32768), 32768, 12);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'admin_input_invalid');
        }
        $data = \_vali($fields, $input);
        $parameters = $request->getAttribute('type.route.params', []);
        if ($action === 'admin.tenants.administrators.remove') {
            $data['member_id'] = (string) ($parameters['member_id'] ?? '');
        }
        return $this->response(RoleService::change($this->connection($request), $identity, substr($request->getHeaderLine('Authorization'), 7), $action, (string) ($parameters['id'] ?? ''), $data));
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        foreach (['X-Tenant-Id', 'X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
            if ($request->hasHeader($header)) {
                throw new HttpError(403, 'identity_context_invalid');
            }
        }
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity || !in_array('realm:admin', $identity->roles(), true)) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('平台管理接口需要受管请求作用域');
        }
        return $this->database->connect($scope);
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
