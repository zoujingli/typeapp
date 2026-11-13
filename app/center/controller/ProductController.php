<?php

declare(strict_types=1);

namespace app\center\controller;

use app\center\input\ProductSearchInput;
use app\center\input\ProductWriteInput;
use app\center\service\ProductService;
use app\common\middleware\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;

/** 客户设备分类的 HTTP 输入；范围、引用保护与审计事务由服务负责。 */
#[Group(prefix: '/center', namePrefix: 'center.')]
final class ProductController
{
    /** 复用请求资源和产品服务，不在构造时连接数据库。 */
    public function __construct(private Factory $messages, private ProductService $products) {}

    /** 产品列表只读当前租户，并返回本次授权事实供页面及时更新动作。 */
    #[Route('/products', name: 'products', middleware: ['system.auth'])]
    public function products(ProductSearchInput $input, ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = TenantContext::id($request);
        return $this->response(200, $this->products->products($this->identity($request), $tenantId, $input->page, $input->perPage, $input->name));
    }

    /** 获授权人员在当前租户创建分类，不建立物模型或接入权限。 */
    #[Route('/products', methods: ['POST'], name: 'product-create', middleware: ['system.auth', 'body.json'], input: ['scenario' => 'create', 'maxDepth' => 12])]
    public function createProduct(ProductWriteInput $input, ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = TenantContext::id($request);
        $data = $input->data->toArray();
        return $this->response(201, ['data' => $this->products->create($this->identity($request), $tenantId, $data['name'], $data['description'] ?? '')]);
    }

    /** 类型化租户与产品路径仍需与认证头匹配，业务授权由原 Service 执行。 */
    #[Route('/products/{product}', methods: ['GET'], name: 'product', middleware: ['system.auth'], constraints: ['product' => '[a-f0-9]{32}'])]
    public function product(string $product, ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(200, ['data' => $this->products->product($this->identity($request), TenantContext::id($request), $product)]);
    }

    /** PATCH 只写提供字段；明确 null 会被输入规则拒绝，省略字段保持数据库当前值。 */
    #[Route('/products/{product}', methods: ['PATCH'], name: 'product-update', middleware: ['system.auth', 'body.json'], constraints: ['product' => '[a-f0-9]{32}'], input: ['scenario' => 'patch', 'maxDepth' => 12])]
    public function updateProduct(string $product, ProductWriteInput $input, ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(200, ['data' => $this->products->change($this->identity($request), TenantContext::id($request), $product, $input->version(), $input->data->toArray())]);
    }

    /** 删除仍检查显式版本，不能绕过模型历史与设备引用。 */
    #[Route('/products/{product}', methods: ['DELETE'], name: 'product-delete', middleware: ['system.auth', 'body.json'], constraints: ['product' => '[a-f0-9]{32}'], input: ['scenario' => 'delete', 'maxDepth' => 12])]
    public function deleteProduct(string $product, ProductWriteInput $input, ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(200, ['data' => $this->products->change($this->identity($request), TenantContext::id($request), $product, $input->version(), null)]);
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
