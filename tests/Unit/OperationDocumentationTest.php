<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\OperationCompiler;
use Type\Build\ApplicationGeneration;

/** 通过公开生成入口核对跨命名空间后的文档类型，不加载业务类。 */
final class OperationDocumentationTest extends TestCase
{
    /** Model 与 Service 共存时最终映射同一文件，保留构造器和其他声明且拒绝重转。 */
    public function testModelsAndServicesShareOneFinalReplacement(): void
    {
        $root = dirname(__DIR__, 2) . '/build/operation-bundle-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700));
        mkdir($root . '/generated', 0700);
        $source = $root . '/Bundle.php';
        try {
            file_put_contents($root . '/composer.json', json_encode(['name' => 'type-tests/operation-bundle', 'autoload' => ['classmap' => ['Bundle.php']]], JSON_THROW_ON_ERROR));
            file_put_contents($source, <<<'PHP'
<?php
declare(strict_types=1);
namespace OperationBundle;
#[\Type\Orm\Attribute\Table('bundle')]
final class Item extends \Type\Orm\Model { public int $id; public string $name; }
final class Companion { public function value(): string { return 'companion'; } }
final class Service {
    public function __construct(public readonly string $prefix) {}
    public function label(): string { return $this->prefix . (new Companion())->value(); }
    #[\Type\Orm\Attribute\Transactional]
    public function write(int $id): int { return $id; }
}
PHP);
            $result = (new ApplicationGeneration())->generate($root, [], [$source], [], [], ['zoujingli/type-orm' => 'dev'], $root . '/generated');
            $symbols = $result['metadata']['symbols']['classes'];
            $target = $symbols['operationbundle\\service']['file'];
            self::assertSame($target, $symbols['operationbundle\\item']['file']);
            self::assertSame($target, $symbols['operationbundle\\companion']['file']);
            self::assertSame(['source:0' => $target], $result['metadata']['replacements']);
            self::assertSame([$root . '/generated/generated-operations.php'], $result['sources']);
            require $result['sources'][0];
            $service = new \OperationBundle\Service('kept-');
            self::assertSame('kept-companion', $service->label());
            \Type\Runtime\CoroutineRuntime::run(static function (): void {
                $scope = new \Type\Runtime\ExecutionScope();
                try {
                    $scope->run(static function (\Type\Runtime\ExecutionScope $current): void {
                        $item = new \OperationBundle\Item();
                        $item->name = 'model';
                        self::assertSame('model', $item->name);
                    });
                } finally {
                    $scope->close();
                }
            });
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('重复转换');
            (new OperationCompiler())->generate($root, [], $result['sources'], ['zoujingli/type-orm' => 'dev']);
        } finally {
            foreach (glob($root . '/generated/*') as $file) {
                unlink($file);
            }
            rmdir($root . '/generated');
            unlink($source);
            unlink($root . '/composer.json');
            rmdir($root);
        }
    }

    /** 验证生成服务文档在原命名语境中解析别名、数组字段、常量及局部类型，且不加载业务类。 */
    public function testAliasesShapesConstantsAndLocalTypesKeepTheirContext(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/operation-docs-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $file = $directory . '/Service.php';
        try {
            file_put_contents($file, <<<'PHP'
<?php
namespace DocFixture {
    class Payload {}
    class Failure extends \RuntimeException { public const CODE = 7; }
}
namespace DocFixture\Business {
    use DocFixture\{Payload as Row, Failure as Fault};
    use DocFixture as Types;
    use DateTimeImmutable as Clock;
    class LocalResult {}
    final class Service {
        /**
         * Fault and Row in this description must stay unchanged.
         * @template T of Row
         * @param array{Fault: Fault, rows: list<Row>, code: Fault::CODE, fn: \Closure(Row): Clock, text: 'Row'} $input
         * @param T $value
         * @return array<LocalResult|Types\Payload|Clock|null>
         * @throws Fault original failure
        */
        #[\Type\Orm\Attribute\Transactional]
        public function apply(array $input, mixed $value): array { return []; }
    }
}
PHP);
            $compiler = new OperationCompiler();
            $mapping = [];
            $result = $compiler->generate($root, $mapping, [$file]);
            $code = $result['code'];
            self::assertStringContainsString('Fault and Row in this description must stay unchanged.', $code);
            self::assertStringContainsString('@template T of Row', $code);
            self::assertStringContainsString('Fault: Fault', $code);
            self::assertStringContainsString('list<Row>', $code);
            self::assertStringContainsString('Fault::CODE', $code);
            self::assertStringContainsString('\\Closure(Row): Clock', $code);
            self::assertStringContainsString("text: 'Row'", $code);
            self::assertStringContainsString('@param T $value', $code);
            self::assertStringContainsString('LocalResult|Types\\Payload|Clock|null', $code);
            self::assertStringContainsString('@throws Fault original failure', $code);
            self::assertStringContainsString('Payload as Row', $code);
            self::assertStringContainsString('class LocalResult', $code);
            self::assertSame($result, $compiler->generate($root, $mapping, [$file]));
            self::assertFalse(class_exists('DocFixture\\Business\\Service', false));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /** 验证类级模板与类型定义可供生成方法使用，不把属性声明误写为生成成员。 */
    public function testClassTypeDefinitionsRemainAvailableWithoutInventingMembers(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/operation-docs-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $file = $directory . '/Service.php';
        try {
            file_put_contents($file, <<<'PHP'
<?php
namespace GenericDoc;
use External\Row;
use External\Min;
/**
 * @template T of Row
 * @phpstan-type Values array{item: T, size: int<min, max>}
 * @phpstan-import-type Row from Row
 * @property string $notForwarded
 */
final class Service {
    /** @param Values $values
     * @return T|Min|self
     */
    #[\Type\Orm\Attribute\Transactional]
    public function read(array $values): mixed { return null; }
}
PHP);
            $code = (new OperationCompiler())->generate($root, [], [$file])['code'];
            self::assertStringContainsString('@template T of Row', $code);
            self::assertStringContainsString('@phpstan-import-type Row from Row', $code);
            self::assertStringContainsString('size: int<min, max>', $code);
            self::assertStringNotContainsString('private \\GenericDoc\\Service $service', $code);
            self::assertStringContainsString('@param Values $values', $code);
            self::assertStringContainsString('@return T|Min|self', $code);
            self::assertStringContainsString('@property string $notForwarded', $code);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
