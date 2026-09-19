<?php

declare(strict_types=1);

use Type\Validate\Field;
use Type\Validate\Helper\ValidateHelper;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;
use Type\Orm\Database;
use Type\Orm\DatabaseException;
use Type\Orm\Connection;
use Type\Orm\Model;
use Type\Orm\ModelDefinition;
use Type\Orm\ModelField;
use Type\Orm\ModelQuery;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

/** 测试模型通过公开映射水合，字段类型继续由 ModelQuery 校验。 */
final class TypeHelperUser extends Model
{
    /** @param array<string, mixed> $values 已从测试数据库读取的字段。 */
    public function __construct(array $values)
    {
        parent::__construct(self::mapping(), $values, true);
    }

    /** 测试查询不绕过已有ORM模型字段映射。 */
    public static function mapping(): ModelDefinition
    {
        return new ModelDefinition('helper_users', 'id', [
            'id' => new ModelField('id', 'integer'),
            'tenant' => new ModelField('tenant', 'integer'),
            'age' => new ModelField('age', 'integer'),
            'active' => new ModelField('active', 'boolean'),
            'note' => new ModelField('note', 'string', true),
            'name' => new ModelField('name', 'string'),
        ]);
    }
}

/** 排序验收模型使用不同于数据库列的字段名称，避免仅证明同名映射。 */
final class TypeHelperSortedUser extends Model
{
    /** @param array<string, mixed> $values 按模型字段名水合的真实查询结果。 */
    public function __construct(array $values)
    {
        parent::__construct(self::mapping(), $values, true);
    }

    /** 业务 years/label 分别映射到数据库 age/name；排序必须经过该映射。 */
    public static function mapping(): ModelDefinition
    {
        return new ModelDefinition('helper_users', 'id', [
            'id' => new ModelField('id', 'integer'),
            'tenant' => new ModelField('tenant', 'integer'),
            'years' => new ModelField('age', 'integer'),
            'active' => new ModelField('active', 'boolean'),
            'label' => new ModelField('name', 'string'),
        ]);
    }
}

