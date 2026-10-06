# PHP 类型属性模型与 CRUD

模型复用 type-orm 的模型查询和不可变 `Query`，运行时不依赖 core；HTTP 示例由应用组合 core、校验器和 ORM。`Connection` 只属于迁移、驱动验收及其他受限基础设施入口，不作为业务 Model 的参数。

普通模型操作不传连接：框架按当前 Swoole 执行作用域自动借还，普通查询默认读从、写入使用主库，通过 `master()` 明确主读；具有指定租户字段的模型由可信上下文约束归属。启动装配、静态 `create/find/search()`、事务与上下文边界见[Model 自动连接与主从路由](model-connections.md)。API、PHP 行为、AOT 和完整平台验收分别记录。

## 声明与生成

模型直接继承 `Type\Orm\Model`，CLI 与 HTTP 示例共用 `examples/model/Models.php`。`Table` Attribute 声明表、主键及生命周期字段；PHP 属性声明字段名、类型和可空性，`Column` 补充列名、精确数值类型、赋值权限及输出可见性。完整示例与迁移步骤见[模型指南](../guide/plugins/type-orm.md#models-relations-output)。

PHP 属性名不能遮蔽 `Model` 基类成员，包括私有成员；完整生产源码构建会检查此冲突。例如数据库列 `state` 应声明为 `#[Column(name: 'state')] public string $status;`，业务通过 `status` 和 `getStatus()` 使用它。映射只改变 PHP 名称，不改数据库列、Schema 快照或历史迁移。

`ModelCompiler::compile($sources)` 静态转换已声明的生产源码，返回完整代码、原文件清单及模型元数据。转换保留业务类名和方法，生成构造、水合、查询入口与属性钩子；类、列及生成成员冲突在构建时拒绝。PHP 开发先加载本代模型，AOT 编译同一结果，不使用运行时反射或源码解释补齐。模型 JSON、旧 `models` 配置及其解析入口已移除；旧键明确报告迁移错误，源码和生成器变化使旧缓存失效。

开发分支的生成模型复用不可变的 `ModelDefinition`、`ModelField` 和 `RelationDefinition`，避免查询与水合反复构造同一声明；重复调用这些对象的构造器会被拒绝。每个具体模型的映射由其 PHP 请求持有，Swoole 工作线程可在多个业务作用域间复用，线程请求结束时释放。映射不保存连接、租户身份、查询条件或模型实例；每次操作仍按当前作用域选路并验证归属。修改声明后重新生成、编译和重启程序，不在运行中改写映射。这项调整未包含 RC14，原生及平台验收按对应候选记录。

## 使用

```php
$user = User::create(['name' => '开发者', 'age' => 20, 'active' => true, 'secret' => '内部字段']);
$same = User::find($user->id);
$id = $user->id;

$partial = User::query()->master()->select(['name'])->findOrFail($id);
$partial->name = '已修改';
$partial->save();
$response = $partial->project(['id', 'name']);
```

`Model::create(array $values): Model` 严格校验字段、租户、版本和必填约束，成功返回已持久化模型；行为钩子取消创建时抛出 `model_creation_cancelled`。`Model::find(int|string $id): ?Model` 按当前模型范围查找，未找到返回 `null`，不会绕过租户或读写路由。查询的 `find/first` 未找到时返回 null，`get` 返回模型列表，空列表为 `[]`。`where`、`whereIn`、`select`、排序和条数限制均返回新查询。部分字段查询自动保留主键用于持久化，但响应仍由显式 `project()` 决定。

字段缺失不使用未初始化 PHP 属性表示。`loaded()` 检查加载状态，访问未加载字段报 `field_not_loaded`，未知字段报 `unknown_field`，可空字段的 null 是已加载值。`fill()` 先整批验证再修改；未知字段、不可赋值字段和持久化后的主键更改均拒绝。业务赋值默认严格类型，数据库水合单独完成整数和布尔转换。

`dirty()` 只包含提供且发生变化的字段；保存部分模型只写这些字段。正常保存返回 `created/updated/unchanged`，行为钩子取消时返回 `cancelled`，目标已不存在报 `not_found`。当前自动主键以数据库 identity/autoincrement 配置为前提。物理删除后模型失效，字段访问和输出均拒绝，调用者需重新查询；软删除模型可以按下文生命周期规则恢复。

`project()` 只输出明确选择且可见的字段；请求隐藏字段报 `hidden_field`。`toArray()` 只包含已加载且被声明为可见的字段。显式批量关系见[关系说明](relations.md)，事务与模型失效见[事务说明](transactions.md)。

## HTTP 示例与验证

`docs/build-config/type-model-http.json` 编译 `/users` 的 GET、POST、PATCH、DELETE 入口，查询参数 `id` 指定记录，`fields[]` 从输出白名单选择字段。列表最多返回 100 条；POST 返回 201，未找到返回 404，输入错误返回 422。该示例只监听回环地址，数据库结构由部署迁移或测试夹具准备。

模型操作要求入口先绑定本次工作的 `ExecutionScope`；各入口的接入范围及 MQTT 回调限制见[当前作用域](managed-tasks.md#当前作用域与应用绑定)。Swoole 提供协程等待，`type-runtime` 基于原生上下文、Channel 和 Timer 管理父子取消、Deadline 与真实收尾。SQL 由 PDO 及所选驱动执行；连接显式关闭或作用域收尾时归还租约，尚未结束的操作继续占用资源。

`tests/models.php` 在三种真实数据库验证生成访问器、水合、CRUD、部分字段、null、批量赋值、安全输出和失效状态。`tests/model-http.php` 通过真实 HTTP 与 MySQL/SQLite 验证相同业务路径。MySQL HTTP 测试创建独立随机数据库，退出后只清理该测试数据库；SQLite 使用独立临时文件。

PHP、原生构建与无业务源码部署分别记录，三库与各平台不能互相替代；实际检查与完整平台验收分别判断。

## 精确数值与 UTC 时间

增加 `decimal/bigint/datetime` 字段。`decimal` 的 precision/scale 明确声明，总精度上限 65、小数位上限 30；`bigint` 是最多 65 位的整数字符串。两者接收整数或十进制字符串，返回规范化字符串，拒绝浮点输入、科学记数法、溢出和非零小数截断；额外的零小数位可以无损消除。

`datetime` 接收带时区的 ISO 文本或 DateTimeInterface，内部保存独立的 DateTimeImmutable 并统一 UTC。生成 getter 返回不可变日期，输出使用固定六位微秒的 UTC 文本；没有时区、非法日期和超过微秒的文本均拒绝。同一时刻的不同偏移不会被误判为变更，外部 DateTime 的修改也不会污染模型。

MySQL 连接初始化 UTC 和严格模式，PostgreSQL 初始化 UTC 与 ISO DateStyle。主动改变原生会话设置属于当前租约的显式副作用，相关复用规则沿用 Connection 的约定。

读写精确字段时核对实际解析到的列，包括临时表遮蔽。数值列的总精度、整数位、小数位和文本容量必须容纳模型声明；MySQL/PG 时间列须保留微秒。SQLite 的精确数值和日期示例使用 TEXT，拒绝会触发数值亲和转换的列。不能证明满足声明的列类型明确拒绝；直接使用低层 Query 时，调用者自行遵守其数据类型契约。

`/invoices` 示例通过 POST、GET 和 PATCH 验证金额、超大 ID、UTC、null 与部分字段。三库 PHP 测试覆盖边界值、临时表、无损规范化、有损输入拒绝及写入前的列类型检查；HTTP 在 MySQL/SQLite 下使用真实持久化验证。原生独立消费者使用相同模型声明验证精确字段，具体入口以验收记录为准。

## 生命周期与业务查询

模型通过 `Table(softDelete: 'deleted_at')` 指向不可批量赋值的可空 datetime 字段。默认查询过滤已删除记录；`withTrashed/onlyTrashed/withoutTrashed` 显式选择范围，不支持软删除的模型拒绝这些操作。`delete/restore/forceDelete` 分别软删除、恢复、物理删除，物理删除后对象失效。

`ModelQuery::scope()` 和实例 `search()` 组合不可变查询。搜索器必须来自显式映射，未知搜索键被拒绝；静态 `Model::search()` 创建显式输入的筛选助手，在 `query()` 或 `paginatePage()` 时以 `unknown_search_field` 拒绝未声明的输入键，两者职责不同。`first()` 保留已有 offset；单表别名支持普通、无总数及游标分页，排序仍须满足真实主键和字段约束。

模型查询支持下述聚合、批量新增与冲突写入；表 Query 的冲突写入不自动带入模型约束。这些新入口属于当前 `main`，尚未包含 RC14。

`Table(createdAt: 'created_at', updatedAt: 'updated_at')` 指定受管时间属性，支持非空 integer（Unix 秒）和 datetime（UTC 微秒），默认不可赋值且不需要调用者提供。两者互不重叠，也不能与主键、租户、版本或软删除属性重叠。创建时统一取时，后续实际实例更新、软删除和恢复只修改更新时间；单条集合写入的所有行使用同一时刻。声明参与生成与映射身份，升级后须重新生成。该能力属于当前 `main`，未包含 RC14；完整声明、业务示例、列精度及升级步骤见[受管时间教程](../guide/database.md#受管创建和更新时间)。

时间在前置事件通过后采样，并与业务字段在同一 SQL 中写入，成功后才反映到对象。无变化的实例 `save()` 不推进时间或版本；`touch()` 仍只推进乐观锁版本。时间不是提交确认，也不保证跨节点单调；取消、失败、回滚及 UNKNOWN 保留原事务结果和模型失效规则。设备采样、会话有效期等业务时间不按名称自动托管。

`ModelBehavior` 是不可变声明：修改器在类型规范化前处理输入，获取器只处理读取和输出；持久化及关系匹配使用原始存储值，展示获取器不能改变写入身份。修改器、获取器各接收一个值。属性赋值、`set` 和批量 `fill` 均遵守赋值白名单；持久化主键和生命周期字段受保护。

## 模型统计与批量新增

```php
$visible = User::query()->where('active', '=', true);
$count = $visible->count();
$totalAge = $visible->sum('age');
$average = $visible->avg('age');
$minimum = $visible->min('age');
$maximum = $visible->max('age');
```

聚合使用模型属性名并沿用租户、软删除及读路由；`master()` 或同库事务保持主读。每次调用执行一条聚合 SQL，数值及时间字段另作实际存储类型校验，不水合模型、不预加载关系。它不是多个统计值的快照接口；需要一致快照时显式使用相应隔离级别的事务。

`sum/avg` 仅支持 integer、bigint、decimal；`min/max` 还支持 string 和 datetime。JSON、布尔等未定义排序语义的字段明确拒绝。四种字段聚合在空集或目标字段全部为 null 时返回 null；业务要将无数据当作零时显式使用 `?? 0`。`count()` 统计匹配行，空集为 0，与某字段是否为 null 无关。SUM/AVG 保留数据库标量类型，不套用单值的 precision/scale，也不强制转浮点。MIN/MAX 按字段类型回读，datetime 为 UTC 的不可变日期。展示获取器不参与数据库统计。

integer 聚合要求实际整型列；映射到文本、小数或浮点列时报 `integer_arithmetic_unsupported`，避免字典序比较和隐式转换。bigint/decimal 必须使用满足精度声明的真实数值列才能统计。SQLite 精确数值 TEXT 列以及其他数据库的数值文本列报 `exact_arithmetic_unsupported`；模型读取这些字段仍可无损进行。相同存储规则也用于 `increment/decrement` 与关系 `withSum`。LIMIT、分组和行锁不参与标量聚合，另建统计查询。

模型 integer 的 `increment/decrement` 还在赋值表达式中检查 SQLite 的整数类型及上下界，并约束 MySQL 无符号整型列不得超出 PHP 有符号整数范围；PostgreSQL 的整型溢出由数据库拒绝。这样避免 SQL 成功写入模型无法水合的值。null 沿用 SQL 的 null 语义；遇到非法整数或溢出时，整条集合写入及版本推进回滚，不通过追加 WHERE 跳过失败行。底层表 Query 没有模型字段声明，其通用数值语义不因此改变。

批量新增用 `Model::query()->insertMany($rows)`，每行都是以模型属性名为键的字段数组。空列表返回 0；非空列表只执行一条 INSERT，返回数据库影响数量。字段修改器、严格类型、赋值白名单、必填与实际存储校验都在写入前执行，不水合结果或猜测自动主键。

整批必须具有相同字段集合，键顺序可不同；可空字段需要显式 null 时应在每行一致提供。可信租户自动补入，显式提供时必须与上下文一致；声明的版本从 1 开始，软删除状态为 null，受管创建和更新时间共用同一次采样，调用者不能覆盖生命周期字段。新增不接受 where、排序、投影、关系、LIMIT 等读取状态，避免丢弃调用者条件。需要逐模型事件、生成主键或领域级联时，在 `Db::transaction()` 中逐条 `create()`。

写入复用集合操作的事务/保存点及原子存储检查，包含 SQLite 触发器 `RAISE(FAIL)` 的整批回滚。失败不会保留前面已插入的行；已有外层事务只回滚本次保存点。数据库参数上限和约束错误明确失败，不添加 `maxRows`、静默截断或自动拆成多次提交。提交结果未知仍须按业务标识对账。

## 模型集合写入

```php
$affected = User::query()->where('age', '<', 18)->update(['active' => false]);
$deleted = User::query()->where('active', '=', false)->delete();
```

两者自动选择主库，保留可信租户和软删除范围，以单条写入 SQL 处理集合，返回数据库报告的影响数量。没有额外的 `maxRows` 限制，不把目标行全部载入内存，也不在应用侧隐式分批。没有业务条件时须显式调用 `allowAll()`，自动租户或软删除条件不能代替调用者的全量写入意图。

分页和关系加载中的 `maxRows` 继续表示一个返回批次及其关联的内存预算，超限明确报错。它不参与集合写入的筛选，也不会把写入截断成前若干条。

`update()` 只接受普通可赋值字段；未知字段、空更新、主键、租户、版本、软删除和受管时间字段直接拒绝。`withBehavior()` 的修改器对每个输入值执行一次，再完成类型规范化、编码和物理存储校验。`delete()` 对声明软删除的模型写入一次 UTC 时间并推进版本；已删除行不重复处理，即使使用 `withTrashed()` 或 `onlyTrashed()`。无软删除声明时物理删除。

集合操作不水合每条模型、不触发逐模型观察器或领域级联。只有通过 `updatedAt` 声明的更新时间会自动维护，普通业务时间保持显式输入；软删除与更新时间共用一次采样。需要逐模型副作用时使用实例操作并显式管理事务。已有模型不会自动刷新；版本化集合更新、软删除及 `increment/decrement` 推进版本后，旧模型保存会发生乐观锁冲突。集合写入本身不代表逐条比较调用者先前读到的版本，需要时显式加入版本条件。

写入不接受显式投影、预加载、关系计算、别名、排序、LIMIT 或行锁，避免悄悄忽略读取状态。MySQL 要求实际目标是 InnoDB 且会话处于严格 SQL 模式；版本列必须是数据库中的非空整数列。版本合法性与递增在同一条 SQL 求值，SQLite 还检查实际值的整数类型，避免溢出转换成 REAL。

约束或版本错误保留 `DatabaseException` 和原始数据库原因链，事务或保存点回滚本次整条写入，不把其他约束错误误报为版本耗尽。提交无法确认时仍是 `UNKNOWN`，需对账，不能承诺已回滚或自动重试。无版本的同值更新保留驱动计数差异：MySQL 默认报告实际改变的行数，PostgreSQL/SQLite 报告匹配行数。

`increment/decrement` 无论是否声明版本字段都使用同一事务保护；触发器或末行约束失败时不能留下前面行的修改。已有外层事务时使用保存点回滚本次操作，外层仍可继续工作；这不增加匹配行数限制，也不把集合写入拆成多次提交。

## 实例事件与副作用

观察器实现 `ModelObserver::onEvent($event, $model)`。新增顺序为 saving → creating → SQL → created → saved；更新对应 updating/updated；删除、恢复与强制删除分别使用 deleting/deleted、restoring/restored、forceDeleting/forceDeleted。读取通知 retrieved。前置事件返回 false 取消写入，保存返回 `cancelled`；没有变化的保存返回 `unchanged` 且不触发写入事件。

带行为的模型写入在事务中执行，后置事件抛错会回滚并使模型失效。写入不能对同一对象或其克隆重入；取消时不持久化，内存中的规范化修改由调用者决定如何处理。事件不自动投递可靠消息，可靠外部效果使用事务 Outbox。

PHP 三库和 MySQL/SQLite HTTP 已覆盖软删除、恢复、强制删除、事件顺序、取消、获取器/修改器、不可变查询组合与事件失败回滚。原生验收按统一记录收尾。

## 乐观锁

用模型声明 `version` 指定不可赋值、非空的整数版本字段。新增从 1 开始；部分查询自动保留版本，写入把主键和旧版本放在同一条件中，成功后推进一次版本。直接赋值版本被拒绝，计数达到 PHP 整数上限时明确失败。

过期版本报 `optimistic_conflict`，记录不存在报 `not_found`；本地没有变更的保存返回 `unchanged`，不推进版本。MySQL 失败写入后使用当前读区分记录消失与旧版本，避免依赖可重复读中的旧快照。版本化写入在事务中执行，冲突、提交未知或回滚会使参与模型及克隆失效，需要重新查询。

软删除和恢复同样检查并推进版本，重复操作不推进；物理删除检查旧版本。底层 Query 是显式 SQL 入口，不自动代入模型版本，批量操作不冒充逐模型乐观锁，使用者必须自行声明对应版本条件或使用单模型路径。

## 唯一身份与冲突写入

这些入口属于当前 `main`，尚未包含 RC14。升级 `type-orm` 和 `type-build` 后重新生成模型；现有 `create`、事务与显式底层 Query 语义保持原样。可运行的新案例位于 `examples/orm-suite/CoreExercise.php` 的 `firstOrCreate()` 和 `upserts()`，由同一三库独立消费者执行，不替换账号创建和审计等包含外部效果的业务流程。

```php
// 数据库必须存在 NOT NULL 的 UNIQUE (tenant_id, code)。可信租户由作用域提供。
$record = UpsertRecord::firstOrCreate(['code' => 'sensor'], ['id' => 101, 'title' => '温度传感器']);

// PostgreSQL / SQLite 显式指定完整真实唯一目标。
$affected = UpsertRecord::upsert([
    ['id' => 102, 'code' => 'sensor', 'title' => '机房温度'],
], ['tenant_id', 'code'], ['title']);

// MySQL 明确接受任意唯一键；包括主键在内的所有可能冲突都须租户安全。
$affected = UpsertRecord::upsertAnyUnique([
    ['id' => 102, 'code' => 'sensor', 'title' => '机房温度'],
], ['title']);
```

`firstOrCreate(array $identity, array $values = [])` 先按字段类型规范化并固定身份，`values` 中同名字段不一致以 `identity_conflict` 在 SQL 前拒绝。租户模型自动补入可信租户字段；身份必须完整匹配真实非空唯一索引，不接受部分、表达式、MySQL 前缀或不可立即约束的索引。缺少证明以 `unsafe_unique_identity` 拒绝。MySQL 的实际表还必须支持事务及严格写入。

查找和创建始终使用主库，创建复用现有 `create` 路径及受管时间。竞争插入使用保存点，确认回滚后只处理服务端明确报告的目标唯一冲突；其他唯一键、外键和其他错误继续传播。`ConstraintException` 提供 `kind()`、`sqlState()`、`vendorCode()`；无法确定索引身份时不会猜测或恢复。SQLite 自有事务先取得写锁；MySQL 自有事务用当前读取获胜行，已有业务事务仍保留自己的快照。

软删除或快照不可见的获胜者以 `unique_conflict_not_visible` 失败，不返回、覆盖或复活该记录。调用方应退出原事务，在新事务中核对可见性后决定是否重试；框架不重跑外层业务。事务结果 `UNKNOWN` 原样传播，不再次创建，也不把未知结果当作已回滚。

`upsert` 与 `upsertAnyUnique` 同时提供静态 Model 和 `ModelQuery` 入口。完整批次先完成字段、类型、形状和租户规范化，普通字段必须可赋值；主键、可信租户、创建时间、更新时间、版本和软删除状态不能出现在 `updateFields`。指定冲突目标也不能被更新。每行须提供完整非空目标；两种接口严格按驱动能力区分，MySQL 不接受指定目标的 `upsert`。

整批只执行一条写入 SQL，不预读业务行、不触发实例事件、不推测自动主键。插入共用一次受管时间；冲突更新保留创建时间、统一推进更新时间及版本。版本耗尽由同一 SQL 失败并回滚完整批次。真实索引与存储检查在事务持有表元数据时完成；新 DDL 不会悄然更换当前写入的安全证明。

PostgreSQL/SQLite 的软删除冲突跳过，影响行数为零；MySQL 缺少等价更新过滤条件，软删除模型以 `unsafe_upsert_visibility` 拒绝。MySQL 检查全部可能命中的唯一索引；省略自动主键时不把生成主键当作外部冲突目标，其他不含租户、可空、表达式或前缀唯一键都会拒绝。不能用先查后写绕过这些限制。

影响行数保留驱动原值：PostgreSQL/SQLite 插入或执行冲突更新通常计一行；MySQL 新增计一、实际更新计二、未变化计零。受管版本或时间有变化时不属于未变化写入。同一批次重复冲突也遵循数据库真实语义，PostgreSQL 可能拒绝同一语句重复更新同一目标，框架不去重或拆批。约束失败与 `UNKNOWN` 保留原结果，不自动重试。
