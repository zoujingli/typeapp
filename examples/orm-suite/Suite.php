<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\DatabaseException;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\MigrationException;
use Type\Orm\Migration\Migrator;
use Type\Orm\ModelBehavior;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Orm\Relation;

/** 一个业务流程，三种安装仅替换 DriverFactory 与声明式物理存储。 */
final class Suite
{
    public static function run(): array
    {
        return CoroutineRuntime::run(static fn (): array => self::execute());
    }

    private static function execute(): array
    {
        $driver = DriverFactory::create();
        $scopes = CoreExercise::scopes($driver);
        $sessions = CoreExercise::sessions($driver);
        $plan = Schema::plan($driver->name());
        $migrator = new Migrator($driver, 'type_suite_migrations');
        self::check(array_column($migrator->status($plan), 'state') === ['pending', 'pending'], '新消费环境不是空迁移状态');
        self::check(array_column($migrator->run($plan), 'state') === ['applied', 'applied'], '业务迁移没有完成');
        self::check(array_column($migrator->run($plan), 'attempts') === [1, 1] && count($migrator->history()) === 4, '重复迁移再次执行了已完成内容');
        $changed = new Migration($plan[0]->version(), '修改历史', $plan[0]->statements(), $plan[0]->transactional());
        self::check(self::reject(static fn () => $migrator->status([$changed, $plan[1]]), 'TYPE_MIGRATION_CHANGED'), '历史迁移变化未拒绝');
        $manager = new DatabaseManager(['default' => $driver, 'archive' => DriverFactory::create()], 4, 1);
        Db::configure($manager);
        $scope = new ExecutionScope();
        try {
            return $scope->run(static function (ExecutionScope $current) use ($manager, $driver, $sessions, $scopes): array {
                $lazy = User::query();
                self::check($manager->statistics()['active'] === [], '构造查询提前借用了连接');
                $connection = Db::connection('default', true);
                $user = User::create(['name' => '用户甲', 'active' => true, 'external_id' => '123456789012345678901234567890',
                    'credit' => '12345678901234567890.12', 'profile' => ['tier' => 'gold', 'enabled' => true, 'number' => 1],
                    'joined_at' => new DateTimeImmutable('2026-09-09T08:30:01.123456+08:00'), 'secret' => '仅供内部']);
                self::check($user->isPersisted() && $user->getId() > 0, '静态模型创建或主键返回错误');
                $freshUser = User::find($user->getId());
                self::check(User::find(2147483647) === null
                    && self::reject(static fn () => User::create(['unknown' => true]), 'unknown_field')
                    && self::reject(static fn () => User::create(['name' => 1]), 'invalid_field_type')
                    && self::reject(static fn () => User::create([]), 'required_field')
                    && self::reject(static fn () => Article::create(['version' => 99]), 'field_not_fillable'), '静态 CRUD 没有保持未命中或严格赋值边界');
                $profile = $freshUser->getProfile();
                self::check($freshUser->getExternalId() === '123456789012345678901234567890' && $freshUser->getCredit() === '12345678901234567890.12'
                    && $freshUser->getActive() === true && count($profile) === 3 && $profile['tier'] === 'gold' && $profile['enabled'] === true && $profile['number'] === 1
                    && $freshUser->getJoinedAt()->format('Y-m-d H:i:s.uP') === '2026-09-09 00:30:01.123456+00:00'
                    && !array_key_exists('secret', $freshUser->toArray()), '模型精确类型、JSON、时间或隐藏字段不一致');
                (new Details(['user_id' => $user->getId(), 'bio' => '个人简介']))->save();
                $observer = new ArticleObserver();
                $behavior = (new ModelBehavior())->observe($observer)->setter('title', static fn (mixed $value): mixed => trim((string) $value));
                $article = new Article(['user_id' => $user->getId(), 'title' => ' 第一篇 ', 'status' => 'published', 'views' => 0], false, $behavior);
                $article->save();
                self::check($article->getTitle() === '第一篇' && $observer->events() === ['saving', 'creating', 'created', 'saved'], '创建事件或修改器没有贯通');
                $second = new Article(['user_id' => $user->getId(), 'title' => '草稿篇', 'status' => 'draft', 'views' => 0]);
                $second->save();
                (new Article(['user_id' => $user->getId(), 'title' => '第三篇', 'status' => 'published', 'views' => 0]))->save();
                $cancelled = new Article(['user_id' => $user->getId(), 'title' => '取消发布', 'status' => 'draft', 'views' => 0], false, $behavior);
                self::check($cancelled->save() === 'cancelled' && !$cancelled->isPersisted(), '取消事件没有阻止新增');
                $one = new Tag(['label' => '框架']);
                $one->save();
                $two = new Tag(['label' => '数据库']);
                $two->save();
                $tags = Relation::belongsToMany(static fn (Connection $connection): ModelQuery => Tag::query()->onConnection($connection), 'type_suite_article_tags', 'article_id', 'tag_id', 'id', 'id', ['weight'], 2);
                self::check($tags->attach($article, $one->getId(), ['weight' => 10])
                    && !$tags->attach($article, $one->getId(), ['weight' => 11]), '重复标签挂载没有保持唯一关系');
                $sync = $tags->sync($article, [['id' => $one->getId(), 'pivot' => ['weight' => 11]], ['id' => $two->getId(), 'pivot' => ['weight' => 20]]]);
                self::check($sync === ['attached' => 1, 'detached' => 0, 'updated' => 1], '标签整组同步错误');
                $posts = Relation::hasMany(static fn (Connection $connection): ModelQuery => Article::query()->onConnection($connection)->with('tags', $tags), 'user_id', 'id', 2);
                $details = Relation::hasOne(static fn (Connection $connection): ModelQuery => Details::query()->onConnection($connection), 'user_id');
                $author = Relation::belongsTo(static fn (Connection $connection): ModelQuery => User::query()->onConnection($connection)->select(['name']), 'user_id');
                $view = User::query()->select(['name'])->with('articles', $posts)->with('details', $details)->cursorPaginate(2, null, 30)->items()[0];
                self::check(count($view->related('articles')) === 3 && $view->related('details')->getBio() === '个人简介'
                    && count($view->related('articles')[0]->related('tags')) === 2 && $view->related('articles')[0]->related('tags')[1]->pivot() === ['weight' => 20], '一对一、一对多或多对多预加载错误');
                self::check(Article::query()->with('author', $author)->find($article->getId())->related('author')->getName() === '用户甲', '文章作者关系错误');
                $base = Article::query();
                $published = $base->scope(static fn (ModelQuery $query): ModelQuery => $query->where('status', '=', 'published'));
                self::check($published->count() === 2 && $base->count() === 3 && $base->paginate(2, 2)->items()[0]->getTitle() === '第三篇', '查询范围污染共享查询或普通分页错误');
                $page = $published->cursorPaginate(1);
                self::check($page->hasMore() && $published->cursorPaginate(1, $page->next())->items()[0]->getTitle() === '第三篇', '模型游标分页错误');
                self::check($base->with('tags', $tags)->chunk(2, static fn (array $models): bool => count($models) <= 2, 20) === 3, '有界业务导出不完整');
                $stale = Article::query()->find($article->getId());
                $article->setViews(10);
                $article->save();
                $stale->setViews(20);
                self::check(self::reject(static fn () => $stale->save(), 'optimistic_conflict')
                    && Article::query()->find($article->getId())->getViews() === 10, '过期版本覆盖最新文章');
                $observer->clear();
                self::check($article->delete() && $base->find($article->getId()) === null
                    && $base->onlyTrashed()->count() === 1 && $base->withTrashed()->count() === 3
                    && $observer->events() === ['deleting', 'deleted'], '软删除过滤或事件错误');
                $observer->clear();
                self::check($article->restore() && $base->count() === 3 && $observer->events() === ['restoring', 'restored'], '恢复文章失败');
                $rolledBack = null;
                try {
                    Db::transaction(static function () use ($user, &$rolledBack): void {
                        self::check(self::reject(static fn () => Db::connection('archive'), 'cross_database_transaction'), '事务内访问另一逻辑库');
                        $rolledBack = new Article(['user_id' => $user->getId(), 'title' => '不应保存', 'status' => 'draft', 'views' => 0]);
                        $rolledBack->save();
                        throw new RuntimeException('撤销发布');
                    });
                } catch (RuntimeException $error) {
                    self::check($error->getMessage() === '撤销发布', '事务错误丢失');
                }
                self::check($base->count() === 3 && self::reject(static fn () => $rolledBack->getTitle(), 'model_invalid'), '回滚没有同步模型失效');
                CoreExercise::run($connection, $user->id, $article->id);
                CoreExercise::tenants($current, $user->getId());
                $capabilities = self::capabilities($manager, $connection, $article->getId());
                self::check(User::query()->master()->find($user->getId())->getName() === '用户甲'
                    && Db::connection() === $connection, '未配置副本时没有使用主库');
                $helper = User::search(['active' => true, 'name' => '用户甲'])->equal('active')->like('name');
                self::check($helper instanceof \Type\Orm\Helper\QueryHelper && $helper->query()->firstOrFail()->getId() === $user->getId(), '静态 search 与模型水合不一致');
                $unfiltered = User::search(['enabled' => true, 'keyword' => '用户甲', 'sort' => 'name', 'page' => 1, 'page_size' => 1]);
                $filtered = $unfiltered->equal(['enabled' => 'active'])->like(['keyword' => 'name'])->order(['name']);
                self::check($filtered->query()->firstOrFail()->getId() === $user->getId()
                    && User::search(['keyword' => '用户甲'], 'member')->like(['keyword' => 'name'])->query()->firstOrFail()->getId() === $user->getId()
                    && $filtered->paginatePage()->items()[0]->getId() === $user->getId()
                    && self::reject(static fn () => $unfiltered->query(), 'unknown_search_field'), '筛选映射、别名、排序分页或不可变声明错误');
                foreach ([['actvie' => true], ['unexpected' => ''], ['unexpected' => null], ['unexpected' => false], ['unexpected' => 0]] as $unknownInput) {
                    $unknown = User::search($unknownInput)->equal('active');
                    self::check(self::reject(static fn () => $unknown->query(), 'unknown_search_field')
                        && self::reject(static fn () => $unknown->paginatePage(), 'unknown_search_field'), '模型筛选静默忽略了未知键');
                }
                self::check(User::search(['active' => false])->equal('active')->query()->count() === 0
                    && User::search(['id' => 0])->equal('id')->query()->count() === 0
                    && User::search(['active' => ''])->equal('active')->query()->count() === 1
                    && Article::search(['deleted_at' => null])->equal('deleted_at')->query()->count() === 3, '已声明筛选丢失空串、零值、布尔或空值语义');
                $race = new Article(['user_id' => $user->getId(), 'title' => '并发更新', 'status' => 'published', 'views' => 0]);
                $race->save();
                return ['driver' => $driver->name(), 'version' => $connection->serverVersion(), 'scope_checks' => $scopes, 'sessions' => $sessions, 'race_id' => $race->getId(),
                    'models' => true, 'relations' => true, 'soft_delete' => true, 'events' => true, 'scopes' => true, 'core_queries' => true,
                    'pagination' => true, 'optimistic_lock' => true, 'migrations' => true, 'strong_read' => true, 'tenant_isolation' => true, 'capabilities' => $capabilities];
            });
        } finally {
            $scope->close();
            $manager->close();
        }
    }