/** 公共行为断言不读取测试实现内部状态。 */
function helpersAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 只经公开查询和分页结果验证排序，不读取私有 orders 或检查生成 SQL 文本。 */
function helpersOrdering(Connection $connection): void
{
    foreach ([[5, 1, 18, 1, null, 'Alpha'], [6, 1, 18, 1, null, 'Beta'], [7, 2, 99, 1, null, '其他租户']] as $sortRow) {
        $connection->execute('INSERT INTO helper_users (id, tenant, age, active, note, name) VALUES (?, ?, ?, ?, ?, ?)', $sortRow);
    }
    $base = $connection->table('helper_users')->where('tenant', '=', 1);
    $allowed = ['oldest' => 'age', 'label' => 'name', 'id' => 'id'];
    $original = _query($base, ['sort' => 'oldest', 'direction' => 'desc']);
    $sorted = $original->order($allowed, ['id' => 'ASC']);
    helpersAssert(array_column($sorted->paginatePage(2)->items(), 'id') === [3, 2], '客户端排序被默认主键顺序覆盖');
    helpersAssert(array_column($original->paginatePage(2)->items(), 'id') === [1, 2], '排序修改了旧助手或原查询');
    $second = _query($base, ['sort' => 'oldest', 'direction' => 'DESC', 'page' => 2, 'page_size' => 2])->order($allowed)->paginatePage();
    $third = _query($base, ['sort' => 'oldest', 'direction' => 'dEsC', 'page' => 3, 'page_size' => 2])->order($allowed)->paginatePage();
    helpersAssert($second->total() === 5 && array_column($second->items(), 'id') === [5, 6]
        && array_column($third->items(), 'id') === [1], '排序分页没有稳定主键收尾或泄漏其他租户');
    foreach ([[], ['sort' => ''], ['sort' => '', 'direction' => '']] as $missingOrder) {
        helpersAssert(
            array_column(_query($base, $missingOrder)->order($allowed, ['oldest' => 'DESC'])->paginatePage(2)->items(), 'id') === [3, 2],
            '缺失或空排序没有采用显式默认'
        );
    }
    helpersAssert(
        array_column(_query($base, ['sort' => 'oldest'])->order($allowed, ['oldest' => 'DESC'])->paginatePage()->items(), 'id') === [1, 2, 5, 6, 3],
        '已选择字段的缺失方向应为ASC，不继承未选默认方向'
    );
    helpersAssert(
        array_column(_query($base, ['sort' => 'oldest', 'direction' => ''])->order($allowed)->paginatePage()->items(), 'id') === [1, 2, 5, 6, 3],
        '已选择字段的空方向没有采用ASC'
    );
    helpersAssert(
        array_column(_query($base, ['sort' => 'id', 'direction' => 'DESC'])->order($allowed)->paginatePage()->items(), 'id') === [6, 5, 3, 2, 1],
        '客户端已选主键后分页重复追加了主键'
    );
    helpersAssert(array_column(_query($base, ['orderBy' => 'oldest', 'orderType' => 'DESC'])
        ->order($allowed, [], 'orderBy', 'orderType')->paginatePage(2)->items(), 'id') === [3, 2], '显式配置的输入键未生效');

    $fixed = $base->orderBy('active', 'DESC');
    helpersAssert(
        array_column(_query($fixed, ['sort' => 'oldest'])->order($allowed)->paginatePage()->items(), 'id') === [2, 5, 6, 3, 1],
        '客户端排序替换了已有业务优先级'
    );
    $sameColumn = $base->orderBy('age', 'ASC');
    helpersAssert(
        array_column(_query($sameColumn, ['sort' => 'oldest', 'direction' => 'DESC'])->order($allowed)->paginatePage()->items(), 'id') === [1, 2, 5, 6, 3],
        '重复选择业务固定列重排了方向或导致重复分页列'
    );
    $repeated = _query($base)->order($allowed, ['id' => 'ASC'])->order($allowed, ['id' => 'DESC']);
    helpersAssert(array_column($repeated->paginatePage()->items(), 'id') === [1, 2, 3, 5, 6], '链式重复排序未保留第一次方向');

    $aliased = $connection->table('helper_users', 'u')->where('u.tenant', '=', 1);
    $aliasedRows = _query($aliased, ['sort' => 'score', 'direction' => 'DESC'])
        ->order(['score' => 'u.age'])->query()->orderByIfAbsent('u.id')->get();
    helpersAssert(array_column($aliasedRows, 'id') === [3, 2, 5, 6, 1], '普通Query的受信表别名排序或租户约束丢失');

    $invalidInputs = [
        ['sort' => 'tenant'], ['sort' => 'id DESC; DROP TABLE helper_users'], ['sort' => ['id']], ['sort' => null], ['sort' => false], ['sort' => 0],
        ['sort' => ' '], ['sort' => 'oldest,label'], ['sort' => str_repeat('a', 129)],
        ['sort' => 'id', 'direction' => ['DESC']], ['sort' => 'id', 'direction' => null], ['sort' => 'id', 'direction' => true],
        ['sort' => 'id', 'direction' => 'DESC;--'], ['sort' => 'id', 'direction' => ' DESC '], ['sort' => 'id', 'direction' => 'RANDOM'],
        ['direction' => 'DESC'], ['sort' => '', 'direction' => 'ASC'], ['direction' => null],
    ];
    foreach ($invalidInputs as $invalidInput) {
        $invalidRejected = false;
        try {
            _query($base, $invalidInput)->order($allowed);
        } catch (InvalidArgumentException) {
            $invalidRejected = true;
        }
        helpersAssert($invalidRejected, '未知、歧义或注入排序输入未被拒绝');
    }
    $badDeclarations = [
        [['alias' => 'age DESC'], []], [['alias' => 'age'], ['unknown' => 'ASC']],
        [['alias' => 'age'], ['alias' => 'SIDEWAYS']], [['first' => 'age', 'second' => 'age'], ['first' => 'ASC', 'second' => 'DESC']],
        [['alias' => 'age'], ['ASC']],
    ];
    foreach ($badDeclarations as $badDeclaration) {
        $declarationRejected = false;
        try {
            _query($base, [])->order($badDeclaration[0], $badDeclaration[1]);
        } catch (InvalidArgumentException) {
            $declarationRejected = true;
        }
        helpersAssert($declarationRejected, '非法默认排序或重复映射不能因客户端未选择而忽略');
    }
    $hiddenBadDefault = false;
    try {
        _query($base, ['sort' => 'id'])->order($allowed, ['oldest' => 'RANDOM']);
    } catch (InvalidArgumentException) {
        $hiddenBadDefault = true;
    }
    helpersAssert($hiddenBadDefault, '客户端合法排序掩盖了非法默认方向');
    foreach ([['id', 'RANDOM'], ['id', 'DESC;--'], ['id DESC', 'ASC']] as $badOrder) {
        $directRejected = false;
        try {
            $base->orderBy('id')->orderByIfAbsent($badOrder[0], $badOrder[1]);
        } catch (DatabaseException) {
            $directRejected = true;
        }
        helpersAssert($directRejected, '重复排序列绕过了底层方向或标识符验证');
    }
    $strictDuplicate = false;
    try {
        $base->orderBy('id')->orderBy('id')->paginate();
    } catch (DatabaseException) {
        $strictDuplicate = true;
    }
    helpersAssert($strictDuplicate, 'orderBy原有重复分页列契约被改变');

    $modelBase = (new ModelQuery($connection, TypeHelperSortedUser::mapping(), static fn (array $values): TypeHelperSortedUser => new TypeHelperSortedUser($values)))
        ->where('tenant', '=', 1);
    $modelAllowed = ['oldest' => 'years', 'label' => 'label', 'id' => 'id'];
    $modelSorted = _query($modelBase, ['sort' => 'oldest', 'direction' => 'DESC', 'page' => 2, 'page_size' => 2])->order($modelAllowed);
    helpersAssert($modelSorted->query() instanceof ModelQuery, '模型排序被降为无映射查询');
    $modelPage = $modelSorted->paginatePage();
    helpersAssert($modelPage->total() === 5 && count($modelPage->items()) === 2 && $modelPage->items()[0] instanceof TypeHelperSortedUser
        && $modelPage->items()[0]->get('id') === 5 && $modelPage->items()[1]->get('id') === 6, '模型映射排序、水合、稳定分页或租户隔离失败');
    $modelExisting = $modelBase->orderBy('years', 'ASC');
    $modelFixed = _query($modelExisting, ['sort' => 'oldest', 'direction' => 'DESC'])->order($modelAllowed)->paginatePage();
    helpersAssert(
        array_map(static fn (TypeHelperSortedUser $user): int => $user->get('id'), $modelFixed->items()) === [1, 2, 5, 6, 3],
        '模型字段映射后重复排序没有保留原始方向'
    );
    $modelPriority = _query($modelBase->orderBy('active', 'DESC'), ['sort' => 'oldest'])->order($modelAllowed)->paginatePage();
    helpersAssert(
        array_map(static fn (TypeHelperSortedUser $user): int => $user->get('id'), $modelPriority->items()) === [2, 5, 6, 3, 1],
        '模型排序没有保留固定业务优先级'
    );
    $modelDefault = _query($modelBase)->order($modelAllowed, ['oldest' => 'DESC'])->paginatePage(2);
    helpersAssert($modelDefault->items()[0]->get('id') === 3 && $modelDefault->items()[1]->get('id') === 2
        && $modelBase->paginate(1, 2)->items()[0]->get('id') === 1, '模型默认排序修改了旧查询');
    $modelPrimary = _query($modelBase, ['sort' => 'id', 'direction' => 'DESC'])->order($modelAllowed)->paginatePage(2);
    helpersAssert($modelPrimary->items()[0]->get('id') === 6 && $modelPrimary->items()[1]->get('id') === 5, '模型主键排序被分页重复添加');
    foreach ([['years', 'RANDOM'], ['unknown', 'ASC'], ['years DESC', 'ASC']] as $badModelOrder) {
        $modelRejected = false;
        try {
            $modelExisting->orderByIfAbsent($badModelOrder[0], $badModelOrder[1]);
        } catch (DatabaseException) {
            $modelRejected = true;
        }
        helpersAssert($modelRejected, '已有模型排序掩盖了非法字段或方向');
    }
    helpersAssert(count($connection->table('helper_users')->get()) === 7, '排序注入或失败路径改变了表数据');
}

