<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Attribute\Schema;

/** 新模块保留独立版本，不改写既有用户、文章等历史迁移。 */
#[Schema(version: '202610060001', description: '创建目录模块', snapshot: 'snapshots/catalog-create.json', operations: [
    ['action' => 'create', 'table' => 'type_suite_catalog', 'columns' => [
        'id' => ['type' => 'integer', 'auto' => true],
        'code' => ['type' => 'string', 'length' => 80],
        'title' => ['type' => 'string', 'length' => 100],
        'active' => ['type' => 'boolean', 'default' => true],
        'age' => ['type' => 'integer', 'bits' => 32, 'default' => 0],
        'money' => ['type' => 'decimal', 'precision' => 30, 'scale' => 2],
        'recorded_at' => ['type' => 'datetime'],
        'notes' => ['type' => 'text', 'nullable' => true],
        'payload' => ['type' => 'binary', 'nullable' => true],
        'spare' => ['type' => 'string', 'nullable' => true],
    ], 'primary' => ['id'], 'indexes' => [
        ['name' => 'type_suite_catalog_code', 'columns' => ['code'], 'unique' => true],
        ['name' => 'type_suite_catalog_title', 'columns' => ['title']],
    ]],
])]
final class CatalogCreate
{
}
