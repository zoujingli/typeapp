<?php

declare(strict_types=1);

namespace app\common\controller;

use app\common\input\InputFields;
use app\common\service\RoleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;

/** 两身份域复用相同的人员及角色协议，入口固定域、动作和字段白名单。 */
final class IdentityController
{
    public function __construct(private Factory $messages) {}

    #[Route('/platform/users', name: 'platform.users.read', middleware: ['platform.auth'])]
    #[Route('/platform/users/{id}', name: 'platform.users.detail', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/platform/roles', name: 'platform.roles.read', middleware: ['platform.auth'])]
    #[Route('/platform/roles/{id}', name: 'platform.roles.detail', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/users', name: 'system.users.read', middleware: ['system.auth'])]
    #[Route('/system/users/{id}', name: 'system.users.detail', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/system/roles', name: 'system.roles.read', middleware: ['system.auth'])]
    #[Route('/system/roles/{id}', name: 'system.roles.detail', middleware: ['system.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function read(ServerRequestInterface $request): ResponseInterface
    {
        $route = explode('.', (string)$request->getAttribute('type.route'));
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        InputFields::only($input, 'query', ['search', 'enabled', 'page', 'per_page'], 'identity_query_invalid');
        $filters = \_vali(['search' => Field::text()->length(0, 100)->defaultValue('')->from('query'),
            'enabled' => Field::integer()->cast()->range(-1, 1)->defaultValue(-1)->from('query'),
            'page' => Field::integer()->cast()->range(1, 100000)->defaultValue(1)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->defaultValue(20)->from('query')], $input);
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
    public function write(ServerRequestInterface $request): ResponseInterface
    {
        $action = (string)$request->getAttribute('type.route');
        $route = explode('.', $action);
        $kind = $route[1];
        $operation = $route[2];
        $version = ['version' => Field::integer()->required()->range(1, 2147483646)];
        $name = ['name' => Field::text()->required()->trim()->length(1, 100)];
        $login = ['login' => Field::text()->required()->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D')];
        $password = ['password' => Field::text()->required()->length(5, 72)];
        $menus = ['menu_ids' => Field::listOf(Field::text()->matches('/^[a-f0-9]{32}$/D'))->required()->length(0, 512)];
        $binding = Field::object(new Schema(['id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D')] + $version));
        $fields = match ($kind . '.' . $operation) {
            'users.create' => $login + $name + $password,
            'users.update' => $version + $login + $name,
            'users.password' => $version + $password,
            'users.status', 'roles.status' => $version + ['enabled' => Field::boolean()->required()],
            'users.sessions', 'users.delete', 'roles.delete' => $version,
            'roles.create' => $name + $menus,
            'roles.copy', 'roles.update' => $version + $name,
            'roles.permissions' => $version + $menus,
            'roles.assign' => ['users' => Field::listOf($binding)->required()->length(1, 100), 'roles' => Field::listOf($binding)->required()->length(0, 64)],
            default => throw new HttpError(404, 'not_found'),
        };
        $identity = $this->identity($request);
        $scope = $route[0] === 'system' ? (string)$identity->attributes()['tenant_id'] : 'platform';
        return $this->response(['data' => RoleService::change(
            $identity,
            substr($request->getHeaderLine('Authorization'), 7),
            $action,
            $request->getAttribute('type.route.params', [])['id'] ?? '',
            InputFields::body($request, $fields),
            $route[0],
            $scope
        )]);
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
