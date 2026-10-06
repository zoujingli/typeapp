<?php

declare(strict_types=1);

namespace app\catalog\service;

use app\catalog\model\Product;
use Type\Runtime\ExecutionScope;

/** 调度只扫描授权目录的一页数据，游标与结果由持久状态存储负责。 */
final class Maintenance
{
    /** @return array<string,mixed> occurrence 是关联身份，不是权限或消息完成凭据。 */
    public function execute(string $marker): array
    {
        return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($marker): array {
            return ['occurrence' => $marker, 'products' => count(Product::query()->orderBy('id')->limit(20)->get())];
        }, ['tenant_id' => 'catalog-a']);
    }
}
