# 原 Service 的事务与缓存声明

本功能使用 PHP 8 Attribute 作**构建期声明**。`OperationCompiler` 在加载原类之前转换完整源码文件，保留原 Service 类名、构造器、公开签名和同文件其他声明；PHP 开发入口与 AOT 消费同一结果。事务和缓存直接组合已有 `Db`、保存点、提交后动作与 `TypedCache`，不依赖运行时反射或代理。

## 使用接口

将业务类纳入生产 `sources`，使用标准准备与运行入口：

```json
{
  "sources": ["app"]
}
```

`operations.classes` 已移除，包含空映射的旧配置也明确拒绝。构建器通过 `OperationCompiler::generate($root, [], $sources, $packages)` 得到 `code`、`originals` 和 `operations` 元数据；标准入口把它合并进 Model 的文件替换关系，同文件只加载最终结果。原源码与生成内容共同参与代次身份，已提前加载原类、陈旧代次及重复转换都会拒绝。AST 使用锁定的 PHP-Parser 与 NameResolver，不执行原业务文件。缺少声明所需的生产组件时在构建期失败。

```php
use Type\Cache\Attribute\Cacheable;
use Type\Cache\Attribute\CacheEvict;
use Type\Cache\TypedCache;
use Type\Orm\Attribute\Transactional;
use app\common\model\User;

final class UserService
{
    #[Cacheable(cache: 'cache', key: 'user:{id}', ttlMilliseconds: 60000)]
    public function find(TypedCache $cache, int $id): ?array
    {
        $user = User::query()->find($id);
        return $user === null ? null : $user->present(['id', 'name']);
    }

    #[Transactional]
    #[CacheEvict(cache: 'cache', key: 'user:{id}')]
    public function rename(TypedCache $cache, int $id, string $name): void
    {
        $user = User::query()->findOrFail($id);
        $user->name = $name;
        $user->save();
    }
}
```

在标准入口完成准备后，直接使用原类型：

```php
$service = new \app\system\service\UserService();
$service->rename($cache, 7, '中文名称');
```

普通方法调用、手动 `new` 后调用和类内 `$this->find(...)` 均执行相同声明。构造器和无声明的辅助方法保留原实现。不要绕过标准入口直接加载原文件，也不再创建另一种生成服务类型。

## 事务与失效顺序

示例中的 `User` 是消费应用声明并编译的领域模型；应用启动装配 `Db`，宿主在调用操作前绑定当前执行作用域。`#[Transactional]` 默认使用 `default` 数据源，`#[Transactional(database: 'archive')]` 显式指定其他逻辑数据源；不接受 `connection` 参数。生成代码调用 `Db::transaction()`，闭包为 `Closure(): 返回类型`，业务方法无需接收连接。`void` 方法不生成 `return 表达式`，其他返回值原样返回。

没有重试、异常吞掉或事务状态猜测；保存点、取消、回滚、未知提交结果均沿用原连接的真实语义。

`CacheEvict` 与事务组合时，在事务体内调用原方法之前登记零参数 `afterCommit` 回调。业务失败时该事务帧的回调被丢弃，内层保存点成功只合并到父事务；只有最外层提交确认后才执行缓存失效。提交后清理失败沿用 `AfterCommitException`，不能把已提交业务当作回滚，也不能重试原方法。

不带 `Transactional` 的 `CacheEvict` 也检查其逻辑数据源：有活动事务时登记最外层确认提交后的动作，保存点回滚及外层回滚均丢弃；无事务时在方法成功返回后立即失效。`database` 省略时沿用同方法的 `Transactional` 数据源，否则默认为 `default`；显式不一致会拒绝。`all: true` 只切换传入缓存的命名空间代次，不使用 Redis `FLUSHDB`。

## 缓存键、类型和一致性

