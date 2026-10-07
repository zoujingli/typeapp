<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$source = $root . '/examples/routing/Controllers.php';
$compiler = new Type\Build\RouteCompiler();
$validationSource = $root . '/plugin/type-validate/src';
$configuration = $compiler->declarations($root, 'examples/routing/route.php');
$explicit = $compiler->generate($root, $configuration, [$source, $validationSource]);
expect(count($explicit['routes']) === 16, '资源路由、普通路由和类型化动作没有展开');
expect($explicit['routes'][0]['name'] === 'api.books.index' && $explicit['routes'][0]['middleware'] === ['group'], '分组名称或中间件丢失');
$typed = array_column($explicit['routes'], null, 'name');
expect($typed['api.typed.show']['action']['arguments'] === [['name' => 'id', 'type' => 'int']]
    && $typed['api.typed.show']['action']['return'] === 'array'
    && $typed['api.typed.show']['action']['status'] === 200, '整数类型化动作契约没有进入路由模型');
expect(
    $typed['api.typed.store']['action']['return'] === 'array' && $typed['api.typed.store']['action']['status'] === 201,
    '固定 201 的业务数组动作契约错误'
);
expect($typed['api.typed.search']['action']['arguments'] === [['name' => 'term', 'type' => 'string']]
    && $typed['api.typed.destroy']['action']['return'] === 'void'
    && $typed['api.typed.destroy']['action']['status'] === 204, '字符串或 void 动作契约错误');
$attributes = $compiler->generate($root, ['class' => $configuration['class'], 'attributes' => ['examples/routing/Controllers.php']], [$source, $validationSource]);
expect($explicit['routes'] === $attributes['routes'], '显式和 Attribute 声明没有进入同一模型');
$discovered = $compiler->generate($root, $compiler->declarations($root, 'examples/routing/attribute-route.php'), [$source, $validationSource]);
expect($discovered['routes'] === $attributes['routes'], '生产源码中的路由注解没有自动进入同一模型');
expect(!str_contains($explicit['code'], 'Reflection') && !str_contains($explicit['code'], 'glob('), '生成路由包含生产扫描或反射');
expect(!class_exists(TypeApp\RoutingExample\BooksController::class, false), '构建阶段加载了应用控制器');
$invalidPrefix = $configuration;
$invalidPrefix['routes'][0]['prefix'] = '/api?debug=true';
$rejected = false;
try {
    $compiler->generate($root, $invalidPrefix, [$source, $validationSource]);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '分组路径错误地接受了查询分隔符');
$invalidResource = $configuration;
$invalidResource['routes'][0]['routes'][0]['constraints'] = ['idx' => '[0-9]+'];
$rejected = false;
try {
    $compiler->generate($root, $invalidResource, [$source, $validationSource]);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '资源路由静默忽略了错误参数约束');
