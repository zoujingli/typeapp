<?php

declare(strict_types=1);

namespace TypeApp\RoutingExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Resource;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\Message\Factory;

/** 用资源与分组 Attribute 展示编译期路由，处理动作只回显匹配信息。 */
#[Group(prefix: '/api', namePrefix: 'api.', middleware: ['group'])]
#[Resource(path: '/books', name: 'books', constraints: ['id' => '[0-9]+'])]
final class BooksController
{
    private string $greeting;

    /** 保存构造注入的问候值，供生成工厂与请求隔离验收。 */
    public function __construct(string $greeting)
    {
        $this->greeting = $greeting;
    }

    /** 回显列表动作和路由信息，不查询真实书籍数据。 */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'index');
    }
    /** 回显新建表单动作，验证静态路径优先于参数路径。 */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'create');
    }
    /** 回显资源创建动作，验证 POST 的生成映射。 */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'store');
    }
    /** 回显单条资源动作及经过约束的路径 ID。 */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'show');
    }
    /** 回显编辑动作，验证嵌套路径与参数匹配。 */
    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'edit');
    }
    /** 回显资源更新动作，验证生成的写入方法映射。 */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'update');
    }
    /** 回显删除动作，不对任何实际业务数据执行删除。 */
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'destroy');
    }

    /** 回显额外查询路由，验证路由级中间件与分组组合。 */
    #[Route(path: '/lookup/{term}', methods: ['GET'], name: 'lookup', middleware: ['route'])]
    public function lookup(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'lookup');
    }

    private function reply(ServerRequestInterface $request, string $action): ResponseInterface
    {
        $messages = new Factory();
        return $messages->createResponse()->withHeader('Content-Type', 'application/json')
            ->withBody($messages->createStream((string) json_encode([
                'greeting' => $this->greeting, 'action' => $action, 'route' => $request->getAttribute('type.route'),
                'parameters' => $request->getAttribute('type.route.params'), 'trace' => $request->getAttribute('trace', ''),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