`Cacheable.cache` 必须指向非空 `TypedCache` 形参，TTL 使用有界正毫秒整数；实例配置的 `maximumTtlMilliseconds` 仍是实际运行约束。结果处理直接复用 `TypedCache::remember()`：`null` 可以命中，异常不缓存，TTL 到期重新回源，跨 `clear()` 的旧回源不会写回新代。

key 是非空模板，使用 `{参数名}` 引用标量形参；所有业务标量参数都必须出现，不能漏掉租户、分页、过滤条件或版本。指定缓存参数与明确的 `Connection` 参数不参与键；其他数组、对象、mixed、callable 等参数不允许用于 Cacheable，不能依靠隐式字符串转换猜测身份。

实际键为 `type-operation:` 加 SHA-256，哈希输入是模板与按名字排序的参数值映射，通过 `JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION` 编码。模板和参数名共同参与身份，区分 `null`、空字符串、布尔、整数、浮点和字符串；相同模板与参数可供读取和更新方法共享失效。无效 UTF-8、NaN 等无法编码的值明确失败，不碰巧生成相同键。

`CacheEvict` 只需要选择真正影响缓存身份的标量参数；更新载荷不要求进入读取键，所以事务更新可以接受 `array $data`。`Cacheable` 不能同时标记事务或失效，也不能缓存 `void` 方法。

`Cacheable(database: 'archive', ...)` 可指定逻辑数据源；默认是 `default`。活动事务内直接执行业务读取，绕过已有共享缓存且不填充新值；退出事务后恢复普通缓存行为。`Db::inTransaction()` 只观察当前作用域已有会话，不借默认库或新连接；跨库调用继续拒绝。仅安装缓存组件的消费者不生成 ORM 调用，也不要求安装 ORM。

缓存实例仍须按应用、环境、租户/数据库身份和 codec 隔离。构建器不从服务隐藏状态猜测身份；直接调用底层 `TypedCache` 的手写业务仍由应用负责一致性。UNKNOWN 不发布确认提交后的失效，不自动重试；失效错误保留真实 COMMITTED 结果。这些行为不构成分布式事务。

## 明确拒绝的声明

- 属性重复、参数未知或重复、解包、非静态可求值的 Attribute 参数。
- 构造器、字段、常量、参数或非公开方法上的操作 Attribute。
- 静态/抽象/魔术方法、引用返回、引用参数、variadic、无参数或返回类型。
- `never`、`callable`、`iterable`、交叉类型与 `self/static/parent` 相对类型。
- 条件声明类、继承或 Trait 服务、生成器与依赖原方法执行帧的 `func_get_args` 等调用。
- 转换文件中的 `__DIR__`、`__FILE__`、`__LINE__`，以及声明方法中的 `__METHOD__`、`__FUNCTION__`。无法维持原位置或执行帧语义时明确拒绝，不悄悄改变结果；报告保留输入方法位置。
- 非法逻辑数据源名、缺失或可空的 TypedCache 参数、缓存 key 遗漏参数或模板语法错误、缓存任意数组/对象入参、非法组合与 TTL。

受支持方法保留参数名、默认常量、返回类型和 PHPDoc，文件中的 namespace、use 别名和文档语境一并保留。默认值只接受可直接静态求值的常量表达式，不读取业务类常量或执行代码。DocBlock 的 `@Transactional` / `@Cacheable` 不会被隐式解释为 Attribute。


## 验证入口

构建示例配置后运行同一套公开行为检查：

```sh
php tests/operations.php build/operations/type-app
```

测试使用公开生成结果、真实数据库和 Redis；地址取自 `TYPE_REDIS_HOST` 与 `TYPE_REDIS_PORT`。`tests/operations-databases.php <原生产物或--php> <MySQL工具根> <PostgreSQL工具根>` 创建独立三库并复用 COMMIT 断线代理，验证原类型、手动构造、类内互调、命名源、缓存绕过、保存点和 UNKNOWN。PHP、AOT 与各平台证据分别记录，未完成全量原生验收不代表新候选可发布。
