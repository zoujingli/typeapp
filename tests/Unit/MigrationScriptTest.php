<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\MigrationCompiler;
use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * @internal
 */
final class MigrationScriptTest extends TestCase
{
    public function testCompilerFreezesMigrationRegistryWithRollbackSql(): void
    {
        $root = dirname(__DIR__, 2);
        $compiled = (new MigrationCompiler())->compile($root, 'database/migrations');
        self::assertNotEmpty($compiled['files']);
        self::assertContains('020_platform_system', $compiled['versions']);
        self::assertStringContainsString('final class Migrations', $compiled['code']);
        self::assertStringContainsString('DROP TABLE IF EXISTS', $compiled['code']);
        self::assertStringNotContainsString('MigrationFiles::load', $compiled['code']);
        self::assertStringNotContainsString('include ', $compiled['code']);
    }

    public function testSchemaMigrationCompilesCreateAndDrop(): void
    {
        $script = new class extends SchemaMigration {
            public function version(): string { return '202611140001'; }
            public function description(): string { return '创建示例表'; }
            public function up(Schema $schema): void
            {
                $schema->create('demo_item', function (Blueprint $table): void {
                    $table->increments('id');
                    $table->string('label', 100)->comment('标签');
                    $table->comment('示例');
                });
            }
            public function down(Schema $schema): void
            {
                $schema->dropIfExists('demo_item');
            }
        };
        $migration = $script->compile('mysql');
        self::assertSame('202611140001', $migration->version());
        self::assertTrue($migration->reversible());
        self::assertStringContainsString('CREATE TABLE', $migration->statements()[0]);
        self::assertStringContainsString('COMMENT', $migration->statements()[0]);
        self::assertSame(['DROP TABLE IF EXISTS `demo_item`'], $migration->downStatements());
        $sqlite = $script->compile('sqlite');
        self::assertStringContainsString('AUTOINCREMENT', $sqlite->statements()[0]);
        self::assertStringNotContainsString('ENGINE=InnoDB', $sqlite->statements()[0]);
    }

    public function testGeneratedBlueprintContainsTableAndSeed(): void
    {
        $source = \Type\Orm\Migration\MigrationGenerator::blueprintSource('202611150001', '当前结构', [[
            'name' => 'platform_menu',
            'comment' => '平台菜单',
            'columns' => [[
                'name' => 'id', 'type' => 'string', 'length' => 32, 'bits' => 32, 'nullable' => false,
                'default' => null, 'comment' => '标识', 'auto' => false,
            ]],
            'primary' => ['id'],
            'indexes' => [],
            'foreign' => [],
        ]], ["INSERT INTO platform_menu (id, name) VALUES ('abc', '工作台')"]);
        self::assertStringContainsString('return new class extends SchemaMigration', $source);
        self::assertStringNotContainsString('function version()', $source);
        self::assertStringContainsString("hasTable('platform_menu')", $source);
        self::assertStringContainsString('->string(', $source);
        self::assertStringContainsString('->comment(', $source);
        self::assertStringContainsString('dropIfExists', $source);
        self::assertStringContainsString("insert('platform_menu'", $source);
    }

    public function testInsertQuotesIdentifiers(): void
    {
        $script = new class extends SchemaMigration {
            public function version(): string { return '202611150002'; }
            public function description(): string { return '写入初始行'; }
            public function up(Schema $schema): void
            {
                $schema->insert('platform_menu', [['id' => 'abc', 'name' => "工作台"]]);
            }
        };
        self::assertSame(
            ["INSERT INTO `platform_menu` (`id`, `name`) VALUES ('abc', '工作台')"],
            $script->compile('mysql')->statements()
        );
    }

    public function testGeneratedBlueprintDropsForeignKeyChildrenBeforeParents(): void
    {
        $source = \Type\Orm\Migration\MigrationGenerator::blueprintSource('202611150003', '按依赖回滚', [
            ['name' => 'child', 'comment' => '', 'columns' => [], 'primary' => [], 'indexes' => [],
                'foreign' => [['columns' => ['parent_id'], 'on' => 'parent', 'references' => ['id']]], 'checks' => []],
            ['name' => 'parent', 'comment' => '', 'columns' => [], 'primary' => [], 'indexes' => [], 'foreign' => [], 'checks' => []],
        ]);
        self::assertLessThan(
            strpos($source, "dropIfExists('parent')"),
            strpos($source, "dropIfExists('child')")
        );
    }
}
