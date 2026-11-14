<?php

declare(strict_types=1);

namespace app\common\controller;

use app\common\service\RoleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Validate\ValidationException;

/**
 * 两身份域复用相同的人员及角色协议，入口固定域、动作和字段白名单。
 */
final class IdentityController
{
    /**
     * 保存响应工厂。域和动作由路由固定，构造不查询身份。
     */
    public function __construct(private Factory $messages) {}

    #[Route('/platform/users', name: 'platform.users.read', middleware: ['platform.auth'])]
    #[Route('/platform/users/{id}', name: 'platform.users.detail', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/roles', name: 'platform.roles.read', middleware: ['platform.auth'])]
    #[Route('/platform/roles/{id}', name: 'platform.roles.detail', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/users', name: 'system.users.read', middleware: ['system.auth'])]
    #[Route('/system/users/{id}', name: 'system.users.detail', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/roles', name: 'system.roles.read', middleware: ['system.auth'])]
    #[Route('/system/roles/{id}', name: 'system.roles.detail', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    /**
     * 按路由域列出或读取用户和角色。查询键只接受搜索、状态和分页。
     */
    public function read(ServerRequestInterface $request): ResponseInterface
    {
        $route = explode('.', (string)$request->getAttribute('type.route'));
        if (array_diff(array_keys($request->getQueryParams()), ['search', 'enabled', 'page', 'per_page']) !== []) {
            throw new HttpError(422, 'identity_query_invalid');
        }
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
        return $this->response(RoleService::directory(
            $this->identity($request),
            $route[1],
            $request->getAttribute('type.route.params', [])['id'] ?? '',
            $filters,
            $route[0]
        ));
    }

    #[Route('/platform/users', methods: ['POST'], name: 'platform.users.create', middleware: ['platform.auth'])]
    #[Route('/platform/users/{id}', methods: ['PATCH'], name: 'platform.users.update', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/users/{id}/status', methods: ['POST'], name: 'platform.users.status', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/users/{id}/password', methods: ['POST'], name: 'platform.users.password', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/users/{id}/sessions', methods: ['DELETE'], name: 'platform.users.sessions', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/roles/assign', methods: ['PUT'], name: 'platform.roles.assign', middleware: ['platform.auth'])]
    #[Route('/platform/roles', methods: ['POST'], name: 'platform.roles.create', middleware: ['platform.auth'])]
    #[Route('/platform/roles/{id}/copy', methods: ['POST'], name: 'platform.roles.copy', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/roles/{id}', methods: ['PATCH'], name: 'platform.roles.update', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/roles/{id}/status', methods: ['POST'], name: 'platform.roles.status', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/roles/{id}', methods: ['DELETE'], name: 'platform.roles.delete', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/roles/{id}/permissions', methods: ['PUT'], name: 'platform.roles.permissions', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/users', methods: ['POST'], name: 'system.users.create', middleware: ['system.auth'])]
    #[Route('/system/users/{id}', methods: ['PATCH'], name: 'system.users.update', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/users/{id}/status', methods: ['POST'], name: 'system.users.status', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/users/{id}/password', methods: ['POST'], name: 'system.users.password', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/users/{id}/sessions', methods: ['DELETE'], name: 'system.users.sessions', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/roles/assign', methods: ['PUT'], name: 'system.roles.assign', middleware: ['system.auth'])]
    #[Route('/system/roles', methods: ['POST'], name: 'system.roles.create', middleware: ['system.auth'])]
    #[Route('/system/roles/{id}/copy', methods: ['POST'], name: 'system.roles.copy', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/roles/{id}', methods: ['PATCH'], name: 'system.roles.update', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/roles/{id}/status', methods: ['POST'], name: 'system.roles.status', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/roles/{id}', methods: ['DELETE'], name: 'system.roles.delete', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/roles/{id}/permissions', methods: ['PUT'], name: 'system.roles.permissions', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/users/{id}', methods: ['DELETE'], name: 'system.users.delete', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    /**
     * 按路由动作创建、修改、授权或删除用户和角色。域由入口固定。
     */
    public function write(ServerRequestInterface $request): ResponseInterface
    {
        $action = (string)$request->getAttribute('type.route');
        $route = explode('.', $action);
        $kind = $route[1];
        $operation = $route[2];
        $version = [
            ..._version(),
        ];
        $name = [
            'name.required' => '名称不能为空',
            'name.trim' => '名称需要去除空白',
            'name.length:1,100' => '名称长度不正确',
        ];
        $login = [
            'login.required' => '账号不能为空',
            'login.matches:/^[a-z0-9][a-z0-9_.@-]{2,99}$/D' => '账号格式不正确',
        ];
        $password = [
            'password.required' => '口令不能为空',
            'password.length:5,72' => '口令长度不正确',
        ];
        $menus = [
            'menu_ids.required' => '菜单不能为空',
            'menu_ids.list' => '菜单必须是列表',
            'menu_ids.length:0,512' => '菜单数量超出范围',
            'menu_ids.matches:/^[a-f0-9]{32}$/D' => '菜单标识不正确',
        ];
        $rules = match ($kind . '.' . $operation) {
            'users.create' => $login + $name + $password,
            'users.update' => $version + $login + $name,
            'users.password' => $version + $password,
            'users.status', 'roles.status' => $version + [
                'enabled.required' => '启用状态不能为空',
                'enabled.boolean' => '启用状态不正确',
            ],
            'users.sessions', 'users.delete', 'roles.delete' => $version,
            'roles.create' => $name + $menus,
            'roles.copy', 'roles.update' => $version + $name,
            'roles.permissions' => $version + $menus,
            'roles.assign' => [],
            default => throw new HttpError(404, 'not_found'),
        };
        $identity = $this->identity($request);
        $scope = $route[0] === 'system' ? (string)$identity->attributes()['tenant_id'] : 'platform';
        $input = $kind . '.' . $operation === 'roles.assign' ? $this->assignments() : \_vali($rules, 'body');
        return $this->response(['data' => RoleService::change(
            $identity,
            substr($request->getHeaderLine('Authorization'), 7),
            $action,
            $request->getAttribute('type.route.params', [])['id'] ?? '',
            $input,
            $route[0],
            $scope
        )]);
    }

    /**
     * @return array{users:list<array{id:string,version:int}>,roles:list<array{id:string,version:int}>}
     */
    private function assignments(): array
    {
        $body = \_input('body');
        if (array_diff(array_keys($body), ['users', 'roles']) !== []) {
            throw new HttpError(422, 'unexpected_field');
        }
        $rules = [
            'id.required' => '标识不能为空',
            'id.matches:/^[a-f0-9]{32}$/D' => '标识不正确',
            ..._version(),
        ];
        return [
            'users' => $this->bindings('users', $body['users'] ?? null, array_key_exists('users', $body), $rules, 1, 100),
            'roles' => $this->bindings('roles', $body['roles'] ?? null, array_key_exists('roles', $body), $rules, 0, 64),
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return list<array<string, mixed>>
     */
    private function bindings(string $name, mixed $rows, bool $provided, array $rules, int $minimum, int $maximum): array
    {
        if (!$provided) {
            throw new ValidationException([$name => ['required']]);
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new ValidationException([$name => ['type_list']]);
        }
        if (count($rows) < $minimum || count($rows) > $maximum) {
            throw new ValidationException([$name => ['length']]);
        }
        $bindings = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ValidationException([$name => ['type_object']]);
            }
            $bindings[] = \_vali($rules, $row);
        }
        return $bindings;
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
