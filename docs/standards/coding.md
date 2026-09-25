# 代码、PHPDoc 与 TypePHP

实现与依赖选择先满足[项目标准](project.md)的全量编译和极简高效要求；以下约束同时适用于业务、组件与生成代码。

## 格式

PHP 文件使用无 BOM 的 UTF-8、LF、四空格缩进和 `declare(strict_types=1);`。类名采用 PascalCase，方法和属性采用 camelCase，常量采用 UPPER_SNAKE_CASE；命名空间与 PSR-4 路径的大小写一致。应用职责目录可以使用 `controller`、`model`、`service`，对应命名空间也保持小写；组件现有公开命名空间保留兼容。

格式基线为 PHP CS Fixer 的 PSR-12 规则和仓库显式安全规则。`composer cs-check` 只检查，`composer cs-fix` 只做已声明的非 risky 格式修正。配置排除 vendor、build、运行数据；不启用类型迁移、隐式裁参、语义重写或自动替换事务逻辑的规则。格式化后仍需语法、公共行为和相关 AOT 验收。

## PHPDoc

类、接口及新增或修改的公开方法写清职责与调用条件。注释解释约束和原因，不重复方法名称；签名无法完整表达的信息用标准 PHPDoc 补充：

- 数组结构使用 `array{...}`、`list<T>` 或 `array<TKey,TValue>`。
- 回调写出完整 `Closure(参数类型...): 返回类型`，使用 `class-string<T>` 描述类名。
- `@throws` 记录调用方需要处理的异常及触发条件；不列出没有实际路径的异常。
- `@internal` 标明仅供组件协作的入口；修改内部实现不应让业务依赖该入口。
- 涉及事务、缓存、流和租约时说明提交时机、所有者、清理责任、期限单位和缺失/null区别。

下面的示例仅说明受限基础设施方法如何显式持有连接，不代表业务 Model 的公共接口；业务 Model 使用无连接的 `create()`、`query()`、`find()` 和 `save()`。

```php
/**
 * 在当前连接中保存用户，并将副作用绑定到最终提交结果。
 *
 * @param array{name: string, age: int, email?: string|null} $values 待写入业务字段。
 * @throws ModelException 字段非法、模型失效或乐观锁冲突。
 */
public function create(Connection $connection, array $values): array
{
    // 实现必须使用同一连接，不能在回调中私建另一条连接。
}
```

示例仅说明注释格式；实际方法必须有完整返回与异常路径。私有方法也在存在非显然条件、算法或资源约束时补说明。生成器输出的 PHPDoc 同样需要与生成签名匹配，不能依赖运行时反射猜测参数。

## TypePHP 语法约束

生产目标取自当前工具链锁，由锁文件确定；不以标准 PHP 单测作为原生兼容证明。完整依据见[语法基线](../standards/typephp.md)，日常编码至少遵守：

1. 全局只声明；业务启动放入全局 `main(): void` 或 `main(int $argc, array $argv): void`，一份应用只有一个入口。
2. 普通 Zend-backed 类的方法可以 AOT；不把所有类改为 `#[Native]`。PSR、数组、PDO 和 Redis 互操作使用明确类型与资源所有者。
3. 调用数量遵守签名；闭包和工厂写全参数，不用反射裁参模拟 PHP 的宽松行为。例如 `Swoole\Timer::tick()` 的回调须声明 `int $timerId`，即使业务不使用该参数。动态引用需要显式 std::ref()；编译期函数使用当前std接口，业务扩展点优先值参数与返回值。
4. 标量局部默认固定原生存储，按职责命名，不在字符串、对象和循环键之间复用为不兼容类型；catch、foreach同样遵守函数作用域约束。整数除法/溢出显式保持业务语义，仅在确有需要时使用varint_types或局部std::any()。
5. 缺失字段与显式 null 分开，属性明确初始化；不依赖 Reflection lazy object 或未初始化属性内部状态。
6. 生产不 `eval`、加载未知 PHP 文件或回退 Composer 源码加载。`config.files` 中的 PHP 是受限配置声明，允许 `env()`；`config/route.php` 是路由声明，不允许 `env()`。两者都在构建期解析后一起编译。`.env` 只在启动读取数据，不嵌入编译产物。
7. HTTP 路由来自控制器 `#[Route]`/`#[Group]`/`#[Resource]` 或 `config/route.php`，由构建生成 `register()`；缓存/事务 Attribute 通过显式生成的服务组合入口生效。直接调用原服务不自动拦截，不提供运行时 AOP。
8. 保留受支持的零参数 `toArray()`；避免与转换关键词冲突的额外参数方法，生成器和手写源码共同接受验收。

## 说明文字

README、异常与日志说明围绕实际用途、约束和操作结果。保留技术名词、稳定错误码和标准字段，不添加语言偏好宣传、无依据性能倍数或“全生态完全兼容”等结论。说明与当前实现同步，待完成能力写明验收条件。
