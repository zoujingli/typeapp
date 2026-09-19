<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use Closure;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Query;
use Type\Validate\ValidationException;

/** 产品与模型版本的持久所有者；模型编号永不复用，发布后所有写路径拒绝变更。 */
final class ProductService
{
    /** @return array<string, mixed> 当前租户有界产品列表及本次权限。 */
    public function products(Connection $connection, Identity $identity, string $tenantId, int $page, int $perPage, string $name): array
    {
        return $this->run($connection, $identity, $tenantId, 'product.listed', $tenantId, false, function (Connection $transaction, array $context) use ($tenantId, $page, $perPage, $name): array {
            $rows = $transaction->table('iot_products')->where('tenant_id', '=', $tenantId)->select(['id', 'tenant_id', 'name', 'description', 'version', 'created_at', 'updated_at']);
            if ($name !== '') {
                $rows = $rows->where('name', 'LIKE', '%' . $name . '%');
            }
            return $this->page($rows->orderBy('created_at', 'DESC')->orderBy('id'), $page, $perPage) + ['context' => $context];
        });
    }

    /** @return array<string, mixed> 新产品；名称不是可跨租户引用的标识。 */
    public function create(Connection $connection, Identity $identity, string $tenantId, string $name, string $description): array
    {
        return $this->run($connection, $identity, $tenantId, 'product.created', $tenantId, true, static function (Connection $transaction, array $context) use ($tenantId, $name, $description): array {
            $product = ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenantId, 'name' => $name, 'description' => $description, 'version' => 1, 'created_at' => time(), 'updated_at' => time()];
            $transaction->table('iot_products')->insert($product + ['next_model_version' => 1]);
            return $product;
        });
    }

    /** @return array<string, mixed> 不存在与其他租户资源统一返回product_not_found。 */
    public function product(Connection $connection, Identity $identity, string $tenantId, string $productId): array
    {
        return $this->run($connection, $identity, $tenantId, 'product.viewed', $productId, false, fn (Connection $transaction, array $context): array => $this->findProduct($transaction, $tenantId, $productId));
    }

    /**
     * null资料代表删除未发布产品；有发布版本时整个产品保留。
     * @param array{name: string, description: string}|null $data 要替换的公开资料。
     * @return array<string, mixed> 更新后的产品或删除结果。
     * @throws HttpError 权限不足、旧版本或发布历史阻止删除。
     */
    public function change(Connection $connection, Identity $identity, string $tenantId, string $productId, int $version, ?array $data): array
    {
        return $this->run($connection, $identity, $tenantId, $data === null ? 'product.deleted' : 'product.changed', $productId, true, function (Connection $transaction, array $context) use ($tenantId, $productId, $version, $data): array {
            $product = $this->findProduct($transaction, $tenantId, $productId);
            $this->version($product, $version);
            $query = $transaction->table('iot_products')->where('tenant_id', '=', $tenantId)->where('id', '=', $productId);
            if ($data === null) {
                $models = $transaction->table('iot_models')->where('tenant_id', '=', $tenantId)->where('product_id', '=', $productId);
                if ($models->where('status', '=', 'published')->first() !== null) {
                    throw new HttpError(409, 'published_model_retained');
                }
                $models->delete();
                $query->delete();
                return ['deleted' => true];
            }
            $query->update(['name' => $data['name'], 'description' => $data['description'], 'version' => $version + 1, 'updated_at' => time()]);
            return $this->findProduct($transaction, $tenantId, $productId);
        });
    }

    /** @return array<string, mixed> 精确版本列表，包括各自完整定义，不合并最新草稿。 */
    public function models(Connection $connection, Identity $identity, string $tenantId, string $productId, int $page, int $perPage): array
    {
        return $this->run($connection, $identity, $tenantId, 'model.listed', $productId, false, function (Connection $transaction, array $context) use ($tenantId, $productId, $page, $perPage): array {
            $this->findProduct($transaction, $tenantId, $productId);
            $result = $this->page($transaction->table('iot_models')->where('tenant_id', '=', $tenantId)->where('product_id', '=', $productId)->orderBy('model_version', 'DESC'), $page, $perPage);
            $items = [];
            foreach ($result['items'] as $row) {
                $items[] = self::decode($row);
            }
            $result['items'] = $items;
            return $result + ['context' => $context];
        });
    }

    /**
     * 创建永久新编号的草稿；复制旧定义仍形成当前租户自己的新事实。
     * @return array<string, mixed> 新草稿。
     * @throws ValidationException 定义非法。
     */
    public function createModel(Connection $connection, Identity $identity, string $tenantId, string $productId, mixed $definition): array
    {
        return $this->run($connection, $identity, $tenantId, 'model.created', $productId, true, function (Connection $transaction, array $context) use ($tenantId, $productId, $definition): array {
            $this->findProduct($transaction, $tenantId, $productId);
            $normalized = ModelDefinition::normalize($definition);
            $query = $transaction->table('iot_products')->where('tenant_id', '=', $tenantId)->where('id', '=', $productId);
            $row = $query->first();
            $number = (int) $row['next_model_version'];
            if ($number >= 2147483646) {
                throw new HttpError(409, 'model_version_exhausted');
            }
            $query->update(['next_model_version' => $number + 1]);
            $model = ['tenant_id' => $tenantId, 'product_id' => $productId, 'model_version' => $number, 'version' => 1, 'status' => 'draft',
                'definition' => json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => time(), 'published_at' => null];
            $transaction->table('iot_models')->insert($model);
            return self::decode($model);
        });
    }

    /** @return array<string, mixed> 指定版本；不能回退到最新或跨租户查找。 */
    public function model(Connection $connection, Identity $identity, string $tenantId, string $productId, int $number): array
    {
        return $this->run($connection, $identity, $tenantId, 'model.viewed', $productId, false, static fn (Connection $transaction, array $context): array => self::findModel($transaction, $tenantId, $productId, $number));
    }

    /**
     * 草稿更新、删除与发布在同一租户写序列里完成；发布版本永不修改。
     * @param string $operation edit/delete/publish。
     * @return array<string, mixed> 修改后的版本或删除结果。
     * @throws HttpError 已发布、旧草稿版本、不存在或空发布。
     * @throws ValidationException 新定义非法。
     */
    public function changeModel(Connection $connection, Identity $identity, string $tenantId, string $productId, int $number, int $version, string $operation, mixed $definition = null): array
    {
        if (!in_array($operation, ['edit', 'delete', 'publish'], true)) {
            throw new HttpError(422, 'invalid_model_operation');
        }
        return $this->run($connection, $identity, $tenantId, 'model.' . $operation, $productId, true, function (Connection $transaction, array $context) use ($tenantId, $productId, $number, $version, $operation, $definition): array {
            $model = self::findModel($transaction, $tenantId, $productId, $number);
            if ($model['status'] === 'published') {
                throw new HttpError(409, 'model_immutable');
            }
            $this->version($model, $version);
            $query = $transaction->table('iot_models')->where('tenant_id', '=', $tenantId)->where('product_id', '=', $productId)->where('model_version', '=', $number);
            if ($operation === 'delete') {
                $query->delete();
                return ['deleted' => true, 'model_version' => $number];
            }
            if ($operation === 'publish') {
                if ($model['definition']['properties'] === [] && $model['definition']['events'] === [] && $model['definition']['commands'] === []) {
                    throw new HttpError(422, 'empty_model');
                }
                $query->update(['status' => 'published', 'published_at' => time(), 'version' => $version + 1]);
            } else {
                $normalized = ModelDefinition::normalize($definition);
                $query->update(['definition' => json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'version' => $version + 1]);
            }
            return self::findModel($transaction, $tenantId, $productId, $number);
        });
    }

    /**
     * 供设备注册、接收及控制入口在自身授权与事务内调用；只返回精确已发布版本。
     * @return array<string, mixed> 含完整冻结定义的版本事实。
     * @throws HttpError 模型不存在或未发布；绝不查找其他租户或最新草稿。
     */
    public static function publishedModel(Connection $connection, string $tenantId, string $productId, int $number): array
    {
        $model = self::findModel($connection, $tenantId, $productId, $number);
        if ($model['status'] !== 'published') {
            throw new HttpError(409, 'model_not_published');
        }
        return $model;
    }

    /** @return array{valid: bool, model_version: int} 模型调试公开入口；不发送指令或写入遥测。 */
    public function validate(Connection $connection, Identity $identity, string $tenantId, string $productId, int $number, string $kind, string $identifier, mixed $values): array
    {
        return $this->run($connection, $identity, $tenantId, 'model.validated', $productId, false, static function (Connection $transaction, array $context) use ($tenantId, $productId, $number, $kind, $identifier, $values): array {
            $model = self::publishedModel($transaction, $tenantId, $productId, $number);
            ModelDefinition::validateValues($model['definition'], $kind, $identifier, $values);
            return ['valid' => true, 'model_version' => $number];
        });
    }

    /**
     * 复用双端授权写事务，产品与版本保留既有业务规则；不推进租户资料版本。
     * @param Closure(Connection, array<string, mixed>): array<string, mixed> $operation 已授权的同连接业务操作。
     */
    private function run(Connection $connection, Identity $identity, string $tenantId, string $action, string $subjectId, bool $write, Closure $operation): array
    {
        try {
            if (!$write) {
                $current = (new IdentityService('customer'))->refresh($connection, $identity);
                if ($current === null || $current->subject() !== $identity->subject()) {
                    throw new HttpError(401, 'unauthenticated');
                }
                $permissions = RoleService::permissions($connection, $current, 'customer', $tenantId);
                if (!in_array('customer.products.read', $permissions, true)) {
                    throw new HttpError(403, 'permission_denied');
                }
                return $operation($connection, ['permissions' => $permissions, 'menus' => RoleService::menus('customer', $permissions), 'identity' => IdentityService::context($current, $tenantId)]);
            }
            return RoleService::authorized($connection, $identity, null, 'customer.products.manage', static function (Connection $transaction, Identity $current, array $permissions) use ($tenantId, $action, $subjectId, $operation): array {
                $context = ['permissions' => $permissions, 'menus' => RoleService::menus('customer', $permissions), 'identity' => IdentityService::context($current, $tenantId)];
                $result = $operation($transaction, $context);
                AuditLog::append($transaction, $tenantId, $current, $action, $result['id'] ?? $subjectId, 'success', ['context' => 'product-model', 'version' => $result['model_version'] ?? ($result['version'] ?? 0)], 'customer');
                return $result;
            }, 'customer', $tenantId);
        } catch (HttpError $failure) {
            $this->failure($connection, $identity, $tenantId, $action, $subjectId, in_array($failure->status(), [401, 403, 404], true) ? 'denied' : 'failed', $failure->errorCode());
            throw $failure;
        } catch (ValidationException $invalid) {
            $this->failure($connection, $identity, $tenantId, $action, $subjectId, 'failed', $invalid->errorCode());
            throw $invalid;
        }
    }

    private function failure(Connection $connection, Identity $identity, string $tenantId, string $action, string $subjectId, string $result, string $reason): void
    {
        $member = $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('user_id', '=', $identity->subject())->first();
        AuditLog::append($connection, $member === null ? null : $tenantId, $identity, $action, $subjectId, $result, ['context' => 'product-model', 'reason' => $reason], 'customer');
    }

    private function findProduct(Connection $connection, string $tenantId, string $productId): array
    {
        $product = $connection->table('iot_products')->where('tenant_id', '=', $tenantId)->where('id', '=', $productId)->select(['id', 'tenant_id', 'name', 'description', 'version', 'created_at', 'updated_at'])->first();
        if ($product === null) {
            throw new HttpError(404, 'product_not_found');
        }
        return $product;
    }

    private static function findModel(Connection $connection, string $tenantId, string $productId, int $number): array
    {
        $model = $connection->table('iot_models')->where('tenant_id', '=', $tenantId)->where('product_id', '=', $productId)->where('model_version', '=', $number)->first();
        if ($model === null) {
            throw new HttpError(404, 'model_not_found');
        }
        return self::decode($model);
    }

    private static function decode(array $model): array
    {
        $model['definition'] = json_decode((string) $model['definition'], true, 12, JSON_THROW_ON_ERROR);
        $model['structure_hash'] = ModelDefinition::structuralHash($model['definition']);
        return $model;
    }

    private function version(array $row, int $version): void
    {
        if ($version < 1 || $version >= 2147483646 || (int) $row['version'] !== $version) {
            throw new HttpError(409, 'stale_version');
        }
    }

    /** 调用方已按产品主键或固定产品下唯一的模型编号收尾排序；复用成员列表的有界查询。 */
    private function page(Query $rows, int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        return ['items' => $rows->limit($perPage, ($page - 1) * $perPage)->get(), 'total' => (int) $rows->aggregate('COUNT'), 'page' => $page, 'per_page' => $perPage];
    }
}
