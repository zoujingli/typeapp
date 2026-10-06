<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Closure;
use RuntimeException;
use Throwable;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\DatabaseManager;
use Type\Orm\Driver;
use Type\Orm\Db;
use Type\Orm\ModelConditions;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Orm\Query;
use Type\Orm\QueryEvent;
use Type\Orm\TransactionOutcome;
use Type\Runtime\ExecutionScope;
use Type\Runtime\Deadline;
use Type\Runtime\ManagedTask;
use Type\Runtime\TaskException;

/** 三库共用的属性、组合查询、关系计算和诊断公共行为验收。 */
final class CoreExercise
{
    /** 完整批次、数据库冲突目标、受管时间版本及不可见行通过一条真实冲突 SQL 验证。 */
    public static function upserts(): void
    {
        $connection = Db::connection('default', true);
        $driver = $connection->driverName();
        $connection->execute('CREATE TABLE type_suite_upserts (id INTEGER NOT NULL, tenant_id VARCHAR(30) NOT NULL, code VARCHAR(50) NOT NULL, title VARCHAR(50) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, version BIGINT NOT NULL, PRIMARY KEY (tenant_id, id), UNIQUE (tenant_id, code))');
        $connection->execute('CREATE TABLE type_suite_upsert_labels (id INTEGER PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, title VARCHAR(50) NOT NULL)');
        ExecutionScope::current()->run(static function (ExecutionScope $current) use ($driver): void {
            $connection = Db::connection('default', true);
            $write = static fn (array $rows): int => $driver === 'mysql'
                ? UpsertRecord::upsertAnyUnique($rows, ['title'])
                : UpsertRecord::upsert($rows, ['tenant_id', 'code'], ['title']);
            self::check($write([['id' => 1, 'code' => 'alpha', 'title' => '新增'], ['id' => 2, 'code' => 'beta', 'title' => '同批']]) === 2, '批量冲突新增数量错误');
            $before = UpsertRecord::find(1);
            self::check($before->getCreatedAt() === UpsertRecord::find(2)->getCreatedAt(), '冲突批次没有统一创建时间');
            $writes = $connection->statistics()['write_attempts'];
            self::check(self::reject(static fn (): int => $write([['id' => 3, 'code' => 'valid', 'title' => '合法'], ['id' => 4, 'code' => 'invalid', 'title' => 9]]), 'invalid_field_type')
                && $connection->statistics()['write_attempts'] === $writes && UpsertRecord::find(3) === null, '批次后行非法留下前行写入');
            self::check($write([['id' => 9, 'code' => 'alpha', 'title' => '更新']]) === ($driver === 'mysql' ? 2 : 1), '冲突更新影响行数被伪造');
            $updated = UpsertRecord::find(1);
            self::check($updated->getTitle() === '更新' && $updated->getVersion() === 2 && $updated->getCreatedAt() === $before->getCreatedAt()
                && UpsertRecord::find(9) === null, '冲突写入重置主键、版本或创建时间');
            self::check(UpsertRecord::firstOrCreate(['code' => 'alpha'])->getVersion() === 2, '唯一创建与冲突写入元数据不兼容');
            self::check(self::reject(static fn (): int => $driver === 'mysql' ? UpsertRecord::upsertAnyUnique([['id' => 1, 'code' => 'alpha', 'title' => '拒绝']], ['version'])
                : UpsertRecord::upsert([['id' => 1, 'code' => 'alpha', 'title' => '拒绝']], ['tenant_id', 'code'], ['version']), 'field_not_fillable'), '冲突写入允许重置版本');
            $connection->table('type_suite_upserts')->where('tenant_id', '=', 'upsert-tenant')->where('id', '=', 1)->update(['version' => PHP_INT_MAX]);
            $failed = false;
            try {
                $write([['id' => 10, 'code' => 'new-before-overflow', 'title' => '应回滚'], ['id' => 11, 'code' => 'alpha', 'title' => '溢出']]);
            } catch (\Type\Orm\DatabaseException $error) {
                $failed = true;
            }
            self::check($failed && UpsertRecord::find(10) === null && UpsertRecord::find(1)->getVersion() === PHP_INT_MAX, '冲突版本耗尽留下部分写入');
            $connection->table('type_suite_upserts')->where('tenant_id', '=', 'upsert-tenant')->where('id', '=', 1)->update(['version' => 2]);
        }, ['tenant_id' => 'upsert-tenant']);
        ExecutionScope::current()->run(static function (ExecutionScope $current) use ($driver): void {
            $row = [['id' => 1, 'code' => 'alpha', 'title' => '其他租户']];
            $driver === 'mysql' ? UpsertRecord::upsertAnyUnique($row, ['title']) : UpsertRecord::upsert($row, ['tenant_id', 'code'], ['title']);
            self::check(UpsertRecord::find(1)->getVersion() === 1, '冲突写入越过租户边界');
        }, ['tenant_id' => 'other-upsert-tenant']);
        ExecutionScope::current()->run(static function (ExecutionScope $current) use ($driver): void {
            $rows = [['id' => 88, 'code' => 'alpha', 'alias' => 'invisible-upsert', 'optional_code' => null]];
            if ($driver === 'mysql') {
                self::check(self::reject(static fn (): int => UniqueRecord::upsertAnyUnique($rows, ['alias']), 'unsafe_upsert_visibility'), 'MySQL 静默写入不可见软删除目标');
            } else {
                self::check(UniqueRecord::upsert($rows, ['tenant_id', 'code'], ['alias']) === 0
                    && UniqueRecord::query()->onlyTrashed()->find(1)->getAlias() === 'first', '不可见软删除冲突被覆盖或复活');
            }
        }, ['tenant_id' => 'unique-tenant']);
        $plain = static fn (array $rows): int => $driver === 'mysql' ? UpsertLabel::upsertAnyUnique($rows, ['title']) : UpsertLabel::upsert($rows, ['code'], ['title']);
        self::check($plain([['id' => 1, 'code' => 'plain', 'title' => 'first']]) === 1
            && $plain([['id' => 2, 'code' => 'plain', 'title' => 'changed']]) === ($driver === 'mysql' ? 2 : 1)
            && $plain([['id' => 3, 'code' => 'plain', 'title' => 'changed']]) === ($driver === 'mysql' ? 0 : 1), '新增、更新和未变化没有保留驱动真实计数');
        $writes = $connection->statistics()['write_attempts'];
        $duplicates = [['id' => 4, 'code' => 'same-batch', 'title' => 'first'], ['id' => 5, 'code' => 'same-batch', 'title' => 'last']];
        if ($driver === 'pgsql') {
            self::check(self::reject(static fn (): int => $plain($duplicates))
                && UpsertLabel::query()->where('code', '=', 'same-batch')->count() === 0, 'PostgreSQL 同批重复冲突被拆批或留下部分写入');
        } else {
            $plain($duplicates);
            self::check(UpsertLabel::find(4)->getTitle() === 'last', '数据库同批冲突顺序被改变');
        }
        self::check($connection->statistics()['write_attempts'] === $writes + 1, '模型冲突批次没有使用一条写入 SQL');
        self::check(self::reject(static fn (): int => $driver === 'mysql'
            ? UnsafeUniqueRecord::upsertAnyUnique([['id' => 1, 'code' => 'unsafe']], ['code'])
            : UnsafeUniqueRecord::upsert([['id' => 1, 'code' => 'unsafe']], ['code'], ['id']), $driver === 'mysql' ? 'unsafe_unique_identity' : 'field_not_fillable'), '冲突写入接受不安全索引或受保护字段');
    }

