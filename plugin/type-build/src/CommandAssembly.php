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
    public function generate(array $application, array $modules, array $sources): array
    {
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
        [$classes, $interfaces] = $this->symbols($sources);
        $services = [];
        $commands = [];
        foreach ($enabled as $module) {
            if (!is_string($module) || !array_key_exists($module, $modules) || !is_array($modules[$module])) {
                throw new RuntimeException('启用的模块未安装或未声明：' . (is_string($module) ? $module : '(非字符串)'));
            }
            foreach (['services', 'commands'] as $collection) {
                $rows = $modules[$module][$collection] ?? [];
                if (!is_array($rows) || !array_is_list($rows)) {
                    throw new RuntimeException($module . ' 的 ' . $collection . ' 必须是声明列表');
                }
                foreach ($rows as $row) {
                    $key = $collection === 'services' ? 'id' : 'name';
                    if (!is_array($row) || !is_string($row[$key] ?? null) || !preg_match('/^[a-z][a-z0-9_.:-]*$/D', $row[$key])) {
                        throw new RuntimeException('模块声明缺少合法标识：' . $module . '/' . $collection);
                    }
                    $id = $row[$key];
                    $row['origin'] = $module . ':' . $id;
                    if ($collection === 'services') {
                        if (isset($services[$id])) {
                            throw new RuntimeException('重复服务注册：' . $row['origin'] . ' / ' . $services[$id]['origin']);
                        }
                        $row['lifetime'] = $row['lifetime'] ?? 'execution';
                        $row['arguments'] = $row['arguments'] ?? [];
                        if (!in_array($row['lifetime'], ['singleton', 'execution'], true) || !is_array($row['arguments']) || !array_is_list($row['arguments'])) {
                            throw new RuntimeException('服务生命周期或构造参数声明无效：' . $row['origin']);
                        }
                        $class = $row['class'] ?? null;
                        if (!is_string($class) || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $class)) {
                            throw new RuntimeException('服务类名无效：' . $row['origin']);
                        }
                        $symbol = $classes[strtolower($class)] ?? throw new RuntimeException('生产源码中找不到服务类：' . $row['origin'] . ' -> ' . $class);
                        if ($symbol['node']->isAbstract()) {
                            throw new RuntimeException('不能构造抽象服务：' . $row['origin']);
                        }
                        $row['method'] = 'service_' . count($services);
                        $services[$id] = $row;
                    } else {
                        if (isset($commands[$id]) || in_array($id, ['help', 'check'], true)) {
                            throw new RuntimeException('重复命令注册：' . $row['origin']);
                        }
                        $commands[$id] = $row;
                    }
                }
            }
        }
        if ($commands === []) {
            throw new RuntimeException('启用模块没有可执行命令');
        }
        $dependencies = [];
        foreach ($services as $id => $service) {
            $dependencies[$id] = [];
            foreach ($service['arguments'] as $argument) {
                if (!is_array($argument) || count($argument) !== 1) {
                    throw new RuntimeException('构造参数必须只声明 service、config 或 value：' . $service['origin']);
                }
                if (array_key_exists('service', $argument)) {
                    $dependency = $argument['service'];
                    if (!is_string($dependency) || !isset($services[$dependency])) {
                        throw new RuntimeException('缺失服务绑定：' . $service['origin'] . ' -> ' . (is_string($dependency) ? $dependency : '(非字符串)'));
                    }
                    $dependencies[$id][] = $dependency;
                } elseif (array_key_exists('config', $argument)) {
                    if (!is_string($argument['config']) || !array_key_exists($argument['config'], $configuration)) {
                        throw new RuntimeException('缺失配置绑定：' . $service['origin']);
                    }
                } elseif (!array_key_exists('value', $argument) || (!is_scalar($argument['value']) && $argument['value'] !== null)) {
                    throw new RuntimeException('构造常量只支持标量或 null：' . $service['origin']);
                }
            }
            $constructor = $this->constructor(strtolower($service['class']), $classes);
            $parameters = $constructor?->params ?? [];
            $required = count(array_filter($parameters, static fn (Node\Param $parameter): bool => $parameter->default === null && !$parameter->variadic));
            $variadic = array_filter($parameters, static fn (Node\Param $parameter): bool => $parameter->variadic) !== [];
            if (($constructor !== null && !$constructor->isPublic()) || count($service['arguments']) < $required
                || (!$variadic && count($service['arguments']) > count($parameters))) {
                throw new RuntimeException('构造参数数量或可见性不符合声明：' . $service['origin']);
            }
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

        return ['code' => $this->render($services, $commands, $configuration), 'enabled-modules' => $enabled,
            'disabled-modules' => array_values(array_diff(array_keys($modules), $enabled)), 'commands' => array_keys($commands)];
    }

    private function symbols(array $sources): array
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
            $traverser = new NodeTraverser(new NameResolver());
            try {
                $nodes = $traverser->traverse($parser->parse(file_get_contents($file)) ?? []);
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
                    $classes[$name] = ['node' => $node, 'parent' => strtolower($node->extends?->toString() ?? ''),
                        'interfaces' => array_map(static fn (Node\Name $name): string => strtolower($name->toString()), $node->implements)];
                } elseif ($node instanceof Node\Stmt\Interface_) {
                    $interfaces[$name] = array_map(static fn (Node\Name $name): string => strtolower($name->toString()), $node->extends);
                }
            }
        }

        return [$classes, $interfaces];
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

    private function render(array $services, array $commands, array $configuration): string
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Type\\Generated {\nfinal class CommandApplication\n{\n";
        $code .= "    private array \$singletons = [];\n    private array \$execution = [];\n    private bool \$running = false;\n    private \\Type\\Core\\Configuration \$configuration;\n";
        $code .= "    public function __construct(\\Type\\Core\\Configuration \$configuration) { \$this->configuration = \$configuration; }\n";
        foreach ($services as $id => $service) {
            $arguments = [];
            foreach ($service['arguments'] as $argument) {
                $arguments[] = isset($argument['service']) ? '$this->' . $services[$argument['service']]['method'] . '()'
                    : (isset($argument['config']) ? '$this->configuration->text(' . var_export($argument['config'], true) . ')' : var_export($argument['value'], true));
            }
            $cache = $service['lifetime'] === 'singleton' ? 'singletons' : 'execution';
            $key = var_export($id, true);
            $code .= "    private function {$service['method']}(): \\{$service['class']}\n    {\n";
            $code .= "        if (!array_key_exists($key, \$this->$cache)) { \$this->{$cache}[$key] = new \\{$service['class']}(" . implode(', ', $arguments) . "); }\n";
            $code .= "        return \$this->{$cache}[$key];\n    }\n";
        }
        $help = '可用命令：help、check、' . implode('、', array_keys($commands)) . "\n";
        $code .= "    public function run(string \$name, array \$arguments): int\n    {\n";
        $code .= "        if (\$name === 'help') { echo " . var_export($help, true) . "; return 0; }\n";
        $code .= "        if (\$name === 'check') { echo \"离线配置检查通过。\\n\"; return 0; }\n";
        $code .= "        if (\$this->running) { throw new \\RuntimeException('命令应用不能重入执行'); }\n        \$this->running = true;\n        try {\n            switch (\$name) {\n";
        foreach ($commands as $name => $command) {
            $code .= '                case ' . var_export($name, true) . ":\n                    \$events = new \\Type\\Core\\Events();\n";
            foreach ($command['listeners'] as $listener) {
                $code .= '                    $events->listen(' . var_export($listener['event'], true) . ', $this->' . $services[$listener['service']]['method'] . "());\n";
            }
            $resources = array_map(static fn (string $id): string => '$this->' . $services[$id]['method'] . '()', $command['resources']);
            $code .= '                    return (new \\Type\\Core\\Application())->run($this->' . $services[$command['service']]['method'] . '(), $this->configuration, $arguments, [' . implode(', ', $resources) . "], \$events);\n";
        }
        $code .= "                default: throw new \\InvalidArgumentException('未知命令：' . \$name);\n            }\n        } finally { \$this->execution = []; \$this->running = false; }\n    }\n}\n}\n";
        $code .= "namespace {\nfunction main(int \$argc, array \$argv): void\n{\n    try {\n";
        $code .= '        $configuration = \\Type\\Core\\Configuration::fromEnvironment(' . var_export($configuration, true) . ");\n";
        $code .= "        \$application = new \\Type\\Generated\\CommandApplication(\$configuration);\n        \$status = \$application->run(\$argc > 1 ? (string) \$argv[1] : 'help', array_slice(\$argv, 2));\n        if (\$status !== 0) { exit(\$status); }\n";
        $code .= "    } catch (\\Throwable \$error) { fwrite(STDERR, '命令执行失败：' . \$error->getMessage() . PHP_EOL); exit(70); }\n}\n}\n";

        return $code;
    }
}
