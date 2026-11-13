<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\ModelCompiler;
use Type\Build\ModelSchemaSynchronizer;

/**
 * 保护反向更新的源码保全与声明失败契约；MySQL 结构读取由真实数据库入口验证。
 * @internal
 * @coversNothing
 */
final class ModelSchemaSynchronizerTest extends TestCase
{
    public function testRepeatedSynchronizationPreservesBusinessMethodsAndAliases(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace SyncModels;
use Type\Orm\Model;
/** 读数业务模型。 */
final class Reading extends Model
{
    protected string $table = 'readings';
    protected array $hidden = ['amount'];
    protected array $schema = ['id' => 'int', 'amount' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'column' => 'value']];
    // 业务方法与格式由维护者持有。
    public function label(): string { return 'value=' . $this->amount; }
}
PHP;
        $structure = ['table' => 'readings', 'comment' => '设备读数', 'columns' => [
            $this->column('id', 'int', ['key_type' => 'PRI', 'extra' => 'auto_increment']),
            $this->column('value', 'decimal', ['precision_value' => 18, 'scale_value' => 6, 'comment' => '精确读数']),
            $this->column('memo', 'varchar', ['nullable' => 'YES', 'comment' => '备注 */' . "\n" . '不可执行']),
        ]];
        $sync = new ModelSchemaSynchronizer();
        $result = $sync->synchronize('SyncModels\Reading', $structure, $source);
        self::assertSame(['memo'], $result['added']);
        self::assertSame([], $result['removed']);
        self::assertStringContainsString("public function label(): string { return 'value=' . \$this->amount; }", $result['code']);
        self::assertStringContainsString("protected array \$hidden = ['amount'];", $result['code']);
        self::assertStringContainsString('@property string $amount 精确读数', $result['code']);
        self::assertStringContainsString('@property string|null $memo 备注 * / 不可执行', $result['code']);
        self::assertSame($result['code'], $sync->synchronize('SyncModels\Reading', $structure, $result['code'])['code']);
        $directory = dirname(__DIR__, 2) . '/build/model-sync-unit-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        try {
            file_put_contents($directory . '/Reading.php', $result['code']);
            $compiled = (new ModelCompiler())->compile([$directory . '/Reading.php']);
            self::assertSame('value', $compiled['models'][0]['fields']['amount']['column']);
            self::assertSame(6, $compiled['models'][0]['fields']['amount']['scale']);
        } finally {
            unlink($directory . '/Reading.php');
            rmdir($directory);
        }
    }

    public function testUnsupportedTypeAndMissingFieldRequireExplicitDecisions(): void
    {
        $sync = new ModelSchemaSynchronizer();
        $structure = ['table' => 'readings', 'comment' => '', 'columns' => [$this->column('id', 'int', ['key_type' => 'PRI'])]];
        $created = $sync->synchronize('SyncModels\Reading', $structure)['code'];
        foreach ([
            ['table' => 'readings', 'comment' => '', 'columns' => [$this->column('id', 'float', ['key_type' => 'PRI'])]],
            ['table' => 'readings', 'comment' => '', 'columns' => [$this->column('id', 'int')]],
        ] as $invalid) {
            try {
                $sync->synchronize('SyncModels\Reading', $invalid, $created);
                self::fail('不支持的结构未拒绝');
            } catch (\RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        $extended = $structure;
        $extended['columns'][] = $this->column('memo', 'varchar', ['nullable' => 'YES']);
        $source = $sync->synchronize('SyncModels\Reading', $extended)['code'];
        try {
            $sync->synchronize('SyncModels\Reading', $structure, $source);
            self::fail('字段删除没有要求显式确认');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('--drop-missing', $error->getMessage());
        }
        self::assertSame(['memo'], $sync->synchronize('SyncModels\Reading', $structure, $source, true)['removed']);
    }

    private function column(string $name, string $type, array $options = []): array
    {
        return $options + ['name' => $name, 'type' => $type, 'declaration' => $type, 'nullable' => 'NO', 'default_value' => null,
            'key_type' => '', 'extra' => '', 'comment' => '', 'precision_value' => null, 'scale_value' => null, 'expression' => ''];
    }
}
