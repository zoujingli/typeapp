<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\DatabaseException;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\MigrationException;
use Type\Orm\Migration\Migrator;
use Type\Orm\ModelBehavior;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Orm\ReadWriteSession;
use Type\Runtime\ExecutionScope;
use Type\Orm\Relation;

/** 一个业务流程，三种安装仅替换 DriverFactory 与声明式物理存储。 */
final class Suite
{
    public static function run(): array
    {
        $driver = DriverFactory::create();
        $plan = Schema::plan($driver->name());
        $migrator = new Migrator($driver, 'type_suite_migrations');
        self::check(array_column($migrator->status($plan), 'state') === ['pending', 'pending'], '新消费环境不是空迁移状态');
        self::check(array_column($migrator->run($plan), 'state') === ['applied', 'applied'], '业务迁移没有完成');
        self::check(array_column($migrator->run($plan), 'attempts') === [1, 1] && count($migrator->history()) === 4, '重复迁移再次执行了已完成内容');
        $changed = new Migration($plan[0]->version(), '修改历史', $plan[0]->statements(), $plan[0]->transactional());
        self::check(self::reject(static fn () => $migrator->status([$changed, $plan[1]]), 'TYPE_MIGRATION_CHANGED'), '历史迁移变化未拒绝');
        $manager = new DatabaseManager(['primary' => $driver, 'replica' => DriverFactory::create('reader')], 4, 1);
        $scope = new ExecutionScope();
        $readScope = new ExecutionScope();
        try {
            $routing = new ReadWriteSession($manager, $scope);
            self::check($routing->read()->identity()['role'] === 'reader', '普通读取未选择 reader 身份');
            $connection = $routing->write();
            $user = new User(['name' => '用户甲', 'active' => true, 'external_id' => '123456789012345678901234567890',
                'credit' => '12345678901234567890.12', 'profile' => ['tier' => 'gold', 'enabled' => true, 'number' => 1],
                'joined_at' => new DateTimeImmutable('2026-09-09T08:30:01.123456+08:00'), 'secret' => '仅供内部']);
            self::check($user->save($connection) === 'created' && $user->getId() > 0, '模型创建或主键返回错误');
            $freshUser = User::query($connection)->find($user->getId());
            $profile = $freshUser->getProfile();
            self::check($freshUser->getExternalId() === '123456789012345678901234567890' && $freshUser->getCredit() === '12345678901234567890.12'
                && $freshUser->getActive() === true && count($profile) === 3 && $profile['tier'] === 'gold' && $profile['enabled'] === true && $profile['number'] === 1
                && $freshUser->getJoinedAt()->format('Y-m-d H:i:s.uP') === '2026-09-09 00:30:01.123456+00:00'
                && !array_key_exists('secret', $freshUser->toArray()), '模型精确类型、JSON、时间或隐藏字段不一致');
            (new Details(['user_id' => $user->getId(), 'bio' => '个人简介']))->save($connection);
            $observer = new ArticleObserver();
            $behavior = (new ModelBehavior())->observe($observer)->setter('title', static fn (mixed $value): mixed => trim((string) $value));
            $article = new Article(['user_id' => $user->getId(), 'title' => ' 第一篇 ', 'status' => 'published', 'views' => 0], false, $behavior);
            $article->save($connection);
            self::check($article->getTitle() === '第一篇' && $observer->events() === ['saving', 'creating', 'created', 'saved'], '创建事件或修改器没有贯通');
            $second = new Article(['user_id' => $user->getId(), 'title' => '草稿篇', 'status' => 'draft', 'views' => 0]);
            $second->save($connection);
            (new Article(['user_id' => $user->getId(), 'title' => '第三篇', 'status' => 'published', 'views' => 0]))->save($connection);
            $cancelled = new Article(['user_id' => $user->getId(), 'title' => '取消发布', 'status' => 'draft', 'views' => 0], false, $behavior);
            self::check($cancelled->save($connection) === 'cancelled' && !$cancelled->isPersisted(), '取消事件没有阻止新增');
            $one = new Tag(['label' => '框架']);
            $one->save($connection);
            $two = new Tag(['label' => '数据库']);
            $two->save($connection);
            $tags = Relation::belongsToMany(static fn (Connection $connection): ModelQuery => Tag::query($connection), 'type_suite_article_tags', 'article_id', 'tag_id', 'id', 'id', ['weight'], 2);
            self::check($tags->attach($connection, $article, $one->getId(), ['weight' => 10])
                && !$tags->attach($connection, $article, $one->getId(), ['weight' => 11]), '重复标签挂载没有保持唯一关系');
            $sync = $tags->sync($connection, $article, [['id' => $one->getId(), 'pivot' => ['weight' => 11]], ['id' => $two->getId(), 'pivot' => ['weight' => 20]]]);
            self::check($sync === ['attached' => 1, 'detached' => 0, 'updated' => 1], '标签整组同步错误');
            $posts = Relation::hasMany(static fn (Connection $connection): ModelQuery => Article::query($connection)->with('tags', $tags), 'user_id', 'id', 2);
            $details = Relation::hasOne(static fn (Connection $connection): ModelQuery => Details::query($connection), 'user_id');
            $author = Relation::belongsTo(static fn (Connection $connection): ModelQuery => User::query($connection)->select(['name']), 'user_id');
            $view = User::query($connection)->select(['name'])->with('articles', $posts)->with('details', $details)->cursorPaginate(2, null, 30)->items()[0];
            self::check(count($view->related('articles')) === 3 && $view->related('details')->getBio() === '个人简介'
                && count($view->related('articles')[0]->related('tags')) === 2 && $view->related('articles')[0]->related('tags')[1]->pivot() === ['weight' => 20], '一对一、一对多或多对多预加载错误');
            self::check(Article::query($connection)->with('author', $author)->find($article->getId())->related('author')->getName() === '用户甲', '文章作者关系错误');
            $base = Article::query($connection);
            $published = $base->scope(static fn (ModelQuery $query): ModelQuery => $query->where('status', '=', 'published'));
            self::check($published->count() === 2 && $base->count() === 3 && $base->paginate(2, 2)->items()[0]->getTitle() === '第三篇', '查询范围污染共享查询或普通分页错误');
            $page = $published->cursorPaginate(1);
            self::check($page->hasMore() && $published->cursorPaginate(1, $page->next())->items()[0]->getTitle() === '第三篇', '模型游标分页错误');
            self::check($base->with('tags', $tags)->chunk(2, static fn (array $models): bool => count($models) <= 2, 20) === 3, '有界业务导出不完整');
            $stale = Article::query($connection)->find($article->getId());
            $article->setViews(10);
            $article->save($connection);
            $stale->setViews(20);
            self::check(self::reject(static fn () => $stale->save($connection), 'optimistic_conflict')
                && Article::query($connection)->find($article->getId())->getViews() === 10, '过期版本覆盖最新文章');
            $observer->clear();
            self::check($article->delete($connection) && $base->find($article->getId()) === null
                && $base->onlyTrashed()->count() === 1 && $base->withTrashed()->count() === 3
                && $observer->events() === ['deleting', 'deleted'], '软删除过滤或事件错误');
            $observer->clear();
            self::check($article->restore($connection) && $base->count() === 3 && $observer->events() === ['restoring', 'restored'], '恢复文章失败');
            $rolledBack = null;
            try {
                $connection->transaction(static function (Connection $transaction) use ($user, &$rolledBack): void {
                    $rolledBack = new Article(['user_id' => $user->getId(), 'title' => '不应保存', 'status' => 'draft', 'views' => 0]);
                    $rolledBack->save($transaction);
                    throw new RuntimeException('撤销发布');
                });
            } catch (RuntimeException $error) {
                self::check($error->getMessage() === '撤销发布', '事务错误丢失');
            }
            self::check($base->count() === 3 && self::reject(static fn () => $rolledBack->getTitle(), 'model_invalid'), '回滚没有同步模型失效');
            CoreExercise::run($connection, $user->id, $article->id);
            $capabilities = self::capabilities($manager, $connection, $article->getId());
            $reads = new ReadWriteSession($manager, $readScope);
            self::check($reads->read()->identity()['role'] === 'reader' && $reads->read(true)->identity()['role'] === 'writer'
                && User::query($reads->read(true))->find($user->getId())->getName() === '用户甲'
                && $routing->read() === $connection, '强一致或写后粘滞读取选择错误');
            self::check(self::reject(static fn () => $reads->read()->table('type_suite_tags')->insert(['label' => '只读不应写入'])), 'reader 身份没有拒绝写入');
            $race = new Article(['user_id' => $user->getId(), 'title' => '并发更新', 'status' => 'published', 'views' => 0]);
            $race->save($connection);
            return ['driver' => $driver->name(), 'version' => $connection->serverVersion(), 'race_id' => $race->getId(),
                'models' => true, 'relations' => true, 'soft_delete' => true, 'events' => true, 'scopes' => true, 'core_queries' => true,
                'pagination' => true, 'optimistic_lock' => true, 'migrations' => true, 'strong_read' => true, 'capabilities' => $capabilities];
        } finally {
            $readScope->close();
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
                $other = $manager->connect($scope, 'primary');
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
        $article = Article::query($connection)->find($id);
        $file = (string) getenv('TYPE_SUITE_BARRIER');
        if ($file === '' || !is_file($file)) {
            throw new RuntimeException('缺少并发验证屏障');
        }
        file_put_contents($file, "ready\n", FILE_APPEND | LOCK_EX);
        $deadline = microtime(true) + 10;
        while (substr_count((string) file_get_contents($file), "\n") < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('并发更新屏障超时');
            }
            usleep(1000);
        }
        $article->setViews($article->getViews() + 1);
        try {
            $article->save($connection);
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
        $article = Article::query($connection)->find($id);
        self::check($article->getViews() === 1 && $article->getVersion() === 2, '两个独立进程没有留下唯一成功版本');
        return ['views' => 1, 'version' => 2];
    }

    /** 两个真实进程同时更新同一行，数据库计算新值和版本，避免读改写丢失。 */
    public static function incrementRace(Connection $connection, int $id): string
    {
        $file = (string) getenv('TYPE_SUITE_BARRIER');
        if ($file === '' || !is_file($file)) {
            throw new RuntimeException('缺少原子更新屏障');
        }
        file_put_contents($file, "ready\n", FILE_APPEND | LOCK_EX);
        $deadline = microtime(true) + 10;
        while (substr_count((string) file_get_contents($file), "\n") < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('原子更新屏障超时');
            }
            usleep(1000);
        }
        for ($index = 0; $index < 20; $index++) {
            self::check(Article::query($connection)->where('id', '=', $id)->increment('views') === 1, '原子更新影响行数错误');
        }
        return 'incremented';
    }

    /** 两进程各二十次更新全部保留，版本同步递增。 */
    public static function verifyIncrementRace(Connection $connection, int $id): array
    {
        $article = Article::query($connection)->findOrFail($id);
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
