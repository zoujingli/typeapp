<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Type\Build\ModelCompiler;

/** 静态声明错误在构建前拒绝；业务行为通过三库独立消费者另行验收。 */
final class PhpModelCompilerTest extends TestCase
{
    public function testInvalidDeclarationsAreRejectedWithoutLoadingBusinessCode(): void
    {
        $prefix = '<?php declare(strict_types=1); namespace ModelInvalid; use Type\\Orm\\Model; use Type\\Orm\\Attribute\\{Table, Column, HasMany}; ';
        $invalid = [
            "#[Table('users')] class User extends Model { public int \$id; public string \$name; #[Column(name: 'name')] public string \$duplicate; }",
            "#[Table('users')] class User extends Model { public int \$id; public string \$id; }",
            "#[Table('users')] class User extends Model { public int \$id; public function mapping(): void {} }",
            "#[Table('users')] class User extends Model { public int \$id; public function __construct() {} }",
            "#[Table('users')] class User extends Model { public int \$id; public function getId(): int { return 1; } }",
            "#[Table('users')] class User extends Model { public int \$id = 1; }",
            "#[Table('users')] class User extends Model { public int \$id; public string|int \$name; }",
            "#[Table('users')] class User extends Model { public int \$id; #[Column(type: 'decimal')] public float \$money; }",
            "#[Table('users')] class User extends Model { public int \$id; #[Column] protected string \$name; }",
            "#[Table('users')] class User extends Model { public int \$id; #[Column] public static string \$name; }",
            "#[Table('users')] class User extends Model { public int \$id; public string \$name { get => 'bypass'; } }",
            "#[Table('users')] abstract class User extends Model { public int \$id; }",
            "#[Table('users')] class User extends Model { public int \$id; } class Child extends User {}",
            "class Base extends Model {} #[Table('users')] class User extends Base { public int \$id; }",
            "#[Table('users')] class User extends Model { public int \$id; #[HasMany(Missing::class, 'user_id')] public array \$posts; }",
            "#[Table('users'), Table('other')] class User extends Model { public int \$id; }",
            "#[Table(getenv('UNSAFE_MODEL_TABLE'))] class User extends Model { public int \$id; }",
            "#[Table('users', version: 'version')] class User extends Model { public int \$id; public ?int \$version; }",
            "#[Table('users')] class User extends Model { public int \$id; } #[Table('other')] class User extends Model { public int \$id; }",
            "#[Table('users')] class User extends Model { public int \$id; private string \$id; }",
            "#[Table('users')] class User extends Model { public int \$id; public function set(string \$name, mixed \$value): void {} }",
            "#[Table('users')] class User extends Model { public int \$id; public function path(): string { return __DIR__; } }",
            "#[Table('users')] class User extends Model { public int \$id; public function path(): string { return __FILE__; } }",
            "#[Table('users')] class User extends Model { #[Table('bad')] public int \$id; }",
            "#[Table('users')] class User extends Model { #[\\Type\\Orm\\Attribute\\Colum] public int \$id; }",
            'class User extends Model { #[Column] public int $id; }',
        ];
        $directory = dirname(__DIR__, 2) . '/build/model-compiler-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $file = $directory . '/Model.php';
        try {
            foreach ($invalid as $source) {
                file_put_contents($file, $prefix . $source);
                $rejected = false;
                try {
                    (new ModelCompiler())->compile([$file]);
                } catch (RuntimeException) {
                    $rejected = true;
                }
                self::assertTrue($rejected, $source);
                self::assertFalse(class_exists('ModelInvalid\\User', false));
            }
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function testSameClassAndSourceChangesDetermineGeneratedIdentity(): void
    {
        $directory = dirname(__DIR__, 2) . '/build/model-identity-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $file = $directory . '/Model.php';
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace ModelIdentity;
use Type\Orm\Model;
use Type\Orm\Attribute\Table;
#[Table('users')]
final class User extends Model {
    public int $id;
    public string $name;
    public function presentName(): string { return 'before:' . $this->name; }
}
final class Companion { public const PRESENT = true; }
PHP;
        try {
            file_put_contents($file, $source);
            $compiler = new ModelCompiler();
            $first = $compiler->compile([$file]);
            self::assertSame($first, $compiler->compile([$file]));
            self::assertSame([str_replace('\\', '/', realpath($file))], $first['originals']);
            self::assertSame('ModelIdentity\\User', $first['models'][0]['class']);
            self::assertStringContainsString('class Companion', $first['code']);
            self::assertStringContainsString('presentName', $first['code']);
            file_put_contents($file, str_replace('before:', 'after:', $source));
            self::assertNotSame(hash('sha256', $first['code']), hash('sha256', $compiler->compile([$file])['code']));
            self::assertFalse(class_exists('ModelIdentity\\User', false));
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function testLegacyConfigurationAlwaysReportsMigration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('models 配置已移除');
        (new ModelCompiler())->assertConfiguration(['models' => []]);
    }

    public function testInstalledDependencyModelsUseAdaptationsAndInvalidateDevelopmentCache(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/model-dependency-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory . '/vendor/composer', 0700, true));
        self::assertTrue(mkdir($directory . '/vendor/example/models/src', 0700, true));
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace DependencyModels;
use Type\Orm\Model;
use Type\Orm\Attribute\Table;
#[Table('users')]
final class User extends Model {
    public int $id;
    public static function marker(): string { return 'original'; }
}
PHP;
        try {
            file_put_contents($directory . '/vendor/autoload.php', '<?php');
            file_put_contents($directory . '/composer.json', json_encode(['name' => 'example/app', 'require' => ['example/models' => '*'],
                'require-dev' => ['example/not-installed' => '*']], JSON_THROW_ON_ERROR));
            file_put_contents($directory . '/composer.lock', '{}');
            file_put_contents($directory . '/application.json', '{}');
            file_put_contents($directory . '/vendor/example/models/src/User.php', $source);
            $metadata = ['packages' => [['name' => 'example/models', 'version' => '1.0.0', 'install-path' => '../example/models',
                'autoload' => ['psr-4' => ['DependencyModels\\' => 'src']], 'extra' => ['type' => ['protocol' => 1, 'sources' => ['src'],
                    'rewrites' => [['source' => 'src/User.php', 'sha256' => hash('sha256', $source), 'reason' => '验证模型转换保持同一适配行为',
                        'replacements' => [['from' => "return 'original';", 'to' => "return 'adapted';", 'count' => 1]]]]]]]]];
            $builder = new \Type\Build\DevelopmentBuilder();
            $generation = '';
            foreach (['adapted', 'changed'] as $marker) {
                $metadata['packages'][0]['extra']['type']['rewrites'][0]['replacements'][0]['to'] = "return '{$marker}';";
                file_put_contents($directory . '/vendor/composer/installed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
                $prepared = $builder->prepare($directory, 'application.json', 'build/development');
                self::assertNotSame($generation, $prepared['generation']);
                self::assertSame($prepared, $builder->prepare($directory, 'application.json', 'build/development'));
                $generation = $prepared['generation'];
                $program = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require '
                    . var_export($prepared['directory'] . '/models.php', true) . '; echo DependencyModels\\User::marker();';
                $process = new \Type\Testing\Process([PHP_BINARY, '-r', $program], $directory, getenv());
                try {
                    $result = $process->wait(10);
                    self::assertTrue($result->successful(), $result->stderr);
                    self::assertSame($marker, $result->stdout);
                } finally {
                    $process->stop();
                }
            }
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
