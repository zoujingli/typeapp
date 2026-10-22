<?php

declare(strict_types=1);

namespace app\iot\controller;

use app\iot\service\ProductService;
use app\iot\input\ProductWriteInput;
use app\iot\input\ProductSearchInput;
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
#[Group(prefix: '/customer', namePrefix: 'customer.')]
final class ProductController
{
    /** 复用请求资源和产品服务，不在构造时连接数据库。 */
    public function __construct(private Factory $messages, private ProductService $products)
    {
    }

    /** 产品列表只读当前租户，并返回本次授权事实供页面及时更新动作。 */
    #[Route('/tenants/{tenant}/products', name: 'products', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function products(string $tenant, ProductSearchInput $input, ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request, $tenant);
        return $this->response(200, $this->products->products($this->identity($request), $tenantId, $input->page, $input->perPage, $input->name));
    }

    /** 获授权人员在当前租户创建分类，不建立物模型或接入权限。 */
    #[Route('/tenants/{tenant}/products', methods: ['POST'], name: 'product-create', middleware: ['customer.auth', 'body.json'], constraints: ['tenant' => '[a-f0-9]{32}'], input: ['scenario' => 'create', 'maxDepth' => 12])]
    public function createProduct(string $tenant, ProductWriteInput $input, ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request, $tenant);
        $data = $input->data->toArray();
        return $this->response(201, ['data' => $this->products->create($this->identity($request), $tenantId, $data['name'], $data['description'] ?? '')]);
    }

    /** 类型化租户与产品路径仍需与认证头匹配，业务授权由原 Service 执行。 */
    #[Route('/tenants/{tenant}/products/{product}', methods: ['GET'], name: 'product', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'product' => '[a-f0-9]{32}'])]
    public function product(string $tenant, string $product, ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(200, ['data' => $this->products->product($this->identity($request), $this->tenant($request, $tenant), $product)]);
    }

    /** PATCH 只写提供字段；明确 null 会被输入规则拒绝，省略字段保持数据库当前值。 */
    #[Route('/tenants/{tenant}/products/{product}', methods: ['PATCH'], name: 'product-update', middleware: ['customer.auth', 'body.json'], constraints: ['tenant' => '[a-f0-9]{32}', 'product' => '[a-f0-9]{32}'], input: ['scenario' => 'patch', 'maxDepth' => 12])]
    public function updateProduct(string $tenant, string $product, ProductWriteInput $input, ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(200, ['data' => $this->products->change($this->identity($request), $this->tenant($request, $tenant), $product, $input->version(), $input->data->toArray())]);
    }

    /** 删除仍检查显式版本，不能绕过模型历史与设备引用。 */
    #[Route('/tenants/{tenant}/products/{product}', methods: ['DELETE'], name: 'product-delete', middleware: ['customer.auth', 'body.json'], constraints: ['tenant' => '[a-f0-9]{32}', 'product' => '[a-f0-9]{32}'], input: ['scenario' => 'delete', 'maxDepth' => 12])]
    public function deleteProduct(string $tenant, string $product, ProductWriteInput $input, ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(200, ['data' => $this->products->change($this->identity($request), $this->tenant($request, $tenant), $product, $input->version(), null)]);
    }

    /** 只接受一个明确租户头，路由与头必须相同；不将输入映射为资源池名称。 */
    private function tenant(ServerRequestInterface $request, ?string $tenant = null): string
    {
        $headers = $request->getHeader('X-Tenant-Id');
        $parameters = $request->getAttribute('type.route.params', []);
        if (count($headers) !== 1 || !preg_match('/^[a-f0-9]{32}$/D', $headers[0])
            || (($tenant ?? $parameters['tenant'] ?? '') !== $headers[0])) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        return $headers[0];
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