/** 默认值复用公开校验入口，区分缺失、显式输入、PATCH和声明快照。 */
function helpersDefaults(): void
{
    $defaultRules = [
        'limit' => Field::integer()->cast()->range(1, 100)->defaultValue('20'),
        'active' => Field::boolean()->defaultValue(false),
        'note' => Field::text()->nullable()->defaultValue(null),
    ];
    helpersAssert(_vali($defaultRules, []) === ['limit' => 20, 'active' => false, 'note' => null], '缺失字段没有使用经过校验的显式默认值');
    $defaultProfile = new stdClass();
    $defaultProfile->city = '杭州';
    $profileRule = Field::object(new Schema(['city' => Field::text()->required()]))->defaultValue($defaultProfile);
    $defaultProfile->city = '不能影响已声明规则';
    helpersAssert(_vali(['profile' => $profileRule], []) === ['profile' => ['city' => '杭州']], '声明后的对象修改污染了默认值');
    helpersAssert(_vali($defaultRules, ['limit' => '3', 'active' => true, 'note' => '']) === ['limit' => 3, 'active' => true, 'note' => ''], '默认值覆盖了明确输入');
    helpersAssert(_vali($defaultRules, [], patch: true) === [], 'PATCH为缺失字段注入了默认值');
    helpersAssert(_vali($defaultRules, ['note' => null], patch: true) === ['note' => null], 'PATCH中的null被视为缺失');
    $optional = Field::integer();
    helpersAssert(_vali(['value' => $optional], []) === [] && _vali(['value' => $optional->defaultValue(0)], []) === ['value' => 0], '默认声明修改了原Field或丢失零值');
    $defaultData = ValidateHelper::data($defaultRules, []);
    helpersAssert($defaultData->has('note') && $defaultData->get('note') === null && !$defaultData->has('unknown'), 'Data没有区分有效默认null与未声明字段');
    $source = new Input(['body' => ['page' => '99'], 'query' => []]);
    $sourceRules = ['page' => Field::integer()->from('query', 'p')->cast()->defaultValue('2')];
    helpersAssert(_vali($sourceRules, $source) === ['page' => 2] && $source->source('query') === [], '默认值混合或修改了原始输入来源');
    helpersAssert(_vali(['value' => Field::text()->defaultValue(' x ')->trim()->length(1, 1)], []) === ['value' => 'x'], '默认文本没有经过转换与长度规则');
    $scenarioRule = Field::integer()->defaultValue(7)->inScenarios(['create']);
    helpersAssert(_vali(['value' => $scenarioRule], [], scenario: 'update') === [], '不适用的场景产生了默认值');
    helpersAssert(_vali(['value' => $scenarioRule], [], scenario: 'create') === ['value' => 7], '适用场景丢失默认值');
    $conditional = Field::integer()->defaultValue(7)->when(
        static fn (Input $input, string $scenario): bool => ($input->source('body')['enabled'] ?? false) === true && $scenario === 'create'
    );
    helpersAssert(_vali(['value' => $conditional], [], scenario: 'create') === [], '未满足条件时仍填入默认值');
    helpersAssert(_vali(['value' => $conditional], ['enabled' => true], scenario: 'create') === ['value' => 7], '默认值条件没有收到原始输入与场景');
    $contextRule = Field::integer()->defaultValue(7)->rule(
        'context',
        static fn (mixed $value, Input $input, string $scenario): bool => $value === 7 && !array_key_exists('value', $input->source('body')) && $scenario === 'create'
    );
    helpersAssert(_vali(['value' => $contextRule], [], scenario: 'create') === ['value' => 7], '默认值回调没有收到有效值、原始输入和场景');

    foreach ([
        [Field::integer()->defaultValue(5), ['value' => null], 'null_not_allowed'],
        [Field::integer()->defaultValue(5), ['value' => ''], 'type_integer'],
        [Field::integer()->defaultValue('private-default-sentinel'), [], 'type_integer'],
        [Field::integer()->cast()->defaultValue('999999999999999999999'), [], 'type_integer'],
        [Field::integer()->range(1, 10)->defaultValue(20), [], 'range'],
        [Field::text()->defaultValue(null), [], 'null_not_allowed'],
        [Field::number()->defaultValue(INF), [], 'type_number'],
        [Field::text()->required()->defaultValue('fallback'), [], 'required'],
        [Field::text()->defaultValue('fallback')->required(), [], 'required'],
    ] as $invalidDefault) {
        $defaultRejected = false;
        try {
            _vali(['value' => $invalidDefault[0]], $invalidDefault[1]);
        } catch (ValidationException $defaultError) {
            $defaultRejected = $defaultError->errors() === ['value' => [$invalidDefault[2]]]
                && $defaultError->status() === 422 && !str_contains($defaultError->getMessage(), 'private-default-sentinel');
        }
        helpersAssert($defaultRejected, '默认值绕过了类型、规则、必填或错误脱敏');
    }
    $nested = new Schema(['city' => Field::text()->defaultValue('杭州')]);
    $nestedRules = ['profile' => Field::object($nested)->defaultValue(new stdClass()), 'items' => Field::listOf(Field::object($nested))->defaultValue([new stdClass()])];
    helpersAssert(_vali($nestedRules, []) === ['profile' => ['city' => '杭州'], 'items' => [['city' => '杭州']]], '对象或列表的默认值未递归校验');
    helpersAssert(_vali(['profile' => Field::object($nested)], []) === [], '子字段默认值不应制造未提供的父对象');
    helpersAssert(_vali($nestedRules, [], patch: true) === [], 'PATCH填充了缺失对象或列表');
    helpersAssert(_vali($nestedRules, ['profile' => new stdClass(), 'items' => [new stdClass()]], patch: true) === ['profile' => [], 'items' => [['city' => '杭州']]], 'PATCH没有区分对象补丁和列表整体替换');
    helpersAssert(_vali(['items' => Field::listOf(Field::integer())->defaultValue([])], []) === ['items' => []], '空列表默认值被视为未声明');
    $nestedFailed = false;
    try {
        _vali(['profile' => Field::object(new Schema(['city' => Field::text()->required()]))->defaultValue(new stdClass())], []);
    } catch (ValidationException $nestedError) {
        $nestedFailed = $nestedError->errors() === ['profile.city' => ['required']];
    }
    helpersAssert($nestedFailed, '默认对象的嵌套必填错误丢失路径');
    $mutatingRule = $profileRule->rule('original', static function (mixed $value, Input $input, string $scenario): bool {
        if (!$value instanceof stdClass || $value->city !== '杭州') {
            return false;
        }
        $value->city = '宁波';
        return true;
    });
    helpersAssert(_vali(['profile' => $mutatingRule], []) === ['profile' => ['city' => '宁波']]
        && _vali(['profile' => $mutatingRule], []) === ['profile' => ['city' => '宁波']]
        && _vali(['profile' => $profileRule], []) === ['profile' => ['city' => '杭州']], '回调修改污染了下一次校验或派生规则的默认值');
    $cycle = new stdClass();
    $cycle->self = $cycle;
    foreach ([$cycle, new DateTimeImmutable('2000-01-01'), static fn (): int => 1] as $unsupportedDefault) {
        $declarationRejected = false;
        try {
            Field::text()->defaultValue($unsupportedDefault);
        } catch (InvalidArgumentException $declarationError) {
            $declarationRejected = true;
        }
        helpersAssert($declarationRejected, '默认值接受了循环对象、任意对象或隐式执行工厂');
    }
    $cycle->self = null;
    $callbackFailed = false;
    try {
        _vali(['value' => Field::integer()->defaultValue(7)->rule('custom', static function (mixed $value, Input $input, string $scenario): bool {
            throw new LogicException('规则故障哨兵');
        })], []);
    } catch (LogicException $callbackError) {
        $callbackFailed = $callbackError->getMessage() === '规则故障哨兵';
    }
    helpersAssert($callbackFailed, '默认值规则异常被伪装成普通输入错误');
}

