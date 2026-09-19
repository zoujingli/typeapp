# 显式关系与批量预加载

关系加载复用当前 Connection 与 ModelQuery，返回当前查询创建的模型，不使用全局身份表或隐式懒加载。

```php
$articles = Relation::hasMany(
    static fn (Connection $connection): ModelQuery => Article::query($connection)->select(['title']),
    'user_id',
);
$users = User::query($connection)->select(['name'])->with('articles', $articles)->get();
$output = $users[0]->project(['name'], ['articles' => ['title']]);
```

`Relation::belongsTo` 使用当前模型的外键匹配目标键；`hasOne/hasMany` 使用当前模型键匹配目标外键。各工厂可设置键名和批量大小，默认每批 250 个不同键，上限 1000。空父集合和全部外键为 null 时不查询目标表。

预加载补齐两侧匹配键，保留目标查询的筛选、字段选择与排序，最后追加目标主键以保证稳定顺序。单条关系未匹配返回 null，多条关系未匹配返回 `[]`；单条关系匹配多条记录报 `non_unique_relation`，应由真实唯一约束保证基数。

关联键使用规范化后的整数或字符串。若数据库排序规则匹配到与模型键值不同的键，明确报 `relation_key_comparison`，要求应用统一键值及排序规则。目标查询带全局 LIMIT 时拒绝批量预加载，避免将总条数伪装为每个父模型的条数。

`related()` 只读取已加载结果；未加载报 `relation_not_loaded`。`relationLoaded()` 检查加载状态。输出必须通过 `project($fields, $relations)` 显式选择属性和关系字段，默认 `toArray/jsonSerialize` 只输出属性；隐藏字段的规则同样适用于关联模型。

Connection 的统计包含读取尝试数、写入尝试数和最近 128 次读取的参数数量，记录有界且不包含 SQL 或参数值。示例以 3 个父键、每批 2 个键验证 1 次父查询和每个关系 2 次目标查询；属性访问与输出不会增加读取次数。

HTTP 示例可使用 `/users?with[]=articles&with[]=profile` 读取用户、文章和简介。关系选择只用于 GET，其余方法明确拒绝该参数。数据库完整性或映射错误按服务器异常处理，不冒充客户端校验失败。

三库 PHP 路径、真实 MySQL/SQLite HTTP、关联变更、空外键、单条基数错误和查询预算已有检查；相同应用入口已接入原生 CI，待集中验收后关闭任务。

## 多对多与中间表

提供 `Relation::belongsToMany()`，声明目标查询、中间表、两侧键、可写可输出的中间表字段和批量大小。中间表需要两侧外键、关系对的唯一约束，键类型和比较规则保持一致；MySQL 使用 InnoDB。

`attach()` 返回是否新增关系，重复挂载不新增记录，显式提供的中间表字段可以更新。`detach()` 返回是否移除关系，不存在时返回 false。`sync()` 接收 `[{id, pivot}]` 列表，避免 PHP 数组键转换 ID；先校验全部目标，再原子移除、增加和更新，空列表只移除当前父记录的关系。返回的 `updated` 是对已有关系提供字段的记录数，不冒充各驱动的实际变化行数。

写入在事务中锁定父记录；MySQL/PostgreSQL 使用行锁，SQLite 的独立操作使用 IMMEDIATE 事务。已有外层事务时通过 savepoint 组合；SQLite 外层延迟事务升级冲突会明确失败，不隐式重试。成功写入清除该父模型的旧关系缓存，失败遵循模型事务失效规则。

预加载先批量读取中间表，再按不同目标键批量读取模型。每个关联结果保留自己的 `pivot()` 字段，默认属性输出不混入中间表数据。目标查询过滤掉的记录不进入结果，重复关系、单条目标歧义及键规范化不一致均拒绝。

`/article-tags?id=文章ID` 的 GET、POST、PATCH、DELETE 示例覆盖读取、挂载、整组同步和解除，并显式输出标签字段及 `pivot`。三库 PHP 测试使用真实唯一约束和外键，两个独立进程并发挂载最终只新增一条关系；MySQL/SQLite HTTP 路径也已验证。原生结果按统一验收记录跟进。
