# Schema 声明与冻结迁移

当前 `main` 新增 `Type\Orm\Attribute\Schema` 和 `type schema:prepare`，尚未包含在 RC14。Schema 声明属于构建输入；显式准备生成三库 SQL，普通开发和 AOT 构建只核验并嵌入冻结内容。它不查询在线结构，也不自动同步表。

## 声明、准备与审查

在应用生产 `sources` 范围内新增独立文件 `app/common/database/CreateProducts.php`。文件只包含具名命名空间、导入、`strict_types` 和一个无成员、无继承的迁移类：

```php
<?php

declare(strict_types=1);

namespace app\common\database;

use Type\Orm\Attribute\Schema;

#[Schema(
    version: '202610060001',
    description: '创建产品目录',
    operations: [
        [
            'action' => 'create',
            'table' => 'products',
            'columns' => [
                'id' => ['type' => 'integer', 'auto' => true],
                'code' => ['type' => 'string', 'length' => 80],
                'title' => ['type' => 'string', 'length' => 160],
                'active' => ['type' => 'boolean', 'default' => true],
                'amount' => ['type' => 'decimal', 'precision' => 30, 'scale' => 2],
                'recorded_at' => ['type' => 'datetime'],
                'notes' => ['type' => 'text', 'nullable' => true],
                'payload' => ['type' => 'binary', 'nullable' => true],
            ],
            'primary' => ['id'],
            'indexes' => [
                ['name' => 'products_code_unique', 'columns' => ['code'], 'unique' => true],
                ['name' => 'products_title_index', 'columns' => ['title']],
            ],
        ],
    ],
    snapshot: 'snapshots/create-products.json',
)]
final class CreateProducts {}
```

安装 `type-orm`、所选驱动和开发依赖 `type-build` 后，在应用根执行：

```sh
php vendor/bin/type schema:prepare app/common/database/CreateProducts.php
```

结果包含快照路径、摘要和是否新建。快照保存 `protocol`、声明身份、三库有序 SQL、各驱动事务标志与 `sha256`；逐项审查后将 PHP 声明和 JSON 一并提交。同声明、同协议再次准备不会改变文件。已存在的不同内容报 `TYPE_SCHEMA_FROZEN`，不得通过删除旧快照重写已应用迁移。

正常构建不调用 SQL 生成器。快照缺失报 `TYPE_SCHEMA_SNAPSHOT_MISSING`；协议、声明或 SQL 摘要不符报 `TYPE_SCHEMA_SNAPSHOT_CHANGED`。开发缓存复用前也会核验快照。准备失败不发布半份 JSON。路径只能位于声明文件目录内，不能通过 `..` 或符号链接越界。

## 接入现有迁移入口

开发与 AOT 共用的生成器提供 `CreateProducts::migration(string $driver): Migration`。在应用已有的 `common/database/Schema::migrations($driver)` 返回列表末尾加入 `CreateProducts::migration($driver)`；保留所有历史版本、描述、SQL 顺序和事务标志。这里没有第二套迁移注册表，也不重新声明已发布模板、应用或 Broker 的旧表。

调用应用现有的 `Migrator::status($plan)` 检查状态，随后显式 `run($plan)`。独立模板的外层入口是 `php dev.php migrate status` 与 `php dev.php migrate run`；物联中心使用已有安装或升级流程。Schema 不自行开放额外安装入口。完整基础设施调用可运行 `examples/orm-suite/SchemaExercise.php`，它与三库独立消费者共用同一计划。

生成类内嵌三库 SQL，只依据已安装驱动的名称返回既有 `Migration` 对象。生产不加载声明 PHP 或 JSON，沿用版本排序、迁移锁、checksum、状态和恢复协议。正常显式 SQL `new Migration(...)` 入口继续保留。

迁移成功后，业务用 `#[Table('products')]` 模型及公开 `create/find/query/save/forceDelete` 操作。模型字段与 Schema 各自负责业务规范化和真实数据库结构，修改模型不自动改变表。精确数值在 Model 中使用 decimal 字符串，UTC 时间使用 `DateTimeImmutable`；二进制列由基础设施参数或驱动原生二进制表示操作，本能力不引入 Model binary 编解码器。

## 字段与驱动差异

