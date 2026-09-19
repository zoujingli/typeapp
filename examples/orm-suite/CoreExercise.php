<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Closure;
use RuntimeException;
use Throwable;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\DatabaseException;
use Type\Orm\ModelConditions;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Orm\Query;
use Type\Orm\QueryEvent;
use Type\Orm\TransactionOutcome;
use Type\Runtime\ExecutionScope;

/** 三库共用的属性、组合查询、关系计算和诊断公共行为验收。 */
final class CoreExercise
{
    public static function run(Connection $connection, int $userId, int $articleId): void
    {
        $user = User::query($connection)->findOrFail($userId);
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
        $partial = User::query($connection)->select(['name'])->findOrFail($userId);
        self::check(self::reject(static fn () => $partial->active, 'field_not_loaded'), '未加载属性被当作 null');
        self::check(self::reject(static fn () => $partial->articles, 'relation_not_loaded'), '关系属性触发了隐式读取');
        $post = Article::query($connection)->findOrFail($articleId);
        self::check($post->deleted_at === null && self::reject(static function () use ($post): void {
            $post->version = 100;
        }, 'field_not_fillable'), 'null 或只读生命周期属性错误');
        self::check(self::reject(static function () use ($post): void {
            $post->id = 100;
        }, 'field_not_fillable'), '持久化主键允许修改');

        $base = Article::query($connection);
        $grouped = $base->whereGroup(static fn (ModelConditions $conditions): ModelConditions => $conditions->where('status', '=', 'published')
            ->orWhere('title', '=', '草稿篇'))->whereNotIn('id', []);
        self::check($grouped->count() === 3 && $base->whereNull('deleted_at')->count() === 3, '模型条件组或 NULL/NOT IN 错误');
        self::check($base->where('status', '=', 'draft')->orWhere('views', '=', 10)->count() === 2, '模型 OR 映射错误');
        self::check(User::query($connection)->whereJson('profile', ['enabled'], true)->exists(), '模型 JSON 标量条件错误');
        self::check($base->orderBy('id')->value('views') === 10 && count($base->pluck('title', 'id')) === 3, '模型值提取或类型转换错误');
        self::check(self::reject(static fn () => $base->findOrFail(-1), 'not_found') && !$base->whereIn('id', [])->exists(), '模型缺失语义错误');
        $before = $connection->statistics();
        self::check(str_contains($grouped->toSql(), 'SELECT') && count($grouped->bindings()) === 2 && $connection->statistics() === $before, 'SQL 预览执行了数据库读取');

        $users = User::query($connection);
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
        $post->delete($connection);
        self::check(
            $users->withCount('articles')->firstOrFail()->computed('articles_count') === 2
            && $users->whereHas('articles.tags')->count() === 0
            && $users->withCount('articles', static fn (ModelQuery $query): ModelQuery => $query->withTrashed(), 'all_posts')->firstOrFail()->computed('all_posts') === 3,
            '关系过滤或统计丢失软删除范围'
        );
        $post->restore($connection);
        $creditQuery = $users->where('id', '=', $userId);
        if ($connection->driverName() === 'sqlite') {
            self::check(self::reject(static fn () => $creditQuery->increment('credit'), 'exact_arithmetic_unsupported'), 'SQLite 对精确文本执行了隐式算术');
            self::check(self::reject(static fn () => $base->withSum('author', 'credit'), 'exact_sum_unsupported'), 'SQLite 对精确文本执行了不精确求和');
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
        $snapshot = Article::query($connection)->findOrFail($articleId);
        self::check($base->where('id', '=', $articleId)->increment('views', 2) === 1 && $base->findOrFail($articleId)->views === 12, '原子自增错误');
        $snapshot->views = 90;
        self::check(self::reject(static fn () => $snapshot->save($connection), 'optimistic_conflict'), '原子自增没有使旧版本过期');
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
                $rollback = Article::query($transaction)->findOrFail($articleId);
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
        $simple = Article::query($connection)->simplePaginate(2, 2);
        self::check(count($simple->items()) === 1 && !$simple->hasMore() && Article::query($connection)->simplePaginate(1, 2)->hasMore(), '无总数分页边界错误');
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