/** 显式入口只使用测试自建输入，不读取隐式全局请求。 */
function main(int $argc, array $argv): void
{
    helpersDefaults();
    $rules = ['age' => Field::integer()->required(), 'active' => Field::boolean()->required(), 'note' => Field::text()->nullable(), 'missing' => Field::text()];
    $checked = _vali($rules, ['age' => 0, 'active' => false, 'note' => null, 'ignored' => '不返回']);
    helpersAssert($checked === ['age' => 0, 'active' => false, 'note' => null], '快捷校验混淆了零、false、null或未声明字段');
    $data = ValidateHelper::data(new Schema($rules), new Input(['body' => ['age' => 1, 'active' => true, 'note' => null]]));
    helpersAssert($data->has('note') && $data->get('note') === null && !$data->has('missing'), 'Data没有保留missing与null');
    helpersAssert(_vali($rules, [], patch: true) === [], 'PATCH为缺失输入制造了值');
    helpersAssert(_vali(['name' => Field::text()->required()], ['name' => '']) === ['name' => ''], 'required不应被助手改成隐式非空规则');
    $input = new Input(['route' => ['id' => '9'], 'query' => ['id' => '7'], 'body' => ['id' => '5']]);
    helpersAssert(_vali(['id' => Field::integer()->cast()->from('route')], $input) === ['id' => 9], '快捷校验合并了不同输入来源');
    helpersAssert(_vali(['name' => Field::text()->required()->inScenarios(['create'])], [], scenario: 'update') === [], '场景被助手忽略');
    $failed = false;
    try {
        _vali(['name' => Field::text()->required()->length(1, 20)], ['name' => '']);
    } catch (ValidationException $error) {
        $failed = $error->errors() === ['name' => ['length']];
    }
    helpersAssert($failed, '显式非空规则没有生效');
    $redacted = false;
    try {
        _vali(['age' => Field::integer()], ['age' => 'sensitive-test-value']);
    } catch (ValidationException $error) {
        $redacted = !str_contains($error->getMessage(), 'sensitive-test-value') && $error->errors() === ['age' => ['type_integer']];
    }
    helpersAssert($redacted, '错误信息泄露输入值或丢失稳定错误码');
    $legacyRejected = false;
    try {
        _vali(['name.require' => '名称必填'], []);
    } catch (InvalidArgumentException) {
        $legacyRejected = true;
    }
    helpersAssert($legacyRejected, '有限DSL之外的字符串规则不能静默忽略');
    $database = new Database(new SqliteDriver(':memory:'));
    $scope = new ExecutionScope();
    try {
        $connection = $database->connect($scope);
        $connection->execute('CREATE TABLE helper_users (id INTEGER PRIMARY KEY, tenant INTEGER NOT NULL, age INTEGER NOT NULL, active INTEGER NOT NULL, note TEXT, name TEXT NOT NULL)');
        foreach ([[1, 1, 0, 0, null, '中文甲'], [2, 1, 18, 1, '', '中文乙'], [3, 1, 30, 1, '备注', 'Other'], [4, 2, 20, 1, null, '中文甲']] as $row) {
            $connection->execute('INSERT INTO helper_users (id, tenant, age, active, note, name) VALUES (?, ?, ?, ?, ?, ?)', $row);
        }
        $base = $connection->table('helper_users')->where('tenant', '=', 1)->orderBy('id');
        $helper = _query($base, ['age' => 0, 'active' => false, 'note' => null, 'order' => 'id DESC; DROP TABLE helper_users']);
        $filtered = $helper->equal('age,active,note')->query()->get();
        helpersAssert(array_column($filtered, 'id') === [1], '等值筛选遗漏0、false或NULL');
        helpersAssert(count($helper->query()->get()) === 3 && count($base->get()) === 3, '链式筛选修改了原查询或原助手');
        helpersAssert(count(_query($base, ['age' => ''])->equal('age')->query()->get()) === 3, '空字符串没有按约定跳过');
        helpersAssert(count(_query($base, [])->equal('age')->query()->get()) === 3, '缺失输入没有跳过');
        helpersAssert(array_column(_query($base, ['keyword' => '中文'])->like(['keyword' => 'name'])->query()->get(), 'id') === [1, 2], '白名单列映射模糊筛选错误');
        helpersAssert(array_column(_query($base, ['id' => '1, 3,4'])->in('id')->query()->get(), 'id') === [1, 3], 'IN筛选绕过原有租户约束');
        helpersAssert(_query($base, ['id' => []])->in('id')->query()->get() === [], '空IN列表不应退化为全表');
        helpersAssert(array_column(_query($base, ['age' => [18, 30]])->between('age')->query()->get(), 'id') === [2, 3], '边界值范围筛选错误');
        helpersAssert(_query($base, ['name' => "x' OR 1=1 --"])->equal('name')->query()->get() === [], '注入文本改变了查询语义');
        helpersAssert(count(_query($base, ['name' => '%'])->like('name')->query()->get()) === 3, 'LIKE通配符应沿用数据库语义');
        $page = _query($base, ['page' => '2', 'page_size' => '1'])->paginatePage();
        helpersAssert($page->total() === 3 && $page->number() === 2 && array_column($page->items(), 'id') === [2], '受限分页结果错误');
        $rejected = false;
        try {
            _query($base, [])->equal(['name' => 'id OR 1=1']);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        helpersAssert($rejected, '非法开发侧列声明不能因缺失输入而被忽略');
        foreach (['page' => [0, -1, true, null, '01', '1e2', '2.5', '10001', '9999999999999999999999'], 'page_size' => [0, -1, false, null, '1001']] as $pageKey => $values) {
            foreach ($values as $invalidValue) {
                $pageRejected = false;
                try {
                    _query($base, [$pageKey => $invalidValue])->paginatePage();
                } catch (InvalidArgumentException) {
                    $pageRejected = true;
                }
                helpersAssert($pageRejected, '非法分页输入未被拒绝');
            }
        }
        $invalidFilters = [['IN', null], ['IN', [1, null]], ['IN', '1,,2'], ['IN', array_fill(0, 1001, 1)],
            ['BETWEEN', [1]], ['BETWEEN', [1, null]], ['BETWEEN', '1 - 2'], ['LIKE', false], ['LIKE', null], ['=', new stdClass()]];
        foreach ($invalidFilters as $invalidFilter) {
            $inputHelper = _query($base, ['age' => $invalidFilter[1]]);
            $filterRejected = false;
            try {
                if ($invalidFilter[0] === 'IN') {
                    $inputHelper->in('age');
                } elseif ($invalidFilter[0] === 'BETWEEN') {
                    $inputHelper->between('age');
                } elseif ($invalidFilter[0] === 'LIKE') {
                    $inputHelper->like('age');
                } else {
                    $inputHelper->equal('age');
                }
            } catch (InvalidArgumentException) {
                $filterRejected = true;
            }
            helpersAssert($filterRejected, '歧义或越界筛选输入未被拒绝');
        }
        $modelBase = (new ModelQuery($connection, TypeHelperUser::mapping(), static fn (array $values): TypeHelperUser => new TypeHelperUser($values)))
            ->where('tenant', '=', 1)->orderBy('id');
        $modelFiltered = _query($modelBase, ['id' => [1, 4], 'active' => false, 'note' => null])->in('id')->equal('active,note')->query();
        helpersAssert($modelFiltered instanceof ModelQuery, '模型查询被助手降成无映射查询');
        $models = $modelFiltered->get();
        helpersAssert(count($models) === 1 && $models[0]->get('id') === 1 && count($modelBase->get()) === 3, '模型筛选丢失类型、租户约束或不可变性');
        $modelPage = _query($modelBase, ['age' => [18, 30], 'keyword' => '中文'])->between('age')->like(['keyword' => 'name'])->paginatePage();
        helpersAssert($modelPage->total() === 1 && $modelPage->items()[0]->get('id') === 2, '模型范围、LIKE或分页未经过公开模型查询');
        $global = $_GET;
        $_GET = ['tenant' => 2, 'age' => 999, 'name' => '泄露'];
        try {
            helpersAssert(count(_query($base)->equal('age')->query()->get()) === 3, '查询助手读取了隐式全局输入');
            helpersAssert(_vali(['name' => Field::text()], []) === [], '校验助手读取了隐式全局输入');
        } finally {
            $_GET = $global;
        }
        helpersOrdering($connection);
    } finally {
        $scope->close();
        $database->close();
    }
    echo "显式输入快捷校验、受限查询、白名单排序与SQLite真实结果通过。\n";
}