| 声明 | MySQL | PostgreSQL | SQLite |
| --- | --- | --- | --- |
| `string`，`length` 默认255 | `VARCHAR(n)` | `VARCHAR(n)` | `VARCHAR(n)`，不强制长度 |
| `text` | `TEXT` | `TEXT` | `TEXT` |
| `integer`，`bits` 32或64，默认64 | `INTEGER/BIGINT` | `INTEGER/BIGINT` | `INTEGER`，不模拟32位范围 |
| `boolean` | `BOOLEAN` | `BOOLEAN` | `INTEGER`，不模拟独立布尔类型 |
| `decimal`，`precision/scale` 默认30/0 | `DECIMAL(p,s)` | `DECIMAL(p,s)` | `TEXT`，保全十进制字符串，不提供数据库精度约束 |
| `datetime` | `DATETIME(6)` | `TIMESTAMP(6) WITHOUT TIME ZONE` | `TEXT`，保存 UTC 六位微秒值 |
| `binary` | `LONGBLOB` | `BYTEA` | `BLOB` |

普通字段默认非空。`nullable: true` 接受空值。`default` 只接受对应类型的常量；decimal 必须是精度内的字符串，datetime 必须为完整 UTC `2026-10-06T00:00:00.000001Z`。text/binary 仅接受可空列的 null 默认值。字符串默认值不接受零字节或反斜杠，避免 MySQL SQL 模式改变含义；不接受 `CURRENT_TIMESTAMP`、函数或 SQL 表达式。

标识符为最多63字节的小写字母、数字和下划线，首字符为字母；主键和索引列名必须不重复且存在于建表声明。自增只用于没有默认值的单列整数主键。MySQL 使用 InnoDB；SQLite 的自增使用真实 `AUTOINCREMENT`。索引长度、字符集、排序规则等服务器限制仍由实际数据库检查，不能把 SQLite 类型亲和性视为三库一致的约束。

## 以新版本演进

新增 `AlterProducts.php`，使用新版本和新快照路径，操作按顺序声明：

```php
operations: [
    ['action' => 'add-column', 'table' => 'products', 'name' => 'state',
        'column' => ['type' => 'string', 'length' => 20, 'default' => 'new']],
    ['action' => 'rename-column', 'table' => 'products', 'from' => 'title', 'to' => 'heading'],
    ['action' => 'drop-index', 'table' => 'products', 'name' => 'products_title_index'],
    ['action' => 'add-index', 'table' => 'products', 'name' => 'products_heading_index', 'columns' => ['heading']],
    ['action' => 'drop-column', 'table' => 'products', 'name' => 'notes'],
    ['action' => 'rename-table', 'table' => 'products', 'to' => 'catalog_entries'],
],
```

执行同一准备命令，审查新快照，追加新工厂到原迁移列表，再核对旧 checksum 与保留字段。增列必须可空或有非空常量默认值，不能新增自增列。类型、null、默认值、主键及外键调整暂不提供声明操作，会报 `TYPE_SCHEMA_UNSUPPORTED`；不自动重建 SQLite 表。

SQLite 使用服务器原生 DDL：列改名需要 SQLite 3.25+，删列需要3.35+。旧版本直接由实际 SQL 执行拒绝；有索引、外键或其他依赖的删列也由数据库拒绝。框架不先删除依赖、不关闭外键、不扩宽 PRAGMA 或事务控制。PostgreSQL、SQLite 的受支持迁移使用事务；MySQL 计划标记 `transactional: false`，前段 DDL 在后段失败时可能已生效。

## 失败、核对与恢复

失败后保留 `failed` 状态和真实错误。先调用 `status`、`history`，核对表、列、索引、业务数据与迁移摘要。MySQL 必须检查已生效步骤，按实际情况修复前置条件，再使用 `recover($plan, $version, 'retry', $reason)` 授权重试；这属于人工运维决定，不能自动假设回滚。也可依现有协议确认已完成结果。不要修改旧 SQL 或迁移记录以消除失败。

`examples/orm-suite` 以真实数据验证：首版建表与精确值存取；新版本增列、改名、建删索引、删列后继续 Model CRUD；故意让第二条 DDL 失败，核对 MySQL 部分生效与 PostgreSQL/SQLite 回滚；恢复专用夹具前置条件后重试同一冻结计划；SQLite 另验证带依赖删列失败后索引及数据保持。`tests/orm-suite-consumer.php` 在独立安装目录显式调用准备入口，确认已冻结字节不变，记录 SQL 快照与迁移摘要；PHP/AOT 走同一生成工厂。
