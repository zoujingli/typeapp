<?php

declare(strict_types=1);

namespace TypeApp\RoutingExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Resource;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\Message\Factory;
use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Schema;
use Type\Validate\ValidatedInput;

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

    /** 直接接收严格转换后的整数路径参数并返回业务数组。 */
    #[Route(path: '/books/typed/{id}', methods: ['GET'], name: 'typed.show', constraints: ['id' => '[0-9]+'])]
    public function typedShow(int $id): array
    {
        return ['action' => 'typed-show', 'id' => $id, 'greeting' => $this->greeting];
    }

    /** 返回有正文的固定 201，适合创建类接口。 */
    #[Route(path: '/books/typed', methods: ['POST'], name: 'typed.store', status: 201)]
    public function typedStore(): array
    {
        return ['action' => 'typed-store', 'greeting' => $this->greeting];
    }

    /** 直接接收字符串路径参数，保持原始解码后的值。 */
    #[Route(path: '/books/search/{term}', methods: ['GET'], name: 'typed.search', constraints: ['term' => '[^/]+'])]
    public function typedSearch(string $term): array
    {
        return ['action' => 'typed-search', 'term' => $term, 'greeting' => $this->greeting];
    }

    /** 无返回值动作统一转换为 204。 */
    #[Route(path: '/books/typed/{id}', methods: ['DELETE'], name: 'typed.destroy', constraints: ['id' => '[0-9]+'])]
    public function typedDestroy(int $id): void
    {
        // 示例不执行持久化删除，只验证无正文动作的 HTTP 契约。
    }

    /** GET 只校验查询来源，不要求 JSON 正文；传输与授权仍由中间件控制。 */
    #[Route(path: '/inputs', methods: ['GET'], name: 'inputs.search', input: ['maxQueryBytes' => 128, 'maxQueryFields' => 3])]
    public function searchInput(SearchInput $input): array
    {
        return $input->data->toArray();
    }

    /** 创建输入的默认值只作用于缺失字段，非法输入不会进入动作。 */
    #[Route(path: '/inputs', methods: ['POST'], name: 'inputs.create', status: 201, input: ['maxBytes' => 128, 'maxDepth' => 4])]
    public function createInput(BookInput $input): array
    {
        return $input->data->toArray();
    }

    /** PATCH 输入保留字段存在性；没有提供的可选字段不会获得默认值。 */
    #[Route(path: '/inputs/{id}', methods: ['PATCH'], name: 'inputs.patch', input: ['maxBytes' => 128, 'maxDepth' => 4])]
    public function patchInput(int $id, BookInput $input): array
    {
        return ['id' => $id, 'hasNote' => $input->data->has('note'), 'values' => $input->data->toArray()];
    }

    /** 同名输入分别来自路径、查询和头部，列表头保留原始值边界。 */
    #[Route(path: '/inputs/{id}/sources', methods: ['GET'], name: 'inputs.sources')]
    public function sourceInput(SourceInput $input, ServerRequestInterface $request): array
    {
        return ['values' => $input->data->toArray(), 'trace' => $request->getAttribute('trace', '')];
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

/** 仅查询条件的输入；Data 持有有效字段，未知查询键不会对外输出。 */
final class SearchInput implements ValidatedInput
{
    /** 保留有效查询字段供示例动作使用。 */
    public function __construct(public Data $data)
    {
    }

    /** query 不参与 body 或 route 合并。 */
    public static function schema(): Schema
    {
        return new Schema(['page' => Field::integer()->from('query')->cast()->range(1, 100)->defaultValue(1)]);
    }

    /** 显式工厂的返回类型与动作参数一致。 */
    public static function fromData(Data $data): SearchInput
    {
        return new SearchInput($data);
    }
}

/** 创建与部分更新共用规则，Data::has() 区分缺失和显式 null。 */
final class BookInput implements ValidatedInput
{
    /** 保存有效字段；对象只属于当前请求。 */
    public function __construct(public Data $data)
    {
    }

    /** 必填、可空及默认值继续使用现有 Field 规则。 */
    public static function schema(): Schema
    {
        return new Schema([
            'name' => Field::text()->required()->trim()->length(2, 40),
            'note' => Field::text()->nullable(),
            'active' => Field::boolean()->defaultValue(true),
            'reference' => Field::text(),
        ]);
    }

    /** 保留 Data 的有效存在性，避免把缺失字段转换成 null。 */
    public static function fromData(Data $data): BookInput
    {
        return new BookInput($data);
    }
}

/** 分源输入示例，Header 采用协议规定的大小写无关名称。 */
final class SourceInput implements ValidatedInput
{
    /** 保留分源校验后的结果。 */
    public function __construct(public Data $data)
    {
    }

    /** 路径身份不能被同名 query 覆盖；标量 header 不接受多个值。 */
    public static function schema(): Schema
    {
        return new Schema([
            'id' => Field::integer()->from('route')->required()->cast(),
            'queryId' => Field::integer()->from('query', 'id')->cast(),
            'origin' => Field::text()->from('header', 'X-Origin')->required(),
            'tags' => Field::listOf(Field::text())->from('header', 'X-Tag'),
        ]);
    }

    /** 不依赖请求、连接或运行时类型猜测。 */
    public static function fromData(Data $data): SourceInput
    {
        return new SourceInput($data);
    }
}
