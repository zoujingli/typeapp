<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\DatabaseException;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Orm\Relation;
use Type\Runtime\ExecutionScope;
use TypeApp\ModelExample\Article;
use TypeApp\ModelExample\Drivers;
use TypeApp\ModelExample\User;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function paginationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 执行预期非法查询并只识别数据库或模型拒绝，不吞掉其他实现错误。
 *
 * @param Closure(): mixed $operation 预期被拒绝的公开操作。
 */
function paginationReject(Closure $operation): bool
{
    try {
        $operation();
    } catch (DatabaseException|ModelException $error) {
        return true;
    }
    return false;
}

/**
 * 在指定三库驱动上验证分页、分块遍历与内存增长，作用域退出后释放连接。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argv): void {
        $driver = (string) ($argv[1] ?? 'sqlite');
        $database = new DatabaseManager(['default' => Drivers::create($driver)], 1, 1);
        Db::configure($database);
        $scope = new ExecutionScope();
        try {
            $scope->run(static function (ExecutionScope $current) use ($database, &$scope, $argv, $driver): void {
                $connection = Db::connection('default', true);
                $connection->raw('CREATE TEMPORARY TABLE type_model_users (id INTEGER PRIMARY KEY, display_name VARCHAR(255) NOT NULL, age INTEGER NOT NULL, active BOOLEAN NOT NULL, secret VARCHAR(255) NOT NULL, note TEXT NULL)');
                $connection->raw('CREATE TEMPORARY TABLE type_model_articles (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title VARCHAR(255) NOT NULL)');
                $users = $connection->table('type_model_users');
                $articles = $connection->table('type_model_articles');
                $rows = [];
                $posts = [];
                for ($id = 1; $id <= 17; $id++) {
                    $rows[] = ['id' => $id, 'display_name' => '人员' . $id, 'age' => intdiv($id - 1, 3), 'active' => true, 'secret' => '不输出', 'note' => null];
                    $posts[] = ['id' => $id * 2, 'user_id' => $id, 'title' => '文章甲'];
                    $posts[] = ['id' => $id * 2 + 1, 'user_id' => $id, 'title' => '文章乙'];
                }
                $users->insertMany($rows);
                $articles->insertMany($posts);
                $page = $users->paginate(2, 5);
                paginationExpect(array_column($page->items(), 'id') === [6, 7, 8, 9, 10] && $page->total() === 17 && $page->lastPage() === 4 && $page->hasMore(), '普通分页结果或总数错误');
                $last = $users->paginate(4, 5);
                paginationExpect(count($last->items()) === 2 && !$last->hasMore() && $users->paginate(5, 5)->items() === [], '末页或越界空页错误');
                $empty = $users->where('id', '<', 0)->paginate(1, 5);
                paginationExpect($empty->items() === [] && $empty->total() === 0 && $empty->lastPage() === 1, '空集合分页错误');
                foreach ([static fn () => $users->paginate(0), static fn () => $users->paginate(PHP_INT_MAX, 1000),
                    static fn () => $users->cursorPaginate(1001), static fn () => $users->orderBy('note')->cursorPaginate(5),
                    static fn () => $users->select(['__type_cursor_0' => 'id'])->cursorPaginate(5),
                    static fn () => $users->limit(1)->paginate(), static fn () => $users->cursorPaginate(5, '非法游标')] as $invalid) {
                    paginationExpect(paginationReject($invalid), '分页边界或非法游标没有拒绝');
                }
                $ordered = $users->select(['person' => 'display_name'])->orderBy('age', 'DESC');
                $cursor = null;
                $names = [];
                do {
                    $result = $ordered->cursorPaginate(4, $cursor);
                    foreach ($result->items() as $row) {
                        paginationExpect(array_keys($row) === ['person'], '游标内部排序列泄露至输出');
                        $names[] = $row['person'];
                    }
                    $cursor = $result->next();
                } while ($cursor !== null);
                paginationExpect($names === ['人员16', '人员17', '人员13', '人员14', '人员15', '人员10', '人员11', '人员12', '人员7', '人员8', '人员9', '人员4', '人员5', '人员6', '人员1', '人员2', '人员3'], '重复排序值跨页遗漏、重复或顺序错误');
                $first = $users->cursorPaginate(3);
                $shared = $users->select(['id'])->orderBy('id');
                $sharedPage = $shared->cursorPaginate(3);
                paginationExpect(array_column($shared->cursorPaginate(3, $sharedPage->next())->items(), 'id') === [4, 5, 6]
                    && array_keys($shared->first()) === ['id'], '已按主键排序的共享查询被游标投影修改');
                paginationExpect(paginationReject(static fn () => $users->where('id', '>', 1)->cursorPaginate(3, $first->next())), '其他筛选条件接受了不匹配游标');
                $users->where('id', '=', 2)->delete();
                $users->insert(['id' => 0, 'display_name' => '新增较早记录', 'age' => 0, 'active' => true, 'secret' => '', 'note' => null]);
                paginationExpect(array_column($users->cursorPaginate(3, $first->next())->items(), 'id') === [4, 5, 6], '前页记录变化导致游标偏移漂移');
                $users->where('id', '=', 0)->delete();
                $users->insert($rows[1]);
                paginationExpect(array_column($users->orderBy('id', 'DESC')->cursorPaginate(3)->items(), 'id') === [17, 16, 15], '降序游标错误');
                $relation = Relation::hasMany(static fn (Connection $connection): ModelQuery => Article::query()->onConnection($connection)->select(['title']), 'user_id', 'id', 3);
                $modelQuery = User::query()->select(['name'])->with('articles', $relation);
                $modelPage = $modelQuery->paginate(2, 5, 20);
                paginationExpect($modelPage->total() === 17 && $modelPage->items()[0]->getId() === 6 && count($modelPage->items()[0]->related('articles')) === 2, '模型分页没有加载本页关系');
                $batches = 0;
                $modelCount = $modelQuery->chunk(4, static function (array $models) use (&$batches, $connection): void {
                    $batches++;
                    $reads = $connection->statistics()['read_attempts'];
                    paginationExpect(count($models) <= 4, '模型分批超过父记录上限');
                    foreach ($models as $model) {
                        paginationExpect(count($model->related('articles')) === 2, '批次关系不完整');
                    }
                    paginationExpect($connection->statistics()['read_attempts'] === $reads, '关系访问触发了隐式额外查询');
                }, 20);
                paginationExpect($modelCount === 17 && $batches === 5, '模型分批遍历丢失数据');
                paginationExpect(paginationReject(static fn () => $modelQuery->cursorPaginate(4, null, 6)), '一对多关系超出总行数预算没有拒绝');
                $owner = Relation::belongsTo(static fn (Connection $connection): ModelQuery => User::query()->onConnection($connection)->select(['name']), 'user_id');
                $nested = Relation::hasMany(static fn (Connection $connection): ModelQuery => Article::query()->onConnection($connection)->with('owner', $owner), 'user_id');
                paginationExpect(paginationReject(static fn () => User::query()->with('articles', $nested)->cursorPaginate(4, null, 12)), '嵌套预加载没有共享行数预算');
                $connection->raw('CREATE TEMPORARY TABLE type_model_tags (id INTEGER PRIMARY KEY, label VARCHAR(255) NOT NULL, internal VARCHAR(255) NOT NULL)');
                $connection->raw('CREATE TEMPORARY TABLE type_pagination_user_tags (user_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (user_id, tag_id))');
                $connection->table('type_model_tags')->insert(['id' => 1, 'label' => '标签', 'internal' => '']);
                $connection->table('type_pagination_user_tags')->insertMany([['user_id' => 1, 'tag_id' => 1], ['user_id' => 2, 'tag_id' => 1]]);
                $tags = Relation::belongsToMany(static fn (Connection $connection): ModelQuery => \TypeApp\ModelExample\Tag::query()->onConnection($connection), 'type_pagination_user_tags', 'user_id', 'tag_id');
                $tagged = User::query()->with('tags', $tags);
                paginationExpect(count($tagged->cursorPaginate(2, null, 5)->items()[0]->related('tags')) === 1, '多对多有界预加载结果错误');
                paginationExpect(paginationReject(static fn () => $tagged->cursorPaginate(2, null, 3)), '中间表没有计入共享行数预算');
                paginationExpect($users->chunk(4, static fn (array $rows): bool => false) === 4, '普通批次不能提前退出');
                paginationExpect(paginationReject(static fn () => $connection->transaction(static fn (Connection $transaction): int => $modelQuery->chunk(4, static fn (array $models): mixed => null))), '事务保留模型使分批无界');
                $stream = $users->orderBy('id')->stream(4);
                paginationExpect((int) $stream->next()['id'] === 1 && $database->statistics()['active']['default']['leased'] === 1, '流没有持有连接租约');
                $foreign = new Fiber(static function () use ($stream): bool {
                    try {
                        $stream->next();
                    } catch (RuntimeException $error) {
                        return true;
                    }
                    return false;
                });
                $foreign->start();
                paginationExpect($foreign->getReturn() && !$stream->closed(), '结果流跨执行者读取或错误关闭了原执行者资源');
                paginationExpect(paginationReject(static fn () => $users->get()) && paginationReject(static fn () => $connection->execute('UPDATE type_model_users SET age = age'))
                    && paginationReject(static fn () => $connection->transaction(static fn (Connection $transaction): mixed => null)), '未关闭流允许冲突查询或事务');
                $poolRejected = false;
                try {
                    $database->connect($scope);
                } catch (RuntimeException $error) {
                    $poolRejected = true;
                }
                paginationExpect($poolRejected, '流持有期间连接池容量提前归还');
                $stream->close();
                paginationExpect($stream->closed() && $stream->next() === null && (int) $users->aggregate('COUNT') === 17, '流关闭没有恢复后续查询');
                $early = $users->stream(3);
                paginationExpect($early->each(static fn (array $row): bool => false) === 1 && $early->closed(), '提前中断没有关闭流');
                $failed = $users->stream(3);
                try {
                    $failed->each(static function (array $row): void {
                        throw new RuntimeException('导出中断');
                    });
                } catch (RuntimeException $error) {
                    paginationExpect($error->getMessage() === '导出中断', '清理掩盖了导出异常');
                }
                paginationExpect($failed->closed() && (int) $users->aggregate('COUNT') === 17, '异常中断后数据库不可用');
                paginationExpect(paginationReject(static fn () => $connection->transaction(static fn (Connection $transaction): \Type\Orm\RowStream => $users->stream())), '事务内开启独占流没有拒绝');

                // 数据在固定大小批次创建；导出检验阶段不保留预期结果列表。
                $users->allowAll()->delete();
                $payload = str_repeat('x', 2048);
                for ($start = 1; $start <= 20000; $start += 250) {
                    $batch = [];
                    for ($id = $start; $id < $start + 250; $id++) {
                        $batch[] = ['id' => $id, 'display_name' => '导出' . $id, 'age' => $id, 'active' => true, 'secret' => '', 'note' => $payload];
                    }
                    $users->insertMany($batch);
                }
                unset($batch, $rows, $posts, $names, $page, $last, $empty, $modelPage, $result);
                gc_collect_cycles();
                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }
                $baseline = memory_get_usage(false);
                $export = $users->select(['id', 'note'])->orderBy('id')->stream(64, 4096);
                $sum = 0;
                $count = $export->each(static function (array $row) use (&$sum): void {
                    paginationExpect(strlen($row['note']) === 2048, '流式导出字段不完整');
                    $sum += (int) $row['id'];
                });
                $growth = max(0, memory_get_peak_usage(false) - $baseline);
                paginationExpect($export->closed() && $count === 20000 && $sum === 200010000 && $growth < 12 * 1024 * 1024, '大结果导出没有保持数量、顺序或有界内存');
                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }
                $modelBaseline = memory_get_usage(false);
                $modelRows = User::query()->select(['name'])->with('articles', $relation)->chunk(250, static function (array $models): void {
                    paginationExpect(count($models) <= 250, '大数据模型批次无界');
                }, 1000);
                $modelGrowth = max(0, memory_get_peak_usage(false) - $modelBaseline);
                paginationExpect($modelRows === 20000 && $modelGrowth < 12 * 1024 * 1024, '大数据模型与关系批次累积全部模型');
                $oversized = $users->select(['note'])->stream(1, 100);
                paginationExpect(paginationReject(static fn () => $oversized->next()) && $oversized->closed(), '超限单行未拒绝或未关闭流');
                $pending = $users->stream(2);
                $pending->next();
                $scope->close();
                paginationExpect($pending->closed() && $database->statistics()['active']['default']['leased'] === 0, '作用域结束没有清理未显式关闭的流');
                $pending->close();
                $scope = new ExecutionScope();
                $reopened = $database->connect($scope);
                paginationExpect((int) $reopened->query('SELECT 7 AS value')[0]['value'] === 7, '流清理后连接池不能重新借用');
                $reopened->close();
                echo json_encode(['driver' => $driver, 'rows' => $count, 'sum' => $sum, 'peak_growth' => $growth, 'model_peak_growth' => $modelGrowth, 'leased' => $database->statistics()['active']['default']['leased']], JSON_THROW_ON_ERROR) . PHP_EOL;
            });
        } finally {
            $scope->close();
            $database->close();
        }
    });
}
