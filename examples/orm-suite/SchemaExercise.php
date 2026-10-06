<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use RuntimeException;
use Type\Orm\Db;
use Type\Orm\Driver;
use Type\Orm\DatabaseManager;
use Type\Orm\Migration\Migrator;
use Type\Orm\Migration\MigrationException;
use Type\Runtime\ExecutionScope;

/** 冻结建表、真实字段语义和有数据演进，复用既有迁移记录、失败与恢复协议。 */
final class SchemaExercise
{
    /** 独立安装 PHP 与 AOT 消费完全相同的已冻结 Migration 工厂。 */
    public static function run(Driver $driver): array
    {
        $manager = new DatabaseManager(['default' => $driver], 2, 0);
        Db::configure($manager);
        $migrator = new Migrator($driver, 'type_suite_schema_migrations');
        $create = CatalogCreate::migration($driver->name());
        $checksum = $create->checksum();
        $plan = [$create];
        try {
            self::check($migrator->run($plan)[0]['state'] === 'applied', '冻结建表没有应用');
            $id = self::scope(static function (ExecutionScope $current) use ($driver): int {
                $model = CatalogRecord::create(['code' => 'catalog-1', 'title' => '目录首版', 'active' => true, 'age' => 7,
                    'money' => '12345678901234567890.12', 'recorded_at' => '2026-10-06T02:03:04.123456Z', 'notes' => "多行\n正文"]);
                $fresh = CatalogRecord::find($model->getId());
                self::check($fresh->getMoney() === '12345678901234567890.12' && $fresh->getRecordedAt()->format('u') === '123456'
                    && $fresh->getNotes() === "多行\n正文" && $fresh->getActive() && $fresh->getAge() === 7, '冻结字段存储改变精度或类型');
                $connection = Db::connection('default', true);
                $binary = $driver->name() === 'pgsql' ? "decode('0001ff5c', 'hex')" : "X'0001ff5c'";
                $connection->execute('UPDATE type_suite_catalog SET payload = ' . $binary . ' WHERE id = ?', [$model->getId()]);
                $hex = $driver->name() === 'pgsql' ? "encode(payload, 'hex')" : 'LOWER(HEX(payload))';
                self::check($connection->query('SELECT ' . $hex . ' AS bytes FROM type_suite_catalog WHERE id = ?', [$model->getId()])[0]['bytes'] === '0001ff5c', '二进制列丢失零字节或非UTF8字节');
                $connection->table('type_suite_catalog')->insert(['code' => 'database-defaults', 'title' => '数据库默认值', 'money' => '0.00', 'recorded_at' => '2026-10-06 00:00:00.000001']);
                $defaults = CatalogRecord::query()->where('code', '=', 'database-defaults')->firstOrFail();
                self::check($defaults->getActive() && $defaults->getAge() === 0 && $defaults->getNotes() === null, '实际数据库默认值与声明不符');
                $defaults->forceDelete();
                return $model->getId();
            });
            $plan[] = CatalogAlter::migration($driver->name());
            $states = $migrator->run($plan);
            self::check(array_column($states, 'state') === ['applied', 'applied'] && $states[0]['attempts'] === 1
                && $plan[0]->checksum() === $checksum && CatalogCreate::migration($driver->name())->checksum() === $checksum, '结构演进修改或重跑历史迁移');
            self::scope(static function (ExecutionScope $current) use ($id): void {
                $record = CatalogEntry::find($id);
                self::check($record->getHeading() === '目录首版' && $record->getStatus() === 'new' && $record->getMoney() === '12345678901234567890.12', '原生改名、增列或删列丢失已有业务数据');
                $record->setHeading('升级后修改');
                $record->save();
                self::check(CatalogEntry::find($id)->getHeading() === '升级后修改', '演进后 Model CRUD 不可用');
                $names = array_column(Db::connection('default', true)->columns('type_suite_catalog_entries'), 'name');
                self::check(!in_array('spare', $names, true) && !in_array('title', $names, true) && in_array('heading', $names, true), '结构演进没有执行真实删列及改名');
            });
            $plan[] = CatalogFailure::migration($driver->name());
            self::failed($migrator, $plan);
            $partial = self::scope(static fn (ExecutionScope $current): bool => in_array('retry_marker', array_column(Db::connection('default', true)->columns('type_suite_catalog_entries'), 'name'), true));
            self::check($partial === ($driver->name() === 'mysql'), '事务 DDL 或 MySQL 非事务部分生效被错误统一');
            self::scope(static function (ExecutionScope $current) use ($driver, $id): void {
                $connection = Db::connection('default', true);
                self::check(CatalogEntry::find($id)->getHeading() === '升级后修改', '迁移失败破坏原业务记录');
                if ($driver->name() === 'mysql') {
                    $connection->execute('ALTER TABLE type_suite_catalog_entries DROP COLUMN retry_marker');
                }
                $connection->execute('ALTER TABLE type_suite_catalog_entries ADD COLUMN missing_column INTEGER NULL');
            });
            $migrator->recover($plan, '202610060003', 'retry', '已核对部分生效列并恢复重试前置条件');
            $states = $migrator->run($plan);
            self::check($states[2]['state'] === 'applied' && $states[2]['attempts'] === 2 && $states[0]['attempts'] === 1, '显式恢复没有沿用旧迁移身份或重试计数');
            if ($driver->name() === 'sqlite') {
                $plan[] = CatalogDependency::migration('sqlite');
                self::failed($migrator, $plan);
                self::scope(static function (ExecutionScope $current) use ($id): void {
                    self::check(CatalogEntry::find($id)->getCode() === 'catalog-1'
                        && count(Db::connection('default', true)->uniqueIndexes('type_suite_catalog_entries')) >= 2, 'SQLite 依赖删列被重建或隐式移除索引');
                });
            }
            return ['create_checksum' => $checksum, 'alter_checksum' => $plan[1]->checksum(), 'failure_checksum' => $plan[2]->checksum(),
                'transactional_ddl' => $driver->name() !== 'mysql', 'partial_ddl' => $partial, 'recovery' => true,
                'sqlite_dependency_refused' => $driver->name() === 'sqlite'];
        } finally {
            $manager->close();
        }
    }

    private static function failed(Migrator $migrator, array $plan): void
    {
        $failed = false;
        try {
            $migrator->run($plan);
        } catch (MigrationException $error) {
            $failed = true;
        }
        $states = $migrator->status($plan);
        self::check($failed && $states[count($states) - 1]['state'] === 'failed', '真实迁移失败没有保存 failed 状态');
    }

    /** @param \Closure(ExecutionScope): mixed $operation */
    private static function scope(\Closure $operation): mixed
    {
        $scope = new ExecutionScope();
        try {
            return $scope->run($operation);
        } finally {
            $scope->close();
        }
    }

    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
