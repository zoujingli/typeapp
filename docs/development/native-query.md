# 不可变三库查询

`Connection::table()` 返回不可变 `Query`，沿用已有连接、执行作用域、事务和租约。没有新增连接池，也不加载解释执行的查询代码。

## 使用入口

```php
use Type\Orm\Conditions;

$people = $connection->table('people');
$active = $people->where('status', '=', 'active');
// $people 仍然表示所有人员；必须保留每次组合返回的对象。
$report = $connection->table('people', 'p')
    ->join('teams', 'p.team_id', '=', 't.id', 't')
    ->whereGroup(static fn (Conditions $where): Conditions =>
        $where->whereNull('p.deleted_at')->orWhere('p.score', '>=', 90))
    ->select(['team' => 't.name'])
    ->selectAggregate('SUM', 'p.score', 'total')
    ->groupBy(['t.name'])
    ->havingAggregate('SUM', 'p.score', '>', 100.5)
    ->orderByAllowed($sort, ['total' => 'total', 'team' => 't.name'], 'DESC')
    ->limit(20)
    ->get();
```

普通标识符参数来自应用源码或已知映射；标识符只能由字母、下划线、数字组成，可以用点限定 schema、表和列，不能把表达式、函数或用户原始输入拼进去。排序输入通过 `orderByAllowed` 映射。其他动态表名、筛选列和投影同样由应用明确映射到授权范围；语法有效并不代表业务授权。

所有值通过参数绑定。整数、布尔和 NULL 保留 PDO 参数类型；浮点比较补充 PostgreSQL 的 NUMERIC 或 SQLite 的 REAL 转换，避免聚合表达式把浮点值误推断为整数或文本。输入需要精确十进制时由上层领域类型明确编码，不把二进制浮点当作任意精度金额。

SQLite 标识符使用反引号，避免其双引号兼容模式将不存在的列当作字符串常量；未知列会和其他数据库一样明确报错。

## 查询与返回规则

- `where`、`orWhere`、`whereGroup`、`whereNull`、`whereIn`、`whereNotIn`、`whereJson` 均返回新对象。条件按调用顺序显式括号组合；需要其他结合关系时使用分组回调并返回 `Conditions`。
- NULL 相等与不等生成 `IS NULL` 与 `IS NOT NULL`，其他 NULL 比较拒绝。空 IN 恒假，空 NOT IN 恒真；IN 集合出现 NULL 时拒绝，要求调用者显式组合 NULL 条件。
- `select` 支持列列表和“返回别名 => 源列”映射；`join` 支持 INNER、LEFT，其他类型明确拒绝。
- `selectAggregate`、`groupBy` 和 `havingAggregate` 组成分组报表。支持 COUNT、SUM、AVG、MIN、MAX；任意函数和原生表达式不进入标识符接口。
- `get` 返回关联行列表，空结果为 `[]`；`first` 返回一行或 `null`。`aggregate` 返回数据库标量，空 COUNT 为零，空 SUM 为 NULL；精确数值可能由 PDO 返回字符串，不强制转换成可能丢失精度的浮点。
- 标量 `aggregate` 不接受分组、HAVING 或分页；分页使用 `limit(条数, 偏移)`，调用者需要稳定分页时自行提供唯一排序键。
- 查询对象仍绑定原有连接租约。归还连接、关闭作用域后，已有查询不能再执行。

## 批量写入和全表意图

```php
$people->insert(['id' => 1, 'name' => '开发者']);
$people->insertMany([['id' => 2, 'name' => '设计师'], ['id' => 3, 'name' => '测试员']]);
$people->whereIn('id', [2, 3])->update(['status' => 'active']);
$people->updateMany('id', [['id' => 2, 'score' => 80], ['id' => 3, 'score' => 90]]);
$people->where('id', '=', 3)->delete();
$people->allowAll()->update(['archived' => true]);
```

批次每行必须包含相同列，键顺序可以不同；空批次返回零。`updateMany` 使用单条 CASE UPDATE，原有筛选条件继续生效；匹配键仅接受非 NULL 的整数或字符串，不允许重复匹配键。调用者选择能唯一标识目标行的实际数据库键。不同表示但被数据库排序规则视为相同的键仍由数据库实际匹配语义决定。

批量方法执行一条 SQL，不暗中分批或逐条重试。事务原子性以实际表能力为准，MySQL 使用 InnoDB 等事务表；超过数据库参数、数据包或表达式限制时明确报错。大批次由应用显式切分，需要整体原子性时在同一 `Connection::transaction` 中执行。

没有筛选或已知恒真的更新、删除必须调用 `allowAll()`。恒真空 NOT IN 以及被 OR 组合成恒真的分组不能绕过检查。允许全表的对象不会修改原始对象。写入遇到别名、Join、投影、分组、HAVING、排序或分页会拒绝，避免静默丢弃状态而扩大写入范围；新增与 upsert 也不接受筛选条件。

## JSON、upsert 与返回能力

