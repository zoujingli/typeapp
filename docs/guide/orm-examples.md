# ORM 可运行案例

本页把 ORM 的公开能力对应到仓库中的独立三库消费者。案例使用同一组模型和迁移，在 MySQL、PostgreSQL、SQLite 中分别运行；代码位置、命令和预期结果保持一致。

## 运行完整案例

在主仓根目录执行：

```bash
composer install
composer test:orm-suite
```

该命令为每个驱动创建独立消费者，复制 `examples/orm-suite`，编译模型和 Schema，安装两次迁移，然后运行完整业务套件。输出 JSON 至少包含 `driver`、`version`、`scope_checks`、`sessions` 和 `features`。案例使用临时数据库，命令结束后由消费者清理。

要验证同一程序在无 PHP 源码环境运行：

```bash
composer test:orm-suite-native
```

原生套件先构建单程序，再移走应用源码、Composer 和 `web/dist`，使用最终程序重复三库测试。它证明的是 AOT 程序行为；普通 PHP 套件用于更快的开发反馈。

## 案例与源码对照

| 能力 | 案例入口 | 观察结果 |
| --- | --- | --- |
| 迁移状态、幂等执行、校验和拒绝 | `examples/orm-suite/Suite.php` | 首次 `pending → applied`，重复执行不增加 attempts，修改已记录迁移返回 `TYPE_MIGRATION_CHANGED` |
| Model 创建、字段类型、获取器/修改器 | `examples/orm-suite/Models.php`、`Suite.php` | 精确 `bigint/decimal` 保留字符串，隐藏字段不进入输出，事件顺序为 `saving → creating → created → saved` |
| 条件、搜索、投影、分页、游标、chunk | `Suite::execute()` | 不可变查询互不污染，普通分页、游标分页和有界分批均保留稳定排序 |
| HasOne、HasMany、BelongsTo、BelongsToMany | `Models.php`、`Suite.php` | `with/loadMissing` 批量加载，`withCount/withSum` 写入 computed，关系读取不会隐式发 SQL |
| 多对多写入 | `CoreExercise::relationHandles()` | `attach/detach/sync` 返回 attached/detached/updated，重复挂载更新 pivot，关系写入后清理旧投影 |
| 租户隔离与关系租户 | `CoreExercise::tenants()` | 缺少可信租户拒绝，创建自动补租户，中间表、关系目标和投影均拒绝跨租户数据 |
| 软删除、恢复、强制删除 | `Suite::execute()`、`MutationExercise::run()` | 默认隐藏已删除行，`onlyTrashed/withTrashed` 范围明确，restore/forceDelete 按模型声明执行 |
| 乐观锁与原子算术 | `Suite::race()`、`MutationExercise::integerArithmetic()` | 竞争只有一个版本成功，旧模型返回 `optimistic_conflict`，increment/decrement 在约束失败时整条回滚 |
| 批量新增和集合写入 | `MutationExercise::run()` | `insertMany` 只执行一条 INSERT，整批字段/约束失败回滚；集合 update/delete 不水合模型、不触发逐模型事件 |
| 聚合 | `MutationExercise::run()` | count/sum/avg/min/max 遵守租户、软删除和字段真实类型，精确文本列拒绝有损算术 |
| firstOrCreate | `CoreExercise::firstOrCreate()` | 只接受完整非空真实唯一键；并发竞争返回同一获胜者，软删除冲突不隐式复活 |
| upsert | `CoreExercise::upserts()` | PostgreSQL/SQLite 指定完整唯一目标，MySQL 使用 `upsertAnyUnique`；创建时间、更新时间和版本按驱动语义保留 |
| 事务、保存点、提交后回调 | `MutationExercise::callbacks()`、`Suite::execute()` | 回滚后模型失效，afterCommit 失败不撤销已提交数据，提交未知状态保留并要求新作用域对账 |
| 作用域、取消和连接所有权 | `CoreExercise::scopes()` | 子任务使用独立连接和事务，父取消/关闭/Deadline 唤醒子任务，作用域关闭后无活动租约 |
| 会话重置与退役 | `CoreExercise::sessions()` | SQL 错误、断连、重置失败的物理会话退役，禁止把失效连接透明重试给下一作用域 |
| Schema 冻结与结构演进 | `SchemaExercise.php`、`CatalogCreate.php`、`CatalogAlter.php` | 建表、增列、改名、删列和真实数据保留分别验收；失败迁移保存 failed 状态并用 recover 明确重试 |
| 观察器与取消 | `ArticleObserver.php`、`Suite::execute()` | 前置事件返回 false 时保存返回 cancelled，后置事件异常回滚事务并使模型失效 |
| 事务 Outbox | `examples/outbox`、`docs/guide/plugins/type-orm.md` | 业务行和 pending 意图同库提交，Relay 在事务外投递，凭据和重放窗口可对账 |

## 模型、关系与投影

案例中的 `User`、`Article`、`Tag` 和 `Details` 位于 `examples/orm-suite/Models.php`：

