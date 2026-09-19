# 分页、模型批次与流式导出

## 分页接口

```php
$query = $connection->table('people')->where('active', '=', true);
$page = $query->orderBy('age', 'DESC')->paginate(2, 20);
$page->items();     // 当前页的关联行数组。
$page->total();     // COUNT 查询结果。
$page->number();    // 1 开始的页码。
$page->perPage();
$page->lastPage();  // 空集合为 1。
$page->hasMore();

$page = $query->orderBy('age', 'DESC')->cursorPaginate(100, $after);
$page->items();
$page->next();      // 下一页位置；末页和空结果为 null。
```

分页大小限制 1 至 1000 行；非法页码、整数偏移溢出、已有 LIMIT 等状态明确拒绝。普通分页执行 COUNT 与当前页查询，超过末页返回空列表。两次查询是否同一快照由调用者的数据库事务决定；分页方法不会暗中改变隔离级别。

两类分页保留现有不可变条件及排序，并验证真实表结构。表必须有一个非空单列主键，默认 `id`；自定义主键通过最后一个 `primaryKey` 参数指定。如果调用者未按主键排序，自动追加主键升序，避免重复业务排序值在页间遗漏或重读。既有主键降序保持不变。

游标使用字典序条件和绑定参数，支持最多八列混合升降序。读取额外一行判断是否有后续，不额外执行 COUNT。排序列即使没有被投影也可以生成游标，内部补充字段不会出现在返回行中。`__type_cursor_` 为分页保留前缀，冲突的实际列或投影会拒绝。

当前确定分页只接受单表查询，别名、Join、分组、聚合和已有 LIMIT 明确拒绝；不把无法证明的唯一顺序默认为稳定。排序必须使用真实、非空、非浮点、非 JSON/二进制列，拒绝 NULL 和浮点等不明确排序边界。复合主键、表达式或投影别名排序不进入该接口。

游标最多 8192 字节，绑定查询 SQL、筛选值、投影、排序和数据库身份的指纹。不同筛选、排序或身份不能复用同一游标。位置值保留整数、布尔、字符串等数据库返回类型；文本需有效 UTF-8，精确数值不会强制转换为浮点。

游标是可读的位置数据，没有加密或签名，不能当作权限或租户凭据。业务每次请求都必须重新加入授权筛选；可对发给外部的游标另行签名。修改已读取行的排序值仍可能造成位置变化，新增在当前边界之后的记录可以在后页出现。删除前页记录和插入前页较小主键不会产生 OFFSET 漂移；跨请求游标不承诺全程数据库快照。

## 有界模型与关系批次

```php
$people = User::query($connection)->select(['name'])->with('articles', $articles);
$page = $people->paginate(2, 20, 1000);
$next = $people->cursorPaginate(100, $cursor, 1000);

$processed = $people->chunk(100, static function (array $models): bool {
    foreach ($models as $model) {
        // 该批次的 articles 已经加载；写入导出目标，不积累全部结果。
        exportPerson($model->project(['name'], ['articles' => ['title']]));
    }
    return true; // false 提前结束。
}, 1000);
```

模型查询继续使用生成的工厂、字段精度校验、软删除筛选、模型行为和已有关系映射。`paginate/cursorPaginate/chunk` 的最后一个 `maxRows` 参数是当前页或批次的共享读取预算，默认 10000、最大 100000 行。父模型、嵌套关联、不同关系和多对多中间表一起消耗预算，不为每个子关系重新生成预算。

关联查询只读取剩余预算加一行，超限时在继续水合前报错，不截断成看似完整的关系。每批结束释放本批引用，下一批重新计算预算；不把全部父记录或一对多目标加载到内存。普通 `get()` 保持原有完整结果语义；大结果调用者选择分页或分批入口。

原有自定义 `Relation::load` 签名保持不变。有界路径调用新增 `loadBounded`；自定义关系未实现时明确报 `bounded_relation_unsupported`，不能悄悄走无界加载。框架内三种单外键关系和多对多关系已实现共享预算。

