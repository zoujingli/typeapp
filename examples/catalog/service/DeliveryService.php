<?php

declare(strict_types=1);

namespace app\catalog\service;

use app\catalog\model\Delivery;
use app\catalog\model\Product;
use Type\Orm\Db;
use Type\Orm\Outbox\Store;
use Type\Runtime\ExecutionScope;

/** 固定工作角色有 catalog-a 权限，消息追踪字段不能建立身份。 */
final class DeliveryService
{
    /** Store 只含固定表名与保留策略；连接来自当前任务作用域。 */
    public function __construct(private Store $store)
    {
    }

    /** make 的共同服务契约；任务的实际业务入口使用 consume。 */
    public function execute(string $marker): array
    {
        return ['marker' => $marker];
    }

    /** 校验任务值后，同事务确认凭据及效果；重复消费不重复写入。 */
    public function consume(string $messageId, int $productId): bool
    {
        return ExecutionScope::current()->run(function (ExecutionScope $scope) use ($messageId, $productId): bool {
            return Db::transaction(function () use ($messageId, $productId): bool {
                if (!Product::query()->find($productId) instanceof Product) {
                    throw new \RuntimeException('catalog_product_forbidden');
                }
                if (!$this->store->consumed($messageId, 'catalog:' . $messageId, 'catalog')) {
                    return false;
                }
                (new Delivery(['id' => $messageId, 'product_id' => $productId]))->save();
                return true;
            }, 'catalog');
        }, ['tenant_id' => 'catalog-a']);
    }
}
