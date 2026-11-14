<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\service\MenuService;
use app\common\service\RoleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;

/**
 * 平台维护两套目录，系统只读取本域角色可授予节点。
 */
final class MenuController
{
    /**
     * 保存响应工厂。构造不读取目录，也不建立连接。
     */
    public function __construct(private Factory $messages) {}

    #[Route('/platform/menus/{realm}', name: 'platform.menus.read', middleware: ['platform.auth'], constraints: ['realm' => 'platform|system'])]
    #[Route('/system/menus', name: 'system.menus.read', middleware: ['system.auth'])]
    #[Route('/platform/role-menus', name: 'platform.role-menus.read', middleware: ['platform.auth'])]
    /**
     * 平台按路径中的域读取目录；系统和角色菜单只读取当前身份可授予的节点。
     */
    public function read(ServerRequestInterface $request): ResponseInterface
    {
        $management = $request->getAttribute('type.route') === 'platform.menus.read';
        $realm = $management ? $request->getAttribute('type.route.params', [])['realm'] : (string)$this->identity($request)->attributes()['realm'];
        return $this->response(MenuService::listing($this->identity($request), $realm, $management));
    }

    /**
     * 创建页面与操作节点只能选择构建期登记的能力。
     */
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
    /**
     * 创建和修改要求版本与节点字段；删除只提交版本。
     */
    public function write(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute('type.route.params', []);
        $create = $request->getAttribute('type.route') === 'platform.menus.create';
        $delete = $request->getMethod() === 'DELETE';
        $rules = _version('version', $create ? 0 : 1);
        if (!$delete) {
            $rules += [
                'parent_id.required' => '上级不能为空',
                'parent_id.nullable' => '上级可以为空',
                'parent_id.matches:/^[a-f0-9]{32}$/D' => '上级标识不正确',
                'name.required' => '名称不能为空',
                'name.trim' => '名称需要去除空白',
                'name.length:1,100' => '名称长度不正确',
                'icon.required' => '图标不能为空',
                'icon.length:0,100' => '图标长度不正确',
                'order_number.required' => '排序不能为空',
                'order_number.integer' => '排序不正确',
                'order_number.between:-100000,100000' => '排序超出范围',
                'enabled.required' => '启用状态不能为空',
                'enabled.boolean' => '启用状态不正确',
                'hidden.required' => '隐藏状态不能为空',
                'hidden.boolean' => '隐藏状态不正确',
            ];
            if ($create) {
                $rules += [
                    'kind.required' => '类型不能为空',
                    'kind.in:directory,page,action' => '类型不正确',
                    'permission.nullable' => '权限可以为空',
                    'permission.default' => null,
                    'permission.length:1,120' => '权限长度不正确',
                ];
            }
        }
        return $this->response(['data' => MenuService::save($this->identity($request), $params['realm'], $params['id'] ?? '', \_vali($rules, 'body'), $delete)]);
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
