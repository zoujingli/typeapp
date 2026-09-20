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
use Type\Orm\DatabaseException;
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
                    });
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
                $record = new ScopedRecord(['title' => $tenant, 'value' => 1]);
                self::check($record->save() === 'created' && $record->getTenantId() === $tenant, '租户新增未自动填充');
                $label = new ScopedLabel(['scope_id' => '普通范围', 'label' => $tenant]);
                $label->save();
                self::check($label->getWorkspace() === $tenant, '特殊租户列没有生效');
                $parent = User::query()->find($userId);
                self::check($parent->definition()->relation('records')->loader()->attach($parent, $record->getId()), '关联未自动填充中间表租户');
                return $record->getId();
            }, ['tenant_id' => $tenant]);
        }
        $scope->run(static function (ExecutionScope $current) use ($ids, $userId): void {
            $base = ScopedRecord::query();
            $rows = ScopedRecord::search(['title' => 'tenant-a'])->equal('title')->query()->paginate(1, 10);
            self::check($rows->total() === 1 && $rows->items()[0]->getId() === $ids['tenant-a'], 'search 分页没有隔离');
            self::check($base->where('title', '=', '缺失')->orWhere('title', '=', 'tenant-b')->count() === 0
                && $base->find($ids['tenant-b']) === null, 'OR 或主键读取泄漏');
            self::check(ScopedLabel::query()->count() === 1 && ScopedLabel::query()->first()->getScopeId() === '普通范围', '特殊字段隔离错误');
            $parent = User::query()->with('records')->withCount('records')->find($userId);
            self::check(count($parent->related('records')) === 1 && $parent->computed('records_count') === 1, '全局父模型关系越界');
            $links = $parent->definition()->relation('records')->loader();
            self::check(!$links->detach($parent, $ids['tenant-b']), '解绑修改了其他租户');
            self::check(self::reject(static fn (): bool => $links->attach($parent, $ids['tenant-b']), 'related_not_found'), '挂载接受了其他租户目标');
            $parent = User::query()->find($userId);
            self::check($links->sync($parent, []) === ['attached' => 0, 'detached' => 1, 'updated' => 0], '关系同步未限定租户');
            Db::connection('default', true)->table('type_suite_scoped_links')->insert(['user_id' => $userId, 'record_id' => $ids['tenant-a'], 'tenant_id' => 'tenant-b']);
            self::check(User::query()->with('records')->find($userId)->related('records') === []
                && !User::query()->where('id', '=', $userId)->whereHas('records')->exists(), '中间表自身租户范围失效');
            $partial = $base->select(['title'])->find($ids['tenant-a']);
            self::check(!$partial->loaded('tenant_id'), '投影伪装加载租户列');
            $partial->setTitle('已修改');
            self::check($partial->save() === 'updated' && $partial->getVersion() === 2, '投影丢失原始归属或版本');
            self::check(self::reject(static fn (): mixed => $partial->fill(['title' => '错误', 'tenant_id' => 'tenant-b']), 'tenant_scope_conflict')
                && $partial->getTitle() === '已修改', '冲突赋值发生部分修改');
            self::check(self::reject(static fn (): ScopedRecord => new ScopedRecord(['tenant_id' => 'tenant-b']), 'tenant_scope_conflict'), '新增接受冲突归属');
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
            self::check($name === 'sqlite' || count(array_unique($identities)) === ($name === 'pgsql' ? 1 : 4), '物理连接身份与驱动重置能力不一致');
            $connection = $database->connect($scope);
            self::check(self::reject(static fn (): array => $connection->query('SELECT * FROM type_session_missing_table')), '数据库错误没有传播');
            $connection->close();
            self::check($database->statistics()['idle'] === 0, '出错的物理会话进入了空闲池');
            return ['driver' => $name, 'physical_reuse' => $name === 'pgsql', 'connection_ids' => $identities,
                'checks' => ['crud', 'query', 'execute', 'raw', 'raw-query', 'session-isolation', 'error-retirement']];
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