    /** 真实唯一元数据、固定身份与创建保存点通过公开静态 Model 入口验证。 */
    public static function firstOrCreate(): void
    {
        $connection = Db::connection('default', true);
        $created = Tag::firstOrCreate(['label' => '获取或创建']);
        $existing = Tag::firstOrCreate(['label' => '获取或创建']);
        self::check($created->getId() === $existing->getId(), '获取或创建没有复用唯一身份');
        $before = $connection->statistics();
        self::check(self::reject(static fn (): Tag => Tag::firstOrCreate(['label' => '固定身份'], ['label' => '另一个身份']), 'identity_conflict'), '创建值覆盖唯一身份');
        self::check($connection->statistics()['read_attempts'] === $before['read_attempts']
            && $connection->statistics()['write_attempts'] === $before['write_attempts'], '身份输入冲突没有在 SQL 前拒绝');
        self::check(self::reject(static fn (): User => User::firstOrCreate(['name' => '没有唯一索引']), 'unsafe_unique_identity'), '未声明真实唯一索引仍获取或创建');
        try {
            Details::firstOrCreate(['user_id' => 2147483647], ['bio' => '不存在父模型']);
            throw new RuntimeException('外键失败被吞掉');
        } catch (\Type\Orm\ConstraintException $error) {
            self::check($error->kind() === 'foreign_key', '真实外键错误分类不正确');
        }
        $connection->execute('CREATE TABLE type_suite_unique_records (id INTEGER PRIMARY KEY, tenant_id VARCHAR(30) NOT NULL, code VARCHAR(50) NOT NULL, alias VARCHAR(50) NOT NULL, optional_code VARCHAR(50) NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, deleted_at VARCHAR(30) NULL, version BIGINT NOT NULL, UNIQUE (tenant_id, code), UNIQUE (tenant_id, alias), UNIQUE (tenant_id, optional_code))');
        ExecutionScope::current()->run(static function (ExecutionScope $current): void {
            $connection = Db::connection('default', true);
            $record = UniqueRecord::firstOrCreate(['code' => 'alpha'], ['id' => 1, 'alias' => 'first', 'optional_code' => null]);
            self::check($record->getVersion() === 1 && $record->getCreatedAt() === $record->getUpdatedAt(), '唯一创建没有复用受管时间和版本');
            self::check(self::reject(static fn (): UniqueRecord => UniqueRecord::firstOrCreate(['optional_code' => 'nullable']), 'unsafe_unique_identity')
                && self::reject(static fn (): UniqueRecord => UniqueRecord::firstOrCreate(['id' => 1]), 'unsafe_unique_identity'), '可空或跨租户唯一身份未拒绝');
            $connection->transaction(static function (Connection $transaction): void {
                Tag::create(['label' => '外层写入']);
                try {
                    UniqueRecord::firstOrCreate(['code' => 'beta'], ['id' => 2, 'alias' => 'first', 'optional_code' => null]);
                    throw new RuntimeException('吞掉其他唯一约束');
                } catch (\Type\Orm\ConstraintException $error) {
                    self::check($error->kind() === 'unique', '真实唯一错误分类错误');
                }
                self::check(Tag::query()->where('label', '=', '外层写入')->exists(), '唯一错误破坏外层保存点');
            });
            $record->delete();
            self::check(self::reject(static fn (): UniqueRecord => UniqueRecord::firstOrCreate(['code' => 'alpha'], ['id' => 3, 'alias' => 'third', 'optional_code' => null]), 'unique_conflict_not_visible'), '软删除唯一冲突被隐式返回或复活');
            self::check(UniqueRecord::query()->onlyTrashed()->find(1) !== null, '不可见唯一冲突覆盖软删除记录');
        }, ['tenant_id' => 'unique-tenant']);
        Tag::query()->whereIn('label', ['获取或创建', '外层写入'])->delete();
        $ready = new Channel(2);
        $release = new Channel(2);
        $tasks = [];
        for ($index = 0; $index < 2; $index++) {
            $tasks[] = ExecutionScope::current()->spawn(static function (ExecutionScope $child) use ($ready, $release): int {
                $connection = Db::connection('default', true);
                $paused = false;
                $listener = $connection->listen($child, static function (QueryEvent $event) use (&$paused, $ready, $release): void {
                    $data = $event->toArray();
                    if (!$paused && str_starts_with($data['sql'], 'SELECT ') && str_contains($data['sql'], 'type_suite_tags') && $data['phase'] === 'statement') {
                        $paused = true;
                        $ready->push(true, 5);
                        self::check($release->pop(5) === true, '唯一竞争释放信号超时');
                    }
                }, 50, 65536, true);
                try {
                    return Tag::firstOrCreate(['label' => '并发唯一创建'])->getId();
                } finally {
                    $listener->stop();
                }
            });
        }
        self::check($ready->pop(5) === true && $ready->pop(5) === true, '并发创建未同时观察不存在身份');
        $release->push(true);
        $release->push(true);
        $first = $tasks[0]->await(10);
        $second = $tasks[1]->await(10);
        self::check($first === $second && Tag::query()->where('label', '=', '并发唯一创建')->count() === 1, '真实竞争没有返回同一获胜者');
        Tag::query()->where('label', '=', '并发唯一创建')->delete();
        self::uniqueMetadata();
        self::uniqueSnapshot();
    }

    private static function uniqueMetadata(): void
    {
        $connection = Db::connection('default', true);
        $connection->execute('CREATE TABLE type_suite_unsafe_unique (id INTEGER PRIMARY KEY, code VARCHAR(50) NULL UNIQUE)');
        self::check(self::reject(static fn (): UnsafeUniqueRecord => UnsafeUniqueRecord::firstOrCreate(['code' => 'nullable'], ['id' => 1]), 'unsafe_unique_identity'), '模型非空声明掩盖实际可空唯一列');
        $connection->execute('DROP TABLE type_suite_unsafe_unique');
        $connection->execute('CREATE TABLE type_suite_unsafe_unique (id INTEGER PRIMARY KEY, code VARCHAR(50) NOT NULL)');
        if ($connection->driverName() === 'mysql') {
            $connection->execute('CREATE UNIQUE INDEX type_suite_unsafe_prefix ON type_suite_unsafe_unique (code(3))');
        } else {
            $connection->execute('CREATE UNIQUE INDEX type_suite_unsafe_partial ON type_suite_unsafe_unique (code) WHERE id > 1');
            $connection->execute('CREATE UNIQUE INDEX type_suite_unsafe_expression ON type_suite_unsafe_unique (lower(code))');
        }
        self::check(self::reject(static fn (): UnsafeUniqueRecord => UnsafeUniqueRecord::firstOrCreate(['code' => 'unsafe'], ['id' => 2]), 'unsafe_unique_identity'), '部分、表达式或前缀索引冒充完整唯一身份');
    }

