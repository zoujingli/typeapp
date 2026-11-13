<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\input\InputFields;
use app\common\service\MenuService;
use app\common\service\RoleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Validate\Field;

/** 平台维护两套目录，系统只读取本域角色可授予节点。 */
final class MenuController
{
    public function __construct(private Factory $messages) {}

    #[Route('/platform/menus/{realm}', name: 'platform.menus.read', middleware: ['platform.auth'], constraints: ['realm' => 'platform|system'])]
    #[Route('/system/menus', name: 'system.menus.read', middleware: ['system.auth'])]
    #[Route('/platform/role-menus', name: 'platform.role-menus.read', middleware: ['platform.auth'])]
    public function read(ServerRequestInterface $request): ResponseInterface
    {
        $management = $request->getAttribute('type.route') === 'platform.menus.read';
        $realm = $management ? $request->getAttribute('type.route.params', [])['realm'] : (string)$this->identity($request)->attributes()['realm'];
        return $this->response(MenuService::listing($this->identity($request), $realm, $management));
    }

    /** 创建页面与操作节点只能选择构建期登记的能力。 */
    #[Route('/platform/menu-bindings/{realm}', name: 'platform.menu-bindings.read', middleware: ['platform.auth'], constraints: ['realm' => 'platform|system'])]
    public function bindings(string $realm, ServerRequestInterface $request): ResponseInterface
    {
        RoleService::readContext($this->identity($request), 'platform', '', 'menus.read');
        $items = [];
        foreach (MenuService::definitions() as $definition) {
            if ($definition['realm'] === $realm) {
                $items[] = $definition;
            }
        }
        return $this->response(['items' => $items]);
    }

    #[Route('/platform/menus/{realm}', methods: ['POST'], name: 'platform.menus.create', middleware: ['platform.auth'], constraints: ['realm' => 'platform|system'])]
    #[Route('/platform/menus/{realm}/{id}', methods: ['PUT'], name: 'platform.menus.update', middleware: ['platform.auth'], constraints: ['realm' => 'platform|system', 'id' => '[a-f0-9]{32}'])]
    #[Route('/platform/menus/{realm}/{id}', methods: ['DELETE'], name: 'platform.menus.delete', middleware: ['platform.auth'], constraints: ['realm' => 'platform|system', 'id' => '[a-f0-9]{32}'])]
    public function write(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute('type.route.params', []);
        $create = $request->getAttribute('type.route') === 'platform.menus.create';
        $delete = $request->getMethod() === 'DELETE';
        $fields = ['version' => Field::integer()->required()->range($create ? 0 : 1, 2147483646)];
        if (!$delete) {
            $fields += ['parent_id' => Field::text()->nullable()->required()->matches('/^[a-f0-9]{32}$/D'),
                'name' => Field::text()->required()->trim()->length(1, 100), 'icon' => Field::text()->required()->length(0, 100),
                'order_number' => Field::integer()->required()->range(-100000, 100000),
                'enabled' => Field::boolean()->required(), 'hidden' => Field::boolean()->required()];
            if ($create) {
                $fields += ['kind' => Field::text()->required()->oneOf(['directory', 'page', 'action']),
                    'permission' => Field::text()->nullable()->defaultValue(null)->length(1, 120)];
            }
        }
        return $this->response(['data' => MenuService::save($this->identity($request), $params['realm'], $params['id'] ?? '', InputFields::body($request, $fields), $delete)]);
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