    private static function capabilities(DatabaseManager $manager, Connection $connection, int $articleId): array
    {
        $query = $connection->table('type_suite_articles')->where('id', '=', $articleId);
        $capabilities = $query->capabilities();
        if ($capabilities['row-lock']) {
            self::check(self::reject(static fn () => $query->lockForUpdate()->get()), '事务外行锁被静默释放');
            self::check(self::reject(static fn () => $query->lockForUpdate()->aggregate('COUNT'))
                && self::reject(static fn () => $query->lockForUpdate()->paginate())
                && self::reject(static fn () => $query->lockForUpdate()->update(['views' => 999])), '聚合、分页或写入静默丢弃了行锁意图');
            $scope = new ExecutionScope();
            try {
                $other = $manager->connect($scope, 'default');
                $connection->transaction(static function (Connection $transaction) use ($query, $other, $articleId): void {
                    self::check((int) $query->lockForUpdate()->first()['id'] === $articleId, '没有取得文章行锁');
                    $other->transaction(static function (Connection $transaction) use ($other, $articleId): void {
                        self::check($other->table('type_suite_articles')->where('id', '=', $articleId)->lockForUpdate(true)->get() === [], 'SKIP LOCKED 没有跳过另一个连接持有的锁');
                    });
                });
                $other->transaction(static function (Connection $transaction) use ($other, $articleId): void {
                    self::check(count($other->table('type_suite_articles')->where('id', '=', $articleId)->lockForUpdate(true)->get()) === 1, '外层事务提交后行锁没有释放');
                });
            } finally {
                $scope->close();
            }
        } else {
            self::check(self::reject(static fn () => $query->lockForUpdate()), '不支持的行锁没有拒绝');
        }
        self::check(self::reject(static fn () => $connection->transaction(static fn (Connection $transaction): mixed => null, 'serializable')), '未实现的事务隔离设置被静默接受');
        $users = $connection->table('type_suite_users');
        self::check(count($users->whereJson('profile', ['enabled'], true)->get()) === 1
            && $users->whereJson('profile', ['number'], '1')->get() === []
            && self::reject(static fn () => $users->whereJson('profile', ['tier'], ['gold'])), 'JSON 类型或能力拒绝错误');
        $tags = $connection->table('type_suite_tags');
        if ($capabilities['insert-returning']) {
            self::check($tags->insertReturning([['label' => '原生返回']], ['label'])[0]['label'] === '原生返回', 'INSERT RETURNING 不一致');
        } else {
            self::check(self::reject(static fn () => $tags->insertReturning([['label' => '原生返回']], ['id']))
                && $tags->where('label', '=', '原生返回')->get() === [], 'RETURNING 拒绝前执行了数据写入');
        }
        $row = ['id' => 100, 'label' => '批量标签'];
        if ($capabilities['upsert-conflict-target']) {
            $tags->upsert([$row], ['id'], ['label']);
        } else {
            self::check(self::reject(static fn () => $tags->upsert([$row], ['id'], ['label'])), '指定冲突键被静默忽略');
            $tags->upsertAnyUnique([$row], ['label']);
        }
        self::check($tags->where('id', '=', 100)->first()['label'] === '批量标签', '声明的 upsert 没有完成');
        return $capabilities;
    }