    /** 在真实可重复读快照中保留外层写入，明确报告不可见获胜者，不重跑事务体。 */
    private static function uniqueSnapshot(): void
    {
        $connection = Db::connection('default', true);
        $driver = $connection->driverName();
        if ($driver === 'sqlite') {
            return;
        }
        $original = $driver === 'mysql' ? $connection->query('SELECT @@SESSION.transaction_isolation AS level')[0]['level']
            : $connection->query('SHOW default_transaction_isolation')[0]['default_transaction_isolation'];
        $original = strtoupper(str_replace('-', ' ', $original));
        self::check(in_array($original, ['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE', 'READ UNCOMMITTED'], true), '未知隔离级别');
        $prefix = $driver === 'mysql' ? 'SET SESSION TRANSACTION ISOLATION LEVEL ' : 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL ';
        $connection->execute($prefix . 'REPEATABLE READ');
        $start = new Channel(1);
        $done = new Channel(1);
        $task = ExecutionScope::current()->spawn(static function (ExecutionScope $child) use ($start, $done): int {
            self::check($start->pop(5) === true, '快照竞争未收到开始信号');
            $model = Tag::create(['label' => '快照获胜者']);
            $done->push(true);
            return $model->getId();
        });
        $paused = false;
        $listener = $connection->listen(ExecutionScope::current(), static function (QueryEvent $event) use (&$paused, $start, $done): void {
            $data = $event->toArray();
            if (!$paused && $data['phase'] === 'statement' && str_starts_with($data['sql'], 'SELECT ')
                && in_array('快照获胜者', $data['parameters'], true)) {
                $paused = true;
                $start->push(true);
                self::check($done->pop(5) === true, '快照竞争获胜者没有提交');
            }
        }, 50, 65536, true);
        try {
            $connection->transaction(static function (Connection $transaction): void {
                self::check(self::reject(static fn (): Tag => Tag::firstOrCreate(['label' => '快照获胜者']), 'unique_conflict_not_visible'), '过时快照误报获胜模型或重试外层');
                Tag::create(['label' => '快照外层保留']);
            });
            self::check($task->await(10) > 0 && Tag::query()->where('label', '=', '快照外层保留')->exists(), '竞争恢复破坏外层事务');
        } finally {
            $listener->stop();
            $connection->execute($prefix . $original);
        }
        Tag::query()->whereIn('label', ['快照获胜者', '快照外层保留'])->delete();
    }

    /** 公开父模型句柄的读写分离、作用域、租户绑定、回滚失效与前置拒绝。 */
    public static function relationHandles(ExecutionScope $scope, int $userId): void
    {
        $connection = Db::connection('default', true);
        $article = Article::create(['user_id' => $userId, 'title' => '关系句柄', 'status' => 'draft', 'views' => 0]);
        $id = $article->getId();
        $tagId = Tag::query()->firstOrFail()->getId();
        $before = $connection->statistics();
        $handle = $article->relation('tags');
        self::check(self::reject(static fn (): mixed => $article->relation('missing'), 'unknown_relation')
            && self::reject(static fn (): mixed => $article->relation('author'), 'relation_write_unsupported')
            && self::reject(static fn (): mixed => $article->related('tags'), 'relation_not_loaded'), '关系句柄接受未知关系、只读类型或隐式加载');
        $new = new Article(['user_id' => $userId, 'title' => '未保存', 'status' => 'draft', 'views' => 0]);
        self::check(self::reject(static fn (): bool => $new->relation('tags')->attach($tagId), 'not_persisted'), '未持久化父模型可以写关系');
        $scope->run(static function (ExecutionScope $current) use ($handle, $tagId): void {
            self::check(self::reject(static fn (): bool => $handle->attach($tagId), 'tenant_context_changed'), '全局父模型句柄跟随临时租户改变');
        }, ['tenant_id' => 'another-tenant']);
        $other = new ExecutionScope();
        try {
            $other->run(static function (ExecutionScope $current) use ($handle, $tagId): void {
                self::check(self::reject(static fn (): bool => $handle->attach($tagId), 'model_scope_mismatch'), '关系句柄跨作用域写入');
            });
        } finally {
            $other->close();
        }
        self::check($connection->statistics()['read_attempts'] === $before['read_attempts']
            && $connection->statistics()['write_attempts'] === $before['write_attempts'], '关系句柄前置拒绝产生数据库操作');
        self::check($handle->attach($tagId, ['weight' => 1]) && !$handle->attach($tagId, ['weight' => 2]), '句柄重复挂载契约改变');
        $loaded = Article::query()->with('tags')->findOrFail($id);
        self::check($loaded->related('tags')[0]->pivot()['weight'] == 2, '重复挂载未更新中间表');
        self::check($loaded->relation('tags')->detach($tagId) && !$loaded->relationLoaded('tags'), '句柄解除未使预加载失效');
        $reads = $connection->statistics()['read_attempts'];
        self::check(self::reject(static fn (): mixed => $loaded->related('tags'), 'relation_not_loaded')
            && $connection->statistics()['read_attempts'] === $reads, '写后读取返回旧值或隐式查询');
        self::check($loaded->relation('tags')->sync([['id' => $tagId, 'pivot' => ['weight' => 3]]]) === ['attached' => 1, 'detached' => 0, 'updated' => 0], '句柄同步回执改变');
        $rollback = $loaded->relation('tags');
        try {
            Db::transaction(static function () use ($rollback): void {
                $rollback->sync([]);
                throw new RuntimeException('relation_handle_rollback');
            });
        } catch (RuntimeException $error) {
            self::check($error->getMessage() === 'relation_handle_rollback', '关系回滚错误丢失');
        }
        self::check(self::reject(static fn (): array => $rollback->sync([]), 'model_invalid'), '旧句柄绕过回滚失效');
        $fresh = Article::query()->with('tags')->findOrFail($id);
        self::check(count($fresh->related('tags')) === 1, '回滚没有恢复关系');
        $deleted = $fresh->relation('tags');
        $deleted->sync([]);
        $fresh->forceDelete();
        self::check(self::reject(static fn (): bool => $deleted->attach($tagId), 'model_invalid'), '删除后关系句柄仍可写入');
    }

