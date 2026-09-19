# 统一路由与反向 URL

控制器注解与 `config/route.php`（或示例中的相对 PHP 声明）都由 `Type\Build\RouteCompiler` 解析，进入同一份 `RouteDefinition` 模型，再生成直接控制器调用。生产入口只调用生成类的 `register()`，不读取 `route.php`、不扫描控制器目录、不反射 Attribute。构建 JSON 只保留 `"routing": "config/route.php"` 这类指针，不再内嵌路由表，也不再读取 JSON 路由文件。

本任务的 TypePHP 与完整 CI 验收按当前开发顺序留到集中验收；下方保留可直接运行的入口。没有以 PHP 测试替代生产原生要求。

## 显式声明

在 `type-app.json` 中把 `routing` 指到应用目录内的 PHP 文件：

```json
{
  "routing": "config/route.php"
}
```

```php
<?php

declare(strict_types=1);

return [
    'class' => 'App\\Generated\\Routes',
    'routes' => [
        [
            'prefix' => '/api/{tenant}',
            'name-prefix' => 'api.',
            'constraints' => ['tenant' => '[a-z]+'],
            'middleware' => ['authenticate'],
            'routes' => [
                [
                    'path' => '/books/{id}',
                    'methods' => ['GET'],
                    'name' => 'books.show',
                    'handler' => ['App\\BooksController', 'show'],
                    'constraints' => ['id' => '[0-9]+'],
                    'middleware' => ['authorize-book'],
                ],
            ],
        ],
    ],
];
```

`route.php` 只能包含 `declare(strict_types=1)` 和一次 return 常量数组；构建器解析 AST，不 include、不读取环境。控制器文件必须已经在应用 `sources` 或生产包的源码清单中。动作使用公开实例方法，接收一个 `Psr\Http\Message\ServerRequestInterface` 并返回 `Psr\Http\Message\ResponseInterface`。构造函数按业务需求接收依赖，由启动入口提供工厂；构建器不执行控制器构造函数。参数从请求属性 `type.route.params` 读取，当前路由名在 `type.route` 中。

分组可以嵌套，路径与名称前缀逐层拼接。分组前缀以 `/` 开头且没有尾斜线，名称前缀以 `.` 结尾；空前缀也有效。约束由外到内覆盖，中间件按“全局→外层分组→内层分组→路由→动作”进入，响应反向经过中间件。每次匹配都会创建新中间件，控制器在请求到达动作时才通过工厂创建。

## Attribute 声明

```php
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;

#[Group(prefix: '/api', namePrefix: 'api.', middleware: ['authenticate'])]
final class BooksController
{
    #[Route(path: '/books/{id}', methods: ['GET'], name: 'books.show', constraints: ['id' => '[0-9]+'])]
    public function show(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $id = $request->getAttribute('type.route.params')['id'];
        $messages = new \Type\Core\Http\Message\Factory();
        return $messages->createResponse()->withBody($messages->createStream($id));
    }
}
```

构建配置使用 `"routing": "config/route.php"`，并在该 PHP 文件中给出生成类名。省略 `attributes` 且没有 `routes` 表时，构建器扫描已纳入生产 `sources` 的控制器注解。可以同时保留 `routes` 列表，二者一起检查冲突；可选的 `attributes` 为 `true` 或生产 PHP 文件列表，用来强制扫描或收窄范围。`Route` 可重复使用在方法或类上，类级声明指向 `handle`；`Group` 只用于类。Attribute 参数支持字面量、数组、常量运算及 `类名::class`；应用常量解析与函数调用不被执行，不能静态求值时直接报错。声明按所属类读取，不隐式继承父类的路由 Attribute；动作本身可继承生产源码中已声明的公开方法。

## 资源路由

```php
[
    'resource' => '/books',
    'controller' => 'App\\BooksController',
    'name' => 'books',
    'parameter' => 'id',
    'constraints' => ['id' => '[0-9]+'],
    'only' => ['index', 'store', 'show', 'update', 'destroy'],
]
```

