# type-orm · 查询、模型与事务

[返回组件总览](../components.md)

提供受管数据库连接、不可变 Query、生成模型、关系、分页、事务、迁移和事务 Outbox。ORM 不选择数据库；安装一个驱动后使用同一公开入口，并保留数据库本身的能力差异。

业务 CRUD 优先使用[Model 与关系](#models-relations-output)。当前模型查询和保存需要显式 `Connection`；已确定的自动连接、静态 `search()`、上下文自动租户隔离、默认读从写主及 `master()` 主读规则见[模型连接与主从路由](../../development/model-connections.md)，这些入口尚待实施。本文的连接和表查询示例说明当前底层能力，不代表普通业务必须自行管理连接。

## 安装与依赖

需要 PHP `>=8.4 <8.6`、Swoole `>=6.2 <7`、PDO 与 `type-runtime`；实际访问数据另装 MySQL、PostgreSQL 或 SQLite 驱动。Swoole 管理协程执行、等待、取消和连接租约，PDO 及所选 PDO 驱动负责数据库协议和 SQL 语义。

源码位于本仓库对应 plugin 目录。在消费应用根声明依赖后执行：

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer config repositories.type-orm vcs https://github.com/zoujingli/type-orm.git
composer require zoujingli/type-orm:dev-main
```

依赖包的 repositories 不会传递给根应用，因此上述命令包含组件的全部传递依赖，使用公开 HTTPS 地址，无需 SSH 密钥。提交应用的 `composer.lock`；`dev-main` 是开发版本，不能等同稳定发布。公共安装约定见[组件总览](../components.md#安装组件)。

## 底层连接与查询示例

以下演示当前底层接口，接收当前作用域的 `Connection`，不含独立 `main()`。调用者须准备 `users(id, name, age)` 表，并从驱动文档的完整入口取得连接；领域实体 CRUD 使用模型入口。

```php
<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\Conditions;

/**
 * 应用服务的声明式方法；调用方传入当前作用域的连接，不保留全局 PDO。
 *
 * @return list<array<string, mixed>>
 */
function findAdultUsers(Connection $connection, string $name): array
{
    return $connection->table('users')
        ->whereGroup(static fn (Conditions $conditions): Conditions => $conditions
            ->where('age', '>=', 18)->where('name', '=', $name))
        ->orderBy('id')
        ->limit(20)
        ->get();
}

/** 事务体明确接收实际事务连接，失败不自动重试写入。 */
function renameUser(Connection $connection, int $id, string $name): int
{
    return $connection->transaction(static function (Connection $transaction) use ($id, $name): int {
        return $transaction->table('users')->where('id', '=', $id)->update(['name' => $name]);
    });
}
```

`findAdultUsers($connection, '示例')` 返回符合条件的行列表，未命中为 `[]`；`renameUser()` 返回数据库报告的影响行数。查询使用参数绑定，不把用户文本拼进 SQL。

## 获取和释放连接

从[MySQL](type-orm-mysql.md)、[PostgreSQL](type-orm-pgsql.md)或[SQLite](type-orm-sqlite.md)选择驱动，再创建 `Database($driver, $capacity = 4, $idleLimit = 2)`。每次执行新建 `ExecutionScope`，调用 `$database->connect($scope)`。

Scope 关闭归还租约，Database 由进程所有者关闭。当前数据库池保留逻辑槽位，归还时关闭物理 PDO，会话状态不会泄漏到下一请求；物理连接复用仍待实施。连接只在借用时建立；同步调用满载立即拒绝，Swoole 协程按有界等待配置排队，连接不能跨进程或执行者使用。

多个命名连接使用 `DatabaseManager(['default' => $writer, 'reporting' => $reader])`，通过 `connect($scope, 'reporting')` 显式选择。`rotate($name, $newDriver)` 切换凭据代次，旧租约保留旧身份到归还；同名连接最多保留两个未排空旧代。

## 常用查询与写入

在上述底层示例函数中使用：

```php
$base = $connection->table('users')->where('age', '>=', 18);
$rows = $base->select(['id', 'name'])->orderBy('id')->limit(20)->get();
$count = $base->aggregate('COUNT');
$connection->table('users')->insert(['id' => 7, 'name' => '示例', 'age' => 20]);
$changed = $connection->table('users')->where('id', '=', 7)->update(['age' => 21]);
$deleted = $connection->table('users')->where('id', '=', 7)->delete();
```

上例的写入仅用于准备好的示例表；如果 id=7 已存在，插入会因主键冲突失败。`where/select/orderBy/limit` 返回新对象，原 `$base` 不会随派生查询改变。

| 需求 | 入口与返回 |
| --- | --- |
| 取列表 / 单行 | `get(): array` / `first(): ?array` |
| 组合条件 | `whereIn`、`whereNull`、`whereGroup`、`orWhere` |
| 联表 / 分组 | `join`、`groupBy`、`selectAggregate`、`havingAggregate` |
| JSON 标量 | `whereJson($column, $path, $value)`，按真实方言支持 |
| 批量写入 | `insertMany`、`updateMany`，核对输入范围与返回语义 |
| 全表修改 | 必须显式 `allowAll()`；默认拒绝无条件更新/删除 |
| 动态排序 | `orderByAllowed($input, $allowed)`，使用白名单映射 |
| 能力检查 | `capabilities()`，不将不支持的操作模拟为成功 |

`query/execute` 是受管 SQL 入口；DDL 和会话修改走 `raw/rawQuery`，会将租约标记为不可复用。不要通过业务连接泄露底层 PDO。

## 组合查询与诊断

`whereColumn` 比较两个列标识符；`whereExists/whereNotExists` 及 `whereIn/whereNotIn` 可以组合当前连接的子查询。`selectSub($query, $alias)` 增加标量投影，`Query::fromSub($query, $alias)` 使用派生表，`joinSub` 联接子查询；`distinct`、`union/unionAll` 保留各自去重语义。查询始终不可变，嵌套查询只组合 SQL 和绑定参数，不提前执行，也不允许跨连接或携带行锁。

```php
$articles = $connection->table('articles', 'a')
    ->whereColumn('a.user_id', '=', 'u.id')->where('a.status', '=', 'published');
$query = $connection->table('users', 'u')->whereExists($articles)
    ->select(['id' => 'u.id'])->selectSub($articles->aggregateQuery('COUNT'), 'article_count');
$sql = $query->toSql();
$bindings = $query->bindings();
```

`exists` 判断匹配行是否存在，`value` 返回首行单列或 null，`pluck` 提取值列表，指定键列时拒绝重复键。`firstOrFail/findOrFail` 在缺失时抛出 `not_found`。聚合和集合查询从已投影的结果列提取值。`increment/decrement` 使用单条 UPDATE，返回数据库影响行数；无显式有效条件时拒绝，模型版本同步递增。精确数值文本列只能无损读写，数据库算术要求真实数值列。

`toSql()` 和 `bindings()` 不执行目标 SQL，也不触发模型元数据读取。绑定预览返回原始值，应用应自行控制其输出。监听则默认脱敏：

```php
$log = $connection->listen($scope, null, maxRecords: 100, maxBytes: 65536);
$rows = $query->get();
$records = $log->records();
$statistics = $log->statistics();
$log->stop();
```

作用域关闭也会停止监听。事件包含连接身份摘要、驱动、操作、耗时、成功或失败、事务深度及状态；默认仅存 SQL 摘要和参数数量，SQL 中的字面值同样不泄露。`includeValues: true` 才保留截断后的 SQL 与标量绑定，并用 `truncated` 标记。数量和字节预算共同限制事件与监听错误；过大记录丢弃，超额逐出最早记录，`dropped/listener_failures` 分开计数。`slowMilliseconds` 单位为毫秒，仅标记慢查询。监听闭包接收 `QueryEvent`，异常单独记录，不能改变已发生的提交；不能在回调内重入当前连接。流查询当前记录打开阶段，事务语句中的状态表示该语句完成时的事实。

## 分页与有界遍历

```php
$page = $connection->table('users')->orderBy('id')->paginate(1, 20);
$items = $page->items();
$total = $page->total();

$cursor = $connection->table('users')->orderBy('id')->cursorPaginate(20);
$next = $cursor->next();
$nextPage = $next === null ? null
    : $connection->table('users')->orderBy('id')->cursorPaginate(20, $next);
```

`Page` 有 `number/perPage/lastPage/hasMore`；`CursorPage` 有 `items/next/hasMore`。分页计数和取数是否同一快照取决于调用者事务。游标应按同一查询条件和排序继续使用，不能当成任意过滤条件的通用 offset。

`simplePaginate($page, $perPage)` 返回只有 `items/number/perPage/hasMore` 的 `SimplePage`，多取一行判断后续，不查询总数。普通分页支持联表、DISTINCT、分组及 UNION，计数以实际结果行为准；复杂查询必须用 `uniqueOrderBy(['唯一结果列'])` 声明可唯一确定每行的排序键，重复父模型 ID 不能充当联表结果的唯一键。单表仍自动补主键排序，游标分页不扩展到复杂查询。

`chunk($size, $consumer)` 分批交付行数组；`RowStream::each()` 每次交付一行，返回 false 可提前结束。流式读取必须在连接和 Scope 存活期间完成并关闭流，避免将全部结果重新累积到内存。

<a id="models-relations-output"></a>

## 模型、关系与输出

模型映射由[构建工具](type-build.md)生成，完整应用组织见[数据库与模型](../database.md)。`ModelQuery` 的 `find/first` 返回模型或 null，`get` 返回模型列表。

例如在独立应用的生产源码中声明：

```php
<?php
declare(strict_types=1);

namespace DocsExample;

use Type\Orm\Model;
use Type\Orm\Attribute\Table;

#[Table('users')]
final class User extends Model
{
    public int $id;
    public string $name;
    public int $age;
}
```

将该文件纳入应用 `sources`，然后 prepare/build。数据库应已有 `users` 表，并将 id 定义为该数据库真实的自动主键；模型声明不创建表。旧 `models` 构建键和模型 JSON 已移除，使用旧键会收到迁移错误。转换保留业务类名和方法，原文件及完整转换结果共同进入构建身份；不要为业务类另建生成基类。

PHP 开发需要在加载业务入口前加载本次生成结果。在开发启动器已加载 Composer 后使用下列片段，生产 AOT 会自动纳入这些生成文件：

```php
$generation = (new \Type\Build\DevelopmentBuilder())
    ->prepareConfiguration(__DIR__ . '/type-app.json');
foreach ($generation['files'] as $file) {
    require $generation['directory'] . '/' . $file;
}
```

在持有连接的业务函数中创建、查询并部分更新模型：

```php
$user = new \DocsExample\User(['name' => '示例', 'age' => 20]);
$created = $user->save($connection);
$partial = \DocsExample\User::query($connection)->select(['name'])->find($user->id);
if ($partial !== null) {
    $partial->name = '新名称';
    $partial->save($connection);
    $response = $partial->project(['id', 'name']);
}
```

创建返回 `created`；部分查询自动保留主键，保存只写改动的 name，响应明确投影 id/name。业务扩展可以复用生成映射；不要编辑生成文件保存业务方法。

| 操作 | 语义 |
| --- | --- |
| `loaded/get/set/fill` | 区分未加载、null 与值；fill 按声明的赋值白名单整批校验 |
| `dirty/save` | 只写真实变化；返回 created、updated、unchanged 或行为取消时的 cancelled |
| `project/toArray` | 按输出可见性投影，隐藏字段不可对外读取 |
| `with($name, $relation)` | 显式批量加载关系，`related($name)` 读取已加载结果 |
| `delete/restore/forceDelete` | 软删除、恢复、物理删除，取决于模型声明 |
| `scope/search` | 组合不可变查询；搜索器只能来自显式映射 |

模型直接继承 `Model`，不通过继承或 trait 合并字段，不定义生成的构造、映射、查询及访问器方法。属性必须单独声明、公开、非静态、带类型且无默认值；生成器拒绝重复映射和成员冲突。兼容的 `getName/setName` 访问器仍然经过模型状态。JSON 数组只支持整值赋回，不支持属性引用或间接修改。

模型源码不能使用依赖文件位置的 `__DIR__/__FILE__`，所需资源位置通过显式配置传入，避免代码转换改变资源解析。不能覆盖 `get/set/related` 状态入口，字段转换通过 `ModelBehavior` 声明；未知或放错位置的模型 Attribute 在构建时拒绝。

`Column(name: 'display_name')` 指定数据库列；`Column(type: 'decimal', precision: 30, scale: 2)` 配合 `string` 属性声明精确数值。`Table(softDelete: 'deleted_at', version: 'version')` 引用 PHP 属性名，生命周期属性由框架管理。`visible: false` 控制输出，与 `fillable: false` 的赋值限制独立。

关系属性可声明 `HasOne(Target::class, 'foreignKey')`、`HasMany`、`BelongsTo(Target::class, 'foreignKey')` 或 `BelongsToMany(Target::class, 'pivot_table', 'source_id', 'target_id')`。单模型关系使用可空目标类型，列表使用 `array`；键参数指向模型属性，中间表键使用实际列名。`with('articles.tags')` 批量加载，读取 `$user->articles` 不发起 SQL；显式 `Relation` 工厂仍可传给 `with($name, $relation)`。

`whereHas/whereDoesntHave` 在数据库过滤完整关系路径。`withCount('articles')`、`withSum('articles', 'views')` 使用子查询计算，支持目标 `ModelQuery` 约束和嵌套路径，保留软删除范围。结果通过 `computed('articles_count')` 读取；只有 `project(['name'], [], ['articles_count'])` 显式包含计算值。计算值不进入 `dirty/save/toArray`。

路径最多八层，统计约束不接受 LIMIT；无匹配记录时 COUNT 为 0、SUM 为 null。SQLite 对精确数值 TEXT 列的求和明确报 `exact_sum_unsupported`；MySQL/PostgreSQL 精确算术也要求实际数值存储，不允许文本隐式转换后丢失精度。

已有模型列表使用 `User::query($connection)->with('articles.tags')->load($models)` 补加载；`loadMissing($models)` 复用已经加载的结果，深层路径继续补齐缺失关系。父记录、子模型与 pivot 共享显式读取预算。模型查询始终返回模型；任意联表投影和分组数据使用 `Query`。

`decimal/bigint` 使用精确字符串，拒绝有损浮点输入；`datetime` 使用带时区日期并统一 UTC。SQLite 的精确字段使用满足声明的 TEXT 列，不能让数值亲和转换损失精度。

## 事务与提交结果

`transaction(static function (Connection $transaction): mixed { ... })` 使用固定连接，嵌套事务通过 savepoint。异常触发回滚，参与该层的模型失效，后续需要重新查询。

`afterCommit(static function (): void { ... })` 在最外层提交确认后执行。回调失败并不撤销已经提交的数据；提交确认失败属于未知结果，不能自动重跑业务。`transactionOutcome()` 用于观察当前结果，异常处理要区分回滚、提交未知和提交后失败。

模型声明 `version` 后使用主键与旧版本匹配，冲突为 `optimistic_conflict`；底层 Query 批量写入不会自动加入模型版本或触发逐模型事件。`#[Transactional]` 只在显式生成的组合入口中生效。

## 迁移与 Outbox

`Migration($version, $description, $statements, $transactional)` 保存版本化 SQL。`Migrator($driver)` 提供 `status/run/history/recover`；已执行版本不能改写或从计划删除，后续变化新增版本。MySQL 声明非事务 DDL；PostgreSQL 和 SQLite 普通 DDL 可与成功记录原子提交。

迁移中断或失败后先核对实际数据库，使用 `recover($migrations, $version, 'retry'|'applied', $reason)` 显式恢复；没有自动 down。标准应用的实际迁移命令见[数据库指南](../database.md#显式迁移)。

需要可靠外部投递时，先创建 `Type\Orm\Outbox\Store` 实例，通过 `$store->migration($driverName, $version)` 取得建表迁移，加入应用迁移计划。它是实例方法，返回 Migration 声明，不会自行创建表。在业务事务内调用 `enqueue($connection, $id, $topic, $version, $payload)`，使业务数据和投递意图一起提交或回滚。

### 运行迁移与事务意图示例

先按 [SQLite 插件](type-orm-sqlite.md#安装与依赖)安装驱动。下面是独立的 `app/main.php`，使用带参数[开发启动器](../components.md#运行声明式示例)，在专用 SQLite 文件中创建示例用户与 Outbox 表：

```php
<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\Migrator;
use Type\Orm\Outbox\Store;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

/**
 * 显式迁移后，在同一事务内创建用户与投递意图；每次运行创建一位新用户。
 *
 * @param list<string> $argv 第二项为专用示例数据库的绝对文件路径。
 */
function main(int $argc, array $argv): void
{
    $driver = new SqliteDriver($argv[1] ?? '');
    $store = new Store();
    $migrations = [
        new Migration('001', '创建示例用户表', [
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER NOT NULL)',
        ]),
        $store->migration('sqlite', '002'),
    ];
    $migrator = new Migrator($driver);
    $states = $migrator->run($migrations);
    echo json_encode($states, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

    $database = new Database($driver);
    $scope = new ExecutionScope();
    try {
        $connection = $database->connect($scope);
        $userId = $connection->transaction(static function (Connection $transaction) use ($store): int {
            $transaction->table('users')->insert(['name' => '示例', 'age' => 20]);
            $id = (int) $transaction->lastInsertId();
            $store->enqueue($transaction, 'user.created.' . $id, 'user.created', 1, ['user_id' => $id]);
            return $id;
        });
        $intent = $store->status($connection, 'user.created.' . $userId);
        echo json_encode([
            'user_id' => $userId,
            'outbox_state' => $intent['state'] ?? null,
        ], JSON_THROW_ON_ERROR) . "\n";
    } finally {
        try {
            $scope->close();
        } finally {
            $database->close();
        }
    }
}
```

在独立应用根执行以下命令。使用新的示例文件，不指向已有业务库：

```bash
mkdir -p var/docs-outbox
php dev.php "$(pwd)/var/docs-outbox/example.sqlite"
```

首次运行时两条迁移状态均为 `applied`，末行是 `{"user_id":1,"outbox_state":"pending"}`。再次运行会跳过已执行的迁移，并创建下一位用户；因此建表可重复执行，但整个示例不是只读命令。

本例必须使用文件库：迁移和业务各自使用独立连接，`:memory:` 数据不会跨这些连接共享。两次数据库操作在同一事务连接内；enqueue 失败会回滚用户写入。仅查看迁移可调用 `$migrator->status($migrations)`，历史通过 `$migrator->history()` 读取。

### 投递、对账与回收

上例结束于 pending，尚未调用外部系统。应用实现 `Type\Orm\Outbox\Publisher::publish(Record $record): string`，使用记录的稳定 ID 请求目标系统，并在对方确实接受后返回非空、最多 2000 字节的凭据。

在独立转发命令中构造 `Relay($database, $store, $publisher)`，通过 `runOnce(100)` 有界投递。Relay 自己建立并关闭短 Scope，在事务外调用 Publisher；Database 仍由命令或进程所有者关闭。不要在业务事务内运行 Relay，也不要用一行日志或固定字符串冒充目标系统的接受凭据。

| Store 操作 | 使用时机 |
| --- | --- |
| `status($connection, $id)` | 按稳定 ID 查看状态和已有凭据；未找到为 null |
| `enqueue(...)` | 同 ID、同载荷已存在时返回 false；同 ID 不同载荷拒绝 |
| `consumed($connection, $id, $receipt)` | 消费效果和凭据在同一数据库事务内记录 |
| `replay($connection, $id, $reason)` | 人工核对后重放保留期内的已发布记录，保留消息 ID |
| `collect($connection, 100)` | 只清理已发布、已消费且超过重放窗口的记录 |

Store 默认领取租约为 30000 毫秒，重放保留窗口为 604800 秒。领取批次和回收批次为 1–1000。投递与最终记录之间中断可能重复，目标端仍须按稳定 ID 幂等。`enqueue` 的重复检测不等于整个业务请求防重；数据库唯一键竞争或提交结果未知时需要对账，不能直接重跑整段业务。

## 常见问题与验证

| 现象 | 处理 |
| --- | --- |
| `field_not_loaded` | 补选字段或显式投影，不用默认值掩盖未加载 |
| `unknown_field / hidden_field` | 核对映射、赋值白名单和响应字段 |
| `optimistic_conflict` | 重新读取并决定业务冲突处理，不能盲重试旧模型 |
| SQL 出错后继续使用对象 | 会话可能已失效，结束当前 Scope 并建立新执行 |
| 更新影响行数差异 | 按实际驱动语义解释，不推断三库完全相同 |

生产输入包含 ORM、所选驱动、模型及生成结果。本仓库可运行 `composer test:models`、`composer test:relations`、`composer test:transactions` 与 `composer test:outcomes`；三库真实独立消费和原生验收分别使用 `test:orm-suite` 与 `test:orm-suite-native`。

本文以本仓库当前公开接口为依据；安装版本请同时核对包内 README。[对应源码与包说明](https://github.com/zoujingli/type-orm)。
