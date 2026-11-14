<?php

declare(strict_types=1);

namespace app\center\controller;

use app\center\service\ProductService;
use app\common\input\InputFields;
use app\common\middleware\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Validate\Field;
use Type\Validate\Input;

/** 客户设备分类的 HTTP 输入；范围、引用保护与审计事务由服务负责。 */
#[Group(prefix: '/center', namePrefix: 'center.')]
final class ProductController
{
    /** 复用请求资源和产品服务，不在构造时连接数据库。 */
    public function __construct(private Factory $messages, private ProductService $products) {}

    /** 产品列表只读当前租户，并返回本次授权事实供页面及时更新动作。 */
    #[Route('/products', name: 'products', middleware: ['system.auth'])]
    public function products(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = TenantContext::id($request);
        $input = $this->query($request);
        return $this->response(200, $this->products->products($this->identity($request), $tenantId, $input['page'], $input['per_page'], $input['name']));
    }

    /** 获授权人员在当前租户创建分类，不建立物模型或接入权限。 */
    #[Route('/products', methods: ['POST'], name: 'product-create', middleware: ['system.auth', 'body.json'])]
    public function createProduct(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = TenantContext::id($request);
        $data = $this->body($request, 'create');
        return $this->response(201, ['data' => $this->products->create($this->identity($request), $tenantId, $data['name'], $data['description'] ?? '')]);
    }

    /** 类型化租户与产品路径仍需与认证头匹配，业务授权由原 Service 执行。 */
    #[Route('/products/{product}', methods: ['GET'], name: 'product', middleware: ['system.auth'], constraints: ['product' => '[a-f0-9]{32}'])]
    public function product(string $product, ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(200, ['data' => $this->products->product($this->identity($request), TenantContext::id($request), $product)]);
    }

    /** PATCH 只写提供字段；明确 null 会被输入规则拒绝，省略字段保持数据库当前值。 */
    #[Route('/products/{product}', methods: ['PATCH'], name: 'product-update', middleware: ['system.auth', 'body.json'], constraints: ['product' => '[a-f0-9]{32}'])]
    public function updateProduct(string $product, ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->body($request, 'patch');
        return $this->response(200, ['data' => $this->products->change($this->identity($request), TenantContext::id($request), $product, $data['version'], $data)]);
    }

    /** 删除仍检查显式版本，不能绕过模型历史与设备引用。 */
    #[Route('/products/{product}', methods: ['DELETE'], name: 'product-delete', middleware: ['system.auth', 'body.json'], constraints: ['product' => '[a-f0-9]{32}'])]
    public function deleteProduct(string $product, ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->body($request, 'delete');
        return $this->response(200, ['data' => $this->products->change($this->identity($request), TenantContext::id($request), $product, $data['version'], null)]);
    }

    /** @return array{page:int,per_page:int,name:string} */
    private function query(ServerRequestInterface $request): array
    {
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        InputFields::only($input, 'query', ['page', 'per_page', 'name'], 'product_query_invalid');
        return \_vali(['page' => Field::integer()->from('query')->cast()->range(1, 100000)->defaultValue(1),
            'per_page' => Field::integer()->from('query')->cast()->range(1, 100)->defaultValue(20),
            'name' => Field::text()->from('query')->length(0, 100)->defaultValue('')], $input);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request, string $scenario): array
    {
        $input = Input::json(RequestBody::read($request->getBody(), 32768), 32768, 12);
        $allowed = match ($scenario) {
            'create' => ['name', 'description'],
            'patch' => ['version', 'name', 'description'],
            'delete' => ['version'],
            default => throw new HttpError(500, 'internal_error'),
        };
        InputFields::only($input, 'body', $allowed, 'unexpected_field');
        $fields = ['version' => Field::integer()->required()->range(1, 2147483646)->inScenarios(['patch', 'delete']),
            'name' => Field::text()->required()->trim()->length(1, 100)->inScenarios(['create', 'patch']),
            'description' => Field::text()->length(0, 1000)->inScenarios(['create', 'patch'])];
        $data = \_vali($fields, $input, $scenario, $scenario === 'patch');
        if (($scenario === 'patch' || $scenario === 'delete') && !array_key_exists('version', $data)) {
            throw new \Type\Validate\ValidationException(['version' => ['required']]);
        }
        return $data;
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        if ($request->hasHeader('X-Support-Id') || $request->hasHeader('X-Impersonation-Id') || $request->hasHeader('X-Identity-Realm')) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
