# 显式输入的 `_query` 与 `_vali`

这两个快捷函数只接受显式的查询和输入，不读取全局请求、不从字符串猜测数据库表或类，也不转发任意方法。字段筛选与校验规则均由业务代码明确声明。

## 查询筛选

```php
$query = $connection->table('users')->where('tenant_id', '=', $tenantId);
$result = _query($query, ['status' => 0, 'keyword' => '中文', 'age' => [18, 40]])
    ->equal('status')
    ->like(['keyword' => 'name'])
    ->between('age')
    ->query()
    ->orderBy('id')
    ->get();
```

`_query(Query|ModelQuery $query, array $input = []): QueryHelper` 保留传入查询的连接、模型和既有范围约束。助手及查询都采用不可变链式行为；忽略返回值不会改变旧查询。`query()` 返回原有 `Query|ModelQuery` 公共执行接口，所有租约关闭、模型水合、软删除和字段类型规则仍由 ORM 负责。

四种筛选只处理开发者传入的字段白名单：

| 方法 | 显式输入 | 语义 |
| --- | --- | --- |
| `equal('status,id')` | 标量或 null | 绑定等值；null 生成 `IS NULL`，0/false 是有效值 |
| `like(['keyword' => 'name'])` | 字符串 | 绑定 `%输入%`；`%`、`_`、大小写和排序规则沿用数据库 LIKE 语义 |
| `in('id')` | 标量列表或逗号分隔文本 | 最多1000个非null、非空值；文本分段 trim；空列表匹配零行 |
| `between('age')` | `[下界, 上界]` | 两个绑定的 `>=` / `<=` AND 条件，包含边界，不猜测日期或自动交换上下界 |

字段参数支持逗号分隔同名字段、字段列表或 `输入名 => 数据库列/模型字段` 映射。普通 Query 可用 `['name' => 'u.name']` 显式指定表别名；ModelQuery 必须用模型字段名，数据库列映射继续由模型定义完成。白名单必须来自可信业务代码，不能直接传入请求中的 `column`、`fields`、`order` 或表达式。

包装 ModelQuery（包括 `Model::search()`）时，`query()` 和 `paginatePage()` 会以 `unknown_search_field` 拒绝未声明的输入键；筛选方法登记字段输入名，`order()` 登记排序与方向输入名，`page/page_size` 为固定分页键。直接包装 Query 时仍只选取白名单输入。助手不把请求中的任意键自动变成筛选条件。

缺失输入和空字符串统一跳过筛选；只在 `equal()` 中接受单个 null。LIKE、IN、区间的 null 明确拒绝，避免 SQL 三值逻辑或不完整区间产生歧义。字段声明即使对应输入缺失也要通过标识符检查，拒绝 `|`、`&`、原生 SQL、排序表达式及动态操作符。选中值始终传入既有绑定接口；不会拼接到 SQL 文本。

字符串值最多16 KiB、单次白名单最多100项。ModelQuery 不执行额外的隐式类型转换：整数模型字段对应的 IN 输入应为 `[1, 2]`，而不是期待助手把 `"1,2"` 猜成整数；外部文本先经显式 `Field::integer()->cast()` 等规则转换。

## 白名单排序

排序入口采用显式方法和字段白名单，不解析客户端传入的 SQL 表达式，也不提供任意方法转发。

```php
$page = _query($query, ['sort' => 'oldest', 'direction' => 'DESC', 'page' => '2'])
    ->order(['oldest' => 'age', 'name' => 'name', 'id' => 'id'], ['id' => 'ASC'])
    ->paginatePage();
```

`order(array $allowed, array $defaults = [], string $fieldKey = 'sort', string $directionKey = 'direction'): QueryHelper` 返回新助手。`allowed` 是开发者提供的 `公开名 => 数据库列/模型字段` 映射，或同名字段列表；普通 Query 可映射到 `u.age`，ModelQuery 只接受自己的字段名并通过模型映射取得数据库列。公开名最多128字节，白名单最多100项；输入名也必须是明确标识符。

