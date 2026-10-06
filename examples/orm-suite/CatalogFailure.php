<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Attribute\Schema;

/** 刻意保留第二步真实失败，用于验证事务回滚和非事务部分生效后的显式恢复。 */
#[Schema(version: '202610060003', description: '验证目录升级失败恢复', snapshot: 'snapshots/catalog-failure.json', operations: [
    ['action' => 'add-column', 'table' => 'type_suite_catalog_entries', 'name' => 'retry_marker', 'column' => ['type' => 'string', 'nullable' => true]],
    ['action' => 'drop-column', 'table' => 'type_suite_catalog_entries', 'name' => 'missing_column'],
])]
final class CatalogFailure
{
}
