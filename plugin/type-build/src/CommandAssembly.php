<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;

/** 从声明与源码符号生成直接工厂，不在构建期间执行应用类。 */
final class CommandAssembly
{
    /** @var list<string> 类型不匹配延后到依赖环检查之后报告，确保循环诊断保留完整依赖链。 */
    private array $typeErrors = [];
    /**
     * 从 AST 和显式模块声明生成命令工厂、入口与装配报告，不加载业务类。
     * @param array<string, mixed> $application 唯一应用入口；enabled 选择模块，services/bindings 覆盖组件默认，commands 声明明确入口。
     * @param array<string, array<string, mixed>> $modules 可用模块的服务和命令声明。
     * @param list<string> $sources 完整生产源码路径。
     * @param array<string, mixed> $routing 已由 RouteCompiler 校验的显式路由结果；控制器和中间件加入同一服务图。
     * @return array<string, mixed> 直接调用源码、命令清单及不含配置值的服务依赖图。
     * @throws RuntimeException 重复绑定、不可构造类型、不确定工厂、缺失参数、类型不匹配、依赖环或生命周期冲突。
     */
    public function generate(array $application, array $modules, array $sources, array $routing = []): array
    {
        $this->typeErrors = [];
        $enabled = $application['enabled'] ?? null;
        if (!is_array($enabled) || !array_is_list($enabled) || $enabled === []
            || array_filter($enabled, static fn (mixed $name): bool => !is_string($name)) !== [] || count(array_unique($enabled)) !== count($enabled)) {
            throw new RuntimeException('装配 enabled 必须是非空且不重复的模块列表');
        }
        $configuration = $application['config'] ?? [];
        if (!is_array($configuration)) {
            throw new RuntimeException('装配 config 必须是配置映射');
        }
        foreach ($configuration as $key => $definition) {
            if (!is_string($key) || !is_array($definition) || !is_string($definition['env'] ?? null)
                || !preg_match('/^[A-Z][A-Z0-9_]*$/D', $definition['env']) || !is_string($definition['default'] ?? null)) {
                throw new RuntimeException('配置必须声明合法环境变量与字符串默认值：' . (string) $key);
            }
        }
        [$services, $commands, $bindings, $bindingReport] = $this->declarations($application, $enabled, $modules);
        [$classes, $interfaces] = $this->symbols($sources, $this->factoryBodies($services));
        if ($commands === [] && ($routing['routes'] ?? []) === [] && ($application['events'] ?? []) === []
            && ($application['jobs'] ?? []) === [] && ($application['schedules'] ?? []) === []) {
            throw new RuntimeException('启用模块没有可执行命令');
        }
        foreach ($commands as &$command) {
            if (isset($command['class'])) {
                if (isset($command['service']) || !is_string($command['class'])) {
                    throw new RuntimeException('命令必须只选择 class 或 service：' . $command['origin']);
                }
                $command['service'] = $this->serviceForClass($command['class'], $services, $classes, $interfaces);
            }
        }
        unset($command);
        $events = $this->events($application['events'] ?? [], $services, $classes, $interfaces);
        if ($events !== []) {
            $this->serviceForClass('Type\\Core\\BusinessEvents', $services, $classes, $interfaces);
        }
        foreach (['jobs', 'schedules'] as $collection) {
            if (isset($application[$collection]) && !is_array($application[$collection])) {
                throw new RuntimeException('application.' . $collection . ' 必须是声明列表');
            }
        }
        $jobs = isset($application['jobs']) ? (new JobCompiler())->validate($application['jobs']) : [];
        $schedules = isset($application['schedules']) ? (new ScheduleCompiler())->validate($application['schedules']) : [];
        $jobs = $this->workRoots($jobs, 'Type\\Queue\\Job', 'handle', ['Type\\Queue\\JobContext', 'array'], 'void', $services, $classes, $interfaces);
        $schedules = $this->workRoots($schedules, 'Type\\Scheduler\\Task', 'run', ['Type\\Scheduler\\TaskContext'], 'array', $services, $classes, $interfaces);
        $http = ['class' => $routing['class'] ?? null, 'controllers' => [], 'middleware' => []];
        $middleware = $application['http']['middleware'] ?? [];
        if (!is_array($middleware) || ($middleware !== [] && array_is_list($middleware))) {
            throw new RuntimeException('application.http.middleware 必须是名称到服务标识的映射');
        }
        foreach ($routing['routes'] ?? [] as $route) {
            $class = $route['handler'][0];
            $http['controllers'][$class] = $this->serviceForClass($class, $services, $classes, $interfaces);
            foreach ($route['middleware'] as $name) {
                if (!is_string($middleware[$name] ?? null)) {
                    throw new RuntimeException('路由缺少具名中间件绑定：' . $name);
                }
                $http['middleware'][$name] = $middleware[$name];
            }
        }
        $bootstrap = $application['bootstrap'] ?? null;
        if ($bootstrap !== null) {
            if (!is_array($bootstrap) || !is_string($bootstrap['class'] ?? null) || !is_string($bootstrap['method'] ?? null)) {
                throw new RuntimeException('application.bootstrap 必须声明 class 与 method');
            }
            $method = ($classes[strtolower($bootstrap['class'])]['node'] ?? null)?->getMethod($bootstrap['method']);
            if ($method === null || !$method->isPublic() || !$method->isStatic() || $method->isAbstract() || $method->byRef
                || $this->typeName($method->returnType) !== 'void' || count($method->params) !== 2
                || $this->typeName($method->params[0]->type) !== 'array' || $this->typeName($method->params[1]->type) !== 'bool'
                || $method->params[0]->byRef || $method->params[1]->byRef || $method->params[0]->variadic || $method->params[1]->variadic) {
                throw new RuntimeException('application.bootstrap 必须是 public static run(array, bool): void 形状的角色入口');
            }
            $bootstrap = ['class' => $bootstrap['class'], 'method' => $bootstrap['method']];
        }
        $dependencies = $this->resolveServices($services, $classes, $interfaces, $configuration, $bindings);
        foreach ($http['middleware'] as $name => $id) {
            $this->role($id, 'Psr\\Http\\Server\\MiddlewareInterface', $services, $classes, $interfaces, 'http.middleware:' . $name);
        }
        $visited = [];
        foreach (array_keys($services) as $id) {
            $this->visit($id, $dependencies, [], $visited);
        }
        foreach ($services as $id => $service) {
            if ($service['lifetime'] === 'singleton') {
                foreach ($this->descendants($id, $dependencies) as $dependency) {
                    if ($services[$dependency]['lifetime'] === 'execution') {
                        throw new RuntimeException('单例不能持有执行作用域服务：' . $service['origin'] . ' -> ' . $services[$dependency]['origin']);
                    }
                }
            }
        }
        if ($this->typeErrors !== []) {
            throw new RuntimeException($this->typeErrors[0]);
        }
        foreach ($commands as &$command) {
            $this->role($command['service'] ?? null, 'Type\\Core\\Command', $services, $classes, $interfaces, $command['origin']);
            $command['resources'] = $command['resources'] ?? [];
            $command['listeners'] = $command['listeners'] ?? [];
            if (!is_array($command['resources']) || !array_is_list($command['resources']) || !is_array($command['listeners']) || !array_is_list($command['listeners'])) {
                throw new RuntimeException('命令资源和监听器必须是列表：' . $command['origin']);
            }
            foreach ($command['resources'] as $resource) {
                $this->role($resource, 'Type\\Runtime\\ManagedResource', $services, $classes, $interfaces, $command['origin']);
                if ($services[$resource]['lifetime'] !== 'execution') {
                    throw new RuntimeException('命令资源必须属于执行作用域：' . $command['origin']);
                }
            }
            foreach ($command['listeners'] as $listener) {
                if (!is_array($listener) || !in_array($listener['event'] ?? null, ['ready', 'completed'], true)) {
                    throw new RuntimeException('命令监听器事件无效：' . $command['origin']);
                }
                $this->role($listener['service'] ?? null, 'Type\\Core\\Listener', $services, $classes, $interfaces, $command['origin']);
            }
        }
        unset($command);

        $graph = [];
        foreach ($services as $id => $service) {
            $graph[$id] = ['class' => $service['class'], 'origin' => $service['origin'], 'lifetime' => $service['lifetime'],
                'dependencies' => $dependencies[$id], 'overrides' => $service['overrides'] ?? null];
        }
        return ['code' => $this->render($services, $commands, $configuration, $http, $bootstrap, $events, $jobs, $schedules), 'enabled-modules' => $enabled,
            'disabled-modules' => array_values(array_diff(array_keys($modules), $enabled)), 'commands' => array_keys($commands),
            'command-services' => array_map(static fn (array $command): string => $command['service'], $commands),
            'http' => $http, 'bootstrap' => $bootstrap, 'events' => $events, 'jobs' => $jobs, 'schedules' => $schedules,
            'bindings' => $bindingReport, 'services' => $graph];
    }

