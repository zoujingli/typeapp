<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

/** 将声明方法转换到原 Service 类型；完整文件替换，不加载业务源码或增加运行时 AOP。 */
final class OperationCompiler
{
    private const TRANSACTIONAL = 'Type\\Orm\\Attribute\\Transactional';
    private const CACHEABLE = 'Type\\Cache\\Attribute\\Cacheable';
    private const CACHE_EVICT = 'Type\\Cache\\Attribute\\CacheEvict';

    /**
     * 静态读取业务声明并替换原方法体，保留类型、构造器和同文件的其他声明。
     *
     * @param array{} $configuration 旧 classes 映射已移除，声明由生产源码 Attribute 确定。
     * @param list<string> $sources 显式源码文件/目录，可为相对项目根或已选定的绝对路径。
     * @param array<string, mixed>|null $packages 已审计生产包；省略时复用 SourceSet 的安装依赖审计。
     * @return array{code: string, originals: list<string>, operations: list<array<string, mixed>>}
     * @throws RuntimeException 输入不存在、声明或类型不支持、名称冲突或生成结果无效。
     */
    public function generate(string $root, array $configuration, array $sources, ?array $packages = null): array
    {
        $root = realpath($root) ?: throw new RuntimeException('操作生成项目目录不存在');
        if ($configuration !== []) {
            throw new RuntimeException('operations.classes 映射已移除；请将原 Service 纳入 sources，由标准入口转换声明');
        }
        $packages ??= (new SourceSet())->productionSources($root, [])['included'];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $printer = new Standard();
        $files = array_map(static fn (string $source): string => (new BuildPlatform())->absolute($source) ? $source : $root . '/' . $source, $sources);
        $code = "<?php\n\ndeclare(strict_types=1);\n";
        $operations = [];
        $originals = [];
        $parents = [];
        $transformed = [];
        foreach ((new BuildIdentity())->sources($files) as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (str_contains($source, '@type-build-operation:v2')) {
                throw new RuntimeException('操作源码已经转换，拒绝重复转换：' . $file);
            }
            $ast = (new NodeTraverser(new NameResolver()))->traverse($parser->parse($source) ?? []);
            $topClasses = [];
            foreach ($ast as $statement) {
                foreach ($statement instanceof Node\Stmt\Namespace_ ? $statement->stmts : [$statement] as $declaration) {
                    if ($declaration instanceof Node\Stmt\ClassLike) {
                        $topClasses[] = $declaration;
                    }
                }
            }
            foreach ($finder->find($ast, static fn (Node $node): bool => property_exists($node, 'attrGroups')) as $declaration) {
                foreach ($declaration->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if ($this->recognized($attribute) && !$declaration instanceof Node\Stmt\ClassMethod) {
                            throw new RuntimeException('操作 Attribute 只能标记公开实例方法：' . $file . ':' . $attribute->getStartLine());
                        }
                    }
                }
            }
            $changed = false;
            foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
                if ($class instanceof Node\Stmt\Class_ && $class->extends !== null) {
                    $parents[$class->namespacedName?->toString() ?? ''] = strtolower($class->extends->toString());
                }
                foreach ($class->getMethods() as $method) {
                    $attributes = $this->attributes($method);
                    if ($attributes === []) {
                        continue;
                    }
                    $service = $class->namespacedName?->toString() ?? '';
                    if (!$class instanceof Node\Stmt\Class_ || $class->isAbstract() || $class->extends !== null || $class->getTraitUses() !== [] || !str_contains($service, '\\') || !in_array($class, $topClasses, true)) {
                        throw new RuntimeException('操作业务类必须是具名具体类，当前不支持继承或 Trait：' . $service);
                    }
                    if (!$method->isPublic()) {
                        throw new RuntimeException('操作 Attribute 不能用于非公开方法');
                    }
                    if (isset($attributes[self::TRANSACTIONAL]) && !isset($packages['zoujingli/type-orm'])) {
                        throw new RuntimeException('Transactional 声明需要安装 type-orm 生产依赖');
                    }
                    if ((isset($attributes[self::CACHEABLE]) || isset($attributes[self::CACHE_EVICT])) && !isset($packages['zoujingli/type-cache'])) {
                        throw new RuntimeException('缓存声明需要安装 type-cache 生产依赖');
                    }
                    if ($finder->findFirst($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom
                        || $node instanceof Node\Scalar\MagicConst\Function_ || $node instanceof Node\Scalar\MagicConst\Method
                        || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($node->name->toString()), ['func_get_args', 'func_get_arg', 'func_num_args', 'get_defined_vars'], true)) !== null) {
                        throw new RuntimeException('操作方法不支持生成器或依赖原方法执行帧的语法：' . $service . '::' . $method->name);
                    }
                    $implementation = '_type_operation_' . $method->name->toString();
                    while ($class->getMethod($implementation) !== null) {
                        $implementation .= '_';
                    }
                    $original = clone $method;
                    $original->name = new Node\Identifier($implementation);
                    $original->flags = Node\Stmt\Class_::MODIFIER_PRIVATE;
                    $original->attrGroups = [];
                    $original->setDocComment(new \PhpParser\Comment\Doc('/** @internal 原声明方法的业务实现；只由同类公开方法调用。 */'));
                    $result = $this->method($method, $attributes, $service, isset($packages['zoujingli/type-orm']), $implementation);
                    $transformed[strtolower($service)] = true;
                    $body = $parser->parse('<?php class GeneratedOperation {' . $result['code'] . '}');
                    $method->stmts = $body[0]->getMethods()[0]->stmts;
                    $class->stmts[] = $original;
                    $operations[] = ['service' => $service, 'method' => $method->name->toString(), 'line' => $method->getStartLine()] + $result['metadata'];
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }
            if ($finder->findFirst($ast, static fn (Node $node): bool => $node instanceof Node\Scalar\MagicConst\Dir || $node instanceof Node\Scalar\MagicConst\File || $node instanceof Node\Scalar\MagicConst\Line) !== null) {
                throw new RuntimeException('操作转换文件不支持依赖源码物理位置的魔术常量：' . $file);
            }
            $global = [];
            foreach ($ast as $statement) {
                if ($statement instanceof Node\Stmt\Declare_) {
                    foreach ($statement->declares as $declare) {
                        if ($declare->key->toString() !== 'strict_types' || $declare->value->value !== 1) {
                            throw new RuntimeException('操作源码只接受 strict_types=1 声明');
                        }
                    }
                } elseif ($statement instanceof Node\Stmt\Namespace_) {
                    if ($global !== []) {
                        $code .= "\nnamespace {\n" . $printer->prettyPrint($global) . "\n}\n";
                        $global = [];
                    }
                    $code .= "\nnamespace " . ($statement->name?->toString() ?? '') . " {\n" . $printer->prettyPrint($statement->stmts) . "\n}\n";
                } else {
                    $global[] = $statement;
                }
            }
            if ($global !== []) {
                $code .= "\nnamespace {\n" . $printer->prettyPrint($global) . "\n}\n";
            }
            $originals[] = $file;
        }
        foreach ($parents as $child => $parent) {
            if (isset($transformed[$parent])) {
                throw new RuntimeException('声明操作服务不支持被继承：' . $child);
            }
        }
        try {
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (\PhpParser\Error $error) {
            throw new RuntimeException('生成操作 PHP 无效：' . $error->getMessage(), 0, $error);
        }
        return ['code' => $code, 'originals' => $originals, 'operations' => $operations];
    }

    private function method(Node\Stmt\ClassMethod $method, array $attributes, string $service, bool $orm, string $implementation): array
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
        $body = "        // @type-build-operation:v2\n        {$serviceVariable} = \$this;\n";
        $call = $serviceVariable . '->' . $implementation . '(' . implode(', ', $arguments) . ')';
        $transaction = $attributes[self::TRANSACTIONAL] ?? null;
        $cacheable = $attributes[self::CACHEABLE] ?? null;
        $evict = $attributes[self::CACHE_EVICT] ?? null;
        $database = $transaction['database'] ?? $cacheable['database'] ?? $evict['database'] ?? 'default';
        if (!is_string($database) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $database) !== 1) {
            throw new RuntimeException('操作 database 必须是逻辑数据源名称');
        }
        if ($transaction !== null && isset($evict['database']) && $evict['database'] !== $database) {
            throw new RuntimeException('Transactional 与 CacheEvict 必须使用同一逻辑数据源');
        }
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
            if ($orm) {
                $body .= '        if (\\Type\\Orm\\Db::inTransaction(' . var_export($database, true) . ")) {\n            return " . $call . ";\n        }\n";
            }
            $body .= '        return $' . $cacheParameter . '->remember(' . $key . ', static function () use (' . implode(', ', $captures) . '): '
                . $return . " {\n            return " . $call . ";\n        }, " . $ttl . ");\n";
        } elseif ($transaction !== null) {
            $captures = array_merge([$serviceVariable], array_values($arguments));
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
                . $call . ";\n";
            if ($eviction !== null) {
                if ($orm) {
                    $body .= '        if (\\Type\\Orm\\Db::inTransaction(' . var_export($database, true) . ")) {\n"
                        . '            \\Type\\Orm\\Db::afterCommit(static function () use (' . implode(', ', array_values($arguments))
                        . "): void {\n                " . $eviction['expression'] . ";\n            }, " . var_export($database, true) . ");\n        } else {\n            " . $eviction['expression'] . ";\n        }\n";
                } else {
                    $body .= '        ' . $eviction['expression'] . ";\n";
                }
                if ($return !== 'void') {
                    $body .= '        return ' . $valueVariable . ";\n";
                }
            }
        }
        return ['code' => "\n" . '    public function ' . $name . '(' . implode(', ', $parameters) . '): ' . $return
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
                    self::TRANSACTIONAL => ['database'], self::CACHEABLE => ['cache', 'key', 'ttlMilliseconds', 'database'], self::CACHE_EVICT => ['cache', 'key', 'all', 'database'],
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

}
