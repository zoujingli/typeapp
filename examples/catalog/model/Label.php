<?php

declare (strict_types=1);
namespace app\catalog\model;

use Type\Orm\Model;
/**
 * 标签同样属于可信租户；关系查询不可跨越此边界。
 */
final class Label extends Model
{
    protected string $table = 'catalog_labels';
    protected string $database = 'catalog';
    protected ?string $tenantKey = 'tenant_id';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'code' => 'string'];
}