# 显式编译生成的事务与缓存操作

本功能使用 PHP 8 Attribute 作**构建期声明**，由 `OperationCompiler` 输出普通、有完整类型的组合对象，再与业务和依赖一起 AOT。没有运行时 AOP、反射代理、继承代理、方法改写、动态加载或 `eval()`。

## 使用接口

应用构建配置声明明确的生成类和业务类映射：

```json
{
  "operations": {
    "classes": {
      "app\\generated\\UserOperations": "app\\system\\service\\UserService"
    }
  }
}
```

业务类必须已经包含在生产 `sources` 中。构建器通过 `OperationCompiler::generate(string $root, array $configuration, array $sources): array` 得到 `code` 和 `operations` 元数据；生成类也必须进入 AOT 输入。AST 使用已经锁定的 PHP-Parser 与 NameResolver，识别完整类名、命名空间导入和别名，不执行原业务文件。

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
        return $user === null ? null : $user->project(['id', 'name']);
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

生成类构造器接受已经创建的原业务对象；构建期 DI 或普通调用者显式使用该生成类：

```php
$operations = new \app\generated\UserOperations($userService);
$operations->rename($cache, 7, '中文名称');
```

直接调用 `$userService->rename(...)` **不会**开启事务或清理缓存。原业务内部的 `$this->find(...)` 同样是普通调用；需要复合操作时在调用者显式组合生成对象，不隐式改变原类的自调用语义。

## 事务与失效顺序

示例中的 `User` 是消费应用声明并编译的领域模型；应用启动装配 `Db`，宿主在调用操作前绑定当前执行作用域。`#[Transactional]` 默认使用 `default` 数据源，`#[Transactional(database: 'archive')]` 显式指定其他逻辑数据源；不接受 `connection` 参数。生成代码调用 `Db::transaction()`，闭包为 `Closure(): 返回类型`，业务方法无需接收连接。`void` 方法不生成 `return 表达式`，其他返回值原样返回。

没有重试、异常吞掉或事务状态猜测；保存点、取消、回滚、未知提交结果均沿用原连接的真实语义。

`CacheEvict` 与事务组合时，在事务体内调用原方法之前登记零参数 `afterCommit` 回调。业务失败时该事务帧的回调被丢弃，内层保存点成功只合并到父事务；只有最外层提交确认后才执行缓存失效。提交后清理失败沿用 `AfterCommitException`，不能把已提交业务当作回滚，也不能重试原方法。

不带 `Transactional` 的 `CacheEvict` 在原方法成功返回后立即执行；失败不清理。`all: true` 仅切换传入 `TypedCache` 的命名空间代次，不能同时配置单个 key，不使用 Redis `FLUSHDB`。

## 缓存键、类型和一致性

`Cacheable.cache` 必须指向非空 `TypedCache` 形参，TTL 使用有界正毫秒整数；实例配置的 `maximumTtlMilliseconds` 仍是实际运行约束。结果处理直接复用 `TypedCache::remember()`：`null` 可以命中，异常不缓存，TTL 到期重新回源，跨 `clear()` 的旧回源不会写回新代。

key 是非空模板，使用 `{参数名}` 引用标量形参；所有业务标量参数都必须出现，不能漏掉租户、分页、过滤条件或版本。指定缓存参数与明确的 `Connection` 参数不参与键；其他数组、对象、mixed、callable 等参数不允许用于 Cacheable，不能依靠隐式字符串转换猜测身份。

实际键为 `type-operation:` 加 SHA-256，哈希输入是模板与按名字排序的参数值映射，通过 `JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION` 编码。模板和参数名共同参与身份，区分 `null`、空字符串、布尔、整数、浮点和字符串；相同模板与参数可供读取和更新方法共享失效。无效 UTF-8、NaN 等无法编码的值明确失败，不碰巧生成相同键。

`CacheEvict` 只需要选择真正影响缓存身份的标量参数；更新载荷不要求进入读取键，所以事务更新可以接受 `array $data`。`Cacheable` 不能同时标记事务或失效，也不能缓存 `void` 方法。

缓存实例必须由调用者按应用、环境、租户/数据库身份和 codec 合理隔离。构建器不会从 `Connection` 或任意 service 隐藏状态推断租户；同一命名空间内复用相同模板表示开发者明确选择共享缓存。事务里手动读取缓存不是数据库快照读取；这不是强一致分布式事务，仍遵循现有缓存失效模型。

## 明确拒绝的声明

- 属性重复、参数未知或重复、解包、非静态可求值的 Attribute 参数。
- 构造器、字段、常量、参数或非公开方法上的操作 Attribute。
- 静态/抽象/魔术方法、引用返回、引用参数、variadic、无参数或返回类型。
- `never`、`callable`、`iterable`、交叉类型以及组合对象中语义不同的 `self/static/parent`。
- 未声明的业务类、条件声明类、生成类冲突，以及当前不能完整展开的继承或 Trait 服务。
- 非法逻辑数据源名、缺失或可空的 TypedCache 参数、缓存 key 遗漏参数或模板语法错误、缓存任意数组/对象入参、非法组合与 TTL。

普通已支持类型的公开实例方法会保留参数名、默认常量、返回类型和 PHPDoc，并直接转发；private/protected 辅助方法留在原 service 内，不复制实现。默认值只接受可直接静态求值的常量表达式，不读取业务类常量或执行代码。DocBlock 的 `@Transactional` / `@Cacheable` 不会被隐式解释为 Attribute。


## 验证入口

构建示例配置后运行同一套公开行为检查：

```sh
php tests/operations.php build/operations/type-app
```

测试使用公开生成结果、真实 SQLite 和专用 Redis；地址取自 TYPE_REDIS_HOST 与 TYPE_REDIS_PORT。示例位于 examples/operations，生产源码与生成入口须完整编译。
