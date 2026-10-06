<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Type\Build\BuildIdentity;
use Type\Build\SchemaCompiler;
use Type\Build\SchemaSql;
use Type\Orm\Migration\Migration;

/** 冻结结果是显式审查输入，普通编译不能隐式修复或执行声明源码。 */
final class SchemaCompilerTest extends TestCase
{
    /** 验证可重复准备、生产工厂及缺失/篡改/协议变化拒绝，同时保全旧迁移身份。 */
    public function testFrozenPlansAreDeterministicAndNeverRewrittenByCompilation(): void
    {
        $directory = dirname(__DIR__, 2) . '/build/schema-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $source = $directory . '/Create.php';
        $snapshot = $directory . '/create.json';
        $class = 'Create' . bin2hex(random_bytes(6));
        $declaration = $this->source($class);
        $compiler = new SchemaCompiler();
        $historical = new Migration('20200101', '原有迁移', ['CREATE TABLE old_table (id INTEGER)']);
        $checksum = $historical->checksum();
        try {
            file_put_contents($source, $declaration);
            $this->rejected(fn () => $compiler->compile([$source]), 'TYPE_SCHEMA_SNAPSHOT_MISSING');
            self::assertFileDoesNotExist($snapshot);
            $first = $compiler->prepare($source);
            self::assertTrue($first['created']);
            $bytes = file_get_contents($snapshot);
            self::assertFalse($compiler->prepare($source)['created']);
            self::assertSame($bytes, file_get_contents($snapshot));
            $result = $compiler->compile([$source]);
            self::assertSame([$snapshot], $compiler->inputs([$source]));
            self::assertFalse(class_exists('SchemaFixture\\' . $class, false));
            self::assertStringNotContainsString('file_get_contents', $result['code']);
            eval(substr($result['code'], 5));
            $factory = 'SchemaFixture\\' . $class;
            $plans = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                $migration = $factory::migration($driver);
                self::assertSame($plans['drivers'][$driver]['statements'], $migration->statements());
                self::assertSame($driver !== 'mysql', $migration->transactional());
                self::assertSame('202610060001', $migration->version());
            }
            foreach (['sql', 'protocol', 'drivers'] as $mutation) {
                $changed = $plans;
                if ($mutation === 'sql') {
                    $changed['drivers']['sqlite']['statements'][0] .= ' changed';
                } elseif ($mutation === 'protocol') {
                    $changed['protocol'] = 999;
                    unset($changed['sha256']);
                    $changed['sha256'] = BuildIdentity::digest($changed);
                } else {
                    $changed['drivers'] = 'invalid';
                }
                $bad = json_encode($changed, JSON_THROW_ON_ERROR);
                file_put_contents($snapshot, $bad);
                $this->rejected(fn () => $compiler->compile([$source]), 'TYPE_SCHEMA_SNAPSHOT_CHANGED');
                $this->rejected(fn () => $compiler->inputs([$source]), 'TYPE_SCHEMA_SNAPSHOT_CHANGED');
                self::assertSame($bad, file_get_contents($snapshot));
            }
            file_put_contents($snapshot, $bytes);
            file_put_contents($source, str_replace("'table' => 'products'", "'table' => 'other_products'", $declaration));
            $this->rejected(fn () => $compiler->compile([$source]), 'TYPE_SCHEMA_SNAPSHOT_CHANGED');
            $this->rejected(fn () => $compiler->prepare($source), 'TYPE_SCHEMA_FROZEN');
            self::assertSame($bytes, file_get_contents($snapshot));
            self::assertSame($checksum, $historical->checksum());
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /** 即使开发代次已生成，快照篡改也必须阻止缓存复用。 */
    public function testDevelopmentGenerationVerifiesSnapshotsBeforeCacheReuse(): void
    {
        $directory = dirname(__DIR__, 2) . '/build/schema-cache-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory . '/vendor/composer', 0700, true));
        self::assertTrue(mkdir($directory . '/vendor/zoujingli/type-orm/src', 0700, true));
        self::assertTrue(mkdir($directory . '/app', 0700));
        try {
            file_put_contents($directory . '/composer.json', json_encode(['name' => 'schema/cache-test', 'require' => ['zoujingli/type-orm' => '*']], JSON_THROW_ON_ERROR));
            file_put_contents($directory . '/composer.lock', '{}');
            file_put_contents($directory . '/application.json', '{"sources":["app"]}');
            file_put_contents($directory . '/vendor/autoload.php', '<?php');
            file_put_contents($directory . '/vendor/zoujingli/type-orm/src/Marker.php', '<?php namespace Type\\Orm; final class Marker {}');
            file_put_contents($directory . '/vendor/composer/installed.json', json_encode(['packages' => [[
                'name' => 'zoujingli/type-orm', 'version' => '1.0.0', 'install-path' => '../zoujingli/type-orm',
                'autoload' => ['psr-4' => ['Type\\Orm\\' => 'src/']],
                'extra' => ['type' => ['protocol' => 1, 'sources' => ['src']]],
            ]]], JSON_THROW_ON_ERROR));
            $source = $directory . '/app/Create.php';
            file_put_contents($source, $this->source('Cached'));
            (new SchemaCompiler())->prepare($source);
            $builder = new \Type\Build\DevelopmentBuilder();
            $first = $builder->prepare($directory, 'application.json', 'build/development');
            self::assertFileExists($first['directory'] . '/generated-schema.php');
            self::assertSame($first, $builder->prepare($directory, 'application.json', 'build/development'));
            $snapshot = $directory . '/app/create.json';
            $bytes = file_get_contents($snapshot);
            file_put_contents($snapshot, str_replace('CREATE TABLE', 'DROP TABLE', $bytes));
            $this->rejected(fn () => $builder->prepare($directory, 'application.json', 'build/development'), 'TYPE_SCHEMA_SNAPSHOT_CHANGED');
            self::assertFileExists($first['directory'] . '/generated-schema.php');
            file_put_contents($snapshot, $bytes);
            self::assertSame($first, $builder->prepare($directory, 'application.json', 'build/development'));
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    /** 非法静态声明与越界快照路径不执行源码，也不留下半份冻结文件。 */
    public function testUnsafeDeclarationsAndSnapshotLinksAreRejected(): void
    {
        $directory = dirname(__DIR__, 2) . '/build/schema-invalid-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $source = $directory . '/Create.php';
        $compiler = new SchemaCompiler();
        $valid = $this->source('Invalid');
        try {
            foreach ([
                $valid . "\nfile_put_contents(__DIR__ . '/executed', 'bad');",
                str_replace('final class Invalid {}', 'final class Invalid { public function run(): void {} }', $valid),
                str_replace('namespace SchemaFixture;', 'namespace {', $valid) . '}',
                str_replace("'create.json'", "'../escaped.json'", $valid),
                str_replace("'products'", "getenv('SCHEMA_TABLE')", $valid),
                str_replace('final class Invalid {}', 'abstract class Invalid {}', $valid),
            ] as $declaration) {
                file_put_contents($source, $declaration);
                $this->rejected(fn () => $compiler->prepare($source));
                self::assertFileDoesNotExist($directory . '/create.json');
                self::assertFileDoesNotExist($directory . '/executed');
                self::assertSame([], glob($directory . '/*.tmp-*'));
            }
            file_put_contents($source, $valid);
            self::assertTrue(symlink(dirname($directory) . '/schema-escape.json', $directory . '/create.json'));
            $this->rejected(fn () => $compiler->prepare($source), 'TYPE_SCHEMA_INVALID');
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /** 字段类型、默认值、索引和受限变更在准备阶段明确拒绝。 */
    public function testInvalidColumnIndexAndAlterCombinationsFailExplicitly(): void
    {
        $create = ['action' => 'create', 'table' => 'products', 'columns' => ['id' => ['type' => 'integer']], 'primary' => ['id']];
        $cases = [];
        foreach ([['type' => 'integer', 'nullable' => null], ['type' => 'integer', 'bits' => null], ['type' => 'string', 'length' => -1],
            ['type' => 'decimal', 'precision' => 4, 'scale' => 5], ['type' => 'decimal', 'precision' => 4, 'scale' => 2, 'default' => '123.45'],
            ['type' => 'integer', 'default' => 'CURRENT_TIMESTAMP'], ['type' => 'datetime', 'default' => '2026-02-30T00:00:00.000000Z'],
            ['type' => 'binary', 'default' => 'bytes'], ['type' => 'text', 'auto' => true], ['type' => 'boolean', 'default' => 1]] as $column) {
            $cases[] = array_replace($create, ['columns' => ['id' => $column]]);
        }
        foreach ([['name' => [], 'columns' => ['id']], ['name' => 'idx', 'columns' => [['id']]], ['name' => 'idx', 'columns' => 'id'],
            ['name' => 'idx', 'columns' => ['missing']], ['name' => 'idx', 'columns' => ['id'], 'unique' => null]] as $index) {
            $cases[] = array_replace($create, ['indexes' => [$index]]);
        }
        $cases[] = array_replace($create, ['table' => 'bad.table']);
        $cases[] = ['action' => 'rename-table', 'table' => 'products', 'to' => []];
        $cases[] = ['action' => 'add-column', 'table' => 'products', 'name' => 'required', 'column' => ['type' => 'integer']];
        foreach (['change-type', 'change-null', 'change-default', 'add-foreign-key', 'change-primary'] as $action) {
            $cases[] = ['action' => $action, 'table' => 'products'];
        }
        foreach ($cases as $operation) {
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                $this->rejected(fn () => (new SchemaSql())->generate([$operation], $driver), 'TYPE_SCHEMA_');
            }
        }
    }

    /** 构造无业务执行语句的独立声明，类名按场景隔离。 */
    private function source(string $class): string
    {
        return '<?php declare(strict_types=1); namespace SchemaFixture; '
            . "#[\\Type\\Orm\\Attribute\\Schema(version: '202610060001', description: '创建产品', operations: "
            . "[['action' => 'create', 'table' => 'products', 'columns' => ['id' => ['type' => 'integer', 'auto' => true], 'name' => ['type' => 'string', 'length' => 80]], 'primary' => ['id'], 'indexes' => [['name' => 'products_name_unique', 'columns' => ['name'], 'unique' => true]]]], snapshot: 'create.json')] final class " . $class . ' {}';
    }

    /** 验证公开诊断来自明确拒绝，而非 PHP 类型错误。 */
    private function rejected(callable $operation, string $message = ''): void
    {
        try {
            $operation();
            self::fail('非法 Schema 未拒绝');
        } catch (RuntimeException $exception) {
            if ($message !== '') {
                self::assertStringContainsString($message, $exception->getMessage());
            } else {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }
}