```php
use Type\Orm\ModelQuery;
use TypeApp\OrmSuite\Article;

$page = Article::query()
    ->with('author')
    ->with('tags')
    ->whereHas('author', static fn (ModelQuery $query): ModelQuery => $query->where('active', '=', true))
    ->withCount('tags')
    ->order('id')
    ->paginate(20, 20);

foreach ($page->items() as $article) {
    $row = $article->project(
        ['id', 'title', 'status'],
        ['author', 'tags'],
        ['tags_count'],
    );
}
```

`with` 和 `withCount` 在父列表确定后批量读取，`related()` 或 `computed()` 只读取已加载结果。若只拿到一部分模型，可使用 `Article::query()->loadMissing($models)` 补齐缺失关系；关系路径、父模型和中间表共享 `maxRows` 预算，超限报 `read_budget_exceeded`。

多对多写入必须使用已持久化父模型的句柄：

```php
$article = Article::query()->master()->findOrFail($articleId);
$article->relation('tags')->attach($tagId, ['weight' => 10]);
$receipt = $article->relation('tags')->sync([
    ['id' => $tagId, 'pivot' => ['weight' => 20]],
]);
```

句柄绑定创建它的作用域、租户和父模型。父模型删除、回滚或切换作用域后继续写入会被拒绝；写入完成后当前关系结果失效，需要重新 `with` 或 `loadMissing`。

## 批量写入、冲突写入与生命周期

模型批量新增、集合更新和冲突写入分别对应不同业务语义：

```php
$inserted = User::query()->insertMany([
    ['name' => '甲', 'active' => true, 'external_id' => '1001'],
    ['name' => '乙', 'active' => true, 'external_id' => '1002'],
]);

$changed = Article::query()
    ->where('status', '=', 'draft')
    ->update(['status' => 'published']);

$record = UniqueRecord::firstOrCreate(
    ['code' => 'sensor-001'],
    ['id' => 1001, 'alias' => '温度传感器'],
);
```

`insertMany` 校验整批后执行一条 INSERT，不水合模型、不触发逐模型事件；需要逐条副作用时改用事务中的 `create()`。集合 `update/delete/increment/decrement` 返回数据库影响行数，保留租户、软删除和版本边界，但不会刷新当前已有对象。`firstOrCreate` 只接受完整、非空、真实唯一身份，冲突无法确认时保留异常，不重跑外层业务。

PostgreSQL/SQLite 的显式冲突目标和 MySQL 的任意唯一键入口：

```php
$affected = UpsertRecord::upsert(
    [['id' => 10, 'code' => 'sensor-001', 'title' => '机房温度']],
    ['tenant_id', 'code'],
    ['title'],
);

$affected = UpsertRecord::upsertAnyUnique(
    [['id' => 10, 'code' => 'sensor-001', 'title' => '机房温度']],
    ['title'],
);
```

两者都要求整批字段和租户完整，禁止更新主键、租户、版本、软删除和受管时间。驱动影响行数保留原值：MySQL 新增通常为 1、实际更新为 2、无变化为 0；其他驱动按其真实返回处理。

## 三库迁移与结构演进

`examples/orm-suite/Schema.php` 用同一业务声明构造三库真实 DDL；`SchemaExercise.php` 再运行冻结 Schema 的创建、增列、改名、删列和失败恢复。独立迁移脚本的最小写法：

```php
return new class extends SchemaMigration {
    public function description(): string { return '创建文章'; }

    public function up(Schema $schema): void
    {
        $schema->create('articles', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title', 100);
            $table->integer('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('articles');
    }
};
```

文件名 `003_articles.php` 提供版本；构建期编译后生产程序不扫描迁移 PHP。运行：

```bash
php bin/typeapp migrate status
php bin/typeapp migrate pretend
php bin/typeapp migrate up
php bin/typeapp migrate rollback
```

`CatalogCreate.php → CatalogAlter.php → CatalogFailure.php → CatalogDependency.php` 是完整结构演进案例：首次建表、保留数据改名、受控删列、依赖顺序和失败恢复都在三库执行。已执行迁移内容改变会返回 `TYPE_MIGRATION_CHANGED`，不能编辑旧文件冒充新版本；新增变更必须使用更大的版本文件。

## 三库案例命令与边界

| 目标 | 命令 | 通过条件 |
| --- | --- | --- |
| PHP 三库消费者 | `composer test:orm-suite` | 三个驱动各自完成迁移、Model、关系、事务、冲突和会话检查 |
| 原生三库消费者 | `composer test:orm-suite-native` | 同一单程序在移除 PHP 源码和 Composer 后完成上述检查 |
| 定向模型 | `composer test:models`、`composer test:relations` | 模型字段、关系加载和关系写入通过 |
| 定向事务 | `composer test:transactions`、`composer test:outbox` | 回滚、UNKNOWN、afterCommit、Outbox 意图和投递边界通过 |
| 定向迁移 | `composer test:migrations`、`composer test:migrations-consumer` | AOT 注册表、三库真实 DDL、回滚和恢复通过 |

MySQL 的 DDL 不承诺事务回滚；PostgreSQL/SQLite 仍按真实事务和锁语义验收。SQLite 的 `:memory:` 只在同一物理连接内共享，迁移和业务必须使用文件库或同一连接。任何一个驱动的失败都不能用另一个驱动的通过替代。
