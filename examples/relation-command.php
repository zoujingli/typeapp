<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Orm\Relation;
use Type\Runtime\ExecutionScope;
use TypeApp\ModelExample\Article;
use TypeApp\ModelExample\Drivers;
use TypeApp\ModelExample\Profile;
use TypeApp\ModelExample\User;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function relationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 验证三类模型关系、批量加载、缺失值及查询预算，不访问生产数据。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argv): void {
        $database = new DatabaseManager(['default' => Drivers::create((string) ($argv[1] ?? 'sqlite'))], 1, 0);
        Db::configure($database);
        $scope = new ExecutionScope();
        try {
            $scope->run(static function (ExecutionScope $current) use ($database, &$scope, $argv): void {
                $connection = Db::connection('default', true);
                $connection->raw('CREATE TEMPORARY TABLE type_model_users (id INTEGER PRIMARY KEY, display_name VARCHAR(255), age INTEGER, active BOOLEAN, secret VARCHAR(255), note VARCHAR(255) NULL)');
                $connection->raw('CREATE TEMPORARY TABLE type_model_articles (id INTEGER PRIMARY KEY, user_id INTEGER NULL, title VARCHAR(255))');
                $connection->raw('CREATE TEMPORARY TABLE type_model_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio VARCHAR(255))');
                $connection->table('type_model_users')->insertMany([
                    ['id' => 1, 'display_name' => '甲', 'age' => 20, 'active' => true, 'secret' => '秘密', 'note' => null],
                    ['id' => 2, 'display_name' => '乙', 'age' => 30, 'active' => true, 'secret' => '秘密', 'note' => null],
                    ['id' => 3, 'display_name' => '丙', 'age' => 40, 'active' => true, 'secret' => '秘密', 'note' => null],
                ]);
                $connection->table('type_model_articles')->insertMany([
                    ['id' => 10, 'user_id' => 1, 'title' => '文章甲'], ['id' => 11, 'user_id' => 1, 'title' => '文章乙'],
                    ['id' => 12, 'user_id' => 2, 'title' => '文章丙'], ['id' => 13, 'user_id' => null, 'title' => '匿名文章'],
                ]);
                $connection->table('type_model_profiles')->insert(['id' => 20, 'user_id' => 1, 'bio' => '简介']);
                $articles = Relation::hasMany(static fn (Connection $connection): ModelQuery => Article::query()->onConnection($connection)->select(['title']), 'user_id', 'id', 2);
                $profiles = Relation::hasOne(static fn (Connection $connection): ModelQuery => Profile::query()->onConnection($connection), 'user_id', 'id', 2);
                $base = User::query()->select(['name']);
                $before = $connection->statistics()['read_attempts'];
                $users = $base->with('articles', $articles)->with('profile', $profiles)->orderBy('id')->get();
                relationExpect($connection->statistics()['read_attempts'] - $before === 5, '预加载没有按父键分批查询');
                relationExpect(array_slice($connection->statistics()['recent_read_parameter_sizes'], -5) === [0, 2, 1, 2, 1], '关系查询的批量参数大小不符');
                $reads = $connection->statistics()['read_attempts'];
                relationExpect($users[0]->project(['name'], ['articles' => ['title'], 'profile' => ['bio']]) === [
                    'name' => '甲', 'articles' => [['title' => '文章甲'], ['title' => '文章乙']], 'profile' => ['bio' => '简介'],
                ], '显式关系映射或结果顺序错误');
                relationExpect($users[2]->related('articles') === [] && $users[2]->related('profile') === null, '空关系语义错误');
                relationExpect($connection->statistics()['read_attempts'] === $reads, '关系访问偷偷执行 SQL');
                $unloaded = $base->find(1);
                $rejected = false;
                try {
                    $unloaded->related('articles');
                } catch (ModelException $error) {
                    $rejected = $error->errorCode() === 'relation_not_loaded';
                }
                relationExpect($rejected, '原查询被预加载修改或未加载关系未拒绝');
                $owner = Relation::belongsTo(static fn (Connection $connection): ModelQuery => User::query()->onConnection($connection)->select(['name']), 'user_id', 'id', 2);
                $posts = Article::query()->select(['title'])->with('owner', $owner)->orderBy('id')->get();
                relationExpect($posts[0]->related('owner')->getName() === '甲' && $posts[3]->related('owner') === null, 'belongsTo 或空外键语义错误');
                $connection->table('type_model_articles')->where('id', '=', 11)->update(['user_id' => 2]);
                $changed = $base->with('articles', $articles)->orderBy('id')->get();
                relationExpect(count($changed[0]->related('articles')) === 1 && count($changed[1]->related('articles')) === 2, '关联变更后读取了旧关系状态');
                $rejected = false;
                try {
                    $base->with('articles', Relation::hasMany(static fn (Connection $connection): ModelQuery => Article::query()->onConnection($connection)->limit(1), 'user_id'))->get();
                } catch (ModelException $error) {
                    $rejected = $error->errorCode() === 'relation_limit_unsupported';
                }
                relationExpect($rejected, '预加载静默采用了全局 LIMIT');
                $connection->table('type_model_profiles')->insert(['id' => 21, 'user_id' => 1, 'bio' => '冲突简介']);
                $rejected = false;
                try {
                    $base->with('profile', $profiles)->get();
                } catch (ModelException $error) {
                    $rejected = $error->errorCode() === 'non_unique_relation';
                }
                relationExpect($rejected, '单条关系的歧义没有拒绝');
                echo "三类关系、批量预加载、空值、顺序、显式输出与查询预算通过。\n";
            });
        } finally {
            $scope->close();
            $database->close();
        }
    });
}