`Query::chunk(size, callback, primaryKey)` 同样按游标逐批返回普通行。模型 `chunk` 拒绝活动事务，避免事务用于回滚失效的模型登记无限保留已读取对象。需要关系预加载时使用模型批次；需要固定数据库读取快照、逐行输出时使用下方独占流并自行安排独立关系读取连接。回调自行保留所有行、模型或输出缓冲会突破消费者自身的内存预算。

## 显式关闭的独占结果流

```php
$stream = $connection->table('people')->select(['id', 'name'])->orderBy('id')->stream(100, 1048576);
try {
    while (($row = $stream->next()) !== null) {
        exportRow($row);
    }
} finally {
    $stream->close();
}

// each 保证正常、异常和回调返回 false 时都关闭。
$count = $connection->table('people')->orderBy('id')->stream()->each(
    static fn (array $row): bool => exportRow($row)
);
```

`Connection::stream(sql, parameters, batchSize, maxRowBytes)` 也支持可信的单条 SELECT，参数经过原有 PDO 类型绑定。只接受事务外的连接；不借助 PHP 生成器、析构时机或数据库全量 `fetchAll` 冒充流式读取。

| 驱动 | 读取策略 | 关闭行为 |
| --- | --- | --- |
| MySQL | PDO 非缓冲查询，逐行 fetch | 关闭结果并恢复原缓冲设置 |
| PostgreSQL | 流拥有独立事务，DECLARE NO SCROLL 服务端游标，FETCH 固定批次 | CLOSE 游标并结束流自己的事务 |
| SQLite | 保持同一 PDOStatement，逐行 fetch | 关闭结果游标 |

批次限制 1 至 1000 行，单行上限 1 字节至 16 MiB，默认 1 MiB。客户端内存与批次大小、实际行宽相关，不随总行数增长；PDO 必须先接收至少一行才能检查其长度，PostgreSQL 的一个 FETCH 批次也由 libpq 接收。要限制极大单行造成的瞬时分配，应同时限制投影和数据库列长度，或降低 FETCH 批次。隐式 LOB 资源拒绝，不自动整块读入。

流关闭前，同一 PdoSession 拒绝其他查询、写入、事务和新流；连接池中的租约继续占用容量。`next` 的读取操作复用 ResourceLease 的持有逻辑和执行者检查，作用域取消或超时后不会返回新行。关闭流会释放数据库结果，连接租约本身仍由 Connection/ExecutionScope 管理；关闭连接或结束作用域会关闭未显式关闭的结果并归还租约。收尾失败退役会话，不交给下一次借用。

`each` 在异常及提前中断中通过 finally 关闭；手动 `next` 配合 try/finally 使用。已有同步 PDO 阻塞与数据库网络超时策略继续适用，不承诺 PHP 作用域截止时间可以强制中断正在原生扩展内执行的阻塞调用。导出很慢时，MySQL 非缓冲结果和 PostgreSQL 游标会较长时间持有服务端资源，应使用独立导出连接及数据库超时。

## 验证与后续集中原生验收

现有测试环境变量 `TYPE_MYSQL_*`、`TYPE_PGSQL_*` 指向隔离测试服务。执行：

```bash
php tests/pagination.php --php
# 或只验证指定真实驱动：
php tests/pagination.php --php pgsql

# 集中原生验收时使用完全相同的应用与断言：
php vendor/bin/type docs/build-config/type-pagination.json
php tests/pagination.php build/pagination/type-app
```

测试使用连接专属临时表。验证普通空页/末页、非法参数、混合排序和同值主键收尾、投影隐藏字段、跨查询游标拒绝、前页插入删除、模型分页、嵌套/多对多共享预算、回调停止、独占冲突、池容量、异常关闭、作用域关闭、超大单行以及重新借用。

新增 Page/CursorPage 承担返回值，CursorToken 封装位置校验，RowStream 持有显式关闭语义，ReadBudget 让已有关系加载器共享上限。Query/ModelQuery 仍是调用入口，Connection/PdoSession 仍负责数据库与租约；没有新增独立连接池或未编译的生产回退。根 Composer/CI 未修改，主仓集成时接入上述 PHP 和原生命令即可。
