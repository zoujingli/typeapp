<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\Conditions;
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function queryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 执行预期非法查询并确认数据库契约拒绝，其他异常仍传播。
 *
 * @param Closure(): mixed $operation 预期失败的查询操作。
 */
function queryRejected(Closure $operation): bool
{
    try {
        $operation();
    } catch (\Type\Orm\DatabaseException $error) {
        return true;
    }

    return false;
}

/** 按给定驱动名选择专属示例数据库，未知名称明确拒绝。 */
function queryDriver(string $name): Driver
{
    if ($name === 'sqlite') {
        return new SqliteDriver(':memory:');
    }
    if ($name === 'mysql') {
        return new MysqlDriver(
            (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_MYSQL_PORT') ?: '3306'),
            (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
            (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
            (string) (getenv('TYPE_MYSQL_PASSWORD') ?: '')
        );
    }
    if ($name === 'pgsql') {
        return new PgsqlDriver(
            (string) (getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_PGSQL_PORT') ?: '5432'),
            (string) (getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test'),
            (string) (getenv('TYPE_PGSQL_USER') ?: 'type_app'),
            (string) (getenv('TYPE_PGSQL_PASSWORD') ?: '')
        );
    }
    throw new RuntimeException('未知查询驱动');
}

/**
 * 在明确驱动上执行参数化查询构建与拒绝路径回归，使用专属测试表。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $name = (string) ($argv[1] ?? 'sqlite');
    $scope = new ExecutionScope();
    $database = new Database(queryDriver($name), 1, 1);
    try {
        $connection = $database->connect($scope);
        // 只使用连接专属测试表；生产业务表不在验证范围。
        $connection->raw('CREATE TEMPORARY TABLE type_query_people (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL, team INTEGER NOT NULL, score INTEGER NOT NULL, note VARCHAR(255) NULL, meta ' . ($name === 'pgsql' ? 'JSONB' : 'JSON') . ' NOT NULL)');
        $connection->execute('INSERT INTO type_query_people (id, name, team, score, note, meta) VALUES (?, ?, ?, ?, ?, ?)', [1, "研发' OR 1=1 --", 1, 20, null, '{"tier":"gold"}']);
        $connection->execute('INSERT INTO type_query_people (id, name, team, score, note, meta) VALUES (?, ?, ?, ?, ?, ?)', [2, '销售', 2, 30, '已审核', '{"tier":"silver"}']);
        $base = $connection->table('type_query_people');
        $filtered = $base->where('name', '=', "研发' OR 1=1 --");
        queryExpect(count($filtered->get()) === 1 && count($base->get()) === 2, '查询共享状态或绑定参数不正确');
        $validated = _vali([
            'score' => \Type\Validate\Field::integer()->cast()->range(0, 100),
            'sort' => \Type\Validate\Field::text(), 'direction' => \Type\Validate\Field::text(),
        ], ['score' => '20', 'sort' => 'points', 'direction' => 'DESC']);
        $helper = _query($base, $validated)->equal('score')->order(['points' => 'score', 'id' => 'id']);
        $helperPage = $helper->paginatePage(1);
        queryExpect($validated['score'] === 20 && $helperPage->total() === 1
            && (int) $helperPage->items()[0]['id'] === 1 && count($base->get()) === 2, '校验转换、助手筛选分页或查询不可变性错误');
        $scopedHelper = _query($base->where('team', '=', 1), ['id' => '1,2', 'keyword' => '研发']);
        queryExpect(count($scopedHelper->in('id')->like(['keyword' => 'name'])->query()->get()) === 1, '查询助手绕过了业务范围或受信列映射');
        foreach (['score; DROP TABLE type_query_people', ['points'], null] as $invalidSort) {
            $sortRejected = false;
            try {
                _query($base, ['sort' => $invalidSort])->order(['points' => 'score']);
            } catch (InvalidArgumentException) {
                $sortRejected = true;
            }
            queryExpect($sortRejected, '查询助手接受了未验证的动态字段');
        }
        $connection->raw('CREATE TEMPORARY TABLE type_query_teams (id INTEGER PRIMARY KEY, title VARCHAR(100) NOT NULL)');
        $connection->execute('INSERT INTO type_query_teams (id, title) VALUES (?, ?), (?, ?)', [1, '研发组', 2, '销售组']);
        $report = $connection->table('type_query_people', 'p')
            ->join('type_query_teams', 'p.team', '=', 't.id', 't')
            ->whereGroup(static fn (Conditions $conditions): Conditions => $conditions->whereNull('p.note')->orWhere('p.score', '>=', 30))
            ->whereIn('p.id', [1, 2])
            ->select(['team' => 't.title'])
            ->selectAggregate('SUM', 'p.score', 'total')
            ->groupBy(['t.title'])->havingAggregate('SUM', 'p.score', '>', 10.5)
            ->orderByAllowed('total', ['total' => 'total'], 'DESC')->get();
        queryExpect(count($report) === 2 && $report[0]['team'] === '销售组' && (int) $report[0]['total'] === 30 && $report[1]['team'] === '研发组', 'Join、条件分组、聚合或动态排序错误');
        queryExpect($base->whereIn('id', [])->get() === [] && count($base->whereNotIn('id', [])->get()) === 2, '空 IN 或 NOT IN 语义错误');
        queryExpect(count($base->where('note', '=', null)->get()) === 1 && count($base->where('note', '!=', null)->get()) === 1, 'NULL 比较语义错误');
        queryExpect($base->orderBy('score', 'DESC')->limit(1, 1)->first()['name'] === "研发' OR 1=1 --", '排序、偏移或首行返回错误');
        queryExpect((int) $base->aggregate('COUNT') === 2 && (int) $base->aggregate('SUM', 'score') === 50, '标量聚合错误');
        $rejected = false;
        try {
            $base->orderByAllowed('name', ['score' => 'score']);
        } catch (\Type\Orm\DatabaseException $error) {
            $rejected = true;
        }
        queryExpect($rejected, '动态标识符超出已知范围仍然接受');
        queryExpect($base->insertMany([
            ['id' => 3, 'name' => '财务', 'team' => 2, 'score' => 40, 'note' => null, 'meta' => '{"tier":"gold"}'],
            ['id' => 4, 'name' => '运营', 'team' => 1, 'score' => 50, 'note' => null, 'meta' => '{"tier":"silver"}'],
        ]) === 2, '批量新增行数不正确');
        queryExpect($base->whereIn('id', [3, 4])->update(['note' => '批量审核']) === 2, '批量条件更新失败');
        queryExpect($base->updateMany('id', [['id' => 3, 'score' => 41], ['id' => 4, 'score' => 52]]) === 2, '按键批量更新失败');
        queryExpect((int) $base->whereIn('id', [3, 4])->aggregate('SUM', 'score') === 93, '批量更新未保存各自值');
        $rejected = false;
        try {
            $base->whereNotIn('id', [])->update(['note' => '不应写入']);
        } catch (\Type\Orm\DatabaseException $error) {
            $rejected = true;
        }
        queryExpect($rejected && count($base->where('note', '=', '不应写入')->get()) === 0, '恒真条件绕过全表写入意图');
        $rejected = false;
        try {
            $base->delete();
        } catch (\Type\Orm\DatabaseException $error) {
            $rejected = true;
        }
        queryExpect($rejected && (int) $base->aggregate('COUNT') === 4, '无条件删除未拒绝');
        queryExpect($base->whereIn('id', [])->delete() === 0, '空 IN 删除了数据');
        queryExpect($base->allowAll()->update(['note' => '显式全表']) === 4, '显式全表更新失败');
        queryExpect($base->whereIn('id', [3, 4])->delete() === 2, '条件删除行数不正确');
        $base->where('id', '=', 1)->update(['meta' => '{"tier":"gold","number":1,"enabled":true,"none":null,"items":[{"code":"x"}]}']);
        $base->where('id', '=', 2)->update(['meta' => '{"tier":"silver","number":"1","enabled":1}']);
        queryExpect(count($base->whereJson('meta', ['tier'], 'gold')->get()) === 1, 'JSON 文本标量比较失败');
        queryExpect(count($base->whereJson('meta', ['number'], 1)->get()) === 1 && count($base->whereJson('meta', ['number'], 1.0)->get()) === 1, 'JSON 数字与文本类型未区分');
        queryExpect(count($base->whereJson('meta', ['enabled'], true)->get()) === 1, 'JSON 布尔与数字类型未区分');
        queryExpect(count($base->whereJson('meta', ['none'], null)->get()) === 1 && count($base->whereJson('meta', ['missing'], null)->get()) === 0, 'JSON null 与缺失路径混淆');
        queryExpect(count($base->whereJson('meta', ['items', 0, 'code'], 'x')->get()) === 1, 'JSON 嵌套数组路径失败');
        $rejected = false;
        try {
            $base->whereJson('meta', ['tier'], ['gold']);
        } catch (\Type\Orm\DatabaseException $error) {
            $rejected = true;
        }
        queryExpect($rejected, '没有拒绝未定义的 JSON 复合比较');
        $connection->raw('CREATE TEMPORARY TABLE type_query_upsert (id INTEGER PRIMARY KEY, label VARCHAR(100) NOT NULL UNIQUE, score INTEGER NOT NULL)');
        $upsert = $connection->table('type_query_upsert');
        $upsert->insert(['id' => 1, 'label' => '首项', 'score' => 10]);
        $batch = [['id' => 1, 'label' => '首项', 'score' => 20], ['id' => 2, 'label' => '次项', 'score' => 30]];
        $capabilities = $upsert->capabilities();
        queryExpect($capabilities['json-scalar-equality'] && $capabilities['driver'] === $name, '驱动能力声明不正确');
        if ($name === 'mysql') {
            $rejected = false;
            try {
                $upsert->upsert($batch, ['id'], ['score']);
            } catch (\Type\Orm\DatabaseException $error) {
                $rejected = true;
            }
            queryExpect($rejected && !$capabilities['upsert-conflict-target'], 'MySQL 静默忽略了指定冲突键');
            queryExpect($upsert->upsertAnyUnique($batch, ['score']) === 3, 'MySQL upsert 原生影响行数不正确');
            queryExpect($upsert->upsertAnyUnique([['id' => 1, 'label' => '首项', 'score' => 20]], ['score']) === 0, 'MySQL 未变化更新行数不正确');
            $rejected = false;
            try {
                $upsert->insertReturning([['id' => 4, 'label' => '返回项', 'score' => 40]], ['id', 'label']);
            } catch (\Type\Orm\DatabaseException $error) {
                $rejected = true;
            }
            queryExpect($rejected && !$capabilities['insert-returning'], 'MySQL 将不支持的 RETURNING 降级成另一次查询');
        } else {
            queryExpect($capabilities['upsert-conflict-target'] && $upsert->upsert($batch, ['id'], ['score']) === 2, '指定冲突键 upsert 或影响行数不正确');
            queryExpect($upsert->upsert([['id' => 1, 'label' => '首项', 'score' => 20]], ['id'], ['score']) === 1, '未变化更新的原生行数不正确');
            $returned = $upsert->insertReturning([['id' => 4, 'label' => '返回项', 'score' => 40]], ['id', 'label']);
            queryExpect($capabilities['insert-returning'] && count($returned) === 1 && (int) $returned[0]['id'] === 4 && $returned[0]['label'] === '返回项', 'INSERT RETURNING 结果错误');
        }
        queryExpect((int) $upsert->where('id', '=', 1)->first()['score'] === 20 && (int) $upsert->where('id', '=', 2)->first()['score'] === 30, 'upsert 没有保存预期状态');
        if ($name === 'mysql') {
            queryExpect($upsert->upsertAnyUnique([['id' => 9, 'label' => '次项', 'score' => 35]], ['score']) === 2
                && $upsert->where('id', '=', 9)->first() === null && (int) $upsert->where('id', '=', 2)->first()['score'] === 35, 'MySQL 任意唯一键冲突语义不正确');
            queryExpect($upsert->where('id', '=', 4)->first() === null, '拒绝 RETURNING 前已经执行了 INSERT');
        } else {
            queryExpect(queryRejected(static fn () => $upsert->upsert([['id' => 9, 'label' => '次项', 'score' => 35]], ['id'], ['score']))
                && $upsert->where('id', '=', 9)->first() === null && (int) $upsert->where('id', '=', 2)->first()['score'] === 30, '指定冲突键忽略了另一个唯一约束');
            queryExpect(queryRejected(static fn () => $upsert->upsertAnyUnique($batch, ['score'])), '非 MySQL 接受任意唯一键语义');
        }
        queryExpect(queryRejected(static fn () => $upsert->insertMany([
            ['id' => 9, 'label' => '整批回滚', 'score' => 1], ['id' => 1, 'label' => '重复主键', 'score' => 2],
        ])) && $upsert->where('id', '=', 9)->first() === null, '失败批次留下部分新增数据');
        queryExpect($base->where('id', '=', 1)->updateMany('id', [['score' => 21, 'id' => 1], ['id' => 2, 'score' => 99]]) === 1
            && (int) $base->where('id', '=', 2)->first()['score'] === 30, '按键批量更新丢失原有筛选条件');
        foreach ([
            static fn () => $connection->table('type_query_people; DROP TABLE type_query_people'),
            static fn () => $base->where('id', '= ? OR 1=1 --', 1),
            static fn () => $base->orderBy('score', 'DESC; SELECT 1'),
            static fn () => $base->select(['nonexistent_column'])->get(),
            static fn () => $base->whereIn('id', [null]),
            static fn () => $base->whereGroup(static fn (Conditions $conditions): Conditions => $conditions),
            static fn () => $base->whereJson('meta', ['tier.*'], 'gold'),
            static fn () => $base->select(['id'])->update(['score' => 99]),
            static fn () => $base->orderBy('id')->delete(),
            static fn () => $base->insertMany([['id' => 5, 'score' => 1], ['id' => 6, 'name' => '列不一致']]),
            static fn () => $base->updateMany('id', [['id' => 1, 'score' => 1], ['id' => 1, 'score' => 2]]),
            static fn () => $base->where('id', '=', 1)->whereGroup(static fn (Conditions $conditions): Conditions => $conditions->whereNotIn('id', []), 'OR')->delete(),
            static fn () => $base->delete(),
        ] as $invalid) {
            queryExpect(queryRejected($invalid), '无效查询、未支持状态或全表写入没有拒绝');
        }
        queryExpect($base->where('id', '=', 1000)->first() === null && (int) $base->where('id', '=', 1000)->aggregate('COUNT') === 0
            && $base->where('id', '=', 1000)->aggregate('SUM', 'score') === null, '空结果、COUNT 与 SUM 返回语义错误');
        $connection->table('type_query_teams')->insert(['id' => 3, 'title' => '空组']);
        $emptyTeam = $connection->table('type_query_teams', 't')->join('type_query_people', 't.id', '=', 'p.team', 'p', 'LEFT')
            ->where('t.id', '=', 3)->select(['team' => 't.title', 'person' => 'p.id'])->first();
        queryExpect($emptyTeam['team'] === '空组' && $emptyTeam['person'] === null, 'LEFT JOIN 未保留无关联行');
        $upsert->allowAll()->delete();
        queryExpect((int) $upsert->aggregate('COUNT') === 0, '显式全表删除没有生效');
        $connection->close();
        $rejected = false;
        try {
            $base->get();
        } catch (RuntimeException $error) {
            $rejected = true;
        }
        queryExpect($rejected, '查询在租约归还后仍可执行');
        $clean = $database->connect($scope);
        $rawRow = $clean->query('SELECT :integer AS integer_value, :missing AS missing_value', ['integer' => 7, 'missing' => null]);
        queryExpect((int) $rawRow[0]['integer_value'] === 7 && $rawRow[0]['missing_value'] === null, '命名参数或 NULL 绑定失败');
        $clean->close();
        queryExpect($database->statistics()['idle'] === ($name === 'pgsql' ? 1 : 0), '受管会话没有按驱动策略归还');
        $unsafe = $database->connect($scope);
        queryExpect((int) $unsafe->rawQuery('SELECT ? AS value', [91])[0]['value'] === 91, '参数化原生查询没有返回结果');
        $unsafe->close();
        queryExpect($database->statistics()['idle'] === 0, '原生查询的会话归还后仍被复用');
        echo $name . " 不可变查询与批量报表验证通过。\n";
    } catch (Throwable $error) {
        fwrite(STDERR, '查询命令失败：' . $error->getMessage() . PHP_EOL);
        $scope->close();
        $database->close();
        exit(70);
    }
    $scope->close();
    $database->close();
}
