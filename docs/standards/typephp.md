# TypePHP 0.9 语法与实现基线

本文是当前 TypeApp 构建入口的版本基线。生产代码、生成代码和实际安装的生产依赖均按锁文件交给 TypePHP AOT 编译；PHP 脚本测试只证明开发路径行为，不能替代原生编译和无源码运行验收。

## 锁定身份

- TypePHP：`v0.9.0`，提交 `f127dadf5dc6e554ff5182fd35a6c499fea47242`
- PHPX：`v2.9.0`，提交 `6f2089379cbc7ae22dacf0faa65dd05e40d72c20`
- PHP：`8.5.10 ZTS`（目标平台 SDK 必须与实际 PHP、架构和线程模式一致）

准确依赖以根 `composer.lock` 和 `toolchain.lock.json` 为准；模板也必须携带相同的工具链身份。

## 语言规则

TypePHP 使用严格编译边界：推断的标量局部变量使用固定原生存储；需要动态值或 PHP 引用时显式使用 `std::any()`、`std::ref()` 或 `toRef()`；编译期内置接口使用 `Type::*` 和 `std::*`，遵守锁定版本的编译期接口签名。函数、闭包和生成器入口写出完整参数与返回类型，属性明确初始化，生产代码不使用 `eval`、运行时源码加载或 Composer 解释回退。

编译器提供增量编译、翻译单元拆分和 Native 对象生命周期分析。应用不应依赖生成文件名、缓存目录或内部 C++ 符号；构建入口继续使用仓库声明的 `type-app.json`，以完整编译输入和产物清单作为验收边界。

嵌套 `unset` 由编译器生成单次操作链，保持 PHP 的求值顺序、缺失中间项不创建以及引用写回语义。业务代码直接使用正常 PHP 写法，无需在应用层复制数组或增加通用运行时补丁。

## PHPX 2.9 适配边界

PHPX 的调试实现在 `src/core/debug.cc`，并提供异常/转换策略头文件。TypeApp 仍只在独立、固定的 PHPX 源码副本上应用线程与协程隔离、finalizer bailout 和可写字符串副本适配；共享 `vendor` 或 SDK 不直接改写。适配原文摘要和唯一替换由 `Type\Build\PhpxThreadSource` 门禁，完成后必须重新编译整份 PHPX。

TypePHP 编译器的属性访问边界仍由 `Type\Build\TypephpCompatibility` 承接，适配只接受锁定版本的源码摘要。上游已实现的能力优先直接复用，新的版本升级若使适配不再必要，应先用等价回归用例证明后删除门禁和补丁。

## 一手依据

- [TypePHP v0.9.0](https://github.com/swoole/typephp/tree/v0.9.0)
- [PHPX v2.9.0](https://github.com/swoole/phpx/tree/v2.9.0)
