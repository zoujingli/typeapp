<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;

/** 只解析已纳入生产源码的 AST；注解与 route.php 生成同一模型和直接调用。 */
final class RouteCompiler
{
    /**
     * 读取构建配置中的 routing：空值表示不生成；相对 PHP 文件经 AST 求值；测试可直接传入声明对象。
     *
     * @return array<string, mixed>
     * @throws RuntimeException JSON 路径、可执行 PHP、非对象声明。
     */
    public function declarations(string $root, mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (is_string($value)) {
            if (str_ends_with(strtolower($value), '.json')) {
                throw new RuntimeException('routing 必须是相对 PHP 文件（如 config/route.php），不再读取 JSON');
            }
            if (!str_ends_with($value, '.php')) {
                throw new RuntimeException('routing 必须是相对 PHP 文件（如 config/route.php）');
            }
            $value = $this->phpDeclaration($this->localFile($root, $value));
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('routing 必须是相对 PHP 文件或声明对象');
        }
        return $value;
    }

    public function generate(string $root, array $configuration, array $sources): array
    {
        $this->keys($configuration, ['class', 'routes', 'attributes']);
        $class = $configuration['class'] ?? '';
        if (!is_string($class) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $class)) {
            throw new RuntimeException('routing.class 必须是带命名空间的生成类名');
        }
        [$classes, $files] = $this->symbols($sources);
        if (isset($classes[strtolower($class)])) {
            throw new RuntimeException('生成路由类与生产类重名：' . $class);
        }
        $routes = [];
        $context = ['prefix' => '', 'name-prefix' => '', 'middleware' => [], 'constraints' => []];
        $this->expand($configuration['routes'] ?? [], $context, $classes, $routes);
        $selected = $this->attributeFiles($root, $configuration, $files);
        foreach ($classes as $symbol) {
            if ($selected === null || isset($selected[$symbol['file']])) {
                $this->attributes($symbol, $context, $classes, $routes);
            }
        }
        $this->conflicts($routes);
        $code = $this->render($class, $routes);
        try {
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (\PhpParser\Error $error) {
            throw new RuntimeException('生成路由 PHP 无效：' . $error->getMessage(), 0, $error);
        }
        return ['class' => $class, 'routes' => $routes, 'code' => $code];
    }

