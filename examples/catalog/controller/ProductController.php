<?php

declare(strict_types=1);

namespace app\catalog\controller;

use app\catalog\service\ProductService;
use app\catalog\input\ProductInput;
use app\catalog\input\SearchInput;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\ModelException;

/** HTTP 只接收有效输入、检查授权并返回显式投影。 */
#[Group(middleware: ['catalog.tenant'])]
final class ProductController
{
    /** 服务由应用共同装配，不持有请求或连接。 */
    public function __construct(private ProductService $service)
    {
    }

    /** @return array{data:array} 最多二十条记录。 */
    #[Route('/products', methods: ['GET'], name: 'products.index')]
    public function index(SearchInput $input, ServerRequestInterface $request): array
    {
        $this->authorize($request);
        return ['data' => $this->service->page($input->data->toArray())];
    }

    /** @return array{id:int,code:string,name:string,note:?string,version:int,created_at:int,updated_at:int} 创建成功使用固定 201。 */
    #[Route('/products', methods: ['POST'], name: 'products.create', status: 201)]
    public function create(ProductInput $input, ServerRequestInterface $request): array
    {
        $this->authorize($request);
        return $this->service->create($input->data->toArray());
    }

    /** @return array{id:int,code:string,name:string,note:?string,version:int,created_at:int,updated_at:int} 路径 id 不由查询或正文覆盖。 */
    #[Route('/products/{id}', methods: ['GET'], name: 'products.show')]
    public function show(int $id, ServerRequestInterface $request): array
    {
        $this->authorize($request);
        try {
            return $this->service->find($id);
        } catch (ModelException $error) {
            if ($error->errorCode() === 'record_not_found') {
                throw new HttpError(404, 'record_not_found');
            }
            throw $error;
        }
    }

    /** @return array{id:int,code:string,name:string,note:?string,version:int,created_at:int,updated_at:int} 缺失字段保持原值。 */
    #[Route('/products/{id}', methods: ['PATCH'], name: 'products.update', input: ['patch' => true])]
    public function update(int $id, ProductInput $input, ServerRequestInterface $request): array
    {
        $this->authorize($request);
        try {
            return $this->service->update($id, $input->data->toArray());
        } catch (ModelException $error) {
            if ($error->errorCode() === 'record_not_found') {
                throw new HttpError(404, 'record_not_found');
            }
            throw $error;
        }
    }

    /** 同一 code 的竞争创建返回数据库已证明的唯一商品。 */
    #[Route('/products/ensure', methods: ['POST'], name: 'products.ensure', status: 201)]
    public function ensure(ProductInput $input, ServerRequestInterface $request): array
    {
        $this->authorize($request);
        return $this->service->ensure($input->data->toArray());
    }

    /** 显式固定批次演示三库真实冲突写入语义。 */
    #[Route('/products/import', methods: ['POST'], name: 'products.import')]
    public function import(ServerRequestInterface $request): array
    {
        $this->authorize($request);
        return $this->service->import();
    }

    /** 关系变更必须限定到已经授权的商品。 */
    #[Route('/products/{id}/labels', methods: ['POST'], name: 'products.labels')]
    public function label(int $id, ServerRequestInterface $request): array
    {
        $this->authorize($request);
        return $this->service->label($id);
    }

    /** 模板服务身份没有目录管理角色；展示真实的权限拒绝。 */
    #[Route('/catalog-admin', methods: ['GET'], name: 'products.admin')]
    public function admin(ServerRequestInterface $request): array
    {
        $this->authorize($request);
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity || !in_array('catalog.admin', $identity->roles(), true)) {
            throw new HttpError(403, 'catalog_admin_required');
        }
        return ['authorized' => true];
    }

    /** 只信任认证中间件写入的 Identity；客户端字段不授予权限。 */
    private function authorize(ServerRequestInterface $request): void
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'authentication_required');
        }
        if (!in_array('users', $identity->roles(), true)) {
            throw new HttpError(403, 'forbidden');
        }
    }
}