$base = ['class' => $configuration['class'], 'routes' => [
    ['path' => '/books/{id}', 'methods' => ['GET'], 'name' => 'books.show', 'handler' => [TypeApp\RoutingExample\BooksController::class, 'show'], 'constraints' => ['id' => '[0-9]+']],
]];
$cases = [];
$duplicate = $base;
$duplicate['routes'][] = $base['routes'][0] + [];
$duplicate['routes'][1]['path'] = '/other/{id}';
$cases['重复名称'] = $duplicate;
$duplicate['routes'][1]['path'] = '/books/{slug}';
$duplicate['routes'][1]['name'] = 'books.slug';
$duplicate['routes'][1]['constraints'] = ['slug' => '[a-z]+'];
$cases['同形参数歧义'] = $duplicate;
$alias = $base;
$alias['routes'] = [
    ['path' => '/%62ooks', 'handler' => [TypeApp\RoutingExample\BooksController::class, 'index']],
    ['path' => '/books', 'handler' => [TypeApp\RoutingExample\BooksController::class, 'index']],
];
$cases['编码静态路径冲突'] = $alias;
foreach (['不存在控制器' => ['handler', ['MissingController', 'show']], '私有动作' => ['handler', [TypeApp\RoutingExample\BooksController::class, 'reply']],
    '非法约束' => ['constraints', ['id' => '(']], '未知参数约束' => ['constraints', ['unknown' => '[0-9]+']],
    '重复参数' => ['path', '/books/{id}/{id}'], '不完整参数段' => ['path', '/books/prefix-{id}'],
    '编码路径分隔符' => ['path', '/books/a%2Fb'], '外部 authority 路径' => ['path', '//outside.test'], '重复方法' => ['methods', ['GET', 'get']]] as $label => [$key, $value]) {
    $candidate = $base;
    $candidate['routes'][0][$key] = $value;
    $cases[$label] = $candidate;
}
foreach ($cases as $label => $candidate) {
    $rejected = false;
    try {
        $compiler->generate($root, $candidate, [$source, $validationSource]);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    expect($rejected, '构建未拒绝：' . $label);
}
$directory = $root . '/build/routing-build-tests';
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}
$signatureCases = [
    '未知路径参数' => <<<'PHP'
<?php
namespace TypeApp\RoutingProbe;
final class UnknownParameter { public function handle(string $slug): array { return []; } }
PHP,
    '联合类型' => <<<'PHP'
<?php
namespace TypeApp\RoutingProbe;
final class UnionParameter { public function handle(string|int $id): array { return []; } }
PHP,
    '引用参数' => <<<'PHP'
<?php
namespace TypeApp\RoutingProbe;
final class ReferenceParameter { public function handle(string &$id): array { return []; } }
PHP,
    '可变参数' => <<<'PHP'
<?php
namespace TypeApp\RoutingProbe;
final class VariadicParameter { public function handle(string ...$id): array { return []; } }
PHP,
    '无返回类型' => <<<'PHP'
<?php
namespace TypeApp\RoutingProbe;
final class MissingReturn { public function handle(string $id) { return []; } }
PHP,
];
$signatureDirectory = $directory . '/signatures';
if (!is_dir($signatureDirectory)) {
    mkdir($signatureDirectory, 0777, true);
}
foreach ($signatureCases as $label => $contents) {
    $probe = $signatureDirectory . '/' . md5($label) . '.php';
    file_put_contents($probe, $contents);
    $rejected = false;
    try {
        $compiler->generate($root, ['class' => 'TypeApp\\Generated\\Signature' . md5($label), 'routes' => [[
            'path' => '/probe/{id}', 'handler' => ['TypeApp\\RoutingProbe\\' . trim(strtok(substr($contents, strpos($contents, 'class ') + 6), ' '), '{'), 'handle'],
        ]]], [$probe]);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    expect($rejected, '构建未拒绝动作签名：' . $label);
    unlink($probe);
}
$statusCases = [
    '非 2xx' => ['status' => 302],
    'array 204' => ['status' => 204],
    'array 205' => ['status' => 205],
    'void 非 204' => ['handler' => ['TypeApp\\RoutingExample\\BooksController', 'typedDestroy'], 'status' => 200],
    'Response 固定状态' => ['handler' => ['TypeApp\\RoutingExample\\BooksController', 'lookup'], 'status' => 201],
];
foreach ($statusCases as $label => $changes) {
    $routeCase = ['path' => '/status/{id}', 'methods' => ['GET'], 'handler' => ['TypeApp\\RoutingExample\\BooksController', 'typedShow'],
        'constraints' => ['id' => '[0-9]+']];
    $candidate = ['class' => $configuration['class'], 'routes' => [array_replace($routeCase, $changes)]];
    $rejected = false;
    try {
        $compiler->generate($root, $candidate, [$source, $validationSource]);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    expect($rejected, '构建未拒绝状态冲突：' . $label);
}
$inputRoute = ['path' => '/input', 'handler' => ['TypeApp\\RoutingExample\\BooksController', 'searchInput']];
foreach (['未知策略' => ['unknown' => true], '正文上限零值' => ['maxBytes' => 0], '过宽深度' => ['maxDepth' => 129],
    '错误部分更新类型' => ['patch' => 'yes'], '非法场景' => ['scenario' => ''], '非对象' => 'default'] as $label => $options) {
    $rejected = false;
    try {
        $compiler->generate($root, ['class' => $configuration['class'], 'attributes' => [], 'routes' => [$inputRoute + ['input' => $options]]], [$source, $validationSource]);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    expect($rejected, '构建未拒绝输入策略：' . $label);
}
$missingValidate = false;
try {
    $compiler->generate($root, ['class' => $configuration['class'], 'attributes' => [], 'routes' => [$inputRoute]], [$source]);
} catch (RuntimeException $error) {
    $missingValidate = str_contains($error->getMessage(), 'type-validate');
}
expect($missingValidate, '类型化输入没有核对生产校验依赖');
$inputProbe = $directory . '/input-signature.php';
$inputSignatures = [
    '未实现契约' => ['class Input', 'public static function fromData(Data $data): Input { return new Input(); }'],
    '工厂返回接口' => ['class Input implements ValidatedInput', 'public static function fromData(Data $data): ValidatedInput { return new Input(); }'],
    '工厂缺少类型' => ['class Input implements ValidatedInput', 'public static function fromData($data): Input { return new Input(); }'],
    '工厂是实例方法' => ['class Input implements ValidatedInput', 'public function fromData(Data $data): Input { return new Input(); }'],
];
try {
    foreach ($inputSignatures as $label => [$declaration, $factory]) {
        file_put_contents($inputProbe, '<?php namespace InputProbe; use Type\\Validate\\{Data,Schema,ValidatedInput}; '
            . $declaration . ' { public static function schema(): Schema { throw new \\RuntimeException("禁止构建执行"); } ' . $factory . ' } '
            . 'final class Controller { public function handle(Input $input): array { return []; } }');
        $rejected = false;
        try {
            $compiler->generate($root, ['class' => 'InputProbe\\Routes', 'routes' => [['path' => '/input', 'handler' => ['InputProbe\\Controller', 'handle']]]], [$inputProbe, $validationSource]);
        } catch (RuntimeException $error) {
            $rejected = true;
        }
        expect($rejected, '构建未拒绝输入签名：' . $label);
    }
} finally {
    unlink($inputProbe);
}
$missingSource = false;
try {
    $compiler->generate($root, ['class' => $configuration['class'], 'attributes' => ['examples/routing/Controllers.php']], []);
} catch (RuntimeException $error) {
    $missingSource = true;
}
expect($missingSource, 'Attribute 没有受生产源码集合限制');
$nested = ['class' => $configuration['class'], 'routes' => [['prefix' => '/tenants/{tenant}', 'name-prefix' => 'tenant.', 'middleware' => ['outer'], 'constraints' => ['tenant' => '[a-z]+'], 'routes' => [
    ['prefix' => '/v1', 'name-prefix' => 'v1.', 'middleware' => ['inner'], 'routes' => [['resource' => '/books', 'name' => 'books',
        'controller' => TypeApp\RoutingExample\BooksController::class, 'only' => ['show'], 'constraints' => ['id' => '[0-9]+']]]],
]]]];
$nestedRoutes = $compiler->generate($root, $nested, [$source, $validationSource])['routes'];
expect(count($nestedRoutes) === 1 && $nestedRoutes[0]['path'] === '/tenants/{tenant}/v1/books/{id}'
    && $nestedRoutes[0]['name'] === 'tenant.v1.books.show' && $nestedRoutes[0]['middleware'] === ['outer', 'inner'], '嵌套分组或资源 only 错误');
$definition = new Type\Core\Http\RouteDefinition($nestedRoutes[0]['methods'], $nestedRoutes[0]['path'], $nestedRoutes[0]['segments'], $nestedRoutes[0]['name']);
expect($definition->url(['tenant' => 'acme', 'id' => 42]) === '/tenants/acme/v1/books/42'
    && $definition->match('/tenants/23/v1/books/42') === null, '分组参数约束没有进入同一运行模型');
$fixture = tempnam($directory, 'attribute_');
expect($fixture !== false, '无法准备静态解析验证');
try {
    file_put_contents($fixture, <<<'PHP'
<?php
namespace TypeApp\StaticRouteProbe;
use Type\Core\Http\Attribute\Route;
throw new \RuntimeException('构建期间禁止执行应用源码');
#[Route('/probe')]
final class Controller {
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface { throw new \RuntimeException('不应执行'); }
}
PHP);
    // 输入必须是 PHP 文件；该临时样例只验证构建器解析，不进入应用产物。
    rename($fixture, $fixture . '.php');
    $fixture .= '.php';
    $staticConfig = ['class' => 'TypeApp\\Generated\\StaticRoutes', 'attributes' => [substr($fixture, strlen($root) + 1)]];
    expect(count($compiler->generate($root, $staticConfig, [$fixture])['routes']) === 1, '类级 Route Attribute 未生成');
    expect(!class_exists(TypeApp\StaticRouteProbe\Controller::class, false), '静态解析加载了控制器');
    $dynamic = str_replace("#[Route('/probe')]", "#[Route(strtolower('/PROBE'))]", file_get_contents($fixture));
    file_put_contents($fixture, $dynamic);
    $rejected = false;
    try {
        $compiler->generate($root, $staticConfig, [$fixture]);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    expect($rejected, '路由 Attribute 执行了动态表达式');
    $legacy = $directory . '/legacy-routes.json';
    file_put_contents($legacy, '{"class":"TypeApp\\Generated\\Routes"}');
    $rejectedJson = false;
    try {
        $compiler->declarations($root, 'build/routing-build-tests/legacy-routes.json');
    } catch (RuntimeException $error) {
        $rejectedJson = str_contains($error->getMessage(), '不再读取 JSON');
    }
    expect($rejectedJson, '路由声明仍然接受 JSON 文件');
    $dynamicRoute = $directory . '/dynamic-route.php';
    file_put_contents($dynamicRoute, "<?php\ndeclare(strict_types=1);\nreturn ['class' => strtolower('TypeApp\\\\Generated\\\\Routes')];\n");
    $rejectedDynamic = false;
    try {
        $compiler->declarations($root, 'build/routing-build-tests/' . basename($dynamicRoute));
    } catch (RuntimeException $error) {
        $rejectedDynamic = true;
    }
    expect($rejectedDynamic, '路由 PHP 声明执行了动态表达式');
} finally {
    foreach ([$fixture, $directory . '/legacy-routes.json', $directory . '/dynamic-route.php'] as $temporary) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}
echo "路由声明、资源展开、Attribute 与显式配置一致性验证通过。\n";

// 名称解析必须先于签名裁剪：局部类仍参与重名检查，继承动作和别名不丢失。
$symbolProbe = $directory . '/nested-symbols.php';
try {
    $symbolSource = <<<'PHP'
<?php
namespace TypeApp\NestedRouteProbe;
use Type\Core\Http\Attribute\Route as Endpoint;
use Psr\Http\Message\ServerRequestInterface as Incoming;
use Psr\Http\Message\ResponseInterface as Reply;
class ParentController {
    public function handle(Incoming $request): Reply {
        if (false) { class Local {} }
        throw new \RuntimeException('构建不执行动作');
    }
}
#[Endpoint('/inherited')]
final class Controller extends ParentController {}
PHP;
    file_put_contents($symbolProbe, $symbolSource);
    $symbolConfiguration = ['class' => 'TypeApp\\Generated\\NestedRoutes', 'attributes' => true];
    $symbolRoutes = $compiler->generate($root, $symbolConfiguration, [$symbolProbe])['routes'];
    expect(count($symbolRoutes) === 1 && $symbolRoutes[0]['path'] === '/inherited'
        && $symbolRoutes[0]['action']['arguments'] === [['name' => 'request', 'type' => 'request']]
        && $symbolRoutes[0]['action']['return'] === 'response', '局部声明、Attribute 别名或继承动作名称解析丢失');
    file_put_contents($symbolProbe, $symbolSource . "\nclass Local {}\n");
    $duplicateRejected = false;
    try {
        $compiler->generate($root, $symbolConfiguration, [$symbolProbe]);
    } catch (RuntimeException $error) {
        $duplicateRejected = str_contains($error->getMessage(), '路由源码类重名');
    }
    expect($duplicateRejected, '方法内具名类与顶级类的重名没有被拒绝');
} finally {
    if (is_file($symbolProbe)) {
        unlink($symbolProbe);
    }
}
