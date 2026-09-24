<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Closure;
use RuntimeException;
use Type\Orm\AfterCommitException;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\DatabaseException;
use Type\Orm\Db;
use Type\Orm\Driver;
use Type\Orm\Model;
use Type\Orm\ModelBehavior;
use Type\Orm\ModelDefinition;
use Type\Orm\ModelException;
use Type\Orm\ModelField;
use Type\Orm\ModelQuery;
use Type\Orm\ReadWriteSession;
use Type\Orm\Relation;
use Type\Orm\TransactionException;
use Type\Orm\TransactionOutcome;
use Type\Runtime\ExecutionScope;

/** 三库与独立 AOT 消费者共用的集合写入、读取组合和提交后事务回归。 */
final class MutationExercise
{
    /** 在专属三库表验证完整集合写入、租户与软删除约束、算术回滚和关系加载身份。 */
    public static function run(ExecutionScope $scope): void
    {
        $connection = Db::connection('default', true);
        $date = match ($connection->driverName()) {
            'mysql' => 'DATETIME(6)', 'pgsql' => 'TIMESTAMP(6)', default => 'TEXT'
        };
        $connection->execute('CREATE TABLE type_suite_mutations (id INTEGER PRIMARY KEY, tenant_id VARCHAR(50) NOT NULL, '
            . 'title VARCHAR(100) NOT NULL, value INTEGER NOT NULL, deleted_at ' . $date . ' NULL, version BIGINT NOT NULL, '
            . 'CHECK (id <> 10001 OR value < 2))' . ($connection->driverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
        $seed = [];
        for ($id = 1; $id <= 10002; $id++) {
            $seed[] = ['id' => $id, 'tenant_id' => $id === 10002 ? 'tenant-b' : 'tenant-a', 'title' => 'original',
                'value' => 0, 'deleted_at' => null, 'version' => 1];
        }
        foreach (array_chunk($seed, 500) as $chunk) {
            $connection->table('type_suite_mutations')->insertMany($chunk);
        }
        $scope->run(static function (ExecutionScope $current) use ($connection): void {
            self::unversionedArithmetic($connection);
            $base = MutationRecord::query();
            self::storageEdges($connection, $base);
            $offset = $base->orderBy('id')->limit(3, 1);
            self::check($offset->get()[0]->id === 2 && $offset->first()->id === 2 && $offset->firstOrFail()->id === 2
                && $base->find(2)->id === 2 && $base->limit(1, 1)->find(2) === null, 'first 或主键查询丢失偏移');
            $alias = MutationRecord::query('r')->orderBy('value')->select(['title']);
            self::check(
                $alias->paginate(2, 1)->items()[0]->id === 2 && $alias->simplePaginate(2, 1)->items()[0]->id === 2,
                '别名单表无法组合普通分页'
            );
            $cursor = $alias->cursorPaginate(1);
            self::check(
                $cursor->items()[0]->id === 1 && $alias->cursorPaginate(1, $cursor->next())->items()[0]->id === 2,
                '别名游标没有稳定推进'
            );
            self::check(
                self::reject(static fn (): int => MutationRecord::query('r')->where('id', '=', 1)->update(['value' => 1])),
                '分页放行别名后错误放行集合写入'
            );

            $valid = $base->find(1);
            $archive = ArchiveMutationRecord::query()->find(1);
            $loader = $base->with('labels', Relation::hasMany(static fn (Connection $db): ModelQuery => Tag::query()->onConnection($db), 'id'));
            self::check(self::reject(static fn (): array => $loader->load([$valid, $archive]), 'invalid_model_list')
                && !$valid->relationLoaded('labels')
                && self::reject(static fn (): array => $loader->loadMissing([$archive]), 'invalid_model_list'), '关系补加载接受错误身份或部分修改模型');
            $firstDefinition = new ModelDefinition('type_suite_mutations', 'id', ['id' => new ModelField('id', 'integer'), 'title' => new ModelField('title')]);
            $secondDefinition = new ModelDefinition('type_suite_mutations', 'id', ['id' => new ModelField('id', 'integer'), 'title' => new ModelField('tenant_id')]);
            $manual = new ModelQuery($firstDefinition, static fn (array $row): MappingProbe => new MappingProbe($firstDefinition, $row));
            self::check(
                self::reject(static fn (): array => $manual->load([new MappingProbe($secondDefinition, ['id' => 1, 'title' => 'tenant-a'])]), 'invalid_model_list'),
                '同表同字段名的不同映射被接受'
            );
            self::check($loader->load([$valid])[0]->relationLoaded('labels'), '重新构造的相同映射不能补加载');

            self::check(self::reject(static fn (): int => $base->update(['title' => 'unscoped']))
                && self::reject(static fn (): int => $base->delete()), '默认范围冒充写入条件');
            $target = $base->where('id', '<=', 2);
            foreach (['id' => 1, 'tenant_id' => 'tenant-b', 'version' => 9, 'deleted_at' => null] as $field => $value) {
                self::check(self::reject(static fn (): int => $target->update([$field => $value]), 'field_not_fillable'), '受保护字段被集合覆盖');
            }
            self::check(self::reject(static fn (): int => $target->update(['unknown' => true]), 'unknown_field')
                && self::reject(static fn (): int => $target->update(['value' => '1']), 'invalid_field_type')
                && self::reject(static fn (): int => $target->update([]), 'empty_update')
                && self::reject(static fn (): int => $target->select(['title'])->delete(), 'invalid_write_query'), '集合字段或查询形态未拒绝');
            $observer = new ArticleObserver();
            $behavior = (new ModelBehavior())->observe($observer)->setter('title', static fn (mixed $value): mixed => trim((string) $value));
            $stale = $base->find(1);
            self::check($target->withBehavior($behavior)->update(['title' => ' changed ']) === 2 && $observer->events() === []
                && $base->find(1)->title === 'changed' && $base->find(1)->version === 2, '集合写入的修改器、事件或版本错误');
            $stale->title = 'stale';
            self::check(self::reject(static fn (): string => $stale->save(), 'optimistic_conflict'), '批量更新后旧对象覆盖新版本');
            self::check(self::reject(static fn (): int => $base->allowAll()->update(['value' => 2])), '末行约束失败未拒绝');
            self::check($base->where('value', '!=', 0)->count() === 0 && $base->find(1)->version === 2, '末行失败未回滚整条写入');
            $connection->table('type_suite_mutations')->where('id', '=', 10001)->update(['version' => PHP_INT_MAX]);
            self::check(self::reject(static fn (): int => $base->allowAll()->update(['title' => 'overflow']))
                && self::reject(static fn (): int => $base->allowAll()->increment('value'))
                && self::reject(static fn (): int => $base->allowAll()->decrement('value'))
                && self::reject(static fn (): int => $base->allowAll()->delete()), '版本耗尽未阻止整批变更');
            self::check(
                $base->find(1)->title === 'changed' && $base->find(1)->value === 0 && $base->find(10001)->version === PHP_INT_MAX,
                '版本溢出污染了字段或整批只成功部分'
            );
            $connection->table('type_suite_mutations')->where('id', '=', 10001)->update(['version' => 1]);
            self::check($base->allowAll()->increment('value') === 10001 && $base->allowAll()->decrement('value') === 10001, '集合写入被隐式行数上限截断');
            self::check(self::reject(static fn (): mixed => Db::transaction(static function () use ($target): void {
                $target->update(['title' => 'rolled-back']);
                throw new RuntimeException('rollback');
            })) && $base->find(1)->title === 'changed', '嵌套集合更新逃逸外层回滚');
            self::check($target->delete() === 2 && $target->count() === 0 && $target->onlyTrashed()->count() === 2
                && $target->withTrashed()->delete() === 0 && $target->onlyTrashed()->delete() === 0
                && $target->withTrashed()->find(1)->version === 5, '集合软删除重复推进版本或违反可见范围');
            self::check($target->update(['title' => 'hidden']) === 0, '默认集合更新修改了软删除行');
            $plain = Tag::create(['label' => 'batch-delete']);
            self::check(Tag::query()->where('id', '=', $plain->id)->delete() === 1 && Tag::find($plain->id) === null, '非软删除模型没有物理删除');
            $current->run(static function (ExecutionScope $other): void {
                $untouched = MutationRecord::query()->firstOrFail();
                self::check($untouched->id === 10002 && $untouched->version === 1 && $untouched->value === 0, '集合写入越过租户范围');
            }, ['tenant_id' => 'tenant-b']);
        }, ['tenant_id' => 'tenant-a']);
    }

    /** 无版本模型的整条算术写入覆盖约束、触发器、保存点和万行成功路径。 */
    private static function unversionedArithmetic(Connection $connection): void
    {
        $connection->execute('CREATE TABLE type_suite_counters (id INTEGER PRIMARY KEY, tenant_id VARCHAR(50) NOT NULL, '
            . 'value INTEGER NOT NULL, CHECK (id <> 10001 OR value BETWEEN -1 AND 1))'
            . ($connection->driverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
        $seed = [];
        for ($id = 1; $id <= 10002; $id++) {
            $seed[] = ['id' => $id, 'tenant_id' => $id === 10002 ? 'tenant-b' : 'tenant-a', 'value' => 0];
        }
        foreach (array_chunk($seed, 500) as $chunk) {
            $connection->table('type_suite_counters')->insertMany($chunk);
        }
        $base = CounterRecord::query();
        if ($connection->driverName() === 'sqlite') {
            $connection->execute("CREATE TRIGGER type_counter_fail BEFORE UPDATE ON type_suite_counters WHEN OLD.id = 10001 BEGIN SELECT RAISE(FAIL, 'later row'); END");
            try {
                self::check(self::reject(static fn (): int => $base->allowAll()->increment('value'))
                    && $base->where('value', '!=', 0)->count() === 0, '无版本增量在 SQLite 触发器失败后部分提交');
                self::check(self::reject(static fn (): int => $base->allowAll()->decrement('value'))
                    && $base->where('value', '!=', 0)->count() === 0, '无版本减量在 SQLite 触发器失败后部分提交');
            } finally {
                $connection->execute('DROP TRIGGER type_counter_fail');
            }
        }
        self::check(self::reject(static fn (): int => $base->allowAll()->increment('value', 2))
            && self::reject(static fn (): int => $base->allowAll()->decrement('value', 2))
            && $base->where('value', '!=', 0)->count() === 0, '无版本算术约束失败没有完整回滚');
        self::check(self::reject(static fn (): mixed => Db::transaction(static function () use ($base, $connection): void {
            self::check(
                self::reject(static fn (): int => $base->allowAll()->increment('value', 2))
                && $connection->transactionDepth() === 1 && $base->where('value', '!=', 0)->count() === 0,
                '无版本算术失败破坏外层事务或留下部分写入'
            );
            self::check($base->where('id', '=', 1)->increment('value') === 1, '保存点回滚后不能继续外层事务');
            throw new RuntimeException('rollback');
        })) && $base->where('value', '!=', 0)->count() === 0, '无版本算术逃逸外层回滚');
        self::check(
            $base->allowAll()->increment('value') === 10001 && $base->allowAll()->decrement('value') === 10001
            && $base->where('value', '!=', 0)->count() === 0
            && (int) $connection->table('type_suite_counters')->where('id', '=', 10002)->first()['value'] === 0,
            '无版本算术被截断、计算错误或越过租户范围'
        );
    }

    /** 用真实数据库配置及触发器验证整条写入失败，避免约束被静默跳过。 */
    private static function storageEdges(Connection $connection, ModelQuery $base): void
    {
        if ($connection->driverName() === 'mysql') {
            $mode = (string) $connection->query('SELECT @@SESSION.sql_mode AS modes')[0]['modes'];
            $connection->execute("SET SESSION sql_mode = ''");
            try {
                self::check(self::reject(static fn (): int => $base->allowAll()->update(['title' => 'unsafe']), 'unsafe_batch_storage'), '非严格 MySQL 会话允许集合写入');
            } finally {
                $connection->execute('SET SESSION sql_mode = ?', [$mode]);
            }
            $connection->execute('CREATE TEMPORARY TABLE type_suite_mutations (id INTEGER PRIMARY KEY, tenant_id VARCHAR(50) NOT NULL, '
                . 'title VARCHAR(100) NOT NULL, value INTEGER NOT NULL, deleted_at DATETIME(6) NULL, version BIGINT NOT NULL) ENGINE=MyISAM');
            try {
                $connection->table('type_suite_mutations')->insert(['id' => 1, 'tenant_id' => 'tenant-a', 'title' => 'temporary', 'value' => 0, 'version' => 1]);
                self::check(self::reject(static fn (): int => $base->allowAll()->update(['title' => 'unsafe']), 'unsafe_batch_storage')
                    && $connection->table('type_suite_mutations')->first()['title'] === 'temporary', '临时非事务表遮蔽绕过集合写入校验');
            } finally {
                $connection->execute('DROP TEMPORARY TABLE type_suite_mutations');
            }
        }
        if ($connection->driverName() === 'sqlite') {
            foreach ([0, 1.5, PHP_INT_MAX] as $invalid) {
                $connection->table('type_suite_mutations')->where('id', '=', 10001)->update(['version' => $invalid]);
                $connection->execute('CREATE TRIGGER type_suite_skip BEFORE UPDATE ON type_suite_mutations WHEN OLD.id = 10001 BEGIN SELECT RAISE(IGNORE); END');
                try {
                    self::check(
                        self::reject(static fn (): int => $base->allowAll()->update(['title' => 'unsafe']))
                        && $connection->table('type_suite_mutations')->where('title', '!=', 'original')->first() === null,
                        'SQLite 非法版本被触发器跳过或留下部分更新'
                    );
                } finally {
                    $connection->execute('DROP TRIGGER type_suite_skip');
                }
            }
            $connection->table('type_suite_mutations')->where('id', '=', 10001)->update(['version' => 1]);
        }
        self::check($base->where('title', '!=', 'original')->count() === 0, '存储拒绝路径修改了业务记录');
    }

    /** 外层提交事实与提交后新事务结果分别保留，未知结果不允许自动重试。 */
    public static function callbacks(Driver $driver): array
    {
        $manager = new DatabaseManager(['default' => $driver], 2, 0);
        Db::configure($manager);
        $scope = new ExecutionScope();
        try {
            return $scope->run(static function (ExecutionScope $current) use ($driver, $manager): array {
                $connection = Db::connection('default', true);
                $events = new ArticleObserver();
                try {
                    Db::transaction(static function () use ($events): void {
                        Db::afterCommit(static function (): void {
                            Db::transaction(static function (): void {
                                Db::afterCommit(static function (): void {
                                    Db::transaction(static function (): void {
                                        throw new RuntimeException('inner-rollback');
                                    });
                                });
                            });
                        });
                        Db::afterCommit(static function () use ($events): void {
                            // 仅记录回调到达，不访问模型持久化或外部系统。
                            $events->onEvent('continued', new MappingProbe(new ModelDefinition('probe', 'id', ['id' => new ModelField('id', 'integer')]), ['id' => 1]));
                        });
                    });
                    throw new RuntimeException('没有报告提交后失败');
                } catch (AfterCommitException $error) {
                    self::check($error->outcome() === TransactionOutcome::COMMITTED && $connection->transactionOutcome() === TransactionOutcome::ROLLED_BACK
                        && $events->events() === ['continued'], '外层提交覆写了回调事务结果或后续回调丢失');
                }
                if ($driver->name() === 'mysql') {
                    return ['recursive_rollback' => true, 'deferred_commit_unknown' => 'unsupported'];
                }
                $connection->execute('CREATE TABLE type_callback_parent (id INTEGER PRIMARY KEY)');
                $connection->execute('CREATE TABLE type_callback_child (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES type_callback_parent(id) DEFERRABLE INITIALLY DEFERRED)');
                try {
                    Db::transaction(static function (): void {
                        Db::afterCommit(static function (): void {
                            Db::transaction(static function (): void {
                                Db::connection('default', true)->table('type_callback_child')->insert(['id' => 1, 'parent_id' => -1]);
                            });
                        });
                    });
                    throw new RuntimeException('延迟外键没有导致提交失败');
                } catch (AfterCommitException $error) {
                    self::check($error->outcome() === TransactionOutcome::COMMITTED && $error->errors()[0] instanceof TransactionException
                        && $error->errors()[0]->outcome() === TransactionOutcome::UNKNOWN
                        && $connection->transactionOutcome() === TransactionOutcome::UNKNOWN, '提交后未知事实被覆写');
                }
                try {
                    Db::connection();
                    throw new RuntimeException('未知提交后仍允许普通读取');
                } catch (TransactionException $error) {
                    self::check($error->outcome() === TransactionOutcome::UNKNOWN, '未知状态没有保留');
                }
                $connection->close();
                $session = new ReadWriteSession($manager, $current, 'default', null);
                try {
                    $session->write()->transaction(static function (Connection $transaction): void {
                        $transaction->table('type_callback_child')->insert(['id' => 2, 'parent_id' => -1]);
                    });
                    throw new RuntimeException('底层提交未失败');
                } catch (TransactionException $error) {
                    self::check($error->outcome() === TransactionOutcome::UNKNOWN, '底层未知提交错误');
                }
                self::check($session->reconcile()->table('type_callback_child')->get() === []
                    && $session->outcome() === TransactionOutcome::UNKNOWN, '更换对账连接丢失原未知事实');
                return ['recursive_rollback' => true, 'deferred_commit_unknown' => true, 'reconcile_preserves_unknown' => true];
            });
        } finally {
            $scope->close();
            $manager->close();
        }
    }

    private static function reject(Closure $operation, string $code = ''): bool
    {
        try {
            $operation();
        } catch (ModelException $error) {
            return $code === '' || $error->errorCode() === $code;
        } catch (DatabaseException) {
            return $code === '';
        } catch (RuntimeException $error) {
            return $code === '' && $error->getMessage() === 'rollback';
        }
        return false;
    }

    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

/** 手写映射回归，证明相同属性名不能代替完整映射身份。 */
final class MappingProbe extends Model
{
    /**
     * 以指定映射水合探针模型，验证同表同字段名也不能混用不同映射。
     *
     * @param array<string, mixed> $row 已读取数据库字段。
     */
    public function __construct(ModelDefinition $definition, array $row)
    {
        parent::__construct($definition, $row, true);
    }
}
