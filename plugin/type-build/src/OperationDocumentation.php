<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\NameContext;
use PhpParser\Node as PhpNode;
use PHPStan\PhpDocParser\Ast as Doc;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Printer\Printer;
use RuntimeException;

/** @internal 将文档类型从业务词法语境迁移到组合类；不加载类型或解释业务注解。 */
final class OperationDocumentation
{
    private Lexer $lexer;
    private PhpDocParser $parser;
    private string $className;
    private ?string $classDocumentation = null;
    /** @var array<string, true> 类级模板与本地类型别名。 */
    private array $localTypes = [];
    /** @var list<string> 用于将构造参数绑定到组合类模板。 */
    private array $templates = [];

    /** 保存AST解析器提供的原命名空间与use映射，不执行业务类。 */
    public function __construct(PhpNode\Stmt\Class_ $class, private NameContext $context)
    {
        $this->className = $class->namespacedName->toString();
        $config = new ParserConfig(['lines' => true, 'indexes' => true, 'comments' => true]);
        $this->lexer = new Lexer($config);
        $constants = new ConstExprParser($config);
        $this->parser = new PhpDocParser($config, new TypeParser($config, $constants), $constants);
        $comment = $class->getDocComment()?->getText();
        if ($comment !== null) {
            [$document] = $this->parse($comment);
            $this->localTypes = $this->localNames($document);
            $definitions = [];
            foreach ($document->children as $child) {
                if (!$child instanceof Doc\PhpDoc\PhpDocTagNode) {
                    continue;
                }
                if ($child->value instanceof Doc\PhpDoc\TemplateTagValueNode) {
                    $this->templates[] = $child->value->name;
                }
                if ($this->typeDefinition($child->value)) {
                    $definitions[] = $child;
                }
            }
            // 不把@property/@method等业务类声明冒充组合对象的公开成员。
            if ($definitions !== []) {
                $this->classDocumentation = $this->rewrite((string) new Doc\PhpDoc\PhpDocNode($definitions), $this->localTypes);
            }
        }
    }

    /** 只迁移方法文档所需的类级类型定义，不迁移业务类的成员注解。 */
    public function classComment(): ?string
    {
        return $this->classDocumentation;
    }

    /** 保持组合对象模板与实际业务实例的类型参数关联。 */
    public function constructorComment(): string
    {
        return $this->templates === [] ? '' : '    /** @param \\' . $this->className . '<' . implode(', ', $this->templates) . '> $service */' . "\n";
    }

    /** 保留说明文本和排版，只改写原语境下需要限定的类型。 */
    public function methodComment(PhpNode\Stmt\ClassMethod $method): ?string
    {
        $comment = $method->getDocComment()?->getText();
        return $comment === null ? null : $this->rewrite($comment, $this->localTypes);
    }

    /** @return array{Doc\PhpDoc\PhpDocNode, TokenIterator} */
    private function parse(string $comment): array
    {
        $tokens = new TokenIterator($this->lexer->tokenize($comment));
        $document = $this->parser->parse($tokens);
        foreach ($document->children as $child) {
            if ($child instanceof Doc\PhpDoc\PhpDocTagNode && $child->value instanceof Doc\PhpDoc\InvalidTagValueNode) {
                throw new RuntimeException('操作PHPDoc无法解析：' . $child->name);
            }
        }
        return [$document, $tokens];
    }

    /** @return array<string, true> 文档自身定义的类型名不能按类名扩展。 */
    private function localNames(Doc\PhpDoc\PhpDocNode $document): array
    {
        $names = [];
        foreach ($document->children as $child) {
            if (!$child instanceof Doc\PhpDoc\PhpDocTagNode) {
                continue;
            }
            $value = $child->value;
            if ($value instanceof Doc\PhpDoc\TemplateTagValueNode) {
                $names[$value->name] = true;
            } elseif ($value instanceof Doc\PhpDoc\TypeAliasTagValueNode) {
                $names[$value->alias] = true;
            } elseif ($value instanceof Doc\PhpDoc\TypeAliasImportTagValueNode) {
                $names[$value->importedAs ?? $value->importedAlias] = true;
            }
        }
        return $names;
    }

