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

/** 将显式映射服务的方法声明编为普通组合对象；不加载业务源码或增加运行时 AOP。 */
final class OperationCompiler
{
    private const TRANSACTIONAL = 'Type\\Orm\\Attribute\\Transactional';
    private const CACHEABLE = 'Type\\Cache\\Attribute\\Cacheable';
    private const CACHE_EVICT = 'Type\\Cache\\Attribute\\CacheEvict';

    /**
     * 静态读取业务声明并生成显式组合类，不执行源码或解释PHPDoc为操作属性。
     *
     * @param array{classes?: array<string, class-string>} $configuration 完整生成类名到业务类名的映射。
     * @param list<string> $sources 显式源码文件/目录，可为相对项目根或已选定的绝对路径。
     * @return array{code: string, operations: list<array{wrapper: string, service: class-string, method: string, transaction: ?array<string, mixed>, cacheable: ?array<string, mixed>, evict: ?array<string, mixed>}>}
     * @throws RuntimeException 输入不存在、声明或类型不支持、名称冲突或生成结果无效。
     */
    public function generate(string $root, array $configuration, array $sources): array
    {
        $root = realpath($root) ?: throw new RuntimeException('操作生成项目目录不存在');
        if (array_diff(array_keys($configuration), ['classes']) !== []) {
            throw new RuntimeException('operations 包含未知配置项');
        }
        $mapping = $configuration['classes'] ?? [];
        if (!is_array($mapping) || ($mapping !== [] && array_is_list($mapping))) {
            throw new RuntimeException('operations.classes 必须是生成类到业务类的显式映射');
        }
        $symbols = $this->symbols($root, $sources);
        $code = "<?php\n\ndeclare(strict_types=1);\n";
        $operations = [];
        $generated = [];
        ksort($mapping);
        foreach ($mapping as $wrapper => $service) {
            foreach ([$wrapper, $service] as $className) {
                if (!is_string($className) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $className)) {
                    throw new RuntimeException('操作类名必须是带命名空间的完整类名');
                }
            }
            if (isset($symbols[strtolower($wrapper)]) || isset($generated[strtolower($wrapper)])) {
                throw new RuntimeException('生成操作类与已声明类重名：' . $wrapper);
            }
            $generated[strtolower($wrapper)] = true;
            $symbol = $symbols[strtolower($service)] ?? throw new RuntimeException('业务类未纳入生产源码：' . $service);
            $class = $symbol['node'];
            if (!$class instanceof Node\Stmt\Class_ || $class->isAbstract() || $class->extends !== null || $class->getTraitUses() !== []) {
                throw new RuntimeException('操作业务类必须是显式方法组成的具体类，当前不支持继承或 Trait：' . $service);
            }
            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($this->recognized($attribute)) {
                        throw new RuntimeException('操作 Attribute 只能标记公开实例方法');
                    }
                }
            }
            $finder = new NodeFinder();
            foreach ($finder->find($class->stmts, static fn (Node $node): bool => $node instanceof Node\Param || $node instanceof Node\Stmt\Property || $node instanceof Node\Stmt\ClassConst) as $declaration) {
                foreach ($declaration->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if ($this->recognized($attribute)) {
                            throw new RuntimeException('操作 Attribute 不能用于字段、常量或参数');
                        }
                    }
                }
            }
            $parts = explode('\\', $wrapper);
            $short = array_pop($parts);
            $namespace = implode('\\', $parts);
            $documentation = new OperationDocumentation($class, $symbol['doc-context']);
            $classComment = $documentation->classComment();
            $code .= "\nnamespace {$namespace} {\n\n" . ($classComment === null ? '' : $classComment . "\n")
                . "final class {$short}\n{\n    private \\{$symbol['name']} \$service;\n\n"
                . $documentation->constructorComment()
                . "    public function __construct(\\{$symbol['name']} \$service)\n    {\n        \$this->service = \$service;\n    }\n";
            foreach ($class->getMethods() as $method) {
                $attributes = $this->attributes($method);
                if (strtolower($method->name->toString()) === '__construct' || !$method->isPublic()) {
                    if ($attributes !== []) {
                        throw new RuntimeException('操作 Attribute 不能用于构造器或非公开方法');
                    }
                    continue;
                }
                $result = $this->method($method, $attributes, $symbol['name'], $documentation->methodComment($method));
                $code .= $result['code'];
                $operations[] = ['wrapper' => $wrapper, 'service' => $symbol['name'], 'method' => $method->name->toString()] + $result['metadata'];
            }
            $code .= "}\n}\n";
        }
        try {
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (\PhpParser\Error $error) {
            throw new RuntimeException('生成操作 PHP 无效：' . $error->getMessage(), 0, $error);
        }
        return ['code' => $code, 'operations' => $operations];
    }

    private function method(Node\Stmt\ClassMethod $method, array $attributes, string $service, ?string $doc): array
    {
        $name = $method->name->toString();
        if ($method->isStatic() || $method->isAbstract() || $method->byRef || str_starts_with($name, '__')) {
            throw new RuntimeException('操作只接受普通公开实例方法，不支持静态、抽象、引用返回或魔术方法：' . $service . '::' . $name);
        }
        $return = $this->type($method->returnType, true);
        $parameters = [];
        $arguments = [];
        $parameterTypes = [];
        $optional = false;
        foreach ($method->params as $parameter) {
            if ($parameter->byRef || $parameter->variadic || !is_string($parameter->var->name)) {
                throw new RuntimeException('操作参数不支持引用或 variadic');
            }
            $parameterName = $parameter->var->name;
            $parameterType = $this->type($parameter->type, false);
            $declaration = $parameterType . ' $' . $parameterName;
            if ($parameter->default !== null) {
                $optional = true;
                $declaration .= ' = ' . var_export($this->literal($parameter->default), true);
            } elseif ($optional) {
                throw new RuntimeException('操作必填参数不能位于可选参数之后');
            }
            $parameters[] = $declaration;
            $arguments[$parameterName] = '$' . $parameterName;
            $parameterTypes[$parameterName] = $parameterType;
        }
        $prefix = '_type_operation';
        while (array_filter(array_keys($parameterTypes), static fn (string $parameterName): bool => str_starts_with($parameterName, $prefix)) !== []) {
            $prefix .= '_';
        }
        $serviceVariable = '$' . $prefix . '_service';
        $body = "        {$serviceVariable} = \$this->service;\n";
        $transaction = $attributes[self::TRANSACTIONAL] ?? null;
        $cacheable = $attributes[self::CACHEABLE] ?? null;
        $evict = $attributes[self::CACHE_EVICT] ?? null;
        $eviction = null;
        if ($evict !== null) {
            $cacheParameter = $this->cacheParameter($evict, $parameterTypes);
            $all = $evict['all'] ?? false;
            if (!is_bool($all) || ($all && ($evict['key'] ?? '') !== '')) {
                throw new RuntimeException('CacheEvict.all 必须为布尔值，清命名空间时不能同时指定 key');
            }
            $eviction = ['cache' => $cacheParameter, 'expression' => $all ? '$' . $cacheParameter . '->clear()'
                : '$' . $cacheParameter . '->delete(' . $this->cacheKey($evict['key'] ?? null, $parameterTypes, $cacheParameter, false) . ')'];
        }
        if ($cacheable !== null) {
            if ($transaction !== null || $evict !== null || $return === 'void') {
                throw new RuntimeException('Cacheable 不支持 void 返回或与事务/失效组合');
            }
            $cacheParameter = $this->cacheParameter($cacheable, $parameterTypes);
            $key = $this->cacheKey($cacheable['key'] ?? null, $parameterTypes, $cacheParameter, true);
            $ttl = $cacheable['ttlMilliseconds'] ?? 60000;
            if (!is_int($ttl) || $ttl < 1 || $ttl > 31536000000) {
                throw new RuntimeException('Cacheable TTL 必须是正整数且不超过一年');
            }
            $captures = array_merge([$serviceVariable], array_values($arguments));
            $body .= '        return $' . $cacheParameter . '->remember(' . $key . ', static function () use (' . implode(', ', $captures) . '): '
                . $return . " {\n            return " . $serviceVariable . '->' . $name . '(' . implode(', ', $arguments) . ");\n        }, " . $ttl . ");\n";
        } elseif ($transaction !== null) {
            $database = $transaction['database'] ?? 'default';
            if (!is_string($database) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $database) !== 1) {
                throw new RuntimeException('Transactional.database 必须是逻辑数据源名称');
            }
            $captures = array_merge([$serviceVariable], array_values($arguments));
            $call = $serviceVariable . '->' . $name . '(' . implode(', ', $arguments) . ')';
            $body .= '        ' . ($return === 'void' ? '' : 'return ') . '\\Type\\Orm\\Db::transaction(static function () use ('
                . implode(', ', $captures) . '): ' . $return . " {\n";
            if ($eviction !== null) {
                // 成功提交之前登记；失败时由 Connection 丢弃当前帧，嵌套成功合并至父帧。
                $evictCaptures = array_values($arguments);
                $body .= '            \\Type\\Orm\\Db::afterCommit(static function () use (' . implode(', ', $evictCaptures)
                    . "): void {\n                " . $eviction['expression'] . ";\n            }, " . var_export($database, true) . ");\n";
            }
            $body .= '            ' . ($return === 'void' ? '' : 'return ') . $call . ";\n        }, " . var_export($database, true) . ");\n";
        } else {
            $valueVariable = '$' . $prefix . '_result';
            $body .= '        ' . ($return === 'void' ? '' : ($eviction === null ? 'return ' : $valueVariable . ' = '))
                . $serviceVariable . '->' . $name . '(' . implode(', ', $arguments) . ");\n";
            if ($eviction !== null) {
                $body .= '        ' . $eviction['expression'] . ";\n";
                if ($return !== 'void') {
                    $body .= '        return ' . $valueVariable . ";\n";
                }
            }
        }
        $documentation = $doc === null ? '' : '    ' . str_replace("\n", "\n    ", $doc) . "\n";
        return ['code' => "\n" . $documentation . '    public function ' . $name . '(' . implode(', ', $parameters) . '): ' . $return
            . "\n    {\n" . $body . "    }\n", 'metadata' => ['transaction' => $transaction, 'cacheable' => $cacheable, 'evict' => $evict]];
    }

    private function cacheParameter(array $settings, array $parameters): string
    {
        $cache = $settings['cache'] ?? null;
        if (!is_string($cache) || strcasecmp($parameters[$cache] ?? '', '\\Type\\Cache\\TypedCache') !== 0) {
            throw new RuntimeException('缓存声明 cache 必须指向非空 Type\\Cache\\TypedCache 形参');
        }
        return $cache;
    }

    private function cacheKey(mixed $template, array $parameters, string $cache, bool $complete): string
    {
        if (!is_string($template) || $template === '' || strlen($template) > 512 || str_contains($template, "\0")) {
            throw new RuntimeException('缓存 key 模板必须是非空且不超长的字符串');
        }
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $template, $matches);
        $remainder = preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', '', $template);
        if (str_contains($remainder, '{') || str_contains($remainder, '}')) {
            throw new RuntimeException('缓存 key 模板占位符无效');
        }
        $keys = array_values(array_unique($matches[1]));
        sort($keys);
        foreach ($keys as $name) {
            if (!isset($parameters[$name]) || !$this->scalar($parameters[$name])) {
                throw new RuntimeException('缓存 key 必须引用已声明的标量形参：' . $name);
            }
        }
        if ($complete) {
            foreach ($parameters as $name => $type) {
                if ($name === $cache || strcasecmp($type, '\\Type\\Orm\\Connection') === 0) {
                    continue;
                }
                if (!$this->scalar($type)) {
                    throw new RuntimeException('Cacheable 不能从任意对象或数组参数隐式生成缓存身份');
                }
                if (!in_array($name, $keys, true)) {
                    throw new RuntimeException('Cacheable key 遗漏业务标量参数：' . $name);
                }
            }
        }
        $values = [];
        foreach ($keys as $name) {
            $values[] = var_export($name, true) . ' => $' . $name;
        }
        return "'type-operation:' . hash('sha256', json_encode(['template' => " . var_export($template, true)
            . ", 'arguments' => [" . implode(', ', $values) . ']], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))';
    }

    private function scalar(string $type): bool
    {
        $parts = explode('|', ltrim($type, '?'));
        return array_diff($parts, ['int', 'float', 'string', 'bool', 'null', 'true', 'false']) === [];
    }

    private function type(?Node $type, bool $return): string
    {
        if ($type instanceof Node\NullableType) {
            $inner = $this->type($type->type, false);
            if ($inner === 'mixed' || $inner === 'null') {
                throw new RuntimeException('操作 nullable 类型不合法');
            }
            return '?' . $inner;
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn (Node $part): string => $this->type($part, false), $type->types));
        }
        if ($type instanceof Node\Name) {
            if (in_array(strtolower($type->toString()), ['self', 'static', 'parent'], true)) {
                throw new RuntimeException('组合操作不支持 self/static/parent 相对类型');
            }
            return '\\' . $type->toString();
        }
        if ($type instanceof Node\Identifier && in_array($type->toString(), $return
            ? ['int', 'float', 'string', 'bool', 'array', 'mixed', 'object', 'null', 'true', 'false', 'void']
            : ['int', 'float', 'string', 'bool', 'array', 'mixed', 'object', 'null', 'true', 'false'], true)) {
            return $type->toString();
        }
        throw new RuntimeException('操作参数与返回必须显式声明支持的类型，不支持 never、callable、iterable 或交叉类型');
    }

    private function attributes(Node\Stmt\ClassMethod $method): array
    {
        $result = [];
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (!$this->recognized($attribute)) {
                    continue;
                }
                $name = $this->attributeName($attribute);
                if (isset($result[$name])) {
                    throw new RuntimeException('操作 Attribute 重复：' . $name);
                }
                $values = [];
                $position = 0;
                $named = false;
                $names = match ($name) {
                    self::TRANSACTIONAL => ['database'], self::CACHEABLE => ['cache', 'key', 'ttlMilliseconds'], self::CACHE_EVICT => ['cache', 'key', 'all'],
                };
                foreach ($attribute->args as $argument) {
                    if ($argument->byRef || $argument->unpack || ($argument->name === null && $named)) {
                        throw new RuntimeException('操作 Attribute 不支持解包、引用或命名参数之后的位置参数');
                    }
                    $argumentName = $argument->name?->toString() ?? ($names[$position++] ?? '');
                    $named = $named || $argument->name !== null;
                    if (!in_array($argumentName, $names, true) || array_key_exists($argumentName, $values)) {
                        throw new RuntimeException('操作 Attribute 参数未知或重复：' . $argumentName);
                    }
                    $values[$argumentName] = $this->literal($argument->value);
                }
                $result[$name] = $values;
            }
        }
        return $result;
    }

    private function recognized(Node\Attribute $attribute): bool
    {
        return $this->attributeName($attribute) !== null;
    }

    private function attributeName(Node\Attribute $attribute): ?string
    {
        foreach ([self::TRANSACTIONAL, self::CACHEABLE, self::CACHE_EVICT] as $known) {
            if (strcasecmp($attribute->name->toString(), $known) === 0) {
                return $known;
            }
        }
        return null;
    }

    private function literal(Node\Expr $expression): mixed
    {
        try {
            return (new ConstExprEvaluator(static function (Node\Expr $expression): never {
                throw new RuntimeException('声明仅支持直接常量，不求值业务代码或类常量');
            }))->evaluateDirectly($expression);
        } catch (\Throwable $error) {
            throw new RuntimeException('操作 Attribute 或参数默认值必须是可直接静态求值的常量', 0, $error);
        }
    }

    private function symbols(string $root, array $sources): array
    {
        $files = [];
        foreach ($sources as $source) {
            if (!is_string($source)) {
                throw new RuntimeException('操作生产源码路径无效');
            }
            $resolved = realpath((new BuildPlatform())->absolute($source) ? $source : $root . '/' . $source);
            if ($resolved === false) {
                throw new RuntimeException('操作生产源码不存在');
            }
            if (is_file($resolved)) {
                if (pathinfo($resolved, PATHINFO_EXTENSION) === 'php') {
                    $files[$resolved] = true;
                }
            } else {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                    if ($entry->isFile() && $entry->getExtension() === 'php') {
                        $file = $entry->getRealPath();
                        if (!is_string($file) || !BuildPlatform::contains($resolved, $file)) {
                            throw new RuntimeException('操作源码链接越出声明目录');
                        }
                        $files[$file] = true;
                    }
                }
            }
        }
        ksort($files);
        $classes = [];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach (array_keys($files) as $file) {
            try {
                $resolver = new NameResolver();
                $capture = new /** 构建期捕获各类声明处的名称语境，不执行业务类。 */ class ($resolver) extends \PhpParser\NodeVisitorAbstract {
                    /** 共享同一遍历中的名称解析器，以保留 use 别名和命名空间。 */
                    public function __construct(private NameResolver $resolver)
                    {
                    }

                    /** 在类声明处保存名称语境快照，不替换 PHP 语法节点。 */
                    public function enterNode(Node $node): ?Node
                    {
                        if ($node instanceof Node\Stmt\ClassLike) {
                            $node->setAttribute('type-doc-context', clone $this->resolver->getNameContext());
                        }
                        return null;
                    }
                };
                $nodes = (new NodeTraverser($resolver, $capture))->traverse($parser->parse((string) file_get_contents($file)) ?? []);
            } catch (\PhpParser\Error $error) {
                throw new RuntimeException('操作源码 AST 解析失败：' . $file, 0, $error);
            }
            $declarations = [];
            foreach ($nodes as $node) {
                if ($node instanceof Node\Stmt\Namespace_) {
                    array_push($declarations, ...$node->stmts);
                } else {
                    $declarations[] = $node;
                }
            }
            foreach ($declarations as $node) {
                if (!$node instanceof Node\Stmt\ClassLike) {
                    continue;
                }
                if ($node->name === null) {
                    continue;
                }
                $name = $node->namespacedName->toString();
                if (isset($classes[strtolower($name)])) {
                    throw new RuntimeException('操作源码类重名：' . $name);
                }
                $classes[strtolower($name)] = ['name' => $name, 'node' => $node, 'doc-context' => $node->getAttribute('type-doc-context')];
            }
        }
        return $classes;
    }
}
