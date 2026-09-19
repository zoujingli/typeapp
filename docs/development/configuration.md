# 声明式配置与运行时环境

配置分为两个时点：构建期解析 `config.files` 中明确列出的 PHP 声明，生成可编译的配置类；应用启动时由该类读取进程环境和明确指定的 `.env` 文件，返回不可变的配置快照。生产进程不 `include` 原配置，也不依靠 PHP 源码加载或运行时拦截恢复未编译能力。`config/route.php` 由路由生成器读取，不进入配置节。

原有 `Type\Core\Configuration` 仍负责既有命令装配的字符串配置，保持接口兼容。新的 `Type\Core\Config\Environment` 和 `Repository` 处理嵌套、类型化的应用配置，不通过修改旧类含义迁移调用者。

## 配置声明

配置 PHP 是受限的构建声明，不是任意启动脚本。例如 `config/app.php`：

```php
<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'typeapp'),
    'port' => env('APP_PORT', 9501),
    'debug' => env('APP_DEBUG', false),
    'ratio' => env('APP_RATIO', 1.0),
    'optional' => null,
    'cache' => ['enabled' => true],
];
```

`env()` 是配置声明中的语法节点，不会注册成生产全局函数。使用已有 AST 解析器读出它，将其转换成显式的 `Environment::get()` 调用；编译器不调用 `env()`、`getenv()`，也不读取 `.env`。

构建器公共入口：

```php
$result = (new Type\Build\ConfigCompiler())->generate($root, [
    'class' => 'app\\generated\\ProjectConfig',
    'files' => ['config/app.php', 'config/database.php'],
]);
```

这里是构建工具调用示意，不是生产入口。结果包含生成类名 `class`、实际配置输入的绝对路径列表 `files` 以及生成源码 `code`。调用者将 `files` 纳入完整构建身份，将 `code` 纳入 AOT 源码；原配置不能同时被当作业务 PHP 编译单元。

`files` 必须显式列出，不能通过通配符或运行时目录搜索补全。路径去掉第一个目录和 `.php` 后映射为配置 section：`config/app.php` 为 `app`，`config/admin/auth.php` 为 `admin.auth`。其他显式目录采用同一规则；因此两个目录都声明 `app.php` 会冲突。路径段只接受以字母或下划线开头、后续包含字母、数字、下划线或连字符的名称；拒绝绝对路径、`..`、符号链接与项目外文件。

每个文件严格由一个 `declare(strict_types=1);` 和一个 `return` 数组组成；允许注释。支持嵌套数组、字符串、整数、有限浮点数、`true`、`false`、`null`、正负数字以及 `env('静态键', 标量或 null 默认值)`。数组键只能是整数或不含点的非空字符串，重复键明确拒绝；数字字符串键及负数字键的后续追加沿用当前 PHP 规则。

允许直接标量的 `(int)`、`(float)`、`(bool)`、`(string)` 转换，以及直接 `env()` 的同类转换。配置 DSL 使用严格转换：`(bool) 'false'` 得到 `false`，`(int) 'abc'` 拒绝，浮点转整数只接受有限、无小数且未溢出的值。对 `env()` 的转换先确定默认值类型，运行时仍执行 `Environment` 的严格解析，不会把错误端口静默转成零。这不是任意 PHP 表达式求值。

不支持变量、拼接或运算产生的环境键、命名/展开/引用参数、数组展开、任意函数、文件读写、`include`、`eval`、类/函数声明、闭包、魔术常量或动态插值。需要路径组合、连接创建等行为时，在应用启动方法中使用已经取得的配置。每批最多 256 个文件、每文件最多 1 MiB、路径及数组表达式最多 32 层；重复 section、父子 section 冲突、未知配置键和可见同名类均拒绝。整应用构建还须检查生成类与未加载的生产源码声明冲突。

## 环境读取

```php
function main(): void
{
    $environment = Type\Core\Config\Environment::load('/srv/type-app/.env');
    $configuration = app\generated\ProjectConfig::load($environment);
    $port = $configuration->integer('app.port');
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('应用端口超出允许范围');
    }
}
```

`Environment::load(null)` 只使用进程环境，不自动查找当前目录或父目录的文件。显式文件不存在时使用空 dotenv 快照；文件存在但不可读、不是普通文件、超过限制或格式非法时失败。使用应用明确的根目录组合 `.env` 路径，不能依赖构建机路径。

每次 `get($key, $default)` 优先读取实际进程的 `getenv($key)`，不存在时才取已解析的 dotenv 快照，再不存在才返回默认值。存在的空字符串不等于缺失。读取器不会调用 `putenv()`，不会修改其他请求、测试或应用的全局环境；生成配置类的 `load()` 只在应用选择的启动时点取得快照。

| 默认值类型 | 环境值读取规则 |
| --- | --- |
| `null` | 缺失返回 null，存在返回原始字符串，不把文本 `null` 特殊处理 |
| `string` | 保留原始文本，包括空串 |
| `bool` | 接受 true/yes/on/1、false/no/off/0，大小写不敏感；空串或其他值拒绝 |
| `int` | 有界十进制整数，可带正负号；小数、前导零、空串与溢出拒绝 |
| `float` | 有限十进制或科学记数法；NaN、Infinity、溢出和空串拒绝 |

默认值不能是数组、对象或资源。类型转换失败的错误只包含键名，不回显值。持有秘密的输入参数使用 `SensitiveParameter`，即使 PHP 开启异常调用栈参数展示也不回显原始 dotenv 值。

dotenv 支持 `KEY=value`、`export KEY=value`、单引号、双引号和行内注释。单引号保留文字，允许 `\\` 和 `\'`；双引号支持 `\n`、`\r`、`\t` 以及引号、反斜线、美元符号的转义；未加引号的值支持转义空格和 `#`。未加引号时，行首或空白后的 `#` 开始注释，值内部的 `#` 保留。禁止未转义的变量插值与命令表达式，不执行 shell；不支持物理跨行字符串。重复键拒绝，每文件最多 1 MiB、1024 个键，单行最多 16 KiB。

## 不可变配置仓库

`new Repository(array $values)` 对嵌套数组逐层创建快照，剥离外部引用。只接受数组、标量和 null；对象、资源、循环或超过 64 层的结构拒绝。返回的数组副本不能改变内部状态。

`has('database.password')` 判断路径是否存在，已声明的 null 仍然存在。`get($key, $default = null)` 仅在路径缺失时返回默认值，不覆盖显式 null。`text()`、`integer()`、`boolean()`、`array()` 读取必需项并严格检查类型，不进行第二次隐式转换；业务范围约束由调用者显式验证。

点路径支持数组索引，如 `connections.0.name`。空路径、空段、空字节、超过 1024 字节或 64 层的路径拒绝；数组键本身不能包含点。错误只标明路径或类型，不回显配置值。默认 `var_dump` 使用脱敏信息，JSON 不公开内部私有值，序列化及反序列化均被拒绝；这不构成防止应用代码主动调用 getter 或反射后泄漏秘密的安全隔离。

## 验证入口

在开发主仓运行：

```sh
php tests/configuration.php
php tests/configuration.php --prepare-native
```

第二条返回一次性的 `configuration`、`binary`、`dotenv` 和 `generated` 路径；用返回的配置通过 `vendor/bin/type` 构建，再执行 `php tests/configuration.php <返回的binary>` 验证同一公开应用入口。测试只读写本次独立临时配置，拒绝用例中的秘密均为测试哨兵，不读取其他项目或真实生产 `.env`。