    /**
     * 合并已启用模块的声明。应用模块拥有覆盖组件默认值的优先级；同一来源内的重复仍然拒绝。
     *
     * @return array{0: array, 1: array, 2: array, 3: array} 服务、命令、类型绑定及脱敏绑定来源报告。
     */
    private function declarations(array $application, array $enabled, array $modules): array
    {
        $moduleRows = [];
        foreach ($enabled as $module) {
            if (!array_key_exists($module, $modules) || !is_array($modules[$module])) {
                throw new RuntimeException('启用的模块未安装或未声明：' . (string) $module);
            }
            $isApplication = $modules[$module] === $application;
            $moduleRows[] = [$module, $isApplication, $modules[$module]];
        }
        $ordered = array_values(array_filter($moduleRows, static fn (array $row): bool => !$row[1]));
        foreach (array_filter($moduleRows, static fn (array $row): bool => $row[1]) as $row) {
            $ordered[] = $row;
        }
        $services = [];
        $commands = [];
        $bindings = [];
        $bindingOrigins = [];
        $bindingReport = [];
        foreach ($ordered as [$module, $isApplication, $declaration]) {
            foreach ($this->bindings($declaration['bindings'] ?? []) as $type => $binding) {
                if (isset($bindings[$type]) && !$isApplication) {
                    throw new RuntimeException('重复显式绑定：' . $module . ':' . $type . ' / ' . $bindingOrigins[$type]);
                }
                $kind = array_key_first($binding);
                $bindingReport[$type] = ['kind' => $kind, 'origin' => $module . ':' . $type, 'overrides' => $bindingOrigins[$type] ?? null];
                if ($kind !== 'value') {
                    $bindingReport[$type]['target'] = $binding[$kind];
                }
                $bindings[$type] = $binding;
                $bindingOrigins[$type] = $module . ':' . $type;
            }
            foreach (['services', 'commands'] as $collection) {
                $rows = $declaration[$collection] ?? [];
                if (!is_array($rows) || !array_is_list($rows)) {
                    throw new RuntimeException($module . ' 的 ' . $collection . ' 必须是声明列表');
                }
                $local = [];
                foreach ($rows as $row) {
                    $key = $collection === 'services' ? 'id' : 'name';
                    if (!is_array($row) || !is_string($row[$key] ?? null) || !preg_match('/^[a-z][a-z0-9_.:-]*$/D', $row[$key])) {
                        throw new RuntimeException('模块声明缺少合法标识：' . $module . '/' . $collection);
                    }
                    $id = $row[$key];
                    if (isset($local[$id])) {
                        throw new RuntimeException(($collection === 'services' ? '重复服务注册' : '重复命令注册') . '（同一模块）：' . $module . ':' . $id);
                    }
                    $local[$id] = true;
                    $row['origin'] = $module . ':' . $id;
                    if ($collection === 'services') {
                        $row['lifetime'] = $row['lifetime'] ?? 'execution';
                        if (!in_array($row['lifetime'], ['singleton', 'execution'], true)
                            || (array_key_exists('arguments', $row) && (!is_array($row['arguments']) || !array_is_list($row['arguments'])))) {
                            throw new RuntimeException('服务生命周期或构造参数声明无效：' . $row['origin']);
                        }
                        if (array_key_exists('factory', $row) && !is_array($row['factory'])) {
                            throw new RuntimeException('具名工厂声明必须是对象：' . $row['origin']);
                        }
                        if (isset($services[$id]) && !$isApplication) {
                            throw new RuntimeException('重复服务注册：' . $row['origin'] . ' / ' . $services[$id]['origin']);
                        }
                        if (isset($services[$id]) && $isApplication) {
                            $row['overrides'] = $services[$id]['origin'];
                        }
                        $services[$id] = $row;
                    } else {
                        if (isset($commands[$id]) && !$isApplication) {
                            throw new RuntimeException('重复命令注册：' . $row['origin'] . ' / ' . $commands[$id]['origin']);
                        }
                        if (isset($commands[$id]) && $isApplication) {
                            $row['overrides'] = $commands[$id]['origin'];
                        }
                        if (in_array($id, ['help', 'check'], true)) {
                            throw new RuntimeException('重复命令注册：' . $row['origin']);
                        }
                        $commands[$id] = $row;
                    }
                }
            }
        }
        $index = 0;
        foreach ($services as &$service) {
            $service['method'] = 'service_' . $index++;
        }
        unset($service);

        return [$services, $commands, $bindings, $bindingReport];
    }