    public static function race(Connection $connection, int $id): string
    {
        $article = Article::query()->find($id);
        self::awaitPeer();
        $article->setViews($article->getViews() + 1);
        try {
            $article->save();
            return 'updated';
        } catch (ModelException $error) {
            if ($error->errorCode() !== 'optimistic_conflict') {
                throw $error;
            }
            return 'conflict';
        }
    }

    public static function verifyRace(Connection $connection, int $id): array
    {
        $article = Article::query()->find($id);
        self::check($article->getViews() === 1 && $article->getVersion() === 2, '两个独立进程没有留下唯一成功版本');
        return ['views' => 1, 'version' => 2];
    }

    /** 两个真实进程同时更新同一行，数据库计算新值和版本，避免读改写丢失。 */
    public static function incrementRace(Connection $connection, int $id): string
    {
        self::awaitPeer();
        for ($index = 0; $index < 20; $index++) {
            self::check(Article::query()->where('id', '=', $id)->increment('views') === 1, '原子更新影响行数错误');
        }
        return 'incremented';
    }

    /** 两进程各写一次就绪标记；读写均持锁，兼容 Windows 的强制文件锁。 */
    private static function awaitPeer(): void
    {
        $file = (string) getenv('TYPE_SUITE_BARRIER');
        if ($file === '' || !is_file($file)) {
            throw new RuntimeException('缺少并发验证屏障');
        }
        $handle = fopen($file, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('无法打开并发验证屏障');
        }
        $announced = false;
        $deadline = microtime(true) + 10;
        try {
            while (microtime(true) < $deadline) {
                // 非阻塞尝试也受同一截止约束，不能在持锁时睡眠等待对端。
                if (flock($handle, ($announced ? LOCK_SH : LOCK_EX) | LOCK_NB)) {
                    try {
                        if (!$announced) {
                            if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, "ready\n") !== 6 || !fflush($handle)) {
                                throw new RuntimeException('无法写入并发验证屏障');
                            }
                            $announced = true;
                        }
                        if (!rewind($handle)) {
                            throw new RuntimeException('无法定位并发验证屏障');
                        }
                        $contents = stream_get_contents($handle, 64);
                        if ($contents === false) {
                            throw new RuntimeException('无法读取并发验证屏障');
                        }
                        if (substr_count($contents, "\n") >= 2) {
                            return;
                        }
                    } finally {
                        flock($handle, LOCK_UN);
                    }
                }
                usleep(1000);
            }
            throw new RuntimeException('并发更新屏障超时');
        } finally {
            fclose($handle);
        }
    }

    /** 两进程各二十次更新全部保留，版本同步递增。 */
    public static function verifyIncrementRace(Connection $connection, int $id): array
    {
        $article = Article::query()->findOrFail($id);
        self::check($article->views === 41 && $article->version === 42, '原子更新丢失值或版本');
        return ['views' => $article->views, 'version' => $article->version];
    }

    private static function reject(Closure $operation, string $code = ''): bool
    {
        try {
            $operation();
        } catch (ModelException $error) {
            return $code === '' || $error->errorCode() === $code;
        } catch (MigrationException $error) {
            return $code === '' || str_starts_with($error->getMessage(), $code);
        } catch (DatabaseException $error) {
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