    private function typeDefinition(Doc\PhpDoc\PhpDocTagValueNode $value): bool
    {
        return $value instanceof Doc\PhpDoc\TemplateTagValueNode || $value instanceof Doc\PhpDoc\TypeAliasTagValueNode || $value instanceof Doc\PhpDoc\TypeAliasImportTagValueNode;
    }

    /** @param array<string, true> $locals 类级文档类型；方法级定义在本次解析中合并。 */
    private function rewrite(string $comment, array $locals): string
    {
        [$original, $tokens] = $this->parse($comment);
        $locals += $this->localNames($original);
        $document = (new Doc\NodeTraverser([new Doc\NodeVisitor\CloningVisitor()]))->traverse([$original])[0];
        $resolve = fn (string $name, bool $classReference): string => $this->resolveName($name, $classReference ? [] : $locals);
        $visitor = new /** 在原声明语境中解析文档类型，保留字段键和模板名称。 */ class ($resolve, $this->className) extends Doc\AbstractNodeVisitor {
            /** @var list<Doc\Node> */
            private array $parents = [];
            /** @param \Closure(string, bool): string $resolve */
            public function __construct(private \Closure $resolve, private string $className)
            {
            }
            /** 仅改写类型及类常量引用；记录父节点以区分类型名与数组形状键。 */
            public function enterNode(Doc\Node $node): ?Doc\Node
            {
                $parent = $this->parents === [] ? null : $this->parents[array_key_last($this->parents)];
                $this->parents[] = $node;
                if ($node instanceof Doc\Type\IdentifierTypeNode) {
                    if (($parent instanceof Doc\Type\ArrayShapeItemNode || $parent instanceof Doc\Type\ObjectShapeItemNode) && $parent->keyName === $node) {
                        return null;
                    }
                    if ($parent instanceof Doc\Type\GenericTypeNode && strtolower($parent->type->name) === 'int' && in_array($node->name, ['min', 'max'], true)) {
                        return null;
                    }
                    $classReference = $parent instanceof Doc\PhpDoc\TypeAliasImportTagValueNode && $parent->importedFrom === $node;
                    $node->name = ($this->resolve)($node->name, $classReference);
                } elseif ($node instanceof Doc\ConstExpr\ConstFetchNode && $node->className !== '') {
                    $node->className = ($this->resolve)($node->className, false);
                } elseif ($node instanceof Doc\Type\ThisTypeNode) {
                    return new Doc\Type\IdentifierTypeNode('\\' . $this->className);
                }
                return null;
            }
            /** 与 enterNode 成对回收父节点栈，保持相邻文档节点的语境独立。 */
            public function leaveNode(Doc\Node $node): ?Doc\Node
            {
                array_pop($this->parents);
                return null;
            }
        };
        $document = (new Doc\NodeTraverser([$visitor]))->traverse([$document])[0];
        return (new Printer())->printFormatPreserving($document, $original, $tokens);
    }

    /** @param array<string, true> $locals */
    private function resolveName(string $name, array $locals): string
    {
        if (isset($locals[$name]) || str_starts_with($name, '\\') || str_contains($name, '-')) {
            return $name;
        }
        $lower = strtolower($name);
        if (in_array($lower, ['array', 'list', 'bool', 'boolean', 'int', 'integer', 'float', 'double', 'real', 'string', 'object', 'mixed', 'void', 'never', 'null', 'true', 'false', 'resource', 'scalar', 'numeric', 'callable', 'iterable'], true)) {
            return $name;
        }
        if ($lower === 'self' || $lower === 'static') {
            return '\\' . $this->className;
        }
        if ($lower === 'parent') {
            throw new RuntimeException('无继承操作类的PHPDoc不能引用parent');
        }
        $node = str_starts_with($lower, 'namespace\\') ? new PhpNode\Name\Relative(substr($name, 10)) : new PhpNode\Name($name);
        return '\\' . $this->context->getResolvedClassName($node)->toString();
    }
}