    private function expand(mixed $rows, array $context, array $classes, array &$routes): void
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('routes 必须是路由列表');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('每条路由必须是声明对象');
            }
            if (array_key_exists('routes', $row)) {
                $this->keys($row, ['prefix', 'name-prefix', 'middleware', 'constraints', 'routes']);
                $this->expand($row['routes'], $this->group($context, $row), $classes, $routes);
            } elseif (array_key_exists('resource', $row)) {
                $this->resource($row, $context, $classes, $routes);
            } else {
                $routes[] = $this->route($row, $context, $classes);
            }
        }
    }

    private function group(array $parent, array $row): array
    {
        $prefix = $row['prefix'] ?? '';
        $namePrefix = $row['name-prefix'] ?? '';
        if (!is_string($prefix) || str_contains($prefix, '?') || str_contains($prefix, '#')
            || ($prefix !== '' && (!str_starts_with($prefix, '/') || str_ends_with($prefix, '/')))
            || !is_string($namePrefix) || ($namePrefix !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*\.$/D', $namePrefix))) {
            throw new RuntimeException('分组路径必须为空或无尾斜线的绝对路径，名称前缀必须以点结尾');
        }
        return ['prefix' => $parent['prefix'] . $prefix, 'name-prefix' => $parent['name-prefix'] . $namePrefix,
            'middleware' => array_merge($parent['middleware'], $this->middleware($row['middleware'] ?? [])),
            'constraints' => array_replace($parent['constraints'], $this->constraints($row['constraints'] ?? []))];
    }

    private function resource(array $row, array $context, array $classes, array &$routes): void
    {
        $this->keys($row, ['resource', 'controller', 'name', 'parameter', 'only', 'middleware', 'constraints']);
        $path = $row['resource'] ?? null;
        $name = $row['name'] ?? null;
        $parameter = $row['parameter'] ?? 'id';
        if (!is_string($path) || !str_starts_with($path, '/') || str_ends_with($path, '/')
            || !is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name)
            || !is_string($parameter) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $parameter)) {
            throw new RuntimeException('资源路由路径、名称或参数无效');
        }
        $actions = [
            'index' => [['GET'], ''], 'create' => [['GET'], '/create'], 'store' => [['POST'], ''],
            'show' => [['GET'], '/{' . $parameter . '}'], 'edit' => [['GET'], '/{' . $parameter . '}/edit'],
            'update' => [['PATCH', 'PUT'], '/{' . $parameter . '}'], 'destroy' => [['DELETE'], '/{' . $parameter . '}'],
        ];
        $only = $row['only'] ?? array_keys($actions);
        if (!is_array($only) || !array_is_list($only) || $only === [] || array_filter($only, static fn (mixed $action): bool => !is_string($action)) !== []
            || count(array_unique($only)) !== count($only) || array_diff($only, array_keys($actions)) !== []) {
            throw new RuntimeException('资源路由 only 包含无效或重复动作');
        }
        $context['middleware'] = array_merge($context['middleware'], $this->middleware($row['middleware'] ?? []));
        $resourceConstraints = $this->constraints($row['constraints'] ?? []);
        preg_match_all('/(?:^|\/)\{([A-Za-z_][A-Za-z0-9_]*)\}(?=\/|$)/', $context['prefix'] . $path . '/{' . $parameter . '}', $matches);
        if (array_diff_key($resourceConstraints, array_fill_keys($matches[1], true)) !== []) {
            throw new RuntimeException('资源路由约束引用不存在的参数');
        }
        $context['constraints'] = array_replace($context['constraints'], $resourceConstraints);
        foreach ($actions as $action => [$methods, $suffix]) {
            if (in_array($action, $only, true)) {
                $routes[] = $this->route(['methods' => $methods, 'path' => $path . $suffix, 'name' => $name . '.' . $action,
                    'handler' => [$row['controller'] ?? null, $action]], $context, $classes);
            }
        }
    }

    private function route(array $row, array $context, array $classes): array
    {
        $this->keys($row, ['methods', 'path', 'name', 'handler', 'middleware', 'constraints']);
        $path = $row['path'] ?? null;
        $methods = $row['methods'] ?? ['GET'];
        $name = $row['name'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')
            || !is_array($methods) || !array_is_list($methods) || $methods === []
            || ($name !== null && (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name)))) {
            throw new RuntimeException('路由路径、方法列表或名称无效');
        }
        foreach ($methods as &$method) {
            if (!is_string($method) || !preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/D', $method)) {
                throw new RuntimeException('路由方法无效');
            }
            $method = strtoupper($method);
        }
        unset($method);
        if (count(array_unique($methods)) !== count($methods)) {
            throw new RuntimeException('路由方法重复');
        }
        sort($methods);
        $path = $context['prefix'] . $path;
        if (str_starts_with($path, '//')) {
            throw new RuntimeException('路由必须是本地绝对路径，不能包含 authority');
        }
        $constraints = array_replace($context['constraints'], $this->constraints($row['constraints'] ?? []));
        $segments = [];
        $parameters = [];
        foreach ($path === '/' ? [] : explode('/', substr($path, 1)) as $piece) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $piece, $match)) {
                $parameter = $match[1];
                if (isset($parameters[$parameter])) {
                    throw new RuntimeException('路由参数重复：' . $path);
                }
                $parameters[$parameter] = true;
                $segments[] = ['parameter' => $parameter, 'pattern' => $this->pattern($constraints[$parameter] ?? '[^/]+')];
            } else {
                if (str_contains($piece, '{') || str_contains($piece, '}') || preg_match('/%(?![A-Fa-f0-9]{2})/', $piece)) {
                    throw new RuntimeException('路由参数必须占完整段，且编码必须有效：' . $path);
                }
                $literal = rawurldecode($piece);
                if ($literal === '.' || $literal === '..' || preg_match('/[\\\\\/\x00-\x1f\x7f]/', $literal) || preg_match('//u', $literal) !== 1) {
                    throw new RuntimeException('路由包含非法静态路径段：' . $path);
                }
                $segments[] = ['literal' => $literal];
            }
        }
        if (array_diff_key($row['constraints'] ?? [], $parameters) !== []) {
            throw new RuntimeException('路由约束引用不存在的参数：' . $path);
        }
        $handler = $row['handler'] ?? null;
        if (!is_array($handler) || !array_is_list($handler) || count($handler) !== 2
            || !is_string($handler[0]) || !is_string($handler[1])) {
            throw new RuntimeException('路由 handler 必须是控制器类和方法二元组');
        }
        $symbol = $classes[strtolower($handler[0])] ?? throw new RuntimeException('生产源码中没有路由控制器：' . $handler[0]);
        if ($symbol['node']->isAbstract()) {
            throw new RuntimeException('路由控制器不能是抽象类');
        }
        $method = $this->method($symbol, $handler[1], $classes, []);
        if ($method === null || !$method->isPublic() || $method->isStatic() || $method->isAbstract() || $method->byRef
            || count($method->params) !== 1 || $method->params[0]->variadic || $method->params[0]->byRef
            || !($method->params[0]->type instanceof Node\Name) || $method->params[0]->type->toString() !== 'Psr\\Http\\Message\\ServerRequestInterface'
            || !($method->returnType instanceof Node\Name) || $method->returnType->toString() !== 'Psr\\Http\\Message\\ResponseInterface') {
            throw new RuntimeException('控制器动作必须是公开实例方法并使用 PSR 请求和响应签名：' . implode('::', $handler));
        }
        return ['methods' => $methods, 'path' => $path, 'segments' => $segments,
            'name' => $name === null ? null : $context['name-prefix'] . $name,
            'handler' => [$symbol['name'], $method->name->toString()],
            'middleware' => array_merge($context['middleware'], $this->middleware($row['middleware'] ?? []))];
    }

    private function attributes(array $symbol, array $context, array $classes, array &$routes): void
    {
        $node = $symbol['node'];
        $group = null;
        foreach ($this->attributeList($node->attrGroups) as $attribute) {
            if ($attribute->name->toString() === 'Type\\Core\\Http\\Attribute\\Group') {
                if ($group !== null) {
                    throw new RuntimeException('控制器 Group Attribute 重复：' . $symbol['name']);
                }
                $group = $this->arguments($attribute, ['prefix', 'namePrefix', 'middleware', 'constraints']);
                if (array_key_exists('namePrefix', $group)) {
                    $group['name-prefix'] = $group['namePrefix'];
                    unset($group['namePrefix']);
                }
                $context = $this->group($context, $group);
            }
        }
        foreach ($this->attributeList($node->attrGroups) as $attribute) {
            if ($attribute->name->toString() === 'Type\\Core\\Http\\Attribute\\Route') {
                $row = $this->arguments($attribute, ['path', 'methods', 'name', 'constraints', 'middleware']);
                $row['handler'] = [$symbol['name'], 'handle'];
                $routes[] = $this->route($row, $context, $classes);
            } elseif ($attribute->name->toString() === 'Type\\Core\\Http\\Attribute\\Resource') {
                $row = $this->arguments($attribute, ['path', 'name', 'parameter', 'only', 'constraints', 'middleware']);
                $row['resource'] = $row['path'] ?? null;
                unset($row['path']);
                $row['controller'] = $symbol['name'];
                $this->resource($row, $context, $classes, $routes);
            }
        }
        foreach ($node->getMethods() as $method) {
            foreach ($this->attributeList($method->attrGroups) as $attribute) {
                if ($attribute->name->toString() === 'Type\\Core\\Http\\Attribute\\Route') {
                    $row = $this->arguments($attribute, ['path', 'methods', 'name', 'constraints', 'middleware']);
                    $row['handler'] = [$symbol['name'], $method->name->toString()];
                    $routes[] = $this->route($row, $context, $classes);
                } elseif (in_array($attribute->name->toString(), ['Type\\Core\\Http\\Attribute\\Group', 'Type\\Core\\Http\\Attribute\\Resource'], true)) {
                    throw new RuntimeException('Group 和 Resource Attribute 只能用于控制器类');
                }
            }
        }
    }

    private function arguments(Node\Attribute $attribute, array $names): array
    {
        $values = [];
        $position = 0;
        $named = false;
        $evaluator = new ConstExprEvaluator(static function (Node\Expr $expression): mixed {
            if ($expression instanceof Node\Expr\ClassConstFetch && $expression->class instanceof Node\Name
                && $expression->name instanceof Node\Identifier && $expression->name->toString() === 'class') {
                return $expression->class->toString();
            }
            throw new RuntimeException('路由 Attribute 只接受常量表达式，不执行应用代码');
        });
        foreach ($attribute->args as $argument) {
            if ($argument->unpack || $argument->byRef || ($argument->name === null && $named)) {
                throw new RuntimeException('路由 Attribute 参数顺序或解包无效');
            }
            $name = $argument->name?->toString() ?? ($names[$position++] ?? '');
            $named = $named || $argument->name !== null;
            if (!in_array($name, $names, true) || array_key_exists($name, $values)) {
                throw new RuntimeException('路由 Attribute 参数未知或重复：' . $name);
            }
            try {
                $values[$name] = $evaluator->evaluateDirectly($argument->value);
            } catch (\Throwable $error) {
                throw new RuntimeException('路由 Attribute 必须能静态求值：' . $name, 0, $error);
            }
        }
        return $values;
    }

    private function attributeList(array $groups): array
    {
        $attributes = [];
        foreach ($groups as $group) {
            array_push($attributes, ...$group->attrs);
        }
        return $attributes;
    }

    private function conflicts(array $routes): void
    {
        $names = [];
        foreach ($routes as $index => $route) {
            if ($route['name'] !== null) {
                if (isset($names[$route['name']])) {
                    throw new RuntimeException('路由名称重复：' . $route['name']);
                }
                $names[$route['name']] = true;
            }
            for ($prior = 0; $prior < $index; $prior++) {
                $other = $routes[$prior];
                if (array_intersect($route['methods'], $other['methods']) === [] || count($route['segments']) !== count($other['segments'])) {
                    continue;
                }
                $ambiguous = true;
                foreach ($route['segments'] as $part => $segment) {
                    $previous = $other['segments'][$part];
                    if (array_key_exists('literal', $segment) !== array_key_exists('literal', $previous)
                        || (isset($segment['literal']) && $segment['literal'] !== $previous['literal'])) {
                        $ambiguous = false;
                        break;
                    }
                }
                if ($ambiguous) {
                    throw new RuntimeException('路由重复或同形参数歧义，无法证明约束无交集：' . $route['path'] . ' / ' . $other['path']);
                }
            }
        }
    }

    private function middleware(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException('路由中间件必须是标识列表');
        }
        foreach ($values as $value) {
            if (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/D', $value)) {
                throw new RuntimeException('路由中间件标识无效');
            }
        }
        return $values;
    }

    private function constraints(mixed $values): array
    {
        if (!is_array($values)) {
            throw new RuntimeException('路由约束必须是参数映射');
        }
        foreach ($values as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_string($value)) {
                throw new RuntimeException('路由约束声明无效');
            }
            $this->pattern($value);
        }
        return $values;
    }

    private function pattern(string $expression): string
    {
        if ($expression === '' || strlen($expression) > 512) {
            throw new RuntimeException('路由约束表达式必须在 1 到 512 字节之间');
        }
        foreach (['~', '#', '%', '!', ';', '@', '`'] as $delimiter) {
            if (!str_contains($expression, $delimiter)) {
                $pattern = $delimiter . '(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=256)\\A(?:' . $expression . ')\\z' . $delimiter . 'uD';
                if (@preg_match($pattern, '') === false) {
                    throw new RuntimeException('路由参数正则无效：' . $expression);
                }
                return $pattern;
            }
        }
        throw new RuntimeException('路由约束包含全部可用分隔符');
    }

    private function method(array $symbol, string $name, array $classes, array $visited): ?Node\Stmt\ClassMethod
    {
        $key = strtolower($symbol['name']);
        if (isset($visited[$key])) {
            throw new RuntimeException('路由控制器继承循环');
        }
        $visited[$key] = true;
        foreach ($symbol['node']->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $name) === 0) {
                return $method;
            }
        }
        $parent = $symbol['node']->extends?->toString();
        return $parent !== null && isset($classes[strtolower($parent)]) ? $this->method($classes[strtolower($parent)], $name, $classes, $visited) : null;
    }

    private function symbols(array $sources): array
    {
        $files = [];
        foreach ($sources as $source) {
            if (is_file($source)) {
                if (pathinfo($source, PATHINFO_EXTENSION) === 'php') {
                    $files[realpath($source)] = true;
                }
            } elseif (is_dir($source)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $files[$file->getRealPath()] = true;
                    }
                }
            } else {
                throw new RuntimeException('路由输入源码不存在');
            }
        }
        ksort($files);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $classes = [];
        foreach (array_keys($files) as $file) {
            try {
                $nodes = (new NodeTraverser(new NameResolver()))->traverse($parser->parse(file_get_contents($file)) ?? []);
            } catch (\PhpParser\Error $error) {
                throw new RuntimeException('路由源码解析失败：' . $file . '，' . $error->getMessage(), 0, $error);
            }
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $node) {
                if ($node->name === null) {
                    continue;
                }
                $name = $node->namespacedName->toString();
                if (isset($classes[strtolower($name)])) {
                    throw new RuntimeException('路由源码类重名：' . $name);
                }
                $classes[strtolower($name)] = ['name' => $name, 'node' => $node, 'file' => $file];
            }
        }
        return [$classes, $files];
    }

    /**
     * 只接受 declare(strict_types=1) 与一次 return 常量数组，不 include、不读环境。
     *
     * @return array<string, mixed>
     */
    private function phpDeclaration(string $file): array
    {
        $contents = @file_get_contents($file, false, null, 0, 1048577);
        if ($contents === false || strlen($contents) > 1048576) {
            throw new RuntimeException('路由声明不可读或超过 1 MiB');
        }
        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($contents);
        } catch (\PhpParser\Error $error) {
            throw new RuntimeException('路由声明 PHP 语法无效，行 ' . $error->getStartLine(), 0, $error);
        }
        if (!is_array($statements) || count($statements) !== 2 || !$statements[0] instanceof Node\Stmt\Declare_
            || $statements[0]->stmts !== null || count($statements[0]->declares) !== 1
            || $statements[0]->declares[0]->key->toString() !== 'strict_types'
            || !$statements[0]->declares[0]->value instanceof Node\Scalar\Int_ || $statements[0]->declares[0]->value->value !== 1
            || !$statements[1] instanceof Node\Stmt\Return_ || $statements[1]->expr === null) {
            throw new RuntimeException('路由声明只能包含 declare(strict_types=1) 和一次 return 数组');
        }
        try {
            $value = (new ConstExprEvaluator(static function (Node\Expr $expression): mixed {
                throw new RuntimeException('路由声明只接受常量表达式，不执行应用代码');
            }))->evaluateDirectly($statements[1]->expr);
        } catch (\Throwable $error) {
            throw new RuntimeException('路由声明必须能静态求值', 0, $error);
        }
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new RuntimeException('路由声明必须返回含 class 的关联数组');
        }

        return $value;
    }

    /**
     * 未写 attributes 且没有显式 routes 时，扫描全部已纳入生产源码的控制器注解。
     *
     * @param array<string, mixed> $configuration
     * @param array<string, true> $files
     * @return array<string, true>|null null 表示扫描全部生产类
     */
    private function attributeFiles(string $root, array $configuration, array $files): ?array
    {
        if (!array_key_exists('attributes', $configuration)) {
            return isset($configuration['routes']) ? [] : null;
        }
        $attributes = $configuration['attributes'];
        if ($attributes === true) {
            return null;
        }
        if (!is_array($attributes) || !array_is_list($attributes) || count(array_unique($attributes, SORT_REGULAR)) !== count($attributes)) {
            throw new RuntimeException('routing.attributes 必须是 true 或不重复的显式 PHP 文件列表');
        }
        $selected = [];
        foreach ($attributes as $file) {
            if (!is_string($file)) {
                throw new RuntimeException('Attribute 输入必须是 PHP 文件路径');
            }
            $file = $this->localFile($root, $file);
            if (!isset($files[$file])) {
                throw new RuntimeException('Attribute 文件未纳入生产编译源码：' . $file);
            }
            $selected[$file] = true;
        }

        return $selected;
    }

    private function localFile(string $root, string $relative): string
    {
        $root = realpath($root);
        $file = $root === false ? false : realpath($root . '/' . $relative);
        if ($file === false || !BuildPlatform::contains($root, $file) || !is_file($file)) {
            throw new RuntimeException('路由声明文件不存在或超出应用目录');
        }
        return $file;
    }

    private function keys(array $values, array $allowed): void
    {
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new RuntimeException('路由包含未知配置项');
        }
    }

    private function render(string $class, array $routes): string
    {
        $parts = explode('\\', $class);
        $short = array_pop($parts);
        $namespace = implode('\\', $parts);
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class {$short}\n{\n"
            . "    /**\n     * @param array<class-string, \\Closure(): object> \$controllers 零参数控制器工厂。\n"
            . "     * @param array<string, \\Closure(): \\Psr\\Http\\Server\\MiddlewareInterface> \$middleware 零参数中间件工厂。\n     */\n"
            . "    public static function register(\\Type\\Core\\Http\\Router \$router, array \$controllers, array \$middleware = []): void\n    {\n";
        $controllers = [];
        $middleware = [];
        foreach ($routes as $route) {
            $controllers[$route['handler'][0]] = true;
            foreach ($route['middleware'] as $name) {
                $middleware[$name] = true;
            }
        }
        foreach (['controllers' => $controllers, 'middleware' => $middleware] as $collection => $names) {
            foreach (array_keys($names) as $name) {
                $literal = var_export($name, true);
                $code .= "        if (!(\${$collection}[{$literal}] ?? null) instanceof \\Closure) { throw new \\InvalidArgumentException('缺少路由工厂：' . {$literal}); }\n";
            }
        }
        foreach ($routes as $index => $route) {
            [$controller, $action] = $route['handler'];
            $literal = var_export($controller, true);
            $definition = implode(', ', array_map(
                static fn (mixed $value): string => var_export($value, true),
                [$route['methods'], $route['path'], $route['segments'], $route['name']]
            ));
            $factories = implode(', ', array_map(static fn (string $name): string => '$middleware[' . var_export($name, true) . ']', $route['middleware']));
            $code .= "        \$factory{$index} = \$controllers[{$literal}];\n"
                . "        \$router->register(new \\Type\\Core\\Http\\RouteDefinition({$definition}),\n"
                . "            static fn (): \\Psr\\Http\\Server\\RequestHandlerInterface => new \\Type\\Core\\Http\\ActionHandler(\n"
                . "                static function (\\Psr\\Http\\Message\\ServerRequestInterface \$request) use (\$factory{$index}): \\Psr\\Http\\Message\\ResponseInterface {\n"
                . "                    \$controller = \$factory{$index}();\n"
                . "                    if (!\$controller instanceof \\{$controller}) { throw new \\RuntimeException('路由工厂返回了错误控制器'); }\n"
                . "                    return \$controller->{$action}(\$request);\n"
                . "                }), [{$factories}]);\n";
        }
        return $code . "    }\n}\n";
    }
}