    /** @return array<string, array<string, mixed>> */
    private function bindings(mixed $declarations): array
    {
        if ($declarations === null || $declarations === []) {
            return [];
        }
        if (!is_array($declarations)) {
            throw new RuntimeException('application.bindings 必须是列表或映射');
        }
        $bindings = [];
        $rows = array_is_list($declarations) ? $declarations : array_map(
            static fn (mixed $value, string|int $key): array => is_array($value) ? ['type' => (string) $key, ...$value] : ['type' => (string) $key, 'value' => $value],
            $declarations,
            array_keys($declarations),
        );
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['type'] ?? null) || $row['type'] === '') {
                throw new RuntimeException('显式绑定必须声明 type');
            }
            $type = strtolower(ltrim($row['type'], '?\\'));
            if (isset($bindings[$type])) {
                throw new RuntimeException('重复显式绑定：' . $row['type']);
            }
            $keys = array_values(array_intersect(['service', 'config', 'value', 'factory'], array_keys($row)));
            if (count($keys) !== 1) {
                throw new RuntimeException('显式绑定必须且只能选择 service、config、value 或 factory：' . $row['type']);
            }
            $bindings[$type] = [$keys[0] => $row[$keys[0]]];
        }

        return $bindings;
    }

    /** 业务事件只使用明确类型和公开单参数 void 方法；监听根沿用同一构造服务图。 */
    private function events(mixed $declarations, array &$services, array $classes, array $interfaces): array
    {
        if (!is_array($declarations) || !array_is_list($declarations)) {
            throw new RuntimeException('application.events 必须是事件声明列表');
        }
        $events = [];
        $seen = [];
        foreach ($declarations as $declaration) {
            $class = is_array($declaration) ? ($declaration['class'] ?? null) : null;
            if (!is_string($class) || !isset($classes[strtolower($class)]) || $classes[strtolower($class)]['node']->isAbstract()
                || isset($seen[strtolower($class)]) || array_diff(array_keys($declaration), ['class', 'listeners']) !== []) {
                throw new RuntimeException('业务事件需要不重复的生产具体类声明');
            }
            $seen[strtolower($class)] = true;
            $listeners = $declaration['listeners'] ?? [];
            if (!is_array($listeners) || !array_is_list($listeners)) {
                throw new RuntimeException('业务事件 listeners 必须是有序列表：' . $class);
            }
            $resolved = [];
            $duplicates = [];
            foreach ($listeners as $listener) {
                if (!is_array($listener) || !is_string($listener['method'] ?? null)
                    || array_diff(array_keys($listener), ['service', 'class', 'method']) !== []
                    || (isset($listener['service']) === isset($listener['class']))) {
                    throw new RuntimeException('业务监听必须声明 method 和唯一 service 或 class：' . $class);
                }
                $id = isset($listener['class']) && is_string($listener['class'])
                    ? $this->serviceForClass($listener['class'], $services, $classes, $interfaces) : ($listener['service'] ?? null);
                if (!is_string($id) || !isset($services[$id]) || !is_string($services[$id]['class'] ?? null)) {
                    throw new RuntimeException('业务监听缺失服务绑定：' . $class);
                }
                $listenerClass = $services[$id]['class'];
                $method = ($classes[strtolower($listenerClass)]['node'] ?? null)?->getMethod($listener['method']);
                $key = strtolower($id . '::' . $listener['method']);
                if ($method === null || !$method->isPublic() || $method->isStatic() || $method->isAbstract() || $method->byRef
                    || count($method->params) !== 1 || $method->params[0]->byRef || $method->params[0]->variadic
                    || strtolower($this->typeName($method->params[0]->type) ?? '') !== strtolower($class)
                    || $this->typeName($method->returnType) !== 'void' || isset($duplicates[$key])) {
                    throw new RuntimeException('业务监听需要不重复的 public 实例方法，唯一参数为事件类型且返回 void：' . $class . ' -> ' . $id . '::' . $listener['method']);
                }
                $duplicates[$key] = true;
                $resolved[] = ['service' => $id, 'class' => $listenerClass, 'method' => $listener['method']];
            }
            $events[] = ['class' => $class, 'listeners' => $resolved];
        }
        return $events;
    }

    /** Job 与 Scheduler 的明确任务根、资源和公开执行方法沿用构造图与同一作用域。 */
    private function workRoots(array $rows, string $interface, string $methodName, array $parameters, string $returns, array &$services, array $classes, array $interfaces): array
    {
        foreach ($rows as &$row) {
            $id = isset($row['class']) ? $this->serviceForClass($row['class'], $services, $classes, $interfaces) : ($row['service'] ?? null);
            $origin = ($row['id'] ?? $row['type'] ?? '') . ':' . $interface;
            $this->role($id, $interface, $services, $classes, $interfaces, $origin);
            $row['service'] = $id;
            $row['class'] = $services[$id]['class'];
            if ($services[$id]['lifetime'] !== 'execution') {
                throw new RuntimeException('任务处理器必须属于执行作用域：' . $origin);
            }
            $methodClass = strtolower($row['class']);
            $method = null;
            $ancestors = [];
            while (isset($classes[$methodClass]) && !isset($ancestors[$methodClass])) {
                $ancestors[$methodClass] = true;
                $method = $classes[$methodClass]['node']->getMethod($methodName);
                if ($method !== null) {
                    break;
                }
                $methodClass = $classes[$methodClass]['parent'];
            }
            if ($method === null) {
                throw new RuntimeException('任务缺少可静态确定的公开执行方法：' . $origin . '::' . $methodName);
            }
            if ($method !== null) {
                $valid = $method->isPublic() && !$method->isStatic() && !$method->isAbstract() && !$method->byRef
                    && $this->typeName($method->returnType) === $returns && count($method->params) === count($parameters);
                foreach ($method->params as $position => $parameter) {
                    $valid = $valid && !$parameter->byRef && !$parameter->variadic
                        && strtolower($this->typeName($parameter->type) ?? '') === strtolower($parameters[$position] ?? '');
                }
                if (!$valid) {
                    throw new RuntimeException('任务公开执行方法签名不符合接口：' . $origin . '::' . $methodName);
                }
            }
            foreach ($row['resources'] as $resource) {
                $this->role($resource, 'Type\\Runtime\\ManagedResource', $services, $classes, $interfaces, $origin);
                if ($services[$resource]['lifetime'] !== 'execution') {
                    throw new RuntimeException('任务资源必须属于执行作用域：' . $origin);
                }
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * 按构造器参数建立完整服务图。缺少 arguments 时只推导可确定的具体类，其他类型必须显式绑定。
     * @param array<string, array<string, mixed>> $services
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, list<string>> $interfaces
     * @param array<string, array<string, mixed>> $configuration
     * @param array<string, array<string, mixed>> $bindings
     * @return array<string, list<string>>
     */
    private function resolveServices(array &$services, array $classes, array $interfaces, array $configuration, array $bindings): array
    {
        $dependencies = [];
        $index = 0;
        while ($index < count($services)) {
            $ids = array_keys($services);
            $id = $ids[$index++];
            $service = &$services[$id];
            $class = $this->serviceClass($service, $classes, $interfaces);
            if (strtolower($class) === 'type\\core\\businessevents') {
                if (isset($service['factory']) || isset($service['arguments']) || $service['lifetime'] !== 'execution') {
                    throw new RuntimeException('BusinessEvents 由当前执行作用域生成，不能自定义工厂、参数或 singleton 生命周期');
                }
                $service['arguments'] = [];
                $service['generated'] = 'events';
                $dependencies[$id] = [];
                unset($service);
                continue;
            }
            $factory = $this->factoryMethod($service, $class, $services, $classes, $interfaces);
            $parameters = $factory['method']->params ?? [];
            if (!$factory['static'] && $factory['owner'] !== null) {
                $dependencies[$id] = [$factory['owner']];
            } else {
                $dependencies[$id] = [];
            }
            $explicit = array_key_exists('arguments', $service);
            $provided = $explicit ? $service['arguments'] : [];
            if (!is_array($provided) || !array_is_list($provided)) {
                throw new RuntimeException('构造参数必须是列表：' . $service['origin']);
            }
            $arguments = [];
            $parametersCount = count($parameters);
            foreach ($parameters as $position => $parameter) {
                if ($parameter->byRef || $parameter->variadic || $this->typeName($parameter->type) === null) {
                    throw new RuntimeException('装配参数必须是确定的具名类型，不能引用或可变参数：' . $service['origin']);
                }
                if (array_key_exists($position, $provided)) {
                    $argument = $this->validateArgument($provided[$position], $service['origin']);
                } else {
                    $argument = $explicit ? null : $this->inferArgument($parameter, $services, $classes, $interfaces, $bindings, $service['class'], $service['origin']);
                    if ($argument === null) {
                        if ($parameter->default !== null) {
                            break;
                        }
                        throw new RuntimeException('构造参数数量或缺失绑定不符合声明：' . $service['origin'] . '::$' . (string) $parameter->var->name);
                    }
                }
                if (isset($argument['config']) && !array_key_exists($argument['config'], $configuration)) {
                    throw new RuntimeException('缺失配置绑定：' . $service['origin'] . '::$' . (string) $parameter->var->name);
                }
                $this->appendDependency($dependencies[$id], $argument, $services, $service['origin']);
                $this->assertArgumentType($parameter, $argument, $services, $classes, $interfaces, $service['origin']);
                $arguments[] = $argument;
            }
            if (($factory['method'] !== null && !$factory['method']->isPublic()) || count($provided) > $parametersCount && !($parameters !== [] && end($parameters)->variadic)) {
                throw new RuntimeException('构造参数数量或可见性不符合声明：' . $service['origin']);
            }
            $service['arguments'] = $arguments;
            $service['factory'] = $factory;
            unset($service);
        }
        foreach ($dependencies as $id => $items) {
            $dependencies[$id] = array_values(array_unique($items));
        }

        return $dependencies;
    }

    /** @param array<string, mixed> $service @return string */
    private function serviceClass(array $service, array $classes, array $interfaces): string
    {
        $class = $service['class'] ?? null;
        if (!is_string($class) || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $class)) {
            throw new RuntimeException('服务类名无效：' . $service['origin']);
        }
        $name = strtolower($class);
        if (isset($service['factory']) && (isset($interfaces[$name]) || isset($classes[$name]))) {
            return $class;
        }
        if (isset($interfaces[$name]) || !isset($classes[$name])) {
            throw new RuntimeException('生产源码中找不到服务类：' . $service['origin'] . ' -> ' . $class);
        }
        if ($classes[$name]['node']->isAbstract()) {
            throw new RuntimeException('不能构造抽象服务：' . $service['origin']);
        }

        return $class;
    }

    /**
     * 返回服务实际消费的构造器或具名工厂方法。工厂仅检查 AST，不调用业务代码。
     * @return array{method: Node\Stmt\ClassMethod|null, static: bool, owner: ?string, class: ?string, name: ?string}
     */
    private function factoryMethod(array $service, string $class, array $services, array $classes, array $interfaces): array
    {
        if (!array_key_exists('factory', $service)) {
            return ['method' => $this->constructor(strtolower($class), $classes), 'static' => false, 'owner' => null, 'class' => null, 'name' => null];
        }
        $factory = $service['factory'];
        if (!is_array($factory) || !is_string($factory['method'] ?? null) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $factory['method'])) {
            throw new RuntimeException('具名工厂必须声明合法 method：' . $service['origin']);
        }
        $owner = null;
        $factoryClass = $factory['class'] ?? null;
        if (isset($factory['service'])) {
            if (!is_string($factory['service'])) {
                throw new RuntimeException('具名工厂 service 必须是服务标识：' . $service['origin']);
            }
            $owner = $factory['service'];
            if ($owner === ($service['id'] ?? null)) {
                throw new RuntimeException('具名工厂不能引用自身：' . $service['origin']);
            }
            if (!isset($services[$owner]) || !is_string($factoryClass)) {
                throw new RuntimeException('具名工厂必须同时声明有效 service 与 class：' . $service['origin']);
            }
        } elseif (!is_string($factoryClass) || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $factoryClass)) {
            throw new RuntimeException('具名工厂必须声明 class 或 service：' . $service['origin']);
        }
        $symbol = $classes[strtolower($factoryClass)] ?? null;
        if ($symbol === null || isset($interfaces[strtolower($factoryClass)])) {
            throw new RuntimeException('生产源码中找不到工厂类：' . $service['origin'] . ' -> ' . (string) $factoryClass);
        }
        $method = $symbol['node']->getMethod($factory['method']);
        if ($method === null || !$method->isPublic() || ($owner === null && !$method->isStatic())) {
            throw new RuntimeException('具名工厂方法必须是 public static，或绑定到服务实例：' . $service['origin']);
        }
        if ($method->byRef || $method->isAbstract() || ($owner !== null && !$this->isA($services[$owner]['class'], $factoryClass, $classes, $interfaces))) {
            throw new RuntimeException('具名工厂不能引用返回、抽象声明或不匹配的实例：' . $service['origin']);
        }
        $finder = new NodeFinder();
        $unsafe = $finder->findFirst($method->stmts ?? [], static function (Node $node): bool {
            if ($node instanceof Node\Stmt\Global_ || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                return true;
            }
            if ($node instanceof Node\Expr\Variable && is_string($node->name) && in_array($node->name, ['GLOBALS', '_ENV', '_SERVER', '_SESSION', '_REQUEST'], true)) {
                return true;
            }
            return $node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), ['current', 'getinstance'], true);
        });
        if ($unsafe !== null) {
            throw new RuntimeException('具名工厂依赖必须来自类型化参数，不能使用全局取值、服务定位器或闭包捕获：' . $service['origin']);
        }
        $return = $this->typeName($method->returnType);
        if ($return === null || !$this->isA($return === 'self' ? $factoryClass : $return, $class, $classes, $interfaces)) {
            throw new RuntimeException('具名工厂返回类型不匹配：' . $service['origin'] . ' -> ' . $class);
        }

        return ['method' => $method, 'static' => $owner === null, 'owner' => $owner, 'class' => $factoryClass, 'name' => $factory['method']];
    }

    /** @return array<string, mixed> */
    private function validateArgument(mixed $argument, string $origin): array
    {
        if (!is_array($argument) || count($argument) !== 1) {
            throw new RuntimeException('构造参数必须只声明 service、config、value 或 factory：' . $origin);
        }
        $keys = array_values(array_intersect(['service', 'config', 'value', 'factory'], array_keys($argument)));
        if (count($keys) !== 1) {
            throw new RuntimeException('构造参数必须只声明 service、config、value 或 factory：' . $origin);
        }
        $key = $keys[0];
        if ($key === 'service' && !is_string($argument[$key])) {
            throw new RuntimeException('服务绑定必须是字符串：' . $origin);
        }
        if ($key === 'config' && !is_string($argument[$key])) {
            throw new RuntimeException('配置绑定必须是字符串：' . $origin);
        }
        if ($key === 'factory' && !is_string($argument[$key])) {
            throw new RuntimeException('工厂绑定必须是服务标识：' . $origin);
        }
        if ($key === 'value' && (!is_scalar($argument[$key]) && $argument[$key] !== null)) {
            throw new RuntimeException('构造常量只支持标量或 null：' . $origin);
        }

        return [$key => $argument[$key]];
    }

    private function appendDependency(array &$dependencies, array $argument, array $services, string $origin): void
    {
        if (isset($argument['service']) || isset($argument['factory'])) {
            $dependency = $argument['service'] ?? $argument['factory'];
            if (!is_string($dependency) || !isset($services[$dependency])) {
                throw new RuntimeException('缺失服务绑定：' . $origin . ' -> ' . (string) $dependency);
            }
            $dependencies[] = $dependency;
        }
    }

    private function inferArgument(Node\Param $parameter, array &$services, array $classes, array $interfaces, array $bindings, string $class, string $origin): ?array
    {
        $type = $this->typeName($parameter->type);
        $binding = $bindings[strtolower($class . '::$' . $parameter->var->name)] ?? $bindings[strtolower(ltrim($type ?? '', '?\\'))] ?? null;
        if ($binding !== null) {
            return $this->validateArgument($binding, $origin);
        }
        if ($type === null || $this->isBuiltin($type)) {
            return null;
        }
        $name = strtolower(ltrim($type, '?\\'));
        if (isset($interfaces[$name]) || !isset($classes[$name]) || $classes[$name]['node']->isAbstract()) {
            throw new RuntimeException('缺失显式绑定：' . $origin . '::$' . (string) $parameter->var->name . ' (' . $type . ')');
        }
        $id = $this->serviceForClass($type, $services, $classes, $interfaces);

        return ['service' => $id];
    }

    private function serviceForClass(string $class, array &$services, array $classes, array $interfaces): string
    {
        $class = ltrim($class, '?\\');
        $name = strtolower(ltrim($class, '?\\'));
        $matches = [];
        foreach ($services as $id => $service) {
            if (strtolower((string) ($service['class'] ?? '')) === $name) {
                $matches[] = $id;
            }
        }
        if (count($matches) > 1) {
            throw new RuntimeException('具体类型存在多个服务绑定：' . $class . ' -> ' . implode(', ', $matches));
        }
        if ($matches !== []) {
            return $matches[0];
        }
        $base = 'auto.' . str_replace('\\', '.', $name);
        $id = $base;
        $suffix = 2;
        while (isset($services[$id])) {
            $id = $base . '.' . $suffix++;
        }
        $services[$id] = ['id' => $id, 'class' => $class, 'lifetime' => 'execution', 'origin' => 'auto:' . $class,
            'method' => 'service_' . count($services)];

        return $id;
    }

    private function assertArgumentType(Node\Param $parameter, array $argument, array $services, array $classes, array $interfaces, string $origin): void
    {
        $type = $this->typeName($parameter->type);
        if ($type === null || $type === 'mixed' || $type === 'object') {
            return;
        }
        if (isset($argument['service']) || isset($argument['factory'])) {
            $id = $argument['service'] ?? $argument['factory'];
            if (!is_string($id) || !isset($services[$id])) {
                throw new RuntimeException('缺失服务绑定：' . $origin . ' -> ' . (string) $id);
            }
            $actual = $services[$id]['class'] ?? null;
            if (!is_string($actual) || !$this->isA($actual, ltrim($type, '?\\'), $classes, $interfaces)) {
                $this->typeErrors[] = '服务绑定类型不匹配：' . $origin . '::$' . (string) $parameter->var->name . ' (' . $type . ')';
            }
            return;
        }
        if (isset($argument['config'])) {
            if (strtolower(ltrim($type, '?')) !== 'string') {
                $this->typeErrors[] = '配置绑定只能用于 string 参数：' . $origin . '::$' . (string) $parameter->var->name;
            }
            return;
        }
        if (array_key_exists('value', $argument) && !$this->valueMatches($argument['value'], $type)) {
            $this->typeErrors[] = '构造常量类型不匹配：' . $origin . '::$' . (string) $parameter->var->name;
        }
    }

    private function valueMatches(mixed $value, string $type): bool
    {
        if ($value === null && str_starts_with($type, '?')) {
            return true;
        }
        $type = strtolower(ltrim($type, '?'));
        return $type === 'mixed' || ($type === 'null' && $value === null) || ($type === 'string' && is_string($value))
            || ($type === 'int' && is_int($value)) || ($type === 'float' && (is_float($value) || is_int($value)))
            || ($type === 'bool' && is_bool($value)) || ($type === 'array' && is_array($value))
            || ($type === 'object' && is_object($value));
    }

    private function typeName(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            $inner = $this->typeName($type->type);

            return $inner === null ? null : '?' . $inner;
        }
        if ($type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }

        return null;
    }

    private function isBuiltin(string $type): bool
    {
        return in_array(strtolower(ltrim($type, '?')), ['array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void'], true);
    }

    /**
     * 具名工厂的安全扫描需要保留对应方法体；其他方法只参与签名和继承校验。
     * 先从声明收集目标，避免为整个生产源码保留表达式树。
     *
     * @return array<string, array<string, true>>
     */
    private function factoryBodies(array $services): array
    {
        $result = [];
        foreach ($services as $service) {
            $factory = $service['factory'] ?? null;
            if (!is_array($factory) || !is_string($factory['method'] ?? null)) {
                continue;
            }
            $class = $factory['class'] ?? null;
            if ($class === null && is_string($factory['service'] ?? null)) {
                $class = $services[$factory['service']]['class'] ?? null;
            }
            if (!is_string($class) || $class === '') {
                continue;
            }
            $result[strtolower($class)][strtolower($factory['method'])] = true;
        }

        return $result;
    }

    /**
     * @param array<string, array<string, true>> $factoryBodies
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, list<string>>}
     */
    private function symbols(array $sources, array $factoryBodies = []): array
    {
        $files = [];
        foreach ($sources as $source) {
            if (is_file($source)) {
                if (pathinfo($source, PATHINFO_EXTENSION) === 'php') {
                    $files[$source] = true;
                }
            } else {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $files[$file->getRealPath()] = true;
                    }
                }
            }
        }
        ksort($files);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $classes = [];
        $interfaces = [];
        foreach (array_keys($files) as $file) {
            try {
                // 当前文件完整名称解析后才保存签名副本。保留所有嵌套声明和
                // 具名工厂完整子树，其余文件无需常驻业务方法体。
                $nodes = (new NodeTraverser(new NameResolver()))->traverse($parser->parse(file_get_contents($file)) ?? []);
            } catch (\PhpParser\Error $error) {
                throw new RuntimeException('源码声明解析失败：' . $file . '，' . $error->getMessage(), 0, $error);
            }
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
                if ($node->name === null || $node instanceof Node\Stmt\Trait_) {
                    continue;
                }
                $name = strtolower($node->namespacedName?->toString() ?? $node->name->toString());
                if (isset($classes[$name]) || isset($interfaces[$name])) {
                    throw new RuntimeException('重复源码符号：' . $name . '，' . $file . ':' . $node->getStartLine());
                }
                if ($node instanceof Node\Stmt\Class_) {
                    $classes[$name] = ['node' => $this->compactClass($node, $factoryBodies[$name] ?? []), 'parent' => strtolower($node->extends?->toString() ?? ''),
                        'interfaces' => array_map(static fn (Node\Name $name): string => strtolower($name->toString()), $node->implements)];
                } elseif ($node instanceof Node\Stmt\Interface_) {
                    $interfaces[$name] = array_map(static fn (Node\Name $name): string => strtolower($name->toString()), $node->extends);
                }
            }
            unset($nodes, $node);
        }

        return [$classes, $interfaces];
    }

    /**
     * 不修改原始声明，具名工厂的完整子树继续供安全扫描使用。
     * @param array<string, true> $keepMethods
     */
    private function compactClass(Node\Stmt\ClassLike $node, array $keepMethods): Node\Stmt\ClassLike
    {
        $copy = clone $node;
        $methods = [];
        foreach ($node->stmts as $statement) {
            if (!$statement instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $method = clone $statement;
            if (!isset($keepMethods[strtolower($statement->name->toString())])) {
                $method->stmts = null;
            }
            $methods[] = $method;
        }
        $copy->stmts = $methods;
        return $copy;
    }

    private function constructor(string $class, array $classes, array $seen = []): ?Node\Stmt\ClassMethod
    {
        if (isset($seen[$class])) {
            throw new RuntimeException('服务继承存在循环：' . $class);
        }
        $seen[$class] = true;
        $symbol = $classes[$class] ?? null;
        if ($symbol === null) {
            return null;
        }

        return $symbol['node']->getMethod('__construct') ?? ($symbol['parent'] !== '' ? $this->constructor($symbol['parent'], $classes, $seen) : null);
    }

    private function visit(string $id, array $dependencies, array $path, array &$visited): void
    {
        if (in_array($id, $path, true)) {
            throw new RuntimeException('依赖循环：' . implode(' -> ', [...$path, $id]));
        }
        if (isset($visited[$id])) {
            return;
        }
        foreach ($dependencies[$id] as $dependency) {
            $this->visit($dependency, $dependencies, [...$path, $id], $visited);
        }
        $visited[$id] = true;
    }

    private function descendants(string $id, array $dependencies): array
    {
        $result = [];
        foreach ($dependencies[$id] as $dependency) {
            $result[] = $dependency;
            array_push($result, ...$this->descendants($dependency, $dependencies));
        }

        return array_unique($result);
    }

    private function isA(string $class, string $expected, array $classes, array $interfaces, array $seen = []): bool
    {
        $class = strtolower($class);
        if ($class === strtolower($expected)) {
            return true;
        }
        if (isset($seen[$class])) {
            return false;
        }
        $seen[$class] = true;
        $parents = $interfaces[$class] ?? [];
        if (isset($classes[$class])) {
            $parents = [...$classes[$class]['interfaces'], $classes[$class]['parent']];
        }
        foreach (array_filter($parents) as $parent) {
            if ($this->isA($parent, $expected, $classes, $interfaces, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function role(mixed $id, string $expected, array $services, array $classes, array $interfaces, string $origin): void
    {
        if (!is_string($id) || !isset($services[$id])) {
            throw new RuntimeException('命令缺少服务绑定：' . $origin);
        }
        if (!$this->isA($services[$id]['class'], $expected, $classes, $interfaces)) {
            throw new RuntimeException('服务未实现要求的接口：' . $origin . ' -> ' . $id . '，需要 ' . $expected);
        }
    }

    private function render(array $services, array $commands, array $configuration, array $http, ?array $bootstrap, array $events, array $jobs, array $schedules): string
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Type\\Generated {\nfinal class CommandApplication\n{\n";
        $code .= "    private array \$singletons = [];\n    private bool \$running = false;\n    private ?\\Type\\Runtime\\ExecutionOwner \$owner = null;\n    private \\Type\\Core\\Application \$runner;\n    private \\Type\\Core\\Configuration \$configuration;\n";
        $code .= "    private string \$identity;\n";
        $code .= "    public function __construct(\\Type\\Core\\Configuration \$configuration) { \$this->configuration = \$configuration; \$this->runner = new \\Type\\Core\\Application(); \$this->identity = bin2hex(random_bytes(16)); }\n";
        $code .= "    private function assertOwner(): void { \$this->owner ??= new \\Type\\Runtime\\ExecutionOwner(false); \$this->owner->assertCurrent(); }\n";
        foreach ($services as $id => $service) {
            $arguments = [];
            foreach ($service['arguments'] as $argument) {
                $dependency = $argument['service'] ?? $argument['factory'] ?? null;
                $arguments[] = $dependency !== null ? '$this->' . $services[$dependency]['method'] . '($scope)'
                    : (isset($argument['config']) ? '$this->configuration->text(' . var_export($argument['config'], true) . ')' : var_export($argument['value'], true));
            }
            $key = var_export($id, true);
            $code .= "    private function {$service['method']}(\\Type\\Runtime\\ExecutionScope \$scope): \\{$service['class']}\n    {\n";
            $factory = $service['factory'] ?? ['name' => null, 'static' => false, 'class' => null, 'owner' => null];
            if (($service['generated'] ?? null) === 'events') {
                $construction = 'new \\Type\\Core\\BusinessEvents($scope, function (object $event): void { $this->dispatchEvent($event); })';
            } elseif ($factory['name'] === null) {
                $construction = 'new \\' . $service['class'] . '(' . implode(', ', $arguments) . ')';
            } elseif ($factory['static']) {
                $construction = '\\' . $factory['class'] . '::' . $factory['name'] . '(' . implode(', ', $arguments) . ')';
            } else {
                $construction = '$this->' . $services[$factory['owner']]['method'] . '($scope)->' . $factory['name'] . '(' . implode(', ', $arguments) . ')';
            }
            if ($service['lifetime'] === 'singleton') {
                $code .= "        if (!array_key_exists($key, \$this->singletons)) { \$this->singletons[$key] = {$construction}; }\n";
                $code .= "        return \$this->singletons[$key];\n    }\n";
            } else {
                $code .= "        return \$scope->service(\$this->identity . ':' . $key, fn (): \\{$service['class']} => {$construction});\n    }\n";
            }
            if (($service['generated'] ?? null) === 'events') {
                $code .= "    /** 在当前作用域取得固定业务监听表的派发入口。 */\n    public function events(): \\Type\\Core\\BusinessEvents { \$this->assertOwner(); return \$this->{$service['method']}(\\Type\\Runtime\\ExecutionScope::current()); }\n";
            }
        }
        $code .= "    private function dispatchEvent(object \$event): void\n    {\n        \$this->assertOwner();\n        \$scope = \\Type\\Runtime\\ExecutionScope::current();\n";
        foreach ($events as $event) {
            $code .= '        if ($event instanceof \\' . $event['class'] . ' && get_class($event) === \\' . $event['class'] . "::class) {\n";
            foreach ($event['listeners'] as $listener) {
                $code .= "            \$scope->assertActive();\n            \$this->" . $services[$listener['service']]['method'] . '($scope)->' . $listener['method'] . "(\$event);\n";
            }
            $code .= "            return;\n        }\n";
        }
        $code .= "        throw new \\InvalidArgumentException('event_not_declared');\n    }\n";
        if ($jobs !== []) {
            $code .= "    /** 只登记确定类型/版本；Worker在每次消息scope内调用直接工厂。 */\n    public function jobs(): \\Type\\Queue\\Registry\n    {\n        \$registry = new \\Type\\Queue\\Registry();\n";
            foreach ($jobs as $job) {
                $factory = $this->workFactory($job, $services, 'Type\\Queue\\JobContext', 'Type\\Queue\\Job');
                $code .= '        $registry->register(' . var_export($job['type'], true) . ', ' . $job['version'] . ', ' . $factory . ");\n";
            }
            $code .= "        return \$registry;\n    }\n";
        }
        if ($schedules !== []) {
            $code .= "    /** @return list<\\Type\\Scheduler\\Definition> 固定计划及当前occurrence的直接任务工厂。 */\n    public function schedules(): array\n    {\n        return [\n";
            foreach ($schedules as $schedule) {
                $factory = $this->workFactory($schedule, $services, 'Type\\Scheduler\\TaskContext', 'Type\\Scheduler\\Task');
                $code .= '            ' . (new ScheduleCompiler())->renderDefinition($schedule, $factory) . ",\n";
            }
            $code .= "        ];\n    }\n";
        }
        if ($http['class'] !== null) {
            $code .= "    /** 只登记直接工厂；首次请求在实际 worker 内绑定所有权。 */\n    public function registerRoutes(\\Type\\Core\\Http\\Router \$router): void\n    {\n";
            $factories = [];
            foreach (['controllers', 'middleware'] as $kind) {
                $factories[$kind] = [];
                foreach ($http[$kind] as $name => $id) {
                    $service = $services[$id];
                    $factories[$kind][] = var_export($name, true) . ' => function (): \\' . $service['class']
                        . ' { $this->assertOwner(); return $this->' . $service['method'] . '(\\Type\\Runtime\\ExecutionScope::current()); }';
                }
            }
            $code .= '        \\' . $http['class'] . '::register($router, [' . implode(', ', $factories['controllers'])
                . '], [' . implode(', ', $factories['middleware']) . "]);\n    }\n";
        }
        $help = '可用命令：help、check、' . implode('、', array_keys($commands)) . "\n";
        $code .= "    public function run(string \$name, array \$arguments): int\n    {\n";
        $code .= "        \$this->assertOwner();\n        \\Type\\Runtime\\CoroutineRuntime::enableIo();\n";
        $code .= "        return \\Type\\Runtime\\CoroutineRuntime::run(fn (): int => \$this->execute(\$name, \$arguments));\n    }\n";
        $code .= "    private function execute(string \$name, array \$arguments): int\n    {\n";
        $code .= "        if (\$name === 'help') { echo " . var_export($help, true) . "; return 0; }\n";
        $code .= "        if (\$name === 'check') { echo \"离线配置检查通过。\\n\"; return 0; }\n";
        $code .= "        if (\$this->running) { throw new \\RuntimeException('命令应用不能重入执行'); }\n        \$this->running = true;\n        try {\n            switch (\$name) {\n";
        foreach ($commands as $name => $command) {
            $code .= '                case ' . var_export($name, true) . ":\n";
            $commandService = $services[$command['service']]['method'];
            $code .= "                    return \$this->runner->runFactories(\n";
            $code .= '                        fn (\\Type\\Runtime\\ExecutionScope $scope): \\Type\\Core\\Command => $this->' . $commandService . "(\$scope),\n";
            $code .= "                        \$this->configuration, \$arguments,\n";
            $resourceFactories = array_map(static fn (string $id): string => '$this->' . $services[$id]['method'] . '($scope)', $command['resources']);
            $code .= '                        fn (\\Type\\Runtime\\ExecutionScope $scope): array => [' . implode(', ', $resourceFactories) . "],\n";
            $code .= '                        fn (\\Type\\Runtime\\ExecutionScope $scope): \\Type\\Core\\Events => (function () use ($scope): \\Type\\Core\\Events { $events = new \\Type\\Core\\Events(); ';
            foreach ($command['listeners'] as $listener) {
                $code .= '$events->listen(' . var_export($listener['event'], true) . ', $this->' . $services[$listener['service']]['method'] . '($scope)); ';
            }
            $code .= "return \$events; })());\n";
        }
        $code .= "                default: throw new \\InvalidArgumentException('未知命令：' . \$name);\n            }\n        } finally { \$this->running = false; }\n    }\n}\n}\n";
        $code .= "namespace {\nfunction main(int \$argc, array \$argv): void\n{\n    try {\n";
        if ($bootstrap !== null) {
            $code .= '        \\' . $bootstrap['class'] . '::' . $bootstrap['method'] . "(\$argv, false);\n";
            return $code . "    } catch (\\Throwable \$error) { fwrite(STDERR, \"应用启动失败：internal_error\\n\"); exit(70); }\n}\n}\n";
        }
        $code .= '        $configuration = \\Type\\Core\\Configuration::fromEnvironment(' . var_export($configuration, true) . ");\n";
        $code .= "        \$application = new \\Type\\Generated\\CommandApplication(\$configuration);\n        \$status = \$application->run(\$argc > 1 ? (string) \$argv[1] : 'help', array_slice(\$argv, 2));\n        if (\$status !== 0) { exit(\$status); }\n";
        $code .= "    } catch (\\Throwable \$error) { fwrite(STDERR, '命令执行失败：' . \$error->getMessage() . PHP_EOL); exit(70); }\n}\n}\n";

        return $code;
    }

    /** 角色先绑定context中的scope；资源登记与任务构造都发生在这个确定范围内。 */
    private function workFactory(array $row, array $services, string $context, string $role): string
    {
        $code = 'function (\\' . $context . ' $context): \\' . $role . ' { $this->assertOwner(); $scope = $context->scope(); '
            . 'if (\\Type\\Runtime\\ExecutionScope::current() !== $scope) { throw new \\RuntimeException(\'任务工厂需要当前角色作用域\'); } ';
        foreach ($row['resources'] as $resource) {
            $code .= '$scope->open($this->' . $services[$resource]['method'] . '($scope)); ';
        }
        return $code . 'return $this->' . $services[$row['service']]['method'] . '($scope); }';
    }
}
