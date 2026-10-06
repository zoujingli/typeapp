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

/** 客户产品与物模型的HTTP输入；身份、授权事务和模型规则由既有服务拥有。 */
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

    /** 获授权人员在当前租户创建产品，模型通过独立版本入口建立。 */
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

    /** 版本列表保留各自定义；新增总是创建新的永久编号。 */
    #[Route('/tenants/{tenant}/products/{product}/models', methods: ['GET', 'POST'], name: 'models', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'product' => '[a-f0-9]{32}'])]
    public function models(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $identity = $this->identity($request);
        if ($request->getMethod() === 'GET') {
            $query = $this->query($request, []);
            return $this->response(200, $this->products->models($identity, $tenantId, $parameters['product'], $query['page'], $query['per_page']));
        }
        $data = $this->payload($request, ['definition' => (new Field('object'))->required()]);
        return $this->response(201, ['data' => $this->products->createModel($identity, $tenantId, $parameters['product'], $data['definition'])]);
    }

    /** 草稿按乐观锁编辑，已发布版本不可通过任何编辑或删除路径修改。 */
    #[Route('/tenants/{tenant}/products/{product}/models/{model}', methods: ['GET', 'PATCH', 'DELETE'], name: 'model', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'product' => '[a-f0-9]{32}', 'model' => '[1-9][0-9]{0,9}'])]
    public function model(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $identity = $this->identity($request);
        $number = (int) $parameters['model'];
        if ($request->getMethod() === 'GET') {
            return $this->response(200, ['data' => $this->products->model($identity, $tenantId, $parameters['product'], $number)]);
        }
        $fields = ['version' => Field::integer()->required()->range(1, 2147483646)];
        if ($request->getMethod() === 'PATCH') {
            $fields['definition'] = (new Field('object'))->required();
        }
        $data = $this->payload($request, $fields);
        return $this->response(200, ['data' => $this->products->changeModel($identity, $tenantId, $parameters['product'], $number, $data['version'], $request->getMethod() === 'DELETE' ? 'delete' : 'edit', $data['definition'] ?? null)]);
    }

    /** 发布冻结该版本内容；重复或过时页面不能再次改写发布时间。 */
    #[Route('/tenants/{tenant}/products/{product}/models/{model}/publish', methods: ['POST'], name: 'model-publish', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'product' => '[a-f0-9]{32}', 'model' => '[1-9][0-9]{0,9}'])]
    public function publishModel(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $data = $this->payload($request, ['version' => Field::integer()->required()->range(1, 2147483646)]);
        return $this->response(200, ['data' => $this->products->changeModel($this->identity($request), $tenantId, $parameters['product'], (int) $parameters['model'], $data['version'], 'publish')]);
    }

    /** 已发布模型调试只验证参数，不代表平台接收或设备执行。 */
    #[Route('/tenants/{tenant}/products/{product}/models/{model}/validate', methods: ['POST'], name: 'model-validate', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'product' => '[a-f0-9]{32}', 'model' => '[1-9][0-9]{0,9}'])]
    public function validateModel(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $data = $this->payload($request, ['kind' => Field::text()->required()->oneOf(['properties', 'event', 'command']), 'identifier' => Field::text()->length(1, 64), 'values' => (new Field('object'))->required()]);
        return $this->response(200, ['data' => $this->products->validate($this->identity($request), $tenantId, $parameters['product'], (int) $parameters['model'], $data['kind'], $data['identifier'] ?? '', $data['values'])]);
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

    /** @param array<string, Field> $fields 输入白名单；其他字段不进入业务调用。 */
    private function payload(ServerRequestInterface $request, array $fields): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 16384), 16384, 12);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'unexpected_field');
        }
        return \_vali($fields, $input);
    }

    /** @param array<string, Field> $fields 额外筛选字段；每页最多100条。 */
    private function query(ServerRequestInterface $request, array $fields): array
    {
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), [...array_keys($fields), 'page', 'per_page']) !== []) {
            throw new HttpError(422, 'product_query_invalid');
        }
        $declarations = [];
        foreach ($fields + ['page' => Field::integer()->cast()->range(1, 100000), 'per_page' => Field::integer()->cast()->range(1, 100)] as $name => $field) {
            $declarations[$name] = $field->from('query');
        }
        $data = \_vali($declarations, $input);
        return $data + ['page' => 1, 'per_page' => 20];
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
