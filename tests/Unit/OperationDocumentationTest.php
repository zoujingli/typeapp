<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\OperationCompiler;

/** 通过公开生成入口核对跨命名空间后的文档类型，不加载业务类。 */
final class OperationDocumentationTest extends TestCase
{
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
        public function apply(array $input, mixed $value): array { return []; }
    }
}
PHP);
            $compiler = new OperationCompiler();
            $mapping = ['classes' => ['DocGenerated\\Operations' => 'DocFixture\\Business\\Service']];
            $result = $compiler->generate($root, $mapping, [$file]);
            $code = $result['code'];
            self::assertStringContainsString('Fault and Row in this description must stay unchanged.', $code);
            self::assertStringContainsString('@template T of \\DocFixture\\Payload', $code);
            self::assertStringContainsString('Fault: \\DocFixture\\Failure', $code);
            self::assertStringContainsString('list<\\DocFixture\\Payload>', $code);
            self::assertStringContainsString('\\DocFixture\\Failure::CODE', $code);
            self::assertStringContainsString('\\Closure(\\DocFixture\\Payload): \\DateTimeImmutable', $code);
            self::assertStringContainsString("text: 'Row'", $code);
            self::assertStringContainsString('@param T $value', $code);
            self::assertStringContainsString('\\DocFixture\\Business\\LocalResult|\\DocFixture\\Payload|\\DateTimeImmutable|null', $code);
            self::assertStringContainsString('@throws \\DocFixture\\Failure original failure', $code);
            self::assertSame($result, $compiler->generate($root, $mapping, [$file]));
            self::assertFalse(class_exists('DocFixture\\Business\\Service', false));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

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
    public function read(array $values): mixed { return null; }
}
PHP);
            $code = (new OperationCompiler())->generate($root, ['classes' => ['GenericGenerated\\Operations' => 'GenericDoc\\Service']], [$file])['code'];
            self::assertStringContainsString('@template T of Row', $code);
            self::assertStringContainsString('@phpstan-import-type Row from \\External\\Row', $code);
            self::assertStringContainsString('size: int<min, max>', $code);
            self::assertStringContainsString('@param \\GenericDoc\\Service<T> $service', $code);
            self::assertStringContainsString('@param Values $values', $code);
            self::assertStringContainsString('@return T|\\External\\Min|\\GenericDoc\\Service', $code);
            self::assertStringNotContainsString('@property', $code);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