    /** 独立消费者的真实驱动与原生产物共同验证上下文和租约边界。 */
    public static function scopes(Driver $driver): array
    {
        self::check(self::reject(static fn (): ExecutionScope => ExecutionScope::current(), 'scope_missing'), '消费者入口残留先前作用域');
        $manager = new DatabaseManager(['default' => $driver], 2, 0);
        Db::configure($manager);
        $parent = new ExecutionScope(context: ['request_id' => 'parent']);
        try {
            $cached = $parent->run(static function (ExecutionScope $current) use ($manager): Connection {
                $outer = Db::connection('default', true);
                Db::transaction(static function () use ($outer, $current, $manager): void {
                    $ready = new Channel(1);
                    $resume = new Channel(1);
                    $task = $current->spawn(static function (ExecutionScope $child) use ($outer, $ready, $resume): array {
                        self::check(ExecutionScope::current() === $child, '子任务没有绑定自身作用域');
                        self::check(self::reject(static fn (): array => $outer->query('SELECT 1')), '子协程使用了父连接');
                        $connection = Db::connection('default', true);
                        self::check($connection !== $outer && $connection->transactionDepth() === 0, '子任务继承父连接或事务');
                        $ready->push(true);
                        self::check($resume->pop(1) === true, '子任务没有收到继续信号');
                        return Db::transaction(static function () use ($child, $connection): array {
                            $snapshot = $child->context();
                            $snapshot['request_id'] = 'child';
                            self::check($connection->transactionDepth() === 1, '子事务没有独立开始');
                            return ['tenant' => $child->binding('tenant_id'), 'context' => $snapshot,
                                'value' => (int) $connection->query('SELECT 7 AS value')[0]['value']];
                        });
                    }, ['tenant_id' => 'tenant-a']);
                    self::check($ready->pop(1) === true, '子任务没有建立独立连接');
                    try {
                        $current->run(static function (ExecutionScope $nested) use ($resume, $task): void {
                            self::check($nested->binding('tenant_id') === 'tenant-b', '重入没有临时覆盖绑定');
                            $resume->push(true);
                            self::check($task->await(1) === ['tenant' => 'tenant-a', 'context' => ['request_id' => 'child'], 'value' => 7], '父子可信值快照相互污染');
                            throw new RuntimeException('scope_restore_probe');
                        }, ['tenant_id' => 'tenant-b']);
                    } catch (RuntimeException $error) {
                        self::check($error->getMessage() === 'scope_restore_probe', '子任务或重入失败');
                    }
                    self::check($current->binding('tenant_id') === 'tenant-a' && $current->context()['request_id'] === 'parent', '异常没有恢复绑定或父上下文');
                    self::check($outer->transactionDepth() === 1 && $manager->statistics()['active']['default']['leased'] === 1, '子任务关闭改变了父事务或残留连接');
                });
                self::check($outer->transactionDepth() === 0, '父事务未完成');
                return $outer;
            }, ['tenant_id' => 'tenant-a']);
        } finally {
            $parent->close();
            $manager->close();
        }
        self::check(self::reject(static fn (): array => $cached->query('SELECT 1')), '关闭作用域后缓存连接仍能执行 SQL');
        self::check($manager->statistics()['active']['default']['leased'] === 0, '关闭作用域后仍有活动租约');
        self::check(self::reject(static fn (): ExecutionScope => ExecutionScope::current(), 'scope_missing'), '退出回调残留当前作用域');

        foreach (['cancel', 'close', 'deadline'] as $mode) {
            $scope = new ExecutionScope($mode === 'deadline' ? new Deadline(0.05) : null);
            $signal = new Channel(1);
            try {
                $task = $scope->spawn(static function (ExecutionScope $child) use ($signal): bool {
                    $subscription = $child->cancellation()->subscribe(static function () use ($signal): void {
                        $signal->close();
                    });
                    try {
                        $signal->pop(1);
                        self::check($child->cancellation()->cancelled(), '父取消、关闭或截止未唤醒子任务');
                        return true;
                    } finally {
                        $child->cancellation()->unsubscribe($subscription);
                    }
                });
                if ($mode === 'cancel') {
                    $scope->cancellation()->cancel();
                } elseif ($mode === 'close') {
                    $scope->close();
                }
                try {
                    self::check($task->await(1) === true, '取消通知结果错误');
                } catch (TaskException $error) {
                    self::check($mode === 'deadline' && $error->errorCode() === 'task_timeout', '取消等待错误码不符');
                }
                self::check($task->join(new Deadline(1)) && $task->finished(), '子任务没有真实收尾');
            } finally {
                $scope->close();
            }
        }

        $database = new Database($driver, 1, 0);
        $waiting = new ExecutionScope();
        $entered = new Channel(1);
        try {
            $delayed = $waiting->spawn(static function (ExecutionScope $child) use ($database, $entered): void {
                $connection = $database->connect($child);
                self::check((int) $connection->query('SELECT 1 AS value')[0]['value'] === 1, '迟到收尾场景未建立真实数据库租约');
                $entered->push(true);
                Coroutine::sleep(0.08);
            });
            self::check($entered->pop(1) === true, '任务没有借到连接');
            self::check(self::reject(static fn (): mixed => $delayed->await(0.001), 'task_timeout'), '等待没有超时');
            self::check(!$delayed->finished() && $database->statistics()['leased'] === 1, '等待超时提前释放连接');
            self::check($delayed->join(new Deadline(1)) && $database->statistics()['leased'] === 0, '任务收尾没有归还租约');
        } finally {
            $waiting->close();
            $database->close();
        }
        self::databaseWait($driver);
        return ['binding-restore', 'snapshot', 'child-transaction', 'connection-owner', 'closed-connection', 'parent-cancel', 'parent-close', 'deadline', 'late-release', 'database-io-wait'];
    }

