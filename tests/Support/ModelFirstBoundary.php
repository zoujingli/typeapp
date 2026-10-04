<?php

declare(strict_types=1);

namespace TypeTests\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** 检查已迁移业务切片的持久化入口；例外绑定具体方法与固定表表达式或 SQL。 */
final class ModelFirstBoundary
{
    /**
     * 只检查调用者明确提供的文件，不把尚未迁移的业务隐式视为已通过。
     *
     * @param array<string, string> $sources 相对文件名到 PHP 源码。
     * @param list<array{owner:string, method:string, argument:string, reason:string}> $exceptions 受限底层访问或基础设施参数；已删除或改写的例外必须同步清理。
     * @return list<string> 可定位的违规原因；空列表表示本次切片通过。
     */
    public function violations(array $sources, array $exceptions = []): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $printer = new Standard();
        $violations = [];
        $used = [];
        foreach ($exceptions as $index => $exception) {
            if (!in_array($exception['method'], ['table', 'query', 'connection_parameter'], true) || trim($exception['reason']) === '') {
                $violations[] = 'invalid_exception: ' . $exception['owner'];
            }
            foreach (array_slice($exceptions, 0, $index) as $previous) {
                if ($exception['owner'] === $previous['owner'] && $exception['method'] === $previous['method']
                    && $exception['argument'] === $previous['argument']) {
                    $violations[] = 'duplicate_exception: ' . $exception['owner'];
                }
            }
        }
        foreach ($sources as $file => $source) {
            $tree = (new NodeTraverser(new NameResolver()))->traverse($parser->parse($source) ?? []);
            foreach ($finder->findInstanceOf($tree, Node\Stmt\Class_::class) as $class) {
                $className = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name?->toString();
                foreach ($class->getProperties() as $property) {
                    if ($property->isPublic() && $this->connectionType($property->type)) {
                        $violations[] = 'public_connection_property: ' . $file . ':' . $property->getStartLine() . ' ' . $className;
                    }
                }
                foreach ($class->getMethods() as $method) {
                    $owner = $className . '::' . $method->name->toString();
                    $location = $file . ':' . $method->getStartLine() . ' ' . $owner;
                    if ($method->isPublic()) {
                        foreach ($method->params as $parameter) {
                            if ($this->connectionType($parameter->type)) {
                                $allowed = false;
                                $argument = '$' . $parameter->var->name . ': ' . $this->typeLabel($parameter->type);
                                foreach ($exceptions as $index => $exception) {
                                    if ($exception['owner'] === $owner && $exception['method'] === 'connection_parameter' && $exception['argument'] === $argument) {
                                        $used[$index] = true;
                                        $allowed = true;
                                    }
                                }
                                if (!$allowed) {
                                    $violations[] = 'public_connection_parameter: ' . $location;
                                }
                            }
                        }
                        if ($this->connectionType($method->returnType)) {
                            $violations[] = 'public_connection_return: ' . $location;
                        }
                    }
                    foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\New_::class) as $creation) {
                        if ($creation->class instanceof Node\Name && $this->connectionType($creation->class)) {
                            $violations[] = 'direct_connection_construction: ' . $file . ':' . $creation->getStartLine() . ' ' . $owner;
                        }
                    }
                    foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\FuncCall::class) as $invocation) {
                        if ($invocation->name instanceof Node\Name
                            && in_array(strtolower($invocation->name->getLast()), ['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array'], true)
                            && $this->databaseCallable($invocation->args[0]->value ?? null)) {
                            $violations[] = 'indirect_database_call: ' . $file . ':' . $invocation->getStartLine() . ' ' . $owner;
                        }
                    }
                    $calls = $finder->find($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                        || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall);
                    foreach ($calls as $call) {
                        $location = $file . ':' . $call->getStartLine() . ' ' . $owner;
                        if (!$call->name instanceof Node\Identifier
                            || ($call instanceof Node\Expr\StaticCall && !$call->class instanceof Node\Name)) {
                            $violations[] = 'dynamic_method_call: ' . $location;
                            continue;
                        }
                        $name = strtolower($call->name->toString());
                        // 控制器可有自己的 query(Request) 输入助手；其方法体仍独立接受同一检查。
                        if (!$call instanceof Node\Expr\StaticCall && $call->var instanceof Node\Expr\Variable
                            && $call->var->name === 'this' && $class->getMethod($name) !== null) {
                            continue;
                        }
                        // 模型工厂和普通业务静态方法不是 Connection 的 SQL 入口。
                        if ($call instanceof Node\Expr\StaticCall
                            && !in_array(strtolower($call->class->toString()), ['type\\orm\\connection', 'type\\orm\\db', 'pdo'], true)) {
                            continue;
                        }
                        if (!in_array($name, ['table', 'query', 'execute', 'exec', 'prepare', 'raw', 'rawquery'], true)
                            || ($name === 'query' && $call->args === [])) {
                            continue;
                        }
                        $argument = $call->args[0]->value ?? null;
                        if (!in_array($name, ['table', 'query'], true) || $argument === null
                            || ($name === 'query' && !$argument instanceof Node\Scalar\String_)) {
                            $violations[] = 'unapproved_database_call: ' . $location . ' ' . $name;
                            continue;
                        }
                        $value = $name === 'query' ? $argument->value : $printer->prettyPrintExpr($argument);
                        if ($name === 'query' && (preg_match('/^SELECT\s.+\sJOIN\s/is', $value) !== 1 || str_contains($value, ';'))) {
                            $violations[] = 'unapproved_database_call: ' . $location . ' 固定 SQL 仅允许单条跨模型只读投影';
                            continue;
                        }
                        $allowed = false;
                        foreach ($exceptions as $index => $exception) {
                            if ($exception['owner'] === $owner && $exception['method'] === $name && $exception['argument'] === $value) {
                                $used[$index] = true;
                                $allowed = true;
                            }
                        }
                        if (!$allowed) {
                            $violations[] = 'unapproved_database_call: ' . $location . ' ' . $name . '(' . $value . ')';
                        }
                    }
                }
            }
        }
        foreach ($exceptions as $index => $exception) {
            if (!isset($used[$index])) {
                $violations[] = 'unused_exception: ' . $exception['owner'] . ' ' . $exception['method'] . '(' . $exception['argument'] . ')';
            }
        }
        return $violations;
    }

    /** 固定底层 callable 也属于 SQL 入口；不把普通事件回调或业务静态方法当数据库调用。 */
    private function databaseCallable(?Node $callable): bool
    {
        $methods = ['table', 'query', 'execute', 'exec', 'prepare', 'raw', 'rawquery'];
        if ($callable instanceof Node\Expr\Array_ && count($callable->items) === 2) {
            $method = $callable->items[1]?->value;
            return $method instanceof Node\Scalar\String_ && in_array(strtolower($method->value), $methods, true);
        }
        if ($callable instanceof Node\Scalar\String_) {
            $parts = explode('::', ltrim(strtolower($callable->value), '\\'));
            return count($parts) === 2 && in_array($parts[0], ['type\\orm\\connection', 'type\\orm\\db', 'pdo'], true) && in_array($parts[1], $methods, true);
        }
        return false;
    }

    /** 名称解析后统一识别别名、可空、联合及交叉类型中的底层连接。 */
    private function connectionType(Node|string|null $type): bool
    {
        if ($type instanceof Node\Name) {
            return in_array(strtolower($type->toString()), ['type\\orm\\connection', 'pdo'], true);
        }
        if ($type instanceof Node\NullableType) {
            return $this->connectionType($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $part) {
                if ($this->connectionType($part)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** 参数例外保留完整声明，不能扩大为任意可空或联合连接类型。 */
    private function typeLabel(Node|string|null $type): string
    {
        if ($type instanceof Node\Name || $type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->typeLabel($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            return '(' . implode($type instanceof Node\UnionType ? '|' : '&', array_map(fn (Node $part): string => $this->typeLabel($part), $type->types)) . ')';
        }
        return (string) $type;
    }
}