类级 `#[Resource(path: '/books', name: 'books', constraints: ['id' => '[0-9]+'])]` 使用同一展开规则。`only` 可筛选动作，省略则生成全部七项；自定义参数名支持嵌套资源，例如 `/authors/{author}/books` 配合 `parameter: 'book'`。

| 动作与名称后缀 | HTTP 方法 | 路径 |
| --- | --- | --- |
| index | GET | /books |
| create | GET | /books/create |
| store | POST | /books |
| show | GET | /books/{id} |
| edit | GET | /books/{id}/edit |
| update | PATCH、PUT | /books/{id} |
| destroy | DELETE | /books/{id} |

## 启动与链接

```php
$messages = new \Type\Core\Http\Message\Factory();
$router = new \Type\Core\Http\Router($messages, $messages);
\App\Generated\Routes::register($router, [
    \App\BooksController::class => static fn () => new \App\BooksController($bookService),
], [
    'authenticate' => static fn () => new \App\Authenticate($identityService),
    'authorize-book' => static fn () => new \App\AuthorizeBook($policy),
]);
$url = $router->url('api.books.show', ['tenant' => 'acme', 'id' => 42], ['q' => '甲 乙']);
// /api/acme/books/42?q=%E7%94%B2%20%E4%B9%99
```

控制器工厂按类名索引，中间件工厂按声明标识索引；注册前检查所需闭包是否齐全。生成链接不会调用任何工厂。第一条请求到达后注册表冻结，不能继续增加路由或全局中间件。原有 `Router::add()` 保留静态路径入口，可增加可选名称；参数、分组与资源使用上述构建声明。

控制器、处理器和中间件工厂均以零参数调用，依赖由显式捕获注入；生成的动作闭包接收一个 `ServerRequestInterface`。这两个层次的签名分别固定，TypePHP 对非 variadic 回调严格检查实参数量。

## 匹配与冲突规则

- 方法按大写匹配，支持合法 HTTP token，包括 `M-SEARCH`。显式 HEAD 优先，没有 HEAD 时复用 GET；Swoole 发送 HEAD 时省略正文。405 返回排序去重的 Allow，GET 同时声明 HEAD 可用。
- 路径按 `/` 分段后各解码一次，百分号参数 `%2F` 的链接是 `%252F`。中文、空格和加号往返保持原值。编码斜线、反斜线、控制字符、无效 UTF-8、`.` 和 `..` 不可成为参数或静态段；路径不能生成 `//host` 形式的外部地址。
- 参数占完整路径段，例如 `{id}`；本接口不支持可选段、跨段通配符和内联正则。额外静态段、尾斜线及路径大小写都保持区别，没有自动跳转。
- 参数约束是没有分隔符的 PCRE 表达式，按整个已解码参数匹配。默认非空单段；表达式最多 512 字节，运行设置匹配与递归上限。URL 缺参数、带未知参数、参数不符合约束或会落入另一条更具体的静态路由时，抛出 `InvalidArgumentException`。
- 匹配优先比较每个位置的静态段，再比较参数段，与注册先后无关。完整静态路径使用索引；参数路由按段数筛选。静态路径存在但方法不匹配时返回 405，不转入参数路由。
- 重复名称、相同方法下编码后等价的静态路径及同形参数路由，在构建时拒绝。对同形路由的任意 PCRE 不尝试证明约束交集为空，即使看起来互斥也保守报告歧义；可使用一个参数路由在动作内处理，或改为明确静态前缀。不同方法可以共享路径。

代理信任、Host 检查、请求目标规范化与签名策略见[HTTP 信任边界](http-trust.md)；此处只规定路由匹配与命名 URL 使用同一参数解释。

## 验证入口

```sh
php tests/routing.php
php tests/routing-build.php
php tests/routing-http.php --php explicit
php tests/routing-http.php --php attributes
php tests/http-native.php --php
```

集中原生验收及 CI 可直接接入：

```sh
php vendor/bin/type docs/build-config/type-routing.json
php tests/routing.php build/routing/type-app
php vendor/bin/type docs/build-config/type-routing-http.json
php tests/routing-http.php build/routing-http/type-app explicit
php vendor/bin/type docs/build-config/type-routing-attributes.json
php tests/routing-http.php build/routing-attributes/type-app attributes
```
