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

    /**
     * 静态读取生产类和路由声明，拒绝冲突后生成直接注册调用，不执行控制器。
     * @param array<string, mixed> $configuration 已解析的 routing 声明。
     * @param list<string> $sources 完整生产源码文件或目录。
     * @return array{class: string, routes: list<array<string, mixed>>, code: string}
     */
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
            if ($symbol['node'] instanceof Node\Stmt\Class_ && ($selected === null || isset($selected[$symbol['file']]))) {
                $this->attributes($symbol, $context, $classes, $routes);
            }
        }
        $this->conflicts($routes);
        // 生成契约已经完整落在 $routes；释放源码符号和筛选表后再渲染并校验
        // 大段生成 PHP，避免把路由 AST 与其他生成器的峰值叠加。
        unset($classes, $files, $selected, $context);
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
        $this->keys($row, ['methods', 'path', 'name', 'handler', 'middleware', 'constraints', 'status', 'input']);
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
        $status = $row['status'] ?? null;
        if ($status !== null && (!is_int($status) || $status < 200 || $status > 299)) {
            throw new RuntimeException('路由固定成功状态必须是 2xx 整数：' . $path);
        }
        $handler = $row['handler'] ?? null;
        if (!is_array($handler) || !array_is_list($handler) || count($handler) !== 2
            || !is_string($handler[0]) || !is_string($handler[1])) {
            throw new RuntimeException('路由 handler 必须是控制器类和方法二元组');
        }
        $symbol = $classes[strtolower($handler[0])] ?? throw new RuntimeException('生产源码中没有路由控制器：' . $handler[0]);
        if (!$symbol['node'] instanceof Node\Stmt\Class_ || $symbol['node']->isAbstract()) {
            throw new RuntimeException('路由控制器不能是抽象类');
        }
        $method = $this->method($symbol, $handler[1], $classes, []);
        if ($method === null || !$method->isPublic() || $method->isStatic() || $method->isAbstract() || $method->byRef) {
            throw new RuntimeException('控制器动作必须是公开实例方法：' . implode('::', $handler));
        }
        $action = $this->action($method, array_keys($parameters), $status, $handler, $classes, $row['input'] ?? []);
        return ['methods' => $methods, 'path' => $path, 'segments' => $segments,
            'name' => $name === null ? null : $context['name-prefix'] . $name,
            'handler' => [$symbol['name'], $method->name->toString()],
            'middleware' => array_merge($context['middleware'], $this->middleware($row['middleware'] ?? [])),
            'action' => $action];
    }

    /**
     * 分析动作参数与返回值，并把构建期可证明的调用契约保存到路由模型。
     *
     * 路径值只允许由同名占位符接收为 string/int；PSR 请求和已校验输入各最多一个。
     * 返回值只允许数组、void 或 PSR 响应，状态码在构建期完成相容性检查。
     *
     * @param list<string> $parameters 路由中声明的路径参数名。
     * @param int|null $status 路由声明的固定成功状态。
     * @return array{arguments: list<array{name: string, type: string}>, return: string, status: int|null}
     */
    private function action(Node\Stmt\ClassMethod $method, array $parameters, ?int $status, array $handler, array $classes, mixed $input): array
    {
        $available = array_fill_keys($parameters, true);
        $used = [];
        $arguments = [];
        $requestCount = 0;
        $inputClass = null;
        foreach ($method->params as $parameter) {
            if ($parameter->variadic || $parameter->byRef || $parameter->type === null) {
                throw new RuntimeException('控制器动作参数必须是非引用的明确类型：' . implode('::', $handler));
            }
            $name = $parameter->var->name;
            if ($parameter->type instanceof Node\Name
                && $parameter->type->toString() === 'Psr\\Http\\Message\\ServerRequestInterface') {
                $requestCount++;
                if ($requestCount > 1) {
                    throw new RuntimeException('控制器动作最多接收一个 PSR 请求参数：' . implode('::', $handler));
                }
                $arguments[] = ['name' => $name, 'type' => 'request'];
                continue;
            }
            if ($parameter->type instanceof Node\Name) {
                if ($inputClass !== null || $parameter->default !== null) {
                    throw new RuntimeException('控制器动作最多接收一个无默认值的已校验输入：' . implode('::', $handler));
                }
                $inputClass = $this->validatedInput($parameter->type->toString(), $classes);
                $arguments[] = ['name' => $name, 'type' => 'input', 'class' => $inputClass];
                continue;
            }
            if (!$parameter->type instanceof Node\Identifier) {
                throw new RuntimeException('控制器动作只接受 string/int 路径参数、已校验输入或 PSR 请求：' . implode('::', $handler));
            }
            $type = strtolower($parameter->type->toString());
            if (!in_array($type, ['string', 'int'], true) || !isset($available[$name]) || isset($used[$name])
                || $parameter->default !== null) {
                throw new RuntimeException('控制器动作参数必须匹配路径占位符且不能带默认值：' . implode('::', $handler));
            }
            $used[$name] = true;
            $arguments[] = ['name' => $name, 'type' => $type];
        }
        $returnType = $method->returnType;
        $return = null;
        if ($returnType instanceof Node\Name && $returnType->toString() === 'Psr\\Http\\Message\\ResponseInterface') {
            $return = 'response';
        } elseif ($returnType instanceof Node\Identifier) {
            $candidate = strtolower($returnType->toString());
            if (in_array($candidate, ['array', 'void'], true)) {
                $return = $candidate;
            }
        }
        if ($return === null) {
            throw new RuntimeException('控制器动作返回值必须是 array、void 或 PSR 响应：' . implode('::', $handler));
        }
        if ($return === 'response' && $status !== null) {
            throw new RuntimeException('PSR 响应动作不能声明固定成功状态：' . implode('::', $handler));
        }
        if ($return === 'array' && $status !== null && in_array($status, [204, 205], true)) {
            throw new RuntimeException('数组动作不能使用无正文成功状态：' . implode('::', $handler));
        }
        if ($return === 'void' && $status !== null && $status !== 204) {
            throw new RuntimeException('void 动作只能省略状态或使用 204：' . implode('::', $handler));
        }
        $result = ['arguments' => $arguments, 'return' => $return,
            'status' => $status ?? ($return === 'void' ? 204 : ($return === 'array' ? 200 : null))];
        if ($inputClass !== null) {
            $result['input'] = $this->inputOptions($input);
        } elseif ($input !== []) {
            throw new RuntimeException('没有已校验输入参数的动作不能声明 input 策略');
        }
        return $result;
    }

    /** 静态核对组件、契约与具体工厂，不调用业务 schema 或构造器。 */
    private function validatedInput(string $name, array $classes): string
    {
        foreach (['Type\\Validate\\ValidatedInput', 'Type\\Validate\\Schema', 'Type\\Validate\\Data', 'Type\\Validate\\Input'] as $required) {
            if (!isset($classes[strtolower($required)])) {
                throw new RuntimeException('类型化输入需要将 type-validate 完整纳入生产依赖：' . $required);
            }
        }
        $symbol = $classes[strtolower($name)] ?? throw new RuntimeException('已校验输入未纳入生产源码：' . $name);
        $node = $symbol['node'];
        if (!$node instanceof Node\Stmt\Class_ || $node->isAbstract() || !$this->implementsInput($symbol, $classes, [])) {
            throw new RuntimeException('输入参数必须是实现 Type\\Validate\\ValidatedInput 的具体类：' . $name);
        }
        $schema = $this->method($symbol, 'schema', $classes, []);
        $factory = $this->method($symbol, 'fromData', $classes, []);
        if ($schema === null || !$schema->isPublic() || !$schema->isStatic() || $schema->isAbstract() || $schema->byRef
            || $schema->params !== [] || !$schema->returnType instanceof Node\Name || $schema->returnType->toString() !== 'Type\\Validate\\Schema') {
            throw new RuntimeException('输入 schema 必须是公开静态零参数方法并返回 Schema：' . $name);
        }
        if ($factory === null || !$factory->isPublic() || !$factory->isStatic() || $factory->isAbstract() || $factory->byRef
            || count($factory->params) !== 1 || $factory->params[0]->byRef || $factory->params[0]->variadic || $factory->params[0]->default !== null
            || !$factory->params[0]->type instanceof Node\Name || $factory->params[0]->type->toString() !== 'Type\\Validate\\Data'
            || !$factory->returnType instanceof Node\Name || !in_array(strtolower($factory->returnType->toString()), [strtolower($name), 'self'], true)
            || ($factory->returnType->toString() === 'self' && $node->getMethod('fromData') === null)) {
            throw new RuntimeException('输入 fromData 必须接收一个 Data 并明确返回自身类型：' . $name);
        }
        return $symbol['name'];
    }

    /** 沿已知类和接口证明输入契约，不使用运行时反射或自动加载。 */
    private function implementsInput(array $symbol, array $classes, array $visited): bool
    {
        $key = strtolower($symbol['name']);
        if (isset($visited[$key])) {
            throw new RuntimeException('输入类型继承循环');
        }
        if ($key === 'type\\validate\\validatedinput') {
            return true;
        }
        $visited[$key] = true;
        $node = $symbol['node'];
        $parents = $node instanceof Node\Stmt\Interface_ ? $node->extends : $node->implements;
        if ($node instanceof Node\Stmt\Class_ && $node->extends !== null) {
            $parents[] = $node->extends;
        }
        foreach ($parents as $parent) {
            $parentSymbol = $classes[strtolower($parent->toString())] ?? null;
            if ($parentSymbol !== null && $this->implementsInput($parentSymbol, $classes, $visited)) {
                return true;
            }
        }
        return false;
    }

    /** 输入预算和策略只有一份公开含义；运行时再与接入层预算取较小值。 */
    private function inputOptions(mixed $input): array
    {
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new RuntimeException('路由 input 必须是策略对象');
        }
        $this->keys($input, ['maxBytes', 'maxDepth', 'maxQueryBytes', 'maxQueryFields', 'scenario', 'patch']);
        $options = array_replace(['maxBytes' => 16384, 'maxDepth' => 8, 'maxQueryBytes' => 16384,
            'maxQueryFields' => 100, 'scenario' => 'default', 'patch' => null], $input);
        foreach (['maxBytes' => [1, 67108864], 'maxDepth' => [2, 128], 'maxQueryBytes' => [1, 67108864], 'maxQueryFields' => [1, 10000]] as $name => [$minimum, $maximum]) {
            if (!is_int($options[$name]) || $options[$name] < $minimum || $options[$name] > $maximum) {
                throw new RuntimeException('路由输入预算无效：' . $name);
            }
        }
        if (!is_string($options['scenario']) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/D', $options['scenario']) !== 1
            || (array_key_exists('patch', $input) && !is_bool($input['patch']))) {
            throw new RuntimeException('路由输入场景或部分更新策略无效');
        }
        return $options;
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
                $row = $this->arguments($attribute, ['path', 'methods', 'name', 'constraints', 'middleware', 'status', 'input']);
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
                    $row = $this->arguments($attribute, ['path', 'methods', 'name', 'constraints', 'middleware', 'status', 'input']);
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
                // 先从原始树找出类声明并删除方法体，再运行名称解析。名称解析器
                // 不需要业务表达式；提前裁剪可避免在全量生产源码上同时保留两棵 AST。
                $nodes = $parser->parse(file_get_contents($file)) ?? [];
                $classNodes = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_);
                foreach ($classNodes as $classNode) {
                    $this->compactClass($classNode);
                }
                $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
            } catch (\PhpParser\Error $error) {
                throw new RuntimeException('路由源码解析失败：' . $file . '，' . $error->getMessage(), 0, $error);
            }
            foreach ($classNodes as $node) {
                if ($node->name === null) {
                    continue;
                }
                $name = $node->namespacedName->toString();
                if (isset($classes[strtolower($name)])) {
                    throw new RuntimeException('路由源码类重名：' . $name);
                }
                $classes[strtolower($name)] = ['name' => $name, 'node' => $node, 'file' => $file];
            }
            unset($nodes);
        }
        return [$classes, $files];
    }

    /**
     * 删除路由验证永远不会读取的类成员和方法体，同时保留继承、接口、Attribute
     * 以及参数和返回类型节点，确保所有现有契约检查仍使用同一 AST 语义。
     */
    private function compactClass(Node\Stmt\ClassLike $node): void
    {
        $methods = [];
        foreach ($node->stmts as $statement) {
            if (!$statement instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $statement->stmts = null;
            $methods[] = $statement;
        }
        $node->stmts = $methods;
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
        $lines = [
            '<?php', '', 'declare(strict_types=1);', '', "namespace {$namespace};", '',
            "final class {$short}", '{',
            '    /** 从 Router 提取已匹配的非空字符串路径参数。 */',
            '    private static function routeString(array $parameters, string $name): string',
            '    {',
            '        $value = $parameters[$name] ?? null;',
            "        if (!is_string(\$value) || \$value === '') {",
            '            throw new \Type\Core\Http\HttpError(422, \'route_parameter_invalid\');',
            '        }',
            '        return $value;',
            '    }', '',
            '    /** 从 Router 提取规范十进制整数路径参数并拒绝溢出。 */',
            '    private static function routeInt(array $parameters, string $name): int',
            '    {',
            '        $value = self::routeString($parameters, $name);',
            "        if (preg_match('/^-?(?:0|[1-9][0-9]*)\\z/D', \$value) !== 1) {",
            '            throw new \Type\Core\Http\HttpError(422, \'route_parameter_invalid\');',
            '        }',
            '        $integer = filter_var($value, FILTER_VALIDATE_INT);',
            '        if (!is_int($integer)) {',
            "            throw new \\Type\\Core\\Http\\HttpError(422, 'route_parameter_invalid');",
            '        }',
            '        return $integer;',
            '    }', '',
            '    /** 递归确认数组只包含 JSON 数据值，不把业务对象隐式序列化。 */',
            '    private static function assertJsonValue(mixed $value, int $depth = 0): void',
            '    {',
            '        if ($depth > 128) {',
            '            throw new \Type\Core\Http\HttpError(500, \'response_encoding_failed\');',
            '        }',
            '        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {',
            '            return;',
            '        }',
            '        if (is_float($value)) {',
            '            if (!is_finite($value)) {',
            '                throw new \Type\Core\Http\HttpError(500, \'response_encoding_failed\');',
            '            }',
            '            return;',
            '        }',
            '        if (!is_array($value)) {',
            '            throw new \Type\Core\Http\HttpError(500, \'response_encoding_failed\');',
            '        }',
            '        foreach ($value as $item) {',
            '            self::assertJsonValue($item, $depth + 1);',
            '        }',
            '    }', '',
            '    /** 将业务数组编码成明确的 UTF-8 JSON 响应。 */',
            '    private static function jsonResponse(array $value, int $status): \Psr\Http\Message\ResponseInterface',
            '    {',
            '        self::assertJsonValue($value);',
            '        try {',
            '            $body = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);',
            '        } catch (\JsonException) {',
            '            throw new \Type\Core\Http\HttpError(500, \'response_encoding_failed\');',
            '        }',
            '        $messages = new \Type\Core\Http\Message\Factory();',
            "        return \$messages->createResponse(\$status)->withHeader('Content-Type', 'application/json; charset=utf-8')",
            '            ->withBody($messages->createStream($body));',
            '    }', '',
            '    /** 创建没有正文的 204 响应，正文交由响应发送层关闭。 */',
            '    private static function emptyResponse(int $status): \Psr\Http\Message\ResponseInterface',
            '    {',
            '        return (new \Type\Core\Http\Message\Factory())->createResponse($status);',
            '    }', '',
        ];
        foreach ($routes as $route) {
            if (isset($route['action']['input'])) {
                $lines = array_merge($lines, $this->inputHelpers());
                break;
            }
        }
        $lines = array_merge($lines, [
            '    /**',
            '     * @param array<class-string, \\Closure(): object> $controllers 零参数控制器工厂。',
            '     * @param array<string, \\Closure(): \\Psr\\Http\\Server\\MiddlewareInterface> $middleware 零参数中间件工厂。',
            '     */',
            '    public static function register(\\Type\\Core\\Http\\Router $router, array $controllers, array $middleware = []): void',
            '    {',
        ]);
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
                $lines[] = "        if (!(\${$collection}[{$literal}] ?? null) instanceof \\Closure) { throw new \\InvalidArgumentException('缺少路由工厂：' . {$literal}); }";
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
            $lines[] = "        \$factory{$index} = \$controllers[{$literal}];";
            $lines[] = "        \$router->register(new \\Type\\Core\\Http\\RouteDefinition({$definition}),";
            $lines[] = '            static fn (): \\Psr\\Http\\Server\\RequestHandlerInterface => new \\Type\\Core\\Http\\ActionHandler(';
            $lines[] = "                static function (\\Psr\\Http\\Message\\ServerRequestInterface \$request) use (\$factory{$index}): \\Psr\\Http\\Message\\ResponseInterface {";
            $lines[] = "                    \$controller = \$factory{$index}();";
            $lines[] = "                    if (!\$controller instanceof \\{$controller}) { throw new \\RuntimeException('路由工厂返回了错误控制器'); }";
            $actionArguments = $route['action']['arguments'];
            $usesPath = false;
            foreach ($actionArguments as $argument) {
                if ($argument['type'] !== 'request') {
                    $usesPath = true;
                    break;
                }
            }
            if ($usesPath) {
                $lines[] = "                    \$routeParameters = \$request->getAttribute('type.route.params', []);";
                $lines[] = "                    if (!is_array(\$routeParameters)) { throw new \\RuntimeException('路由参数属性无效'); }";
            }
            if (isset($route['action']['input'])) {
                $options = var_export($route['action']['input'], true);
                foreach ($actionArguments as $argument) {
                    if ($argument['type'] !== 'input') {
                        continue;
                    }
                    $inputClass = '\\' . $argument['class'];
                    $lines[] = '                    try {';
                    $lines[] = "                        \$inputData = self::readInput(\$request, {$inputClass}::schema(), {$options});";
                    $lines[] = '                    } catch (\\Type\\Validate\\ValidationException $inputError) {';
                    $lines[] = "                        return self::jsonResponse(['error' => \$inputError->errorCode(), 'fields' => \$inputError->errors()], \$inputError->status());";
                    $lines[] = '                    }';
                    $lines[] = "                    \$validatedInput = {$inputClass}::fromData(\$inputData);";
                }
            }
            $expressions = [];
            foreach ($actionArguments as $argument) {
                $argumentName = var_export($argument['name'], true);
                $expressions[] = match ($argument['type']) {
                    'request' => '$request',
                    'string' => "self::routeString(\$routeParameters, {$argumentName})",
                    'int' => "self::routeInt(\$routeParameters, {$argumentName})",
                    'input' => '$validatedInput',
                };
            }
            // 这是生成源码中的变量引用；字符串本身不能保留反斜杠，否则
            // PHP 解析器会把 `\$controller` 识别为命名空间分隔符。
            $invocation = '$controller->' . $action . '(' . implode(', ', $expressions) . ')';
            $resultType = $route['action']['return'];
            if ($resultType === 'response') {
                $lines[] = "                    return {$invocation};";
            } elseif ($resultType === 'array') {
                $status = (int) $route['action']['status'];
                $lines[] = "                    \$result = {$invocation};";
                $lines[] = "                    return self::jsonResponse(\$result, {$status});";
            } else {
                $status = (int) $route['action']['status'];
                $lines[] = "                    {$invocation};";
                $lines[] = "                    return self::emptyResponse({$status});";
            }
            $lines[] = "                }), [{$factories}]);";
        }
        $lines[] = '    }';
        $lines[] = '}';
        return implode("\n", $lines) . "\n";
    }

    /** 只在输入动作存在时生成组件组合，普通 PSR 应用不强制安装校验组件。 */
    private function inputHelpers(): array
    {
        return explode("\n", <<<'PHP'
    /**
     * 先分源读取，再应用 Schema；接入预算只会收紧声明，绝不隐式放宽。
     * @throws \Type\Validate\ValidationException 解析或字段校验失败，错误不含原始值。
     */
    private static function readInput(\Psr\Http\Message\ServerRequestInterface $request, \Type\Validate\Schema $schema, array $options): \Type\Validate\Data
    {
        $maxBytes = $options['maxBytes'];
        $maxDepth = $options['maxDepth'];
        $maxQueryBytes = $options['maxQueryBytes'];
        $maxQueryFields = $options['maxQueryFields'];
        $limits = $request->getAttribute('type.request-limits');
        if ($limits instanceof \Type\Core\Http\RequestLimits) {
            $maxBytes = min($maxBytes, $limits->bytes);
            $maxDepth = min($maxDepth, $limits->depth);
            $maxQueryBytes = min($maxQueryBytes, $limits->bytes);
            $maxQueryFields = min($maxQueryFields, $limits->fields);
        }
        $metadata = $schema->sources();
        $hasBody = false;
        $headers = [];
        foreach ($metadata as $fieldName => $field) {
            if ($field['source'] === 'body') {
                $hasBody = true;
            } elseif ($field['source'] === 'header') {
                $values = $request->getHeader($field['key']);
                if ($values !== []) {
                    if (!$field['list'] && count($values) !== 1) {
                        throw new \Type\Validate\ValidationException([$fieldName => ['multiple_values']]);
                    }
                    $headers[$field['key']] = $field['list'] ? $values : $values[0];
                }
            }
        }
        $input = new \Type\Validate\Input([]);
        if ($hasBody) {
            $body = \Type\Core\Http\RequestBody::read($request->getBody(), $maxBytes);
            if ($body !== '') {
                $contentTypes = $request->getHeader('Content-Type');
                $media = count($contentTypes) === 1 ? strtolower(trim(explode(';', $contentTypes[0], 2)[0])) : '';
                if ($media !== 'application/json' && preg_match('/^application\/[a-z0-9!#$&^_.+-]+\+json$/D', $media) !== 1) {
                    throw new \Type\Core\Http\HttpError(415, 'json_required');
                }
                $input = \Type\Validate\Input::json($body, $maxBytes, $maxDepth);
            }
        }
        $parameters = $request->getAttribute('type.route.params', []);
        if (!is_array($parameters)) {
            throw new \RuntimeException('路由参数属性无效');
        }
        // URI 对象会规范化非法百分号；解析原始目标才能拒绝歧义编码。
        $target = $request->getAttribute('type.raw-target');
        $query = $request->getUri()->getQuery();
        if (is_string($target)) {
            $queryStart = strpos($target, '?');
            $query = $queryStart === false ? '' : substr($target, $queryStart + 1);
        }
        $input = $input->withQuery($query, $maxQueryFields, $maxQueryBytes)
            ->with('route', $parameters)->with('header', $headers);
        $patch = $options['patch'] ?? (strtoupper($request->getMethod()) === 'PATCH');
        return $schema->validate($input, $options['scenario'], $patch);
    }

PHP);
    }
}