`defaults` 是按优先级排列的 `公开名 => ASC/DESC` 映射，最多八项。默认方向及所有字段标识符先验证，不因客户端选择了其他字段就忽略错误配置；两个公开名在默认排序中指向同一列时明确拒绝。它不是传给客户端自由修改的 SQL 片段。

| 显式输入 | 行为 |
| --- | --- |
| sort、direction 均缺失或为空字符串 | 采用 defaults；defaults 为空时不追加排序 |
| sort 为允许的单个公开名，direction 缺失或空串 | 选择该字段并使用 ASC，不继承未选择的默认方向 |
| direction 为 ASC/DESC 的任意大小写 | 规范化方向后调用底层公开排序接口 |
| 未选择 sort 却单独提供非空 direction | 拒绝，不静默忽略方向 |
| sort 为未知名、SQL 表达式、逗号列表、数组、null、bool 或整数 | 拒绝，不能借 null 恢复默认值 |
| direction 为数组、null、bool、带空格文本或其他表达式 | 拒绝，不 trim 或解析 SQL |

客户端只选择一个白名单字段，多列默认顺序由业务明确配置。需要自定义输入键时显式调用 `order($allowed, $defaults, 'orderBy', 'orderType')`；不读取全局请求或同时猜测多个别名。

`Query::orderByIfAbsent()` 与 `ModelQuery::orderByIfAbsent()` 先调用原有标识符、字段映射和方向验证，再只追加尚未出现的列。重复选中已经固定的业务列时，最早的方向和优先级保持不变，不重排原查询；同一个助手反复追加也不会制造新的重复列。现有 `orderBy()` 保持原契约，调用者自己重复 `orderBy()` 造成的非法分页不会被助手掩盖。

分页继续由底层确认真实单列主键，缺失时追加主键形成稳定顺序，已有主键不重复追加。它仍拒绝不支持的别名/Join/聚合/已有 LIMIT、重复排序、可空/浮点/JSON/二进制排序列或超过八列的分页；白名单不扩大这些能力。默认列表包含八个非主键字段时，追加主键会超过上限并明确失败，不能静默丢掉收尾字段。

已有固定排序永远优先。如果先把唯一 id 固定在第一位，再追加年龄，年龄自然无法改变主顺序；标准应用因此先调用白名单 order，再让分页补 id。`GET /users?sort=age&direction=DESC` 展示年龄主排序与 id 收尾；只允许 id/name/age，未知名、非法方向和只传方向在控制器经 `_vali()` 返回校验错误。`name/age` 筛选、原租户条件、软删除、模型水合和连接所有权均保持原有语义。

## 有界分页

```php
$page = _query($query, ['page' => '2', 'page_size' => '20'])
    ->paginatePage(defaultPerPage: 20, maxPerPage: 100, maxPage: 10000);
```

分页只读取显式输入的 `page` 与 `page_size`。缺失或空字符串采用默认值；接受真正的正整数及规范十进制正整数字符串，拒绝 bool、null、小数、指数、前导零、超长数字、零、负数或超限值，不截断或悄悄钳制。开发者的每页上限最大1000，页码上限最大100万；默认限制更小。

Query 的分页主键沿用 `id`，ModelQuery 使用模型自身主键；需要定制 Query 主键或游标分页时从 `query()` 调用已有公开接口。分页采用已经声明的排序，用户排序名只能通过上方的受信映射选择，不重新建立查询或绕过已有租户条件。

## 快捷校验

```php
$values = _vali([
    'age' => Field::integer()->required()->cast()->range(0, 150),
    'active' => Field::boolean()->required(),
    'note' => Field::text()->nullable(),
], ['age' => '0', 'active' => false, 'note' => null]);
```

`_vali(array|Schema $rules, array|Input $input, string $scenario = 'default', bool $patch = false): array` 仅返回已声明并校验通过的有效字段；非PATCH模式可使用下方显式默认值。普通数组就是 `body`，不会自动合并 query、route 或 header。多来源输入使用现有 `Input` 与 `Field::from()`：

