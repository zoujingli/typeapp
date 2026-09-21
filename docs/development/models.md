# PHP 类型属性模型与 CRUD

模型复用 type-orm 的模型查询和不可变 `Query`，运行时不依赖 core；HTTP 示例由应用组合 core、校验器和 ORM。`Connection` 只属于迁移、驱动验收及其他受限基础设施入口，不作为业务 Model 的参数。

普通模型操作不传连接：框架按当前 Swoole 执行作用域自动借还，普通查询默认读从、写入使用主库，通过 `master()` 明确主读；具有指定租户字段的模型由可信上下文约束归属。启动装配、静态 `create/find/search()`、事务与上下文边界见[Model 自动连接与主从路由](model-connections.md)。API、PHP 行为、AOT 和完整平台验收分别记录。

## 声明与生成

模型直接继承 `Type\Orm\Model`，CLI 与 HTTP 示例共用 `examples/model/Models.php`。`Table` Attribute 声明表、主键及生命周期字段；PHP 属性声明字段名、类型和可空性，`Column` 补充列名、精确数值类型、赋值权限及输出可见性。完整示例与迁移步骤见[模型指南](../guide/plugins/type-orm.md#models-relations-output)。

`ModelCompiler::compile($sources)` 静态转换已声明的生产源码，返回完整代码、原文件清单及模型元数据。转换保留业务类名和方法，生成构造、水合、查询入口与属性钩子；类、列及生成成员冲突在构建时拒绝。PHP 开发先加载本代模型，AOT 编译同一结果，不使用运行时反射或源码解释补齐。模型 JSON、旧 `models` 配置及其解析入口已移除；旧键明确报告迁移错误，源码和生成器变化使旧缓存失效。

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

`dirty()` 只包含提供且发生变化的字段；保存部分模型只写这些字段。`save()` 返回 `created/updated/unchanged`，目标已不存在报 `not_found`。当前自动主键以数据库 identity/autoincrement 配置为前提。删除后模型失效，字段访问和输出均拒绝，调用者需重新查询。

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

`ModelQuery::scope()` 和实例 `search()` 组合不可变查询。搜索器必须来自显式映射，未知搜索键被拒绝；静态 `Model::search()` 创建显式输入的筛选助手，在 `query()` 或 `paginatePage()` 时以 `unknown_search_field` 拒绝未声明的输入键，两者职责不同。批量算术使用 `ModelQuery::increment/decrement`，遵守模型租户范围并在主库执行；受控的底层 Query 批量写入不触发逐模型事件或行为转换，已经加载的对象需要显式重新查询。

`ModelBehavior` 是不可变声明：修改器在类型规范化前处理输入，获取器只处理读取和输出；持久化及关系匹配使用原始存储值，展示获取器不能改变写入身份。修改器、获取器各接收一个值。属性赋值、`set` 和批量 `fill` 均遵守赋值白名单；持久化主键和生命周期字段受保护。

观察器实现 `ModelObserver::onEvent($event, $model)`。新增顺序为 saving → creating → SQL → created → saved；更新对应 updating/updated；删除、恢复与强制删除分别使用 deleting/deleted、restoring/restored、forceDeleting/forceDeleted。读取通知 retrieved。前置事件返回 false 取消写入，保存返回 `cancelled`；没有变化的保存返回 `unchanged` 且不触发写入事件。

带行为的模型写入在事务中执行，后置事件抛错会回滚并使模型失效。写入不能对同一对象或其克隆重入；取消时不持久化，内存中的规范化修改由调用者决定如何处理。事件不自动投递可靠消息，可靠外部效果使用事务 Outbox。

PHP 三库和 MySQL/SQLite HTTP 已覆盖软删除、恢复、强制删除、事件顺序、取消、获取器/修改器、不可变查询组合与事件失败回滚。原生验收按统一记录收尾。

## 乐观锁

用模型声明 `version` 指定不可赋值、非空的整数版本字段。新增从 1 开始；部分查询自动保留版本，写入把主键和旧版本放在同一条件中，成功后推进一次版本。直接赋值版本被拒绝，计数达到 PHP 整数上限时明确失败。

过期版本报 `optimistic_conflict`，记录不存在报 `not_found`；本地没有变更的保存返回 `unchanged`，不推进版本。MySQL 失败写入后使用当前读区分记录消失与旧版本，避免依赖可重复读中的旧快照。版本化写入在事务中执行，冲突、提交未知或回滚会使参与模型及克隆失效，需要重新查询。

软删除和恢复同样检查并推进版本，重复操作不推进；物理删除检查旧版本。底层 Query 是显式 SQL 入口，不自动代入模型版本，批量操作不冒充逐模型乐观锁，使用者必须自行声明对应版本条件或使用单模型路径。
