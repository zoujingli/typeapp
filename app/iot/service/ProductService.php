<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\iot\model\Product;
use Closure;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Runtime\ExecutionScope;
use Type\Validate\ValidationException;

/** 客户产品分类资料的持久所有者；不参与注册资格或协议解析，历史引用仍受保护。 */
final class ProductService
{
    /** @return array<string, mixed> 当前租户有界产品列表及本次权限。 */
    public function products(Identity $identity, string $tenantId, int $page, int $perPage, string $name): array
    {
        return $this->run($identity, $tenantId, 'product.listed', $tenantId, false, function (array $context) use ($page, $perPage, $name): array {
            $rows = Product::query()->master();
            if ($name !== '') {
                $rows = $rows->where('name', 'LIKE', '%' . $name . '%');
            }
            return $this->productPage($rows->orderBy('created_at', 'DESC')->orderBy('id'), $page, $perPage) + ['context' => $context];
        });
    }

    /** @return array<string, mixed> 新产品；名称不是可跨租户引用的标识。 */
    public function create(Identity $identity, string $tenantId, string $name, string $description): array
    {
        return $this->run($identity, $tenantId, 'product.created', $tenantId, true, static function (array $context) use ($name, $description): array {
            $product = Product::create(['id' => bin2hex(random_bytes(16)), 'name' => $name, 'description' => $description]);
            return $product->project(['id', 'tenant_id', 'name', 'description', 'version', 'created_at', 'updated_at']);
        });
    }

    /** @return array<string, mixed> 不存在与其他租户资源统一返回product_not_found。 */
    public function product(Identity $identity, string $tenantId, string $productId): array
    {
        return $this->run($identity, $tenantId, 'product.viewed', $productId, false, fn (array $context): array => $this->findProduct($productId)
            ->project(['id', 'tenant_id', 'name', 'description', 'version', 'created_at', 'updated_at']));
    }

    /**
     * null 资料代表删除无引用分类；历史模型及当前设备引用时保留。
     * @param array{name: string, description: string}|null $data 要替换的公开资料。
     * @return array<string, mixed> 更新后的产品或删除结果。
     * @throws HttpError 权限不足、旧版本或历史或设备引用阻止删除。
     */
    public function change(Identity $identity, string $tenantId, string $productId, int $version, ?array $data): array
    {
        return $this->run($identity, $tenantId, $data === null ? 'product.deleted' : 'product.changed', $productId, true, function (array $context) use ($tenantId, $productId, $version, $data): array {
            $product = $this->findProduct($productId);
            $this->version($product->getVersion(), $version);
            if ($data === null) {
                // 历史模型原件不会因退出生产入口而删除；当前分类引用同样在授权锁内检查。
                $transaction = Db::connection('default', true);
                if ($transaction->table('iot_models')->where('tenant_id', '=', $tenantId)->where('product_id', '=', $productId)->exists()
                    || \app\iot\model\Device::query()->master()->where('product_id', '=', $productId)->exists()) {
                    throw new HttpError(409, 'product_referenced');
                }
                $product->delete();
                return ['deleted' => true];
            }
            if (array_key_exists('name', $data)) {
                $product->setName($data['name']);
            }
            if (array_key_exists('description', $data)) {
                $product->setDescription($data['description']);
            }
            // 相同资料也消耗本次表单版本，保持重复提交与旧页面的既有冲突语义。
            $product->dirty() === [] ? $product->touch() : $product->save();
            return $product->project(['id', 'tenant_id', 'name', 'description', 'version', 'created_at', 'updated_at']);
        });
    }

    /**
     * 复用双端授权写事务，分类资料保留乐观锁规则；不推进租户资料版本。
     * @param Closure(array<string, mixed>): array<string, mixed> $operation 已授权且绑定可信租户的业务操作。
     */
    private function run(Identity $identity, string $tenantId, string $action, string $subjectId, bool $write, Closure $operation): array
    {
        $connection = Db::connection('default', true);
        try {
            if (!$write) {
                $current = (new IdentityService('customer'))->refresh($identity);
                if ($current === null || $current->subject() !== $identity->subject()) {
                    throw new HttpError(401, 'unauthenticated');
                }
                $permissions = RoleService::permissions($current, 'customer', $tenantId);
                if (!in_array('customer.products.read', $permissions, true)) {
                    throw new HttpError(403, 'permission_denied');
                }
                return ExecutionScope::current()->run(
                    static fn (ExecutionScope $scope): array => $operation(['permissions' => $permissions, 'menus' => RoleService::menus('customer', $permissions), 'identity' => IdentityService::context($current, $tenantId)]),
                    ['tenant_id' => $tenantId]
                );
            }
            return RoleService::authorized($identity, null, 'customer.products.manage', static function (Identity $current, array $permissions) use ($tenantId, $action, $subjectId, $operation): array {
                $transaction = Db::connection('default', true);
                $context = ['permissions' => $permissions, 'menus' => RoleService::menus('customer', $permissions), 'identity' => IdentityService::context($current, $tenantId)];
                $result = $operation($context);
                AuditLog::append($transaction, $tenantId, $current, $action, $result['id'] ?? $subjectId, 'success', ['context' => 'product', 'version' => $result['version'] ?? 0], 'customer');
                return $result;
            }, 'customer', $tenantId);
        } catch (HttpError $failure) {
            $this->failure($connection, $identity, $tenantId, $action, $subjectId, in_array($failure->status(), [401, 403, 404], true) ? 'denied' : 'failed', $failure->errorCode());
            throw $failure;
        } catch (ValidationException $invalid) {
            $this->failure($connection, $identity, $tenantId, $action, $subjectId, 'failed', $invalid->errorCode());
            throw $invalid;
        } catch (ModelException $conflict) {
            if ($conflict->errorCode() !== 'optimistic_conflict') {
                throw $conflict;
            }
            $this->failure($connection, $identity, $tenantId, $action, $subjectId, 'failed', 'stale_version');
            throw new HttpError(409, 'stale_version');
        }
    }

    private function failure(Connection $connection, Identity $identity, string $tenantId, string $action, string $subjectId, string $result, string $reason): void
    {
        // 授权失败不能授予请求租户上下文；只查询成员归属以决定拒绝审计的关联范围。
        $member = $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('user_id', '=', $identity->subject())->first();
        AuditLog::append($connection, $member === null ? null : $tenantId, $identity, $action, $subjectId, $result, ['context' => 'product', 'reason' => $reason], 'customer');
    }

    private function findProduct(string $productId): Product
    {
        $product = Product::query()->master()->find($productId);
        if ($product === null) {
            throw new HttpError(404, 'product_not_found');
        }
        return $product;
    }

    private function version(int $current, int $version): void
    {
        if ($version < 1 || $version >= 2147483646 || $current !== $version) {
            throw new HttpError(409, 'stale_version');
        }
    }

    /** 产品分页只公开资料字段，不暴露永久编号分配器。 */
    private function productPage(ModelQuery $rows, int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        $result = $rows->paginate($page, $perPage);
        $items = [];
        foreach ($result->items() as $product) {
            $items[] = $product->project(['id', 'tenant_id', 'name', 'description', 'version', 'created_at', 'updated_at']);
        }
        return ['items' => $items, 'total' => $result->total(), 'page' => $result->number(), 'per_page' => $result->perPage()];
    }
}