```php
$input = new Input(['route' => ['id' => '7'], 'body' => ['id' => '99']]);
$values = _vali(['id' => Field::integer()->cast()->from('route')], $input);
// id 为7，不受body的同名字段覆盖。
```

需要明确区分结果的missing/null时使用 `Type\Validate\Helper\ValidateHelper::data()`，返回原有 `Data`，通过 `has()` 判断结果中是否存在字段；显式默认null也存在。它不判断原始请求是否提供：需要该信息时对相应 `Input::source()` 使用 `array_key_exists()`。PATCH、场景、条件、嵌套对象、列表和DTO映射均沿用Schema/Field的真实契约。

`required()` 表示必须提供，不擅自改变现有空字符串语义。需要非空文本时显式追加 `trim()->length(1, 100)`。错误沿用 `ValidationException` 的字段路径与稳定错误码，消息不包含输入值。

只支持 `array<string, Field>` 或 Schema，不隐式解析 `name.require`、`age.integer`、`age.min:0`、`default/value/#alias` 等字符串规则。传入这些格式会明确抛出 `InvalidArgumentException`，不静默放过。

### 显式字段默认值

```php
$values = _vali([
    'page_size' => Field::integer()->cast()->range(1, 100)->defaultValue('20'),
    'active' => Field::boolean()->defaultValue(false),
    'note' => Field::text()->nullable()->defaultValue(null),
], []);
// ['page_size' => 20, 'active' => false, 'note' => null]
```

`Field::defaultValue(mixed $value): Field` 返回新声明，保留原声明不变。仅填充适用场景/条件下、非PATCH、可选且原始来源缺失的字段。它不是未经验证的数据合并，默认值继续经过trim、显式cast、类型、顺序规则和嵌套Schema；错误仍使用对应字段路径及稳定错误码，不把默认原文放入错误消息。

| 输入与声明 | 行为 |
| --- | --- |
| 可选字段缺失且有默认值 | 校验默认值并写入有效结果；没有默认值仍省略 |
| 显式null、空串、0或false | 不替换，按nullable/类型/其他规则校验 |
| required字段缺失 | 仍报required，不由默认值满足必填；两种链式声明顺序相同 |
| PATCH字段缺失 | 不填默认值，也不因required报错 |
| 场景或条件不适用 | 省略字段，不填默认值 |

默认值可以是标量、null、数组或普通stdClass组成的数据树，最多128层容器。声明和每次使用分别复制，不保留数组引用或共享对象；循环数据、资源、其他类对象（含stdClass子类）与Closure工厂在声明时拒绝，不执行序列化/克隆钩子或任意回调。类型或规则不匹配在实际应用默认值时报告，与显式输入相同。默认值写在代码中会进入AOT产物，不能用它声明运行秘密。

嵌套默认对象/列表继续递归校验，不自动制造没有被提供且未声明默认值的父对象。PATCH中的已提供对象仍是字段补丁，不补其缺失子字段；已提供列表替换整个列表，因此列表内新对象按完整校验填入可选默认值并检查必填字段。`when()`和`rule()`接收原始Input与场景，不会看到另一字段补默认值后重新合并的请求；自定义规则异常仍直接向外传播。

## Composer 与全量 AOT

`type-orm` 和 `type-validate` 分别通过 `autoload.files` 加载自己唯一的 `src/functions.php`；文件只含函数声明，没有 `function_exists` 包装、隐式启动或运行时 require。生产源码声明仍完整包含 `src/`，TypePHP 将辅助函数与类一起编译。构建工具只加载自己的工具依赖，不通过这两个业务辅助函数执行应用输入。

PHP 模式安装后加载一次 Composer 自动加载器即可使用全局 `_query()` / `_vali()`；原生进程使用已编译符号，不需要重新加载 PHP 文件。组件不能与另一个同时声明同名全局函数的库并装后悄悄择一使用，冲突应在安装/构建阶段解决。

## 验收

```bash
php tests/helpers.php --php
php tests/helpers.php /绝对路径/原生产物
```
