# type-validate

使用 TypePHP 支持的类型声明构造 `Schema` 和不可变 `Field`，通过显式 `Input` 来源校验 DTO，独立使用无需 core、数据库或 Redis。

## 安装与版本

本组件通过公开 Git 分发子仓安装，不假设已发布到 Packagist。先在应用的 Composer 根配置登记下列组件及传递依赖仓库；HTTPS 读取不需要 SSH 密钥，依赖包自己的 repositories 不会自动传递给消费应用。

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer config repositories.type-validate vcs https://github.com/zoujingli/type-validate.git
composer require zoujingli/type-validate:dev-main
```

`dev-main` 的分支别名为 `1.0.x-dev`；本仓组件间使用 `~1.0.0@dev` 约束。开发分支不等于已发布稳定 1.0 版本。提交应用的 `composer.lock` 固定实际分发提交；构建工具只放 `require-dev`。详细依赖与公开分发规则见[组件组织与安装](https://github.com/zoujingli/typeapp/blob/main/docs/development/component-structure.md)。

```php
<?php

declare(strict_types=1);

use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;

/**
 * 校验固定示例 JSON，展示缺失/null 与 query 来源的独立语义。
 */
function main(): void
{
    $schema = new Schema([
        'name' => Field::text()->required()->trim()->length(2, 40),
        'age' => Field::integer()->required()->range(0, 150),
        'email' => Field::text()->nullable()->email(),
        'page' => Field::integer()->from('query')->cast()->range(1, 100),
    ]);
    $input = Input::json('{"name":"示例用户","age":20,"email":null}')->withQuery('page=1');
    $data = $schema->validate($input, 'default', false);
    echo json_encode($data->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
}
```

支持文本、整数、有限数值、布尔、对象、列表，长度按 Unicode 字符计数；范围、格式、枚举、条件、自定义规则和场景可以组合。默认不转换类型；`cast()` 只执行明确的整数、JSON 数值和布尔文本转换，整数溢出作为类型错误。

来源为 `body/query/route/header`，`from('header', 'X-Origin')` 可映射输入键。字段没有声明时不会进入输出；不同来源不会自动混合。HTTP 适配负责把 header 的大小写约定统一到声明使用的名称。

`required()` 表示必须实际提供，空字符串仍是已提供值，需要结合 `length()`；`nullable()` 单独允许null。可选字段用 `defaultValue()` 显式声明缺失时的值，例如 `Field::integer()->cast()->range(1, 100)->defaultValue('20')`；默认值同样经过转换、类型及规则检查，不能代替required，也不覆盖显式null/空串/0/false。未声明默认值的旧规则保持原行为。

PATCH跳过缺失字段且不补默认值，嵌套对象递归保留该语义；提供的列表替换整个列表，列表元素按完整对象校验。场景/条件不适用的字段也不填默认值。`Data::has()` 区分有效结果缺失与null（含默认null），不代表原始请求是否提供；需要原始存在性时检查 `Input::source()`。`get()` 对缺失字段抛异常，`map()` 调用显式DTO工厂。

默认值接受标量/null/数组/普通stdClass数据树，最多128层容器；声明和每次使用各自取独立副本。循环引用、资源、任意其他对象或默认值工厂会被拒绝，不调用对象序列化或克隆钩子。默认值不经过另一套隐式字符串DSL，不能将运行秘密写成代码默认值。完整边界和例子见[快捷校验与默认值](https://github.com/zoujingli/typeapp/blob/main/docs/development/helpers.md#显式字段默认值)。

`Input::json()` 在解析前限制正文大小，解析器限制深度，同时拒绝重复 JSON 对象键；大整数保留为字符串。`withQuery()` 限制字节数和字段数，拒绝重复标量及非法编码，`name[]` 显式表示列表。嵌套查询方括号语法当前明确拒绝；嵌套 DTO 使用 JSON 对象。

条件和自定义规则接收原始分源 `Input` 及场景。规则按登记顺序运行；开发者规则异常向外传播，不伪装成用户输入错误。解析错误返回稳定的 400、体积超限 413、规则错误 422；`ValidationException` 只包含错误码与字段路径，不携带原始值。

`when()` 的闭包必须声明 `(Input $input, string $scenario): bool`；`rule()` 必须声明 `(mixed $value, Input $input, string $scenario): bool`；`Data::map()` 接收 `(Data $data): object` 的 DTO 工厂。TypePHP 严格检查实参数量，即使规则只使用字段值，也要保留输入和场景参数。以下声明放在应用的字段装配方法内：

```php
$slug = Field::text()->rule('reserved',
    static fn (mixed $value, Input $input, string $scenario): bool => $value !== 'admin');
$code = Field::text()->required()->when(
    static fn (Input $input, string $scenario): bool => ($input->source('body')['role'] ?? '') === 'editor');
```

PHP 行为由 `tests/validation.php` 辅助验证；相同用例可传入编译 ELF。开发先核对锁定 TypePHP 语法并统一实现，再集中编译验收，实际通过范围以任务记录为准。

## 接口与源码组织

`Schema/Field` 是声明与组合规则；`Input` 是有界分源解析；`Data` 是存在性明确的校验结果；`ValidationException` 是不携带原始值的公开错误。模块规模小且职责紧密，保留稳定根入口，不建立多层转发类。

上例可以作为应用入口编译，生产 HTTP 的原始 body/query 仍由控制器明确交给 `Input`。嵌套对象用 `Field::object(new Schema(...))`、列表用 `Field::listOf(...)`；不要通过合并 body/query/route 猜测优先级。`Data::map()` 适用于显式 DTO 构造，不调用任意类名。校验不拥有数据库或网络资源，用户规则自行创建的资源必须交给对应 Scope/所有者。

## AOT 与运行要求

独立安装只依赖 `type-runtime`，无需 Swoole、PDO 或 Redis；完整生产源码与调用方 DTO 一起 AOT，运行保留匹配 PHPX/libphp。不能以 PHP 行为通过代替生成规则与业务闭包的原生验收。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

```sh
composer test:validate
composer build:validate
composer test:validate-native
```

- [TypePHP 回调与输入语义](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)
- [TypeApp 标准物联中心项目](https://github.com/zoujingli/typeapp/blob/main/docs/development/typeapp.md)