    /** 真实写锁等待期间让出协程；停止等待不能提前归还仍在执行 SQL 的连接。 */
    private static function databaseWait(Driver $driver): void
    {
        $controller = new Database($driver, 1, 0);
        $worker = new Database($driver, 1, 0);
        $scope = new ExecutionScope();
        $waiting = new ExecutionScope();
        $entered = new Channel(1);
        $connection = $controller->connect($scope);
        try {
            $connection->execute('CREATE TABLE type_scope_io_lock (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
            $connection->execute('INSERT INTO type_scope_io_lock (id, value) VALUES (1, 0)');
            $task = $connection->transaction(static function (Connection $locked) use ($worker, $waiting, $entered): ManagedTask {
                $locked->execute('UPDATE type_scope_io_lock SET value = 1 WHERE id = 1');
                $task = $waiting->spawn(static function (ExecutionScope $child) use ($worker, $entered): int {
                    $contender = $worker->connect($child);
                    // 先完成连接初始化，观察的在途操作只包含等待行锁的 UPDATE。
                    $contender->query('SELECT 1');
                    $entered->push(true);
                    return $contender->execute('UPDATE type_scope_io_lock SET value = value + 1 WHERE id = 1');
                });
                self::check($entered->pop(2) === true, '数据库等待任务没有开始');
                self::check(self::reject(static fn (): mixed => $task->await(0.01), 'task_timeout'), '真实数据库锁等待没有让出协程');
                $statistics = $worker->statistics();
                self::check(!$task->finished() && $statistics['leased'] === 1 && $statistics['in_flight'] === 1, '真实数据库操作完成前丢失租约或在途预算');
                return $task;
            });
            self::check($task->join(new Deadline(2)) && self::reject(static fn (): mixed => $task->await(0.1), 'cancelled'), '释放数据库锁后操作没有按取消状态收尾');
            self::check($worker->statistics()['leased'] === 0 && $worker->statistics()['in_flight'] === 0, '数据库操作收尾后仍占用租约');
            self::check((int) $connection->query('SELECT value FROM type_scope_io_lock WHERE id = 1')[0]['value'] === 2, '数据库等待后发生丢失或重复写入');
        } finally {
            $waiting->close();
            try {
                $connection->execute('DROP TABLE IF EXISTS type_scope_io_lock');
            } finally {
                $scope->close();
                $worker->close();
                $controller->close();
            }
        }
    }

    /** 同一消费程序验证租户 CRUD、全局父模型关系、部分投影及异常边界。 */
    public static function tenants(ExecutionScope $scope, int $userId): void
    {
        $connection = Db::connection('default', true);
        $driver = $connection->driverName();
        $key = match ($driver) {
            'mysql' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'pgsql' => 'BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $date = match ($driver) {
            'mysql' => 'DATETIME(6)', 'pgsql' => 'TIMESTAMP(6)', default => 'TEXT'
        };
        $connection->raw('CREATE TABLE type_suite_scoped_records (id ' . $key . ', tenant_id VARCHAR(50) NOT NULL, title VARCHAR(100) NOT NULL, value INTEGER NOT NULL, deleted_at ' . $date . ' NULL, version BIGINT NOT NULL)');
        $connection->raw('CREATE TABLE type_suite_scoped_labels (id ' . $key . ', owner_ref VARCHAR(50) NOT NULL, scope_id VARCHAR(50) NOT NULL, label VARCHAR(100) NOT NULL)');
        $connection->raw('CREATE TABLE type_suite_scoped_links (user_id INTEGER NOT NULL, record_id INTEGER NOT NULL, tenant_id VARCHAR(50) NOT NULL, PRIMARY KEY (user_id, record_id, tenant_id))');
        self::check(self::reject(static fn (): int => ScopedRecord::query()->count(), 'tenant_scope_required'), '缺失可信租户没有拒绝');
        $untrusted = new ExecutionScope(context: ['tenant_id' => 'tenant-a']);
        try {
            $untrusted->run(static function (ExecutionScope $current): void {
                self::check(self::reject(static fn (): int => ScopedRecord::query()->count(), 'tenant_scope_required'), '关联数据冒充租户身份');
            });
        } finally {
            $untrusted->close();
        }
        $ids = [];
        foreach (['tenant-a', 'tenant-b'] as $tenant) {
            $ids[$tenant] = $scope->run(static function (ExecutionScope $current) use ($tenant, $userId): int {
                $record = ScopedRecord::create(['title' => $tenant, 'value' => 1]);
                self::check($record->isPersisted() && $record->getTenantId() === $tenant, '静态新增未自动填充租户');
                $label = new ScopedLabel(['scope_id' => '普通范围', 'label' => $tenant]);
                $label->save();
                self::check($label->getWorkspace() === $tenant, '特殊租户列没有生效');
                $parent = User::query()->find($userId);
                self::check($parent->relation('records')->attach($record->getId()), '关联未自动填充中间表租户');
                return $record->getId();
            }, ['tenant_id' => $tenant]);
        }
        $scope->run(static function (ExecutionScope $current) use ($ids, $userId): void {
            $base = ScopedRecord::query();
            $rows = ScopedRecord::search(['title' => 'tenant-a'])->equal('title')->query()->paginate(1, 10);
            self::check($rows->total() === 1 && $rows->items()[0]->getId() === $ids['tenant-a'], 'search 分页没有隔离');
            self::check($base->where('title', '=', '缺失')->orWhere('title', '=', 'tenant-b')->count() === 0
                && ScopedRecord::find($ids['tenant-b']) === null
                && ScopedRecord::find($ids['tenant-a'])->getTitle() === 'tenant-a', 'OR 或静态主键读取泄漏');
            self::check(ScopedLabel::query()->count() === 1 && ScopedLabel::query()->first()->getScopeId() === '普通范围', '特殊字段隔离错误');
            $parent = User::query()->with('records')->withCount('records')->find($userId);
            self::check(count($parent->related('records')) === 1 && $parent->computed('records_count') === 1, '全局父模型关系越界');
            self::check(!$parent->relation('records')->detach($ids['tenant-b']), '解绑修改了其他租户');
            self::check(self::reject(static fn (): bool => $parent->relation('records')->attach($ids['tenant-b']), 'related_not_found'), '挂载接受了其他租户目标');
            $parent = User::query()->find($userId);
            self::check($parent->relation('records')->sync([]) === ['attached' => 0, 'detached' => 1, 'updated' => 0], '关系同步未限定租户');
            Db::connection('default', true)->table('type_suite_scoped_links')->insert(['user_id' => $userId, 'record_id' => $ids['tenant-a'], 'tenant_id' => 'tenant-b']);
            self::check(User::query()->with('records')->find($userId)->related('records') === []
                && !User::query()->where('id', '=', $userId)->whereHas('records')->exists(), '中间表自身租户范围失效');
            $partial = $base->select(['title'])->find($ids['tenant-a']);
            self::check(!$partial->loaded('tenant_id'), '投影伪装加载租户列');
            $partial->setTitle('已修改');
            self::check($partial->save() === 'updated' && $partial->getVersion() === 2, '投影丢失原始归属或版本');
            self::check(self::reject(static fn (): mixed => $partial->fill(['title' => '错误', 'tenant_id' => 'tenant-b']), 'tenant_scope_conflict')
                && $partial->getTitle() === '已修改', '冲突赋值发生部分修改');
            self::check(self::reject(static fn (): ScopedRecord => ScopedRecord::create(['tenant_id' => 'tenant-b', 'title' => '冲突', 'value' => 0]), 'tenant_scope_conflict'), '静态新增接受冲突归属');
            self::check(self::reject(static fn (): int => $base->increment('tenant_id'), 'invalid_increment_field'), '原子修改允许改变归属');
            $current->run(static function (ExecutionScope $other) use ($base, $partial): void {
                self::check(self::reject(static fn (): int => $base->count(), 'tenant_context_changed')
                    && self::reject(static fn (): string => $partial->save(), 'tenant_context_changed'), '已有对象静默切换租户');
            }, ['tenant_id' => 'tenant-b']);
            self::check($partial->delete() && $base->count() === 0 && $base->onlyTrashed()->count() === 1, '软删除越界');
            self::check($partial->restore() && $partial->touch() === 'updated' && $partial->forceDelete(), '恢复或强制删除失败');
        }, ['tenant_id' => 'tenant-a']);
        $scope->run(static function (ExecutionScope $current) use ($userId): void {
            self::check(ScopedRecord::query()->count() === 1 && ScopedRecord::query()->first()->getVersion() === 1
                && count(User::query()->with('records')->find($userId)->related('records')) === 1, '其他租户实体或关系被修改');
        }, ['tenant_id' => 'tenant-b']);
        self::check($scope->binding('tenant_id') === null, '租户绑定没有恢复');
    }

    /** 连续租约通过真实服务端身份验证复用；任意 SQL 和触发器遵循相同重置边界。 */
    public static function sessions(Driver $driver): array
    {
        $database = new Database($driver, 1, 1);
        $scope = new ExecutionScope();
        $name = $driver->name();
        $connection = null;
        try {
            $connection = $database->connect($scope);
            if ($name === 'pgsql') {
                self::check(
                    (int) $connection->query('SELECT count(*) AS active FROM pg_prepared_statements')[0]['active'] === 0,
                    '单次参数查询不应创建需要单独释放的服务端命名语句'
                );
            }
            $connection->execute('CREATE TABLE type_session_probe (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
            if ($name === 'pgsql') {
                $connection->execute("CREATE FUNCTION type_session_dirty() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN PERFORM set_config(''TimeZone'', ''Asia/Shanghai'', false); PERFORM pg_advisory_lock(962091); RETURN NEW; END'");
                $connection->execute('CREATE TRIGGER type_session_dirty BEFORE INSERT ON type_session_probe FOR EACH ROW EXECUTE FUNCTION type_session_dirty()');
            }
            $connection->close();
            $identities = [];
            for ($index = 0; $index < 4; $index++) {
                $connection = $database->connect($scope);
                if ($name !== 'sqlite') {
                    $id = $connection->query($name === 'pgsql' ? 'SELECT pg_backend_pid() AS id' : 'SELECT CONNECTION_ID() AS id')[0]['id'];
                    $identities[] = (string) $id;
                }
                $connection->table('type_session_probe')->insert(['id' => $index, 'value' => 1]);
                self::check(
                    $connection->table('type_session_probe')->where('id', '=', $index)->increment('value') === 1
                    && (int) $connection->table('type_session_probe')->where('id', '=', $index)->value('value') === 2,
                    '连续租约的实际 CRUD 失败'
                );
                if ($name === 'pgsql') {
                    // SELECT 调用存储函数和生成 INSERT 的触发器都可能有会话副作用。
                    $connection->query("SELECT set_config('DateStyle', 'SQL, DMY', false)");
                    $connection->rawQuery("SELECT set_config('application_name', 'session-contaminated', false)");
                    $connection->execute('SET search_path TO pg_catalog');
                    $connection->raw('CREATE TEMPORARY TABLE type_session_temp (id INTEGER)');
                } elseif ($name === 'mysql') {
                    $connection->query("SELECT GET_LOCK('type_session_probe_lock', 0)");
                    $connection->raw('SET @type_session_secret = 77');
                    $connection->execute("SET time_zone = '+08:00'");
                    $connection->rawQuery("SELECT GET_LOCK('type_session_raw_lock', 0)");
                } else {
                    $connection->execute('PRAGMA foreign_keys = OFF');
                    $connection->raw('CREATE TEMPORARY TABLE type_session_temp (id INTEGER)');
                    $connection->query('PRAGMA busy_timeout = 0');
                    $connection->rawQuery('PRAGMA query_only = ON');
                }
                $connection->close();
                $connection = $database->connect($scope);
                if ($name === 'pgsql') {
                    $state = $connection->query("SELECT current_setting('TimeZone') AS zone, current_setting('DateStyle') AS style, current_schema() AS schema, current_setting('application_name') AS application, (SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()) AS locks, to_regclass('pg_temp.type_session_temp') AS temporary")[0];
                    self::check(
                        $state['zone'] === 'UTC' && $state['style'] === 'ISO, YMD' && $state['schema'] !== 'pg_catalog'
                        && $state['application'] !== 'session-contaminated' && (int) $state['locks'] === 0 && $state['temporary'] === null,
                        'PostgreSQL 重置没有清除字符串 SQL 或触发器副作用'
                    );
                } elseif ($name === 'mysql') {
                    $state = $connection->query("SELECT @@time_zone AS zone, @type_session_secret AS secret, IS_FREE_LOCK('type_session_probe_lock') AS first_lock, IS_FREE_LOCK('type_session_raw_lock') AS second_lock")[0];
                    self::check(
                        $state['zone'] === '+00:00' && $state['secret'] === null && (int) $state['first_lock'] === 1 && (int) $state['second_lock'] === 1,
                        'MySQL 未退役带变量或命名锁的会话'
                    );
                } else {
                    self::check((int) $connection->query('PRAGMA foreign_keys')[0]['foreign_keys'] === 1
                        && (int) $connection->query('PRAGMA busy_timeout')[0]['timeout'] === $driver->identity()['session']['busy-milliseconds']
                        && (int) $connection->query('PRAGMA query_only')[0]['query_only'] === 0
                        && $connection->query("SELECT name FROM sqlite_temp_master WHERE name = 'type_session_temp'") === [], 'SQLite 会话状态泄漏');
                }
                $connection->table('type_session_probe')->where('id', '=', $index)->delete();
                $connection->close();
            }
            self::check($name === 'sqlite' || count(array_unique($identities)) === 4, '原生 SQL 退役后不应复用物理会话');
            $connection = $database->connect($scope);
            self::check(self::reject(static fn (): array => $connection->query('SELECT * FROM type_session_missing_table')), '数据库错误没有传播');
            $connection->close();
            self::check($database->statistics()['idle'] === 0, '出错的物理会话进入了空闲池');
            $checks = ['crud', 'query', 'execute', 'raw', 'raw-query', 'session-isolation', 'error-retirement'];
            if ($name !== 'sqlite') {
                $control = $driver->connect();
                try {
                    $connection = $database->connect($scope);
                    $terminated = (int) $connection->query($name === 'pgsql' ? 'SELECT pg_backend_pid() AS id' : 'SELECT CONNECTION_ID() AS id')[0]['id'];
                    if ($name === 'pgsql') {
                        $control->exec('SELECT pg_terminate_backend(' . $terminated . ', 1000)');
                    } else {
                        $control->exec('KILL CONNECTION ' . $terminated);
                    }
                    // 不先执行用户 SQL：失效会话必须在归还/重置路径中退役。
                    $connection->close();
                    self::check($database->statistics()['created'] === 0 && $database->statistics()['idle'] === 0, '归还时断连的会话没有退役');
                    if ($name === 'pgsql') {
                        self::check($database->statistics()['cleanup_failures'] === 1, 'PostgreSQL 重置失败未被记录');
                        $checks[] = 'reset-failure-retirement';
                    }
                    $connection = $database->connect($scope);
                    $replacement = (int) $connection->query($name === 'pgsql' ? 'SELECT pg_backend_pid() AS id' : 'SELECT CONNECTION_ID() AS id')[0]['id'];
                    self::check($replacement !== $terminated, '断连后借出了相同物理会话');
                    $connection->close();
                    if ($name === 'pgsql') {
                        $control->exec('SELECT pg_terminate_backend(' . $replacement . ', 1000)');
                        $connection = $database->connect($scope);
                        self::check(self::reject(static fn (): array => $connection->query('SELECT 7 AS value')), '失效空闲连接发生透明重试');
                        $connection->close();
                        self::check($database->statistics()['created'] === 0, '失效空闲连接未在失败后回收');
                    }
                    $connection = $database->connect($scope);
                    self::check((int) $connection->query('SELECT 7 AS value')[0]['value'] === 7, '断连回收后无法建立新会话');
                    $connection->close();
                    $checks[] = 'disconnect-retirement';
                } finally {
                    $control = null;
                }
            }
            $manager = new DatabaseManager(['default' => $driver], 1, 1);
            try {
                $old = $manager->connect($scope);
                $manager->rotate('default', DriverFactory::create('writer', 2));
                $next = $manager->connect($scope);
                self::check($old->identity()['credential-generation'] === 1 && $next->identity()['credential-generation'] === 2
                    && (int) $old->query('SELECT 1 AS value')[0]['value'] === 1
                    && (int) $next->query('SELECT 2 AS value')[0]['value'] === 2, '轮换没有保持旧租约并启用新代次');
                self::check(self::reject(static function () use ($manager): void {
                    $manager->rotate('default', DriverFactory::create('writer', 2));
                }), '轮换允许重用凭据代次');
                $old->close();
                self::check(($manager->statistics()['retired-generations']['default'] ?? 0) === 0, '旧凭据代次归还后仍可复用');
                $next->close();
                $checks[] = 'credential-generation';
            } finally {
                $manager->close();
            }
            return ['driver' => $name, 'physical_reuse' => $name === 'pgsql', 'connection_ids' => $identities,
                'checks' => $checks];
        } finally {
            $connection?->close();
            try {
                $cleanup = $database->connect($scope);
                $cleanup->execute('DROP TABLE IF EXISTS type_session_probe');
                if ($name === 'pgsql') {
                    $cleanup->execute('DROP FUNCTION IF EXISTS type_session_dirty()');
                }
                $cleanup->close();
            } finally {
                $scope->close();
                $database->close();
            }
        }
    }

    /** 在已有业务数据上验证模型脏字段、持久状态、字段保护和集合查询行为。 */
    public static function run(Connection $connection, int $userId, int $articleId): void
    {
        $user = User::query()->findOrFail($userId);
        self::check($user->name === '用户甲' && $user->displayLabel() === '用户：用户甲', '转换没有保留属性或业务方法');
        $user->name = '属性修改';
        self::check($user->dirty() === ['name' => '属性修改'], '属性修改绕过了变更追踪');
        $user->name = '用户甲';
        self::check($user->dirty() === [], '还原属性没有清除变更');
        self::check(self::reject(static function () use ($user): void {
            $user->name = [];
        }), '错误属性类型被接受');
        self::check(self::reject(static function () use ($user): void {
            $reference = &$user->name;
        }), '虚拟属性允许取引用');
        self::check(self::reject(static function () use ($user): void {
            $user->profile['tier'] = 'changed';
        }), 'JSON 属性允许间接修改');
        self::check(self::reject(static fn () => self::modifyTypedArray($user)), '明确模型类型的 JSON 属性允许间接修改');
        self::check(self::reject(static fn () => self::referenceTypedProperty($user)), '明确模型类型的属性允许取引用');
        self::check(self::reject(static fn () => self::unsetTypedArray($user)), '明确模型类型的 JSON 属性允许间接删除');
        self::check(self::reject(static function () use ($user): void {
            unset($user->profile['tier']);
        }), 'JSON 属性允许间接删除');
        self::check(self::reject(static function () use ($user): void {
            $user->profile[] = 'changed';
        }), 'JSON 属性允许间接追加');
        $profile = $user->profile;
        $profile['tier'] = 'changed';
        $user->profile = $profile;
        self::check(array_key_exists('profile', $user->dirty()), 'JSON 整值赋回没有登记变更');
        self::check(!str_contains(json_encode($user, JSON_THROW_ON_ERROR), '仅供内部'), 'JSON 输出泄露隐藏字段');
        $partial = User::query()->select(['name'])->findOrFail($userId);
        self::check(self::reject(static fn () => $partial->active, 'field_not_loaded'), '未加载属性被当作 null');
        self::check(self::reject(static fn () => $partial->articles, 'relation_not_loaded'), '关系属性触发了隐式读取');
        $post = Article::query()->findOrFail($articleId);
        self::check($post->deleted_at === null && self::reject(static function () use ($post): void {
            $post->version = 100;
        }, 'field_not_fillable'), 'null 或只读生命周期属性错误');
        self::check(self::reject(static function () use ($post): void {
            $post->id = 100;
        }, 'field_not_fillable'), '持久化主键允许修改');

        $base = Article::query();
        self::check(
            self::reject(static fn () => $base->scope(static fn (ModelQuery $query): ModelQuery => User::query()), 'invalid_scope')
            && self::reject(static fn () => $base->search(['user' => 1], ['user' => static fn (ModelQuery $query, mixed $value): ModelQuery => User::query()]), 'invalid_searcher'),
            '范围或搜索器允许替换模型查询'
        );
        $grouped = $base->whereGroup(static fn (ModelConditions $conditions): ModelConditions => $conditions->where('status', '=', 'published')
            ->orWhere('title', '=', '草稿篇'))->whereNotIn('id', []);
        self::check($grouped->count() === 3 && $base->whereNull('deleted_at')->count() === 3, '模型条件组或 NULL/NOT IN 错误');
        self::check($base->where('status', '=', 'draft')->orWhere('views', '=', 10)->count() === 2, '模型 OR 映射错误');
        self::check(User::query()->whereJson('profile', ['enabled'], true)->exists(), '模型 JSON 标量条件错误');
        self::check($base->orderBy('id')->value('views') === 10 && count($base->pluck('title', 'id')) === 3, '模型值提取或类型转换错误');
        self::check(self::reject(static fn () => $base->findOrFail(-1), 'not_found') && !$base->whereIn('id', [])->exists(), '模型缺失语义错误');
        $before = $connection->statistics();
        self::check(str_contains($grouped->toSql(), 'SELECT') && count($grouped->bindings()) === 2 && $connection->statistics() === $before, 'SQL 预览执行了数据库读取');

        $users = User::query();
        $counted = $users->whereHas('articles.tags', static fn (ModelQuery $query): ModelQuery => $query->where('label', '=', '框架'))
            ->withCount('articles')->withCount('articles.tags')->withSum('articles', 'views')->findOrFail($userId);
        self::check($counted->computed('articles_count') === 3 && $counted->computed('articles_tags_count') === 2
            && (int) $counted->computed('articles_views_sum') === 10 && !$counted->relationLoaded('articles'), '数据库端关系过滤或统计错误');
        self::check($counted->dirty() === [] && !array_key_exists('articles_count', $counted->toArray())
            && $counted->project(['name'], [], ['articles_count'])['articles_count'] === 3, '计算值污染持久化或隐式输出');
        self::check(self::reject(static fn () => $counted->set('articles_count', 8), 'unknown_field'), '计算值允许作为字段保存');
        self::check(
            $users->whereDoesntHave('articles.tags', static fn (ModelQuery $query): ModelQuery => $query->where('label', '=', '不存在'))->count() === 1,
            '嵌套不存在过滤错误'
        );
        self::check($users->where('id', '=', -1)->whereHas('articles')->count() === 0, '关系过滤丢失父查询范围');
        self::check($users->whereHas('articles', static fn (ModelQuery $query): ModelQuery => $query->whereHas('tags'))
            ->whereHas('articles.author.articles')->count() === 1, '嵌套回调或循环关系路径的别名发生遮蔽');
        self::check($users->withCount('articles', static fn (ModelQuery $query): ModelQuery => $query->where('status', '=', 'draft'), 'drafts')
            ->firstOrFail()->computed('drafts') === 1, '统计丢失显式子查询约束');
        $post->delete();
        self::check(
            $users->withCount('articles')->firstOrFail()->computed('articles_count') === 2
            && $users->whereHas('articles.tags')->count() === 0
            && $users->withCount('articles', static fn (ModelQuery $query): ModelQuery => $query->withTrashed(), 'all_posts')->firstOrFail()->computed('all_posts') === 3,
            '关系过滤或统计丢失软删除范围'
        );
        $post->restore();
        $creditQuery = $users->where('id', '=', $userId);
        if ($connection->driverName() === 'sqlite') {
            self::check(self::reject(static fn () => $creditQuery->increment('credit'), 'exact_arithmetic_unsupported'), 'SQLite 对精确文本执行了隐式算术');
            self::check(self::reject(static fn () => $base->withSum('author', 'credit')->get(), 'exact_sum_unsupported'), 'SQLite 对精确文本执行了不精确求和');
        } else {
            self::check($base->withSum('author', 'credit')->firstOrFail()->computed('author_credit_sum') === $user->credit, '关系统计损失精确小数');
            self::check($creditQuery->increment('credit') === 1 && $creditQuery->decrement('credit') === 1
                && $creditQuery->firstOrFail()->credit === $user->credit, '精确数值列的原子算术损失精度');
        }
        $loaded = $users->with('articles.tags')->with('articles.author')->with('details')->findOrFail($userId);
        self::check(count($loaded->articles) === 3 && count($loaded->articles[0]->tags) === 2 && $loaded->details->bio === '个人简介', '声明关系预加载或属性访问错误');
        self::check($loaded->articles[0]->author->id === $userId, '相同根关系的多条路径没有合并');
        $shallow = $users->with('articles')->findOrFail($userId);
        $retained = $shallow->articles[0];
        $users->with('articles.tags')->loadMissing([$shallow]);
        self::check($shallow->articles[0] === $retained && count($retained->tags) === 2, '深层补加载重复水合了已有子模型');
        $list = [$users->findOrFail($userId), $users->findOrFail($userId)];
        $users->with('articles.tags')->load($list);
        self::check(count($list[0]->articles[0]->tags) === 2 && count($list[1]->articles) === 3, '列表补加载没有复用批量关系');
        $before = $connection->statistics();
        $users->with('articles')->load($list, true);
        self::check($connection->statistics() === $before, '只补缺失关系仍然重复查询');
        self::check(self::reject(static fn () => $users->with('articles')->load($list, false, 2), 'read_budget_exceeded'), '列表补加载没有执行共享预算');

        self::queries($connection, $userId, $articleId);
        self::diagnostics($connection, $articleId);
        $snapshot = Article::query()->findOrFail($articleId);
        self::check($base->where('id', '=', $articleId)->increment('views', 2) === 1 && $base->findOrFail($articleId)->views === 12, '原子自增错误');
        $snapshot->views = 90;
        self::check(self::reject(static fn () => $snapshot->save(), 'optimistic_conflict'), '原子自增没有使旧版本过期');
        self::check(
            $base->where('id', '=', $articleId)->decrement('views', 2) === 1 && $base->findOrFail($articleId)->views === 10,
            '原子自减或数据库影响行数错误'
        );
        self::check(
            self::reject(static fn () => $base->increment('views')) && self::reject(static fn () => $base->whereNotIn('id', [])->increment('views')),
            '默认软删除范围或恒真条件绕过原子写入保护'
        );
        $rollback = null;
        try {
            $connection->transaction(static function (Connection $transaction) use ($articleId, &$rollback): void {
                $rollback = Article::query()->findOrFail($articleId);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        self::check(self::reject(static fn () => $rollback->title, 'model_invalid'), '属性读取绕过回滚失效');
    }

    private static function modifyTypedArray(User $user): void
    {
        $user->profile['tier'] = 'changed';
    }

    private static function unsetTypedArray(User $user): void
    {
        unset($user->profile['tier']);
    }

    private static function referenceTypedProperty(User $user): void
    {
        $reference = &$user->name;
    }

    private static function queries(Connection $connection, int $userId, int $articleId): void
    {
        $posts = $connection->table('type_suite_articles');
        $users = $connection->table('type_suite_users');
        $otherScope = new ExecutionScope();
        $otherDatabase = new Database(DriverFactory::create(), 1, 0);
        try {
            $other = $otherDatabase->connect($otherScope)->table('type_suite_articles')->select(['id']);
            self::check(
                self::reject(static fn () => $users->whereIn('id', $other))
                && self::reject(static fn () => $users->whereExists($other))
                && self::reject(static fn () => $users->selectSub($other, 'other'))
                && self::reject(static fn () => $users->union($other))
                && self::reject(static fn () => $users->joinSub($other, 'other', 'id', '=', 'other.id')),
                '子查询组合接受了不同连接'
            );
        } finally {
            $otherScope->close();
            $otherDatabase->close();
        }
        $sub = $posts->where('status', '=', 'published')->select(['user_id']);
        self::check(count($users->whereIn('id', $sub)->get()) === 1 && $users->whereNotIn('id', $sub)->get() === [], 'IN 子查询语义错误');
        $outer = $connection->table('type_suite_users', 'u');
        $correlated = $connection->table('type_suite_articles', 'a')->whereColumn('a.user_id', '=', 'u.id')->where('status', '=', 'draft');
        self::check(count($outer->whereExists($correlated)->get()) === 1 && $outer->whereNotExists($correlated)->get() === [], '列比较或 EXISTS 错误');
        $scalar = $outer->select(['u.id'])->selectSub($correlated->aggregateQuery('COUNT'), 'drafts')->where('u.id', '=', $userId);
        $before = $connection->statistics();
        self::check($scalar->bindings() === ['draft', $userId] && $connection->statistics() === $before, '标量子查询绑定顺序或预览副作用错误');
        self::check((int) $scalar->first()['drafts'] === 1, '标量相关子查询执行错误');
        $derived = Query::fromSub($posts->where('views', '>=', 0)->select(['id', 'user_id']), 'p')->where('p.id', '=', $articleId);
        self::check($derived->bindings() === [0, $articleId] && count($derived->get()) === 1, '派生表绑定顺序错误');
        $joined = $outer->joinSub($sub, 'p', 'u.id', '=', 'p.user_id')->select(['id' => 'u.id']);
        self::check(count($joined->get()) === 2 && count($joined->distinct()->get()) === 1, '子查询联表或 DISTINCT 错误');
        self::check(self::reject(static fn () => $joined->paginate()), '复杂分页未要求唯一排序键');
        $page = $joined->distinct()->uniqueOrderBy(['id'])->paginate(1, 1);
        self::check($page->total() === 1 && count($page->items()) === 1 && !$page->hasMore(), 'DISTINCT 总数不是实际结果行数');
        $duplicates = $outer->join('type_suite_articles', 'u.id', '=', 'a.user_id', 'a')->select(['user_id' => 'u.id', 'article_id' => 'a.id'])
            ->uniqueOrderBy(['article_id'])->paginate(2, 2);
        self::check($duplicates->total() === 3 && count($duplicates->items()) === 1, '联表重复父记录错误去重');
        $groups = $posts->select(['status'])->selectAggregate('COUNT', '*', 'total')->groupBy(['status'])->uniqueOrderBy(['status'])->paginate(1, 1);
        self::check($groups->total() === 2 && $groups->hasMore(), '分组分页没有计算实际分组数量');
        $union = $posts->where('id', '=', $articleId)->select(['id'])->unionAll($posts->where('id', '=', $articleId)->select(['id']));
        self::check(
            count($union->get()) === 2 && count($posts->where('id', '=', $articleId)->select(['id'])->union($posts->where('id', '=', $articleId)->select(['id']))->get()) === 1,
            'UNION 与 UNION ALL 没有保持重复行语义'
        );
        $uniqueUnion = $posts->where('id', '=', $articleId)->select(['id'])
            ->unionAll($posts->where('id', '!=', $articleId)->select(['id']));
        self::check($uniqueUnion->uniqueOrderBy(['id'])->paginate(2, 1)->total() === 3, '合并查询总数错误');
        self::check(
            count($uniqueUnion->pluck('id')) === 3 && (int) $uniqueUnion->orderBy('id')->value('id') === $articleId,
            '合并查询值提取改变了分支投影'
        );
        $simple = Article::query()->simplePaginate(2, 2);
        self::check(count($simple->items()) === 1 && !$simple->hasMore() && Article::query()->simplePaginate(1, 2)->hasMore(), '无总数分页边界错误');
        self::check($posts->simplePaginate(4, 2)->items() === [] && $posts->where('id', '=', -1)->increment('views') === 0, '空页或零影响行数错误');
    }

    private static function diagnostics(Connection $connection, int $articleId): void
    {
        $scope = new ExecutionScope();
        $log = $connection->listen($scope, null, 5, 4096, false, 0.0);
        $throwing = $connection->listen($scope, static function (QueryEvent $event): void {
            throw new RuntimeException('listener-secret');
        }, 5, 4096);
        try {
            $connection->query('SELECT ? AS secret', ['private-binding']);
            self::check(self::reject(static fn () => $connection->query('SELECT missing FROM type_missing_table')), '无效 SQL 没有失败');
            $records = $log->records();
            self::check(count($records) === 2 && $records[0]['success'] && !$records[1]['success'] && $records[0]['slow']
                && $records[0]['duration_ms'] >= 0 && $records[0]['redacted'] && !str_contains(json_encode($records), 'private-binding'), '查询诊断状态、耗时或脱敏错误');
            $connection->transaction(static fn (Connection $transaction): int => $transaction->table('type_suite_articles')->where('id', '=', $articleId)->increment('views'));
            self::check(
                $connection->transactionOutcome() === TransactionOutcome::COMMITTED && $throwing->statistics()['listener_failures'] === 3,
                '监听异常改变了提交结果或没有单独登记'
            );
            $last = $log->records()[2];
            self::check($last['transaction_depth'] === 1 && $last['transaction_outcome'] === TransactionOutcome::ACTIVE, '事件没有携带真实事务状态');
            $connection->table('type_suite_articles')->where('id', '=', $articleId)->decrement('views');
            for ($index = 0; $index < 10; $index++) {
                $connection->query('SELECT ? AS n', [$index]);
            }
            self::check(
                $log->statistics()['records'] === 5 && $log->statistics()['bytes'] <= 4096 && $log->statistics()['dropped'] > 0,
                '查询记录没有遵守数量和字节上限'
            );
            $small = $connection->listen($scope, null, 100, 512, true);
            $connection->query('SELECT ? AS payload', [str_repeat('x', 3000)]);
            self::check($small->statistics()['bytes'] <= 512 && $small->statistics()['dropped'] === 1, '过大诊断记录没有按字节预算丢弃');
            $values = $connection->listen($scope, null, 2, 8192, true);
            $connection->query('SELECT ? AS payload', [str_repeat('x', 3000)]);
            self::check(
                $values->records()[0]['truncated'] && strlen($values->records()[0]['parameters'][0]) === 2048,
                '显式参数预览没有报告值截断'
            );
            $recursive = $connection->listen($scope, static fn (QueryEvent $event): array => $connection->query('SELECT 1'));
            $connection->query('SELECT 1');
            self::check($recursive->statistics()['listener_failures'] === 1, '查询监听重入没有单独报告失败');
        } finally {
            $scope->close();
        }
        $before = $log->statistics();
        $connection->query('SELECT 1');
        self::check($log->statistics() === $before && !$before['active'], '作用域结束后仍然监听连接');
    }

    private static function reject(Closure $operation, string $code = ''): bool
    {
        try {
            $operation();
        } catch (ModelException $error) {
            return $code === '' || $error->errorCode() === $code;
        } catch (TaskException $error) {
            return $code === '' || $error->errorCode() === $code;
        } catch (Throwable) {
            return $code === '';
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
