<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Attribute\Schema;

/** 新增版本演进真实数据，所有变更均使用数据库原生 DDL。 */
#[Schema(version: '202610060002', description: '演进目录模块', snapshot: 'snapshots/catalog-alter.json', operations: [
    ['action' => 'add-column', 'table' => 'type_suite_catalog', 'name' => 'state', 'column' => ['type' => 'string', 'length' => 30, 'default' => 'new']],
    ['action' => 'rename-column', 'table' => 'type_suite_catalog', 'from' => 'title', 'to' => 'heading'],
    ['action' => 'drop-index', 'table' => 'type_suite_catalog', 'name' => 'type_suite_catalog_title'],
    ['action' => 'add-index', 'table' => 'type_suite_catalog', 'name' => 'type_suite_catalog_heading', 'columns' => ['heading']],
    ['action' => 'drop-column', 'table' => 'type_suite_catalog', 'name' => 'spare'],
    ['action' => 'rename-table', 'table' => 'type_suite_catalog', 'to' => 'type_suite_catalog_entries'],
])]
final class CatalogAlter
{
}
