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
            self::insertAndAggregate($connection, $current);
            self::integerStorage($connection);
            self::integerArithmetic($connection);
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

    /**
     * 验证整数模型的实际上下界、NULL 与全批回滚；同一入口供三库 PHP 和全量 AOT 消费。
     *
     * @param Connection $connection 专属测试数据库的当前作用域写连接，不连接用户数据。
     */
    public static function integerArithmetic(Connection $connection): void
    {
        foreach ([false, true] as $versioned) {
            $table = $versioned ? 'type_suite_versioned_integer_bounds' : 'type_suite_integer_bounds';
            $connection->execute('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, stored_value BIGINT NULL, version BIGINT NOT NULL DEFAULT 1)'
                . ($connection->driverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
            $fields = ['id' => new ModelField('id', 'integer'), 'value' => new ModelField('stored_value', 'integer', true)];
            if ($versioned) {
                $fields['version'] = new ModelField('version', 'integer', false, false, true, false);
            }
            $definition = new ModelDefinition($table, 'id', $fields, false, null, $versioned ? 'version' : null);
            $query = new ModelQuery($definition, static fn (array $row): MappingProbe => new MappingProbe($definition, $row));
            $query->insertMany([['id' => 1, 'value' => 10], ['id' => 2, 'value' => PHP_INT_MAX], ['id' => 3, 'value' => null]]);
            $initial = $connection->table($table)->orderBy('id')->get();
            self::check(self::reject(static fn (): int => $query->allowAll()->increment('value'))
                && $connection->table($table)->orderBy('id')->get() === $initial
                && $query->findOrFail(2)->get('value') === PHP_INT_MAX, '整数上溢没有拒绝整批、回滚版本或保持可水合');

            $connection->table($table)->where('id', '=', 2)->update(['stored_value' => PHP_INT_MIN]);
            $minimum = $connection->table($table)->orderBy('id')->get();
            self::check(self::reject(static fn (): int => $query->allowAll()->decrement('value'))
                && $connection->table($table)->orderBy('id')->get() === $minimum
                && $query->findOrFail(2)->get('value') === PHP_INT_MIN, '整数下溢改变了业务值、版本或其他匹配行');
            self::check($query->where('id', '=', 2)->increment('value', PHP_INT_MAX) === 1
                && $query->findOrFail(2)->get('value') === -1
                && $query->where('id', '=', 2)->decrement('value', PHP_INT_MAX) === 1
                && $query->findOrFail(2)->get('value') === PHP_INT_MIN, '最大合法增量发生浮点转换或被错误拒绝');
            $query->where('id', '=', 3)->increment('value', PHP_INT_MAX);
            $query->where('id', '=', 3)->decrement('value', PHP_INT_MAX);
            self::check($query->findOrFail(3)->get('value') === null
                && (int) $connection->table($table)->where('id', '=', 3)->value('version') === ($versioned ? 3 : 1), 'NULL 算术改变空值或版本语义');

            $connection->table($table)->where('id', '=', 2)->update(['stored_value' => PHP_INT_MAX]);
            $beforeTransaction = $connection->table($table)->orderBy('id')->get();
            self::check(self::reject(static fn (): mixed => Db::transaction(static function () use ($query, $connection, $table, $beforeTransaction): void {
                self::check(self::reject(static fn (): int => $query->allowAll()->increment('value'))
                    && $connection->transactionDepth() === 1
                    && $connection->table($table)->orderBy('id')->get() === $beforeTransaction, '整数失败未保留外层事务或留下部分更新');
                self::check($query->where('id', '=', 1)->increment('value') === 1 && $query->findOrFail(1)->get('value') === 11, '整数失败回滚保存点后不能继续合法写入');
                throw new RuntimeException('rollback');
            }), 'rollback') && $connection->table($table)->orderBy('id')->get() === $beforeTransaction, '整数算术逃逸外层回滚');

            if ($connection->driverName() === 'sqlite') {
                foreach ([1.5, 'invalid'] as $invalid) {
                    $connection->table($table)->where('id', '=', 2)->update(['stored_value' => $invalid]);
                    $beforeInvalid = $connection->table($table)->orderBy('id')->get();
                    self::check(self::reject(static fn (): int => $query->allowAll()->increment('value'))
                        && self::reject(static fn (): int => $query->allowAll()->decrement('value'))
                        && $connection->table($table)->orderBy('id')->get() === $beforeInvalid, 'SQLite 历史非整数行被静默转换或造成部分写入');
                }
                // 通用表查询没有整数模型契约，继续保留 SQLite 对普通数值表达式的原有语义。
                $connection->table($table)->where('id', '=', 2)->update(['stored_value' => 1.5]);
                self::check($connection->table($table)->where('id', '=', 2)->increment('stored_value') === 1
                    && (float) $connection->table($table)->where('id', '=', 2)->value('stored_value') === 2.5, '模型守卫错误改变通用 Query 的数值运算');
            }
        }
        if ($connection->driverName() === 'mysql') {
            self::unsignedIntegerArithmetic($connection);
        }
    }

    /** MySQL 无符号列也遵守 integer 模型的 PHP 值域，不能成功写入后才在水合时报错。 */
    private static function unsignedIntegerArithmetic(Connection $connection): void
    {
        foreach ([false, true] as $versioned) {
            $table = $versioned ? 'type_suite_versioned_unsigned_bounds' : 'type_suite_unsigned_bounds';
            $connection->execute('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, stored_value BIGINT UNSIGNED NULL, version BIGINT NOT NULL DEFAULT 1) ENGINE=InnoDB');
            $fields = ['id' => new ModelField('id', 'integer'), 'value' => new ModelField('stored_value', 'integer', true)];
            if ($versioned) {
                $fields['version'] = new ModelField('version', 'integer', false, false, true, false);
            }
            $definition = new ModelDefinition($table, 'id', $fields, false, null, $versioned ? 'version' : null);
            $query = new ModelQuery($definition, static fn (array $row): MappingProbe => new MappingProbe($definition, $row));
            $query->insertMany([['id' => 1, 'value' => 10], ['id' => 2, 'value' => PHP_INT_MAX], ['id' => 3, 'value' => null]]);
            $initial = $connection->table($table)->orderBy('id')->get();
            self::check(self::reject(static fn (): int => $query->allowAll()->increment('value'))
                && $connection->table($table)->orderBy('id')->get() === $initial
                && $query->findOrFail(2)->get('value') === PHP_INT_MAX, 'MySQL 无符号列允许 integer 模型上溢或留下部分写入');
            self::check($query->where('id', '=', 2)->decrement('value') === 1
                && $query->findOrFail(2)->get('value') === PHP_INT_MAX - 1
                && $query->where('id', '=', 2)->increment('value') === 1
                && $query->findOrFail(2)->get('value') === PHP_INT_MAX, 'MySQL 无符号列的合法边界运算被错误拒绝');
            $connection->table($table)->where('id', '=', 1)->update(['stored_value' => 0]);
            self::check($query->where('id', '=', 1)->increment('value', PHP_INT_MAX) === 1
                && $query->findOrFail(1)->get('value') === PHP_INT_MAX
                && $query->where('id', '=', 1)->decrement('value', PHP_INT_MAX) === 1
                && $query->findOrFail(1)->get('value') === 0, 'MySQL 无符号列的最大合法增量改变精度');
            $beforeUnderflow = $connection->table($table)->orderBy('id')->get();
            self::check(self::reject(static fn (): int => $query->allowAll()->decrement('value'))
                && $connection->table($table)->orderBy('id')->get() === $beforeUnderflow, 'MySQL 无符号列下溢未保留真实约束或整批回滚');
            $query->where('id', '=', 3)->increment('value', PHP_INT_MAX);
            $query->where('id', '=', 3)->decrement('value', PHP_INT_MAX);
            self::check($query->findOrFail(3)->get('value') === null
                && (int) $connection->table($table)->where('id', '=', 3)->value('version') === ($versioned ? 3 : 1), 'MySQL 无符号列 NULL 算术改变空值或版本语义');

            // 历史超界值即使递减后能回到 PHP int 范围，也不能通过模型运算隐式修复。
            foreach (['9223372036854775808', '18446744073709551615'] as $invalid) {
                $connection->table($table)->where('id', '=', 2)->update(['stored_value' => $invalid]);
                $beforeInvalid = $connection->table($table)->orderBy('id')->get();
                self::check(self::reject(static fn (): int => $query->where('id', '>=', 2)->increment('value'))
                    && self::reject(static fn (): int => $query->where('id', '>=', 2)->decrement('value'))
                    && $connection->table($table)->orderBy('id')->get() === $beforeInvalid, 'MySQL 无符号历史超界值被模型运算静默转换');
            }
            // 通用 Query 保留数据库本身的无符号算术范围，不套用模型的 PHP int 值域。
            $connection->table($table)->where('id', '=', 2)->update(['stored_value' => PHP_INT_MAX]);
            self::check($connection->table($table)->where('id', '=', 2)->increment('stored_value') === 1
                && (string) $connection->table($table)->where('id', '=', 2)->value('stored_value') === '9223372036854775808', 'integer 模型守卫错误收窄通用 MySQL Query');
        }
    }

    /** 一份字段声明验证批量创建、SQL 空值、精确聚合和租户/软删除范围，不用数组结果替代模型行为。 */
    private static function insertAndAggregate(Connection $connection, ExecutionScope $scope): void
    {
        $date = match ($connection->driverName()) {
            'mysql' => 'DATETIME(6)', 'pgsql' => 'TIMESTAMP(6)', default => 'TEXT'
        };
        $money = $connection->driverName() === 'sqlite' ? 'TEXT' : 'DECIMAL(30, 2)';
        $large = $connection->driverName() === 'sqlite' ? 'TEXT' : 'DECIMAL(30, 0)';
        $connection->execute('CREATE TABLE type_suite_bulk (id INTEGER PRIMARY KEY, tenant_id VARCHAR(50) NOT NULL, '
            . 'stored_label VARCHAR(100) NOT NULL, quantity INTEGER NULL, amount ' . $money . ' NULL, large ' . $large . ' NULL, '
            . 'occurred_at ' . $date . ' NULL, deleted_at ' . $date . ' NULL, version BIGINT NOT NULL, optional_note VARCHAR(100) NULL, CHECK (quantity >= 0))'
            . ($connection->driverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
        $definition = new ModelDefinition('type_suite_bulk', 'id', [
            'id' => new ModelField('id', 'integer'), 'tenant_id' => new ModelField('tenant_id'),
            'title' => new ModelField('stored_label'), 'quantity' => new ModelField('quantity', 'integer', true),
            'amount' => new ModelField('amount', 'decimal', true, true, true, true, 4, 2),
            'large' => new ModelField('large', 'bigint', true, true, true, true, 30, 0),
            'occurred_at' => new ModelField('occurred_at', 'datetime', true),
            'deleted_at' => new ModelField('deleted_at', 'datetime', true, false, true, false),
            'version' => new ModelField('version', 'integer', false, false, true, false),
            'optional' => new ModelField('optional_note', 'string', true, true, true, false),
        ], false, 'deleted_at', 'version');
        $query = new ModelQuery($definition, static fn (array $row): MappingProbe => new MappingProbe($definition, $row));
        $row = ['id' => 1, 'title' => ' alpha ', 'quantity' => 2, 'amount' => '99.99', 'large' => '9007199254740993',
            'occurred_at' => new \DateTimeImmutable('2026-10-04T08:30:01.123456+08:00')];
        $second = array_replace($row, ['id' => 2, 'tenant_id' => 'tenant-a', 'title' => 'beta', 'quantity' => 4, 'amount' => '99.98', 'large' => '9007199254740995',
            'occurred_at' => '2026-10-04T00:30:02.123456Z']);
        $third = array_replace($row, ['id' => 3, 'title' => 'gamma', 'quantity' => null, 'amount' => null, 'large' => null, 'occurred_at' => null]);
        $observer = new ArticleObserver();
        $behavior = (new ModelBehavior())->observe($observer)->setter('title', static fn (mixed $value): mixed => trim((string) $value))
            ->setter('tenant_id', static fn (mixed $value): mixed => 'tenant-b');
        $log = $connection->listen($scope, null, 50, 65536, true);
        try {
            self::check($query->withBehavior($behavior)->insertMany([$row, array_reverse($second, true), $third]) === 3 && $observer->events() === [], '批量新增行数、字段次序、修改器或事件语义错误');
        } finally {
            $log->stop();
        }
        $inserts = 0;
        foreach ($log->records() as $record) {
            if (str_starts_with(strtoupper($record['sql'] ?? ''), 'INSERT INTO ')) {
                $inserts++;
            }
        }
        self::check($inserts === 1 && $query->insertMany([]) === 0, '批量新增被拆为多条 INSERT 或空批次行为错误');
        $model = $query->findOrFail(1);
        self::check($model->get('title') === 'alpha' && $model->get('tenant_id') === 'tenant-a' && $model->get('version') === 1
            && $model->get('deleted_at') === null && $model->get('optional') === null
            && $model->get('occurred_at')->format('Y-m-d H:i:s.u') === '2026-10-04 00:30:01.123456', '批量创建没有保持映射、租户、生命周期或 UTC 时间');
        self::check((string) $query->sum('quantity') === '6' && (float) $query->avg('quantity') === 3.0
            && $query->min('quantity') === 2 && $query->max('quantity') === 4 && $query->min('title') === 'alpha'
            && $query->max('occurred_at')->format('Y-m-d H:i:s.u') === '2026-10-04 00:30:02.123456', '模型聚合的字段类型或 null 排除错误');
        foreach ([$query->where('id', '=', 3), $query->where('id', '=', -1)] as $empty) {
            self::check($empty->sum('quantity') === null && $empty->avg('quantity') === null
                && $empty->min('quantity') === null && $empty->max('quantity') === null, '空集或全 null 的聚合被改成零');
        }
        self::check(self::reject(static fn (): mixed => $query->sum('title'), 'invalid_aggregate_field')
            && self::reject(static fn (): mixed => $query->avg('missing'), 'unknown_field')
            && self::reject(static fn (): mixed => $query->limit(1)->sum('quantity')), '非法聚合字段或分页状态没有拒绝');
        if ($connection->driverName() === 'sqlite') {
            self::check(self::reject(static fn (): mixed => $query->sum('amount'), 'exact_arithmetic_unsupported')
                && self::reject(static fn (): mixed => $query->avg('amount'), 'exact_arithmetic_unsupported')
                && self::reject(static fn (): mixed => $query->min('large'), 'exact_arithmetic_unsupported')
                && self::reject(static fn (): mixed => $query->max('amount'), 'exact_arithmetic_unsupported'), 'SQLite 精确文本聚合未拒绝');
        } else {
            self::check((string) $query->sum('amount') === '199.97' && $query->min('amount') === '99.98'
                && preg_match('/^99\.9850*$/D', (string) $query->avg('amount')) === 1
                && (string) $query->sum('large') === '18014398509481988' && $query->max('large') === '9007199254740995', '聚合总精度被单字段精度截断或经过浮点数');
        }
        self::insertRejections($connection, $query, $row);
        $scope->run(static function (ExecutionScope $current) use ($definition, $query, $row): void {
            self::check(self::reject(static fn (): mixed => $query->sum('quantity'), 'tenant_context_changed')
                && self::reject(static fn (): int => $query->insertMany([]), 'tenant_context_changed'), '现有查询越过变更后的租户绑定');
            $other = new ModelQuery($definition, static fn (array $record): MappingProbe => new MappingProbe($definition, $record));
            self::check($other->insertMany([array_replace($row, ['id' => 9, 'quantity' => 100])]) === 1
                && (string) $other->sum('quantity') === '100', '另一租户不能独立创建或聚合');
        }, ['tenant_id' => 'tenant-b']);
        self::check((string) $query->sum('quantity') === '6', '聚合越过租户范围');
        self::check($query->where('id', '=', 1)->delete() === 1 && (string) $query->sum('quantity') === '4'
            && (string) $query->onlyTrashed()->sum('quantity') === '2' && (string) $query->withTrashed()->sum('quantity') === '6', '聚合没有遵守软删除范围');
        self::generatedInsert();
    }

    /** 模型整数映射不能把文本的字典序或数据库隐式转换当作数值语义。 */
    private static function integerStorage(Connection $connection): void
    {
        $shortType = $connection->driverName() === 'sqlite' ? 'INT2' : 'SMALLINT';
        $longType = $connection->driverName() === 'sqlite' ? 'INT8' : 'BIGINT';
        $connection->execute('CREATE TABLE type_suite_integer_storage (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, '
            . 'text_value VARCHAR(30) NOT NULL, real_value REAL NOT NULL, decimal_value DECIMAL(12, 2) NOT NULL, '
            . 'int_value INTEGER NOT NULL, big_value BIGINT NOT NULL, short_value ' . $shortType . ' NOT NULL, long_value ' . $longType . ' NOT NULL)'
            . ($connection->driverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
        $connection->table('type_suite_integer_storage')->insertMany([
            ['id' => 1, 'parent_id' => 1, 'text_value' => '2', 'real_value' => 2.5, 'decimal_value' => '2.50', 'int_value' => 2, 'big_value' => 2, 'short_value' => 2, 'long_value' => 2],
            ['id' => 2, 'parent_id' => 1, 'text_value' => '10', 'real_value' => 10.5, 'decimal_value' => '10.50', 'int_value' => 10, 'big_value' => 10, 'short_value' => 10, 'long_value' => 10],
        ]);
        self::check($connection->table('type_suite_integer_storage')->aggregate('MIN', 'text_value') === '10', '夹具没有建立文本字典序与数值次序差异');
        $fields = [];
        foreach (['id', 'parent_id', 'text_value', 'real_value', 'decimal_value', 'int_value', 'big_value', 'short_value', 'long_value'] as $field) {
            $fields[$field] = new ModelField($field, 'integer');
        }
        $definition = new ModelDefinition('type_suite_integer_storage', 'id', $fields, false);
        $query = new ModelQuery($definition, static fn (array $row): MappingProbe => new MappingProbe($definition, $row));
        foreach (['text_value', 'real_value', 'decimal_value'] as $unsafe) {
            self::check(self::reject(static fn (): mixed => $query->sum($unsafe), 'integer_arithmetic_unsupported')
                && self::reject(static fn (): mixed => $query->avg($unsafe), 'integer_arithmetic_unsupported')
                && self::reject(static fn (): mixed => $query->min($unsafe), 'integer_arithmetic_unsupported')
                && self::reject(static fn (): mixed => $query->max($unsafe), 'integer_arithmetic_unsupported')
                && self::reject(static fn (): int => $query->where('id', '=', 1)->increment($unsafe), 'integer_arithmetic_unsupported')
                && self::reject(static fn (): int => $query->where('id', '=', 1)->decrement($unsafe), 'integer_arithmetic_unsupported'), '整数模型运算接受了非整型物理列');
        }
        foreach (['int_value', 'big_value', 'short_value', 'long_value'] as $safe) {
            self::check(
                (string) $query->sum($safe) === '12' && (float) $query->avg($safe) === 6.0
                && $query->min($safe) === 2 && $query->max($safe) === 10
                && $query->where('id', '=', 1)->increment($safe) === 1 && $query->where('id', '=', 1)->decrement($safe) === 1,
                '真实整型列声明被错误拒绝或整数运算失真'
            );
        }
        $relation = new \Type\Orm\RelationDefinition('HasMany', static function (Connection $database, string $alias) use ($definition): ModelQuery {
            return (new ModelQuery($definition, static fn (array $row): MappingProbe => new MappingProbe($definition, $row), $alias))->onConnection($database);
        }, 'id', 'parent_id');
        $parent = new ModelDefinition('type_suite_integer_storage', 'id', ['id' => new ModelField('id', 'integer')], false, null, null, ['children' => $relation]);
        $parents = new ModelQuery($parent, static fn (array $row): MappingProbe => new MappingProbe($parent, $row));
        self::check(self::reject(static fn (): array => $parents->withSum('children', 'text_value')->get(), 'integer_arithmetic_unsupported')
            && (string) $parents->withSum('children', 'int_value')->findOrFail(1)->computed('children_int_value_sum') === '12', '关系求和未复用整数真实存储校验');
        self::check($connection->table('type_suite_integer_storage')->orderBy('id')->pluck('text_value') === ['2', '10'], '被拒绝的整数算术修改了原文本值');
    }

    /** 批次校验和 SQL 末行错误必须保留完整原始状态，保存点失败不能污染外层事务。 */
    private static function insertRejections(Connection $connection, ModelQuery $query, array $row): void
    {
        foreach (['version' => 9, 'deleted_at' => null] as $protected => $value) {
            self::check(self::reject(static fn (): int => $query->insertMany([array_replace($row, ['id' => 10, $protected => $value])]), 'field_not_fillable'), '批量新增允许覆盖生命周期初值');
        }
        self::check(self::reject(static fn (): int => $query->insertMany([array_replace($row, ['tenant_id' => 'tenant-b'])]), 'tenant_scope_conflict')
            && self::reject(static fn (): int => $query->insertMany([array_replace($row, ['unknown' => true])]), 'unknown_field')
            && self::reject(static fn (): int => $query->insertMany([['id' => 10]]), 'required_field')
            && self::reject(static fn (): int => $query->insertMany([$row, array_replace($row, ['id' => 11, 'quantity' => '4'])]), 'invalid_field_type')
            && self::reject(static fn (): int => $query->insertMany([array_replace($row, ['id' => 10]), array_replace($row, ['id' => 11, 'optional' => null])]), 'inconsistent_insert_fields')
            && self::reject(static fn (): int => $query->insertMany(['record' => $row]), 'invalid_insert_rows'), '批量输入、字段集合或租户校验缺失');
        self::check(self::reject(static fn (): int => $query->where('id', '=', 10)->insertMany([]))
            && self::reject(static fn (): int => $query->orderBy('id')->insertMany([]))
            && self::reject(static fn (): int => $query->limit(1)->insertMany([]))
            && self::reject(static fn (): int => $query->select(['title'])->insertMany([]), 'invalid_insert_query')
            && self::reject(static fn (): int => $query->withTrashed()->insertMany([]), 'invalid_insert_query'), '空批次静默丢弃非插入查询状态');
        $good = array_replace($row, ['id' => 10]);
        $bad = array_replace($row, ['id' => 11, 'quantity' => -1]);
        self::check(self::reject(static fn (): int => $query->insertMany([$good, $row])) && $query->find(10) === null
            && $query->findOrFail(1)->get('title') === 'alpha', '末行唯一键冲突没有回滚新增或修改了已有行');
        self::check(self::reject(static fn (): int => $query->insertMany([$good, $bad])) && $query->find(10) === null, '末行约束失败没有回滚整批新增');
        if ($connection->driverName() === 'sqlite') {
            $connection->execute("CREATE TRIGGER type_bulk_fail BEFORE INSERT ON type_suite_bulk WHEN NEW.id = 11 BEGIN SELECT RAISE(FAIL, 'later row'); END");
            try {
                self::check(self::reject(static fn (): int => $query->insertMany([$good, array_replace($row, ['id' => 11])]))
                    && $query->find(10) === null, 'SQLite 触发器失败留下部分新增');
            } finally {
                $connection->execute('DROP TRIGGER type_bulk_fail');
            }
        }
        if ($connection->driverName() === 'mysql') {
            $mode = (string) $connection->query('SELECT @@SESSION.sql_mode AS modes')[0]['modes'];
            $connection->execute("SET SESSION sql_mode = ''");
            try {
                self::check(self::reject(static fn (): int => $query->insertMany([$good]), 'unsafe_batch_storage'), '非严格 MySQL 会话允许模型批量新增');
            } finally {
                $connection->execute('SET SESSION sql_mode = ?', [$mode]);
            }
        }
        self::check(self::reject(static fn (): mixed => Db::transaction(static function () use ($query, $good, $bad, $connection): void {
            self::check(self::reject(static fn (): int => $query->insertMany([$good, $bad])) && $connection->transactionDepth() === 1
                && $query->find(10) === null, '批量新增失败破坏外层事务或留下部分结果');
            self::check($query->insertMany([$good]) === 1, '保存点失败后无法继续新增');
            throw new RuntimeException('rollback');
        }), 'rollback') && $query->find(10) === null && $query->count() === 3, '保存点后不能继续写入、外层回滚未覆盖新增或校验写入了部分批次');
    }

    /** 生成模型批量写入后正常水合，覆盖自动主键、JSON、精确字段及 DateTime 的开发/AOT 路径。 */
    private static function generatedInsert(): void
    {
        $values = ['name' => '批量模型水合', 'active' => true, 'external_id' => '123456789012345678901234567890',
            'credit' => '12345678901234567890.12', 'profile' => ['enabled' => true, 'value' => 3],
            'joined_at' => new \DateTimeImmutable('2026-10-04T08:30:01.123456+08:00'), 'secret' => 'bulk-only'];
        self::check(User::query()->insertMany([$values]) === 1, '生成模型批量新增失败');
        $user = User::query()->where('name', '=', '批量模型水合')->firstOrFail();
        self::check($user->id > 0 && $user->external_id === $values['external_id'] && $user->credit === $values['credit']
            && $user->profile['enabled'] === true && $user->profile['value'] === 3
            && $user->joined_at->format('Y-m-d H:i:s.u') === '2026-10-04 00:30:01.123456', '批量新增后的生成属性或精确类型水合错误');
        self::check(self::reject(static fn (): int => User::query()->insertMany([array_replace($values, ['id' => 9999])]), 'field_not_fillable'), '批量新增绕过自动主键保护');
        self::check(self::reject(static fn (): mixed => User::query()->min('profile'), 'invalid_aggregate_field')
            && self::reject(static fn (): mixed => User::query()->max('active'), 'invalid_aggregate_field'), 'JSON 或布尔字段被当作可排序模型值');
        self::check($user->delete(), '批量生成模型未能按模型生命周期删除');
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
            return ($code === '' || $code === 'rollback') && $error->getMessage() === 'rollback';
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
