<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\MigrationModelGenerator;
use Type\Build\ModelCompiler;
use Type\Orm\Migration\MigrationFiles;
use Type\Orm\Schema\ModelStructure;
use Type\Orm\Schema\Schema;

/**
 * 迁移 Blueprint 生成的模型必须带字段注释，并能被模型编译器接受。
 * @internal
 * @coversNothing
 */
final class MigrationModelGeneratorTest extends TestCase
{
    public function testMqttModelLocationsUseSystemNamespaceAndClassName(): void
    {
        $root = dirname(__DIR__, 2);
        $location = MigrationModelGenerator::locate($root, 'system_mqtt_operation');
        self::assertSame('app\\system\\model\\MqttOperation', $location['class']);
        self::assertSame($root . '/app/system/model/MqttOperation.php', $location['path']);

        foreach ([
            'platform_mqtt_access_principal' => 'app\\platform\\mqtt\\model\\AccessPrincipal',
            'platform_user' => 'app\\platform\\model\\User',
            'system_user' => 'app\\system\\model\\User',
            'center_device' => 'app\\center\\model\\Device',
        ] as $table => $class) {
            self::assertSame($class, MigrationModelGenerator::locate($root, $table)['class']);
        }
    }

    public function testMigrationBlueprintsRenderCommentedModels(): void
    {
        $schema = new Schema('mysql', null, true);
        foreach (MigrationFiles::load(dirname(__DIR__, 2) . '/database/migrations') as $migration) {
            $migration->up($schema);
        }
        $blueprints = $schema->blueprints();
        $certificate = $blueprints['platform_mqtt_access_certificate'];
        $names = array_map(static fn ($column): string => $column->name, $certificate->columns);
        self::assertContains('overlap_until', $names);
        self::assertContains('serial', $names);
        self::assertContains('recovery_verified', $names);

        $installation = ModelStructure::fromBlueprint($blueprints['platform_installation']);
        $comments = array_column($installation['columns'], 'comment', 'name');
        self::assertSame('安装互斥槽位，固定为 1', $comments['id']);

        $root = dirname(__DIR__, 2);
        $generator = new MigrationModelGenerator();
        $rendered = $generator->render($root, [$installation], ['platform_installation' => true]);
        self::assertSame([], $rendered['files']);

        $audit = ModelStructure::fromBlueprint($blueprints['platform_audit']);
        $previewRoot = $root . '/build/model-preview-root-' . bin2hex(random_bytes(3));
        self::assertTrue(mkdir($previewRoot . '/app/platform/model', 0700, true));
        try {
            $preview = $generator->render($previewRoot, [$audit], []);
        } finally {
            if (is_dir($previewRoot . '/app/platform/model')) {
                rmdir($previewRoot . '/app/platform/model');
                rmdir($previewRoot . '/app/platform');
                rmdir($previewRoot . '/app');
                rmdir($previewRoot);
            }
        }
        self::assertSame('app/platform/model/Audit.php', substr($preview['files'][0]['path'], -strlen('app/platform/model/Audit.php')));
        self::assertStringContainsString('@property string $action', $preview['files'][0]['code']);
        self::assertStringContainsString('where(string|array $field', $preview['files'][0]['code']);
        self::assertArrayHasKey('platform_role_permission', $generator->render($root, [ModelStructure::fromBlueprint($blueprints['platform_role_permission'])], [])['skipped']);

        $directory = $root . '/build/model-from-schema-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($directory));
        try {
            $path = $directory . '/Audit.php';
            file_put_contents($path, $preview['files'][0]['code']);
            $compiled = (new ModelCompiler())->compile([$path]);
            self::assertSame('platform_audit', $compiled['models'][0]['table']);
            self::assertSame('string', $compiled['models'][0]['fields']['action']['type']);
        } finally {
            if (is_file($directory . '/Audit.php')) {
                unlink($directory . '/Audit.php');
            }
            rmdir($directory);
        }
    }
}