通过 `$query->capabilities()` 读取实际驱动、服务版本、支持项和影响行数规则。能力不足时在执行前抛出 `DatabaseException`。该方言只声明 MySQL、PostgreSQL、SQLite，不把 MariaDB 自动当作已验证 MySQL。

| 能力 | MySQL | PostgreSQL | SQLite |
| --- | --- | --- | --- |
| JSON 标量等值 | JSON_EXTRACT + JSON 类型比较 | JSONB 路径与类型比较 | JSON 类型检查 + JSON 提取 |
| 指定冲突键 `upsert` | 明确拒绝 | ON CONFLICT 指定目标 | ON CONFLICT 指定目标 |
| 任意唯一键 `upsertAnyUnique` | 明确支持 | 明确拒绝 | 明确拒绝 |
| `insertReturning` | 明确拒绝 | INSERT RETURNING | INSERT RETURNING |
| UPDATE 影响行数 | 实际变化行数 | 匹配行数 | 匹配行数 |
| upsert 单行影响计数 | 新增 1、变化更新 2、未变化 0 | 新增或更新 1 | 新增或更新 1 |

```php
$gold = $people->whereJson('profile', ['membership', 'tier'], 'gold')->get();
$firstCode = $people->whereJson('profile', ['items', 0, 'code'], 'item-1')->get();

// PostgreSQL / SQLite：目标必须对应真实、非部分的唯一约束。
$people->upsert($rows, ['id'], ['name', 'score']);
// MySQL：调用者明确接受任意唯一键冲突，不能传入一个被忽略的冲突目标。
$people->upsertAnyUnique($rows, ['name', 'score']);
// 支持的数据库返回真实 INSERT RETURNING 结果，不以 INSERT + SELECT 冒充。
$inserted = $people->insertReturning($rows, ['id', 'name']);
```

JSON 路径包含 1 至 16 个简单字段名或非负数组下标，路径片段也通过参数绑定。比较只接受字符串、有限数字、布尔和 NULL；JSON null 不匹配缺失路径，数字 1 不匹配字符串 "1"，布尔 true 不匹配数字 1。对象/数组包含、任意 JSONPath、JSON 更新暂不进入该共同接口，会明确拒绝或不存在对应接口。SQLite 的原始文本字段仍需保存合法 JSON，非法 JSON 由数据库明确报错。

JSON 能力最低声明 MySQL 8.0.19、PostgreSQL 9.5、SQLite 3.38；指定冲突键最低为 PostgreSQL 9.5 / SQLite 3.24；SQLite RETURNING 最低 3.35。构建工具链中的真实验证版本仍以本节下方的矩阵为准，版本门槛不是对所有旧版本、编译选项的测试声明。

upsert 不忽略其他唯一约束错误。影响行数是数据库计数，不能视为统一的“逻辑业务行数”；同批重复冲突、数据库排序规则与触发器仍采用所选数据库的原生语义，调用者应保证每批真实冲突键唯一。RETURNING 行序和没有 ORDER BY 的查询行序不作保证。

## 原生 SQL 和租约

可信、受管 SQL 继续使用 `Connection::query/execute`；调用者承担不会污染会话的约定。DDL 或未知会话副作用使用 `raw`；需要结果集时使用新增的 `rawQuery`。两者都在执行前退役租约，归还时销毁，即使只是成功执行一条 SELECT 也不重新进入空闲池。原生 SQL 自行明确数据库转换语义，例如 SQLite 原生表达式中的浮点参数需要适当 CAST；查询组合已处理其支持的比较语义。

## 原生验证

Linux/macOS 原生三库控制器可使用 `php tests/native-database-failures.php build "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" query mysql`，最后一个驱动可省略以顺序验证三库。`query` 分组包括现有查询和 20000 行分页/导出测试；不同驱动分别持有和关闭自己的真实实例，可并行执行。构建仍通过 `tests/build-scenario.php` 的独立消费者，完整安装三驱动、ORM/runtime及查询场景需要的validate，避免借用未声明的生产包。

在已配置的 Linux 工具链中，设置现有 TYPE_MYSQL_*、TYPE_PGSQL_* 测试环境变量后运行：

```bash
php vendor/bin/type docs/build-config/type-query.json
php tests/query.php build/query/type-app
# 只运行指定真实驱动时：
php tests/query.php build/query/type-app pgsql
```

新增文件各自承担必要职责：`Query` 拥有查询执行与写入意图，`Conditions` 拥有不可变分组，内部 `SqlDialect` 集中三库语法和能力；物联中心应用是编译入口，验证器只启动 ELF 并核对结果。`Connection/PdoSession` 最小扩展驱动版本查询、类型绑定与原生结果集入口。现有三驱动独立消费验证也会重新编译该完整 ORM，检查仍只依赖所选驱动。

本任务没有改动根 Composer 脚本和 CI 工作流。主仓集成时增加构建与验证命令，并将 `build/query/type-app` 及构建身份报告加入原生产物归档即可。
