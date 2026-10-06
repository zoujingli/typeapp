<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Attribute\Schema;

/** SQLite 必须保留依赖拒绝，不能自动删除唯一索引或重建表。 */
#[Schema(version: '202610060004', description: '验证 SQLite 索引依赖拒绝', snapshot: 'snapshots/catalog-dependency.json', operations: [
    ['action' => 'drop-column', 'table' => 'type_suite_catalog_entries', 'name' => 'code'],
])]
final class CatalogDependency
{
}
