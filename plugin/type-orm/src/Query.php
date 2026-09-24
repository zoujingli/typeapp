<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;

/** 受管连接上的不可变表查询，组合 SQL 与绑定参数，不隐式继承模型生命周期语义。 */
final class Query
{
    private Connection $connection;
    private SqlDialect $dialect;
    private string $table;
    private string $tableName;
    private string $alias;
    private string $aliasName;
    private Conditions $conditions;
    private Conditions $having;
    private array $columns = ['*'];
    private array $joins = [];
    private array $groups = [];
    private array $orders = [];
    private array $orderFields = [];
    private bool $aggregated = false;
    private int $limit = 0;
    private int $offset = 0;
    private bool $all = false;
    private string $lock = '';
    private array $selectParameters = [];
    private array $fromParameters = [];
    private array $joinParameters = [];
    private array $unions = [];
    private bool $distinct = false;
    private bool $derived = false;
    private array $uniqueOrder = [];

    /** 绑定已借出的连接并验证表名/别名；不接受原始 SQL 表达式作为标识符。 */
    public function __construct(Connection $connection, string $table, string $alias = '')
    {
        $this->connection = $connection;
        $this->dialect = new SqlDialect($connection->driverName(), $connection->serverVersion());
        $this->table = $this->dialect->identifier($table);
        $this->tableName = $table;
        $this->alias = $alias === '' ? '' : $this->dialect->identifier($alias, false, false);
        $this->aliasName = $alias;
        $this->conditions = new Conditions($this->dialect, $connection);
        $this->having = new Conditions($this->dialect, $connection);
    }

    /** 将完整子查询作为派生表；外层只能使用声明的别名和输出列。 */
    public static function fromSub(Query $query, string $alias): Query
    {
        $statement = $query->compileFor($query->connection);
        $next = new Query($query->connection, $alias, $alias);
        $next->table = '(' . $statement[0] . ')';
        $next->fromParameters = $statement[1];
        $next->derived = true;
        return $next;
    }

    /** 比较列，不接受 SQL 表达式。 */
    public function whereColumn(string $left, string $operator, string $right, string $boolean = 'AND'): Query
    {
        $next = clone $this;
        $next->conditions = $this->conditions->whereColumn($left, $operator, $right, $boolean);
        return $next;
    }

    /** EXISTS 在数据库执行，子查询必须来自当前连接。 */
    public function whereExists(Query $query, bool $not = false, string $boolean = 'AND'): Query
    {
        $next = clone $this;
        $next->conditions = $this->conditions->whereExists($query, $not, $boolean);
        return $next;
    }

    /** 排除存在匹配行的结果。 */
    public function whereNotExists(Query $query): Query
    {
        return $this->whereExists($query, true);
    }

    /** 返回增加参数化比较的新查询；调用者仍负责字段授权与业务归属。 */
    public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): Query
    {
        $next = clone $this;
        $next->conditions = $this->conditions->where($column, $operator, $value, $boolean);

        return $next;
    }

    /** 以 OR 组合新条件，原查询保持不变。 */
    public function orWhere(string $column, string $operator, mixed $value): Query
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /** 筛选 SQL NULL；not=true 表示 IS NOT NULL。 */
    public function whereNull(string $column, bool $not = false): Query
    {
        return $this->where($column, $not ? '!=' : '=', null);
    }

    /**
     * 组合值集合或同连接子查询，空集合遵循显式真假语义。
     *
     * @param list<scalar>|Query $values
     */
    public function whereIn(string $column, array|Query $values, bool $not = false, string $boolean = 'AND'): Query
    {
        $next = clone $this;
        $next->conditions = $this->conditions->whereIn($column, $values, $not, $boolean);

        return $next;
    }

    /**
     * 排除指定集合；空集合恒真，不能替代有效写入约束。
     *
     * @param list<scalar>|Query $values
     */
    public function whereNotIn(string $column, array|Query $values): Query
    {
        return $this->whereIn($column, $values, true);
    }

    /** @param Closure(Conditions): Conditions $group 接收独立条件组并返回组合后的条件。 */
    public function whereGroup(Closure $group, string $boolean = 'AND'): Query
    {
        $next = clone $this;
        $next->conditions = $this->conditions->whereGroup($group, $boolean);

        return $next;
    }

    /**
     * 按实际方言比较 JSON 标量，不支持复合对象包含匹配。
     *
     * @param list<string|int> $path 最多 16 个字段名或非负下标。
     */
    public function whereJson(string $column, array $path, mixed $value, string $boolean = 'AND'): Query
    {
        $next = clone $this;
        $next->conditions = $this->conditions->whereJson($column, $path, $value, $boolean);

        return $next;
    }

    /**
     * 替换查询投影；列必须是已知标识符，字符串键表示结果别名。
     *
     * @param array<int|string, string> $columns
     */
    public function select(array $columns): Query
    {
        if ($columns === []) {
            throw new DatabaseException('SELECT 至少需要一列');
        }
        $next = clone $this;
        $next->columns = [];
        $next->selectParameters = [];
        $next->aggregated = false;
        foreach ($columns as $alias => $column) {
            if (!is_string($column)) {
                throw new DatabaseException('SELECT 列必须是标识符');
            }
            $sql = $this->dialect->identifier($column, true);
            if (is_string($alias)) {
                $sql .= ' AS ' . $this->dialect->identifier($alias, false, false);
            }
            $next->columns[] = $sql;
        }

        return $next;
    }

    /** 追加 COUNT、SUM、AVG、MIN 或 MAX 投影，并以显式别名读取结果。 */
    public function selectAggregate(string $function, string $column, string $alias): Query
    {
        $next = clone $this;
        $next->aggregated = true;
        if ($next->columns === ['*']) {
            $next->columns = [];
        }
        $next->columns[] = $this->dialect->aggregate($function, $column) . ' AS ' . $this->dialect->identifier($alias, false, false);

        return $next;
    }

    /** 追加标量子查询；子查询必须自行保证至多一行、一列。 */
    public function selectSub(Query $query, string $alias): Query
    {
        $statement = $query->compileFor($this->connection);
        $next = clone $this;
        $next->columns[] = '(' . $statement[0] . ') AS ' . $this->dialect->identifier($alias, false, false);
        $next->selectParameters = array_merge($this->selectParameters, $statement[1]);
        return $next;
    }

    /** 对完整投影去重。 */
    public function distinct(bool $enabled = true): Query
    {
        $next = clone $this;
        $next->distinct = $enabled;
        return $next;
    }

    /** 合并兼容投影；当前查询的排序与 LIMIT 作用于合并后的结果。 */
    public function union(Query $query, bool $all = false): Query
    {
        $next = clone $this;
        $next->unions[] = [$query->compileFor($this->connection), $all];
        return $next;
    }

    /** 合并并保留重复结果行。 */
    public function unionAll(Query $query): Query
    {
        return $this->union($query, true);
    }

    /** 将子查询输出参与联表；连接条件只接受列标识符。 */
    public function joinSub(Query $query, string $alias, string $left, string $operator, string $right, string $type = 'INNER'): Query
    {
        $statement = $query->compileFor($this->connection);
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT'], true)) {
            throw new DatabaseException('统一查询只支持 INNER 和 LEFT JOIN');
        }
        $next = clone $this;
        $next->joins[] = $type . ' JOIN (' . $statement[0] . ') AS ' . $this->dialect->identifier($alias, false, false)
            . ' ON ' . $this->dialect->identifier($left) . ' ' . $this->dialect->operator($operator) . ' ' . $this->dialect->identifier($right);
        $next->joinParameters = array_merge($this->joinParameters, $statement[1]);
        return $next;
    }

    /** 声明复杂结果的唯一排序组合；调用方负责其真实唯一性，分页时补齐遗漏列。 */
    public function uniqueOrderBy(array $columns): Query
    {
        if ($columns === [] || !array_is_list($columns) || count(array_unique($columns)) !== count($columns)) {
            throw new DatabaseException('唯一排序键必须是非空且无重复的列列表');
        }
        $next = clone $this;
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new DatabaseException('唯一排序键必须是列标识符');
            }
            $this->dialect->identifier($column);
        }
        $next->uniqueOrder = $columns;
        return $next;
    }

    /** 以列与列比较组合 INNER/LEFT JOIN，不接受值拼接或任意 ON 表达式。 */
    public function join(string $table, string $left, string $operator, string $right, string $alias = '', string $type = 'INNER'): Query
    {
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT'], true)) {
            throw new DatabaseException('统一查询只支持 INNER 和 LEFT JOIN');
        }
        $next = clone $this;
        $sql = $type . ' JOIN ' . $this->dialect->identifier($table);
        if ($alias !== '') {
            $sql .= ' AS ' . $this->dialect->identifier($alias, false, false);
        }
        $sql .= ' ON ' . $this->dialect->identifier($left) . ' ' . $this->dialect->operator($operator) . ' ' . $this->dialect->identifier($right);
        $next->joins[] = $sql;

        return $next;
    }

    /**
     * 追加分组列，调用者负责投影满足数据库分组规则。
     *
     * @param list<string> $columns
     */
    public function groupBy(array $columns): Query
    {
        if ($columns === []) {
            throw new DatabaseException('GROUP BY 至少需要一列');
        }
        $next = clone $this;
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new DatabaseException('GROUP BY 列必须是标识符');
            }
            $next->groups[] = $this->dialect->identifier($column);
        }

        return $next;
    }

    /** 在分组结果上加入聚合比较，参数仍通过绑定传递。 */
    public function havingAggregate(string $function, string $column, string $operator, mixed $value): Query
    {
        $next = clone $this;
        $next->having = $this->having->whereAggregate($function, $column, $operator, $value);

        return $next;
    }

    /** 追加排序，方向只接受 ASC/DESC；外部字段名须先经业务白名单。 */
    public function orderBy(string $column, string $direction = 'ASC'): Query
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new DatabaseException('排序方向只支持 ASC 或 DESC');
        }
        $next = clone $this;
        $next->orders[] = $this->dialect->identifier($column) . ' ' . $direction;
        $next->orderFields[] = ['column' => $column, 'direction' => $direction];

        return $next;
    }

    /**
     * 在没有同名排序列时追加排序，已存在则保留最早的方向与优先级，不修改当前查询。
     *
     * 即使该列已排序也先验证标识符和方向；不改变 orderBy 及分页对重复列的严格契约。
     *
     * @throws DatabaseException 排序标识符无效或方向不为 ASC/DESC。
     */
    public function orderByIfAbsent(string $column, string $direction = 'ASC'): Query
    {
        $next = $this->orderBy($column, $direction);
        foreach ($this->orderFields as $field) {
            if ($field['column'] === $column) {
                return clone $this;
            }
        }

        return $next;
    }

    /** 外部输入必须映射到应用明确允许的列；语法有效不等于有权读取。 */
    public function orderByAllowed(string $input, array $allowed, string $direction = 'ASC'): Query
    {
        if (!isset($allowed[$input]) || !is_string($allowed[$input])) {
            throw new DatabaseException('动态排序列不在已知范围内');
        }

        return $this->orderBy($allowed[$input], $direction);
    }

    /** 声明显式读取条数与零起点偏移，不改变原查询，也不是集合写入分批入口。 */
    public function limit(int $limit, int $offset = 0): Query
    {
        if ($limit < 1 || $offset < 0) {
            throw new DatabaseException('查询条数必须为正数，偏移不能为负数');
        }
        $next = clone $this;
        $next->limit = $limit;
        $next->offset = $offset;

        return $next;
    }

    /**
     * 执行当前投影并一次性返回全部结果。
     *
     * @return list<array<string, mixed>> 未命中返回空列表。
     */
    public function get(): array
    {
        $statement = $this->selectStatement();
        return $this->connection->query($statement[0], $statement[1]);
    }

    /** 声明事务行锁；不支持的驱动拒绝，执行时仍须位于活动事务。 */
    public function lockForUpdate(bool $skipLocked = false): Query
    {
        $this->dialect->requireCapability($skipLocked ? 'skip-locked' : 'row-lock');
        if ($this->groups !== [] || $this->having->sql() !== '' || $this->aggregated) {
            throw new DatabaseException('行锁只支持非聚合查询');
        }
        $copy = clone $this;
        $copy->lock = $skipLocked ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
        return $copy;
    }

    /**
     * 打开事务外独占租约的结果流；调用者必须在作用域结束前关闭。
     *
     * @param int $batchSize 每批最多 1 至 1000 行。
     * @param int $maxRowBytes 单行字节上限，范围 1 至 16 MiB。
     */
    public function stream(int $batchSize = 250, int $maxRowBytes = 1048576): RowStream
    {
        $statement = $this->selectStatement();
        return $this->connection->stream($statement[0], $statement[1], $batchSize, $maxRowBytes);
    }

    /** 分别计数和取页；需同一快照时由调用者事务保证，复杂结果须声明唯一排序键。 */
    public function paginate(int $page = 1, int $perPage = 20, string $primaryKey = 'id'): Page
    {
        $this->pageSize($perPage);
        if ($page < 1 || $page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new DatabaseException('页码无效或偏移溢出');
        }
        $ordered = $this->offsetPaginationQuery($primaryKey);
        $counted = clone $this;
        $counted->orders = [];
        $counted->orderFields = [];
        $total = (int) self::fromSub($counted, '__type_page')->aggregate('COUNT');
        $items = $ordered->limit($perPage, ($page - 1) * $perPage)->get();
        return new Page($items, $total, $page, $perPage);
    }

    /** 不执行总数查询，多取一行判断下一页。 */
    public function simplePaginate(int $page = 1, int $perPage = 20, string $primaryKey = 'id'): SimplePage
    {
        $this->pageSize($perPage);
        if ($page < 1 || $page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new DatabaseException('页码无效或偏移溢出');
        }
        $rows = $this->offsetPaginationQuery($primaryKey)->limit($perPage + 1, ($page - 1) * $perPage)->get();
        $more = count($rows) > $perPage;
        if ($more) {
            array_pop($rows);
        }
        return new SimplePage($rows, $page, $perPage, $more);
    }

    private function offsetPaginationQuery(string $primaryKey): Query
    {
        if ($this->limit !== 0 || $this->lock !== '') {
            throw new DatabaseException('分页不接受已有 LIMIT 或行锁');
        }
        if (!$this->complex(false)) {
            return $this->paginationQuery($primaryKey);
        }
        if ($this->uniqueOrder === []) {
            throw new DatabaseException('复杂结果分页必须通过 uniqueOrderBy 声明唯一排序键');
        }
        $query = clone $this;
        foreach ($this->uniqueOrder as $column) {
            $query = $query->orderByIfAbsent($column);
        }
        $seen = [];
        foreach ($query->orderFields as $field) {
            if (in_array($field['column'], $seen, true)) {
                throw new DatabaseException('分页排序列不能重复');
            }
            $seen[] = $field['column'];
        }
        if (count($seen) > 8) {
            throw new DatabaseException('分页最多支持八个排序列');
        }
        return $query;
    }

    private function complex(bool $includeAlias = true): bool
    {
        return ($includeAlias && $this->alias !== '') || $this->joins !== [] || $this->groups !== [] || $this->having->sql() !== ''
            || $this->aggregated || $this->distinct || $this->derived || $this->unions !== [];
    }

    /** 按稳定单表排序继续读取，after 必须来自同一查询身份；不支持复杂结果游标。 */
    public function cursorPaginate(int $perPage = 100, ?string $after = null, string $primaryKey = 'id'): CursorPage
    {
        $this->pageSize($perPage);
        $query = clone $this->paginationQuery($primaryKey);
        $statement = $query->selectStatement();
        $shape = hash('sha256', serialize([$this->connection->identity(), $statement, $query->orderFields]));
        if ($after !== null) {
            $values = CursorToken::decode($after, $shape, count($query->orderFields));
            $fields = $query->orderFields;
            $query = $query->whereGroup(static function (Conditions $outer) use ($fields, $values): Conditions {
                foreach ($fields as $index => $field) {
                    $outer = $outer->whereGroup(static function (Conditions $inner) use ($fields, $values, $index, $field): Conditions {
                        for ($before = 0; $before < $index; $before++) {
                            $inner = $inner->where($fields[$before]['column'], '=', $values[$before]);
                        }
                        return $inner->where($field['column'], $field['direction'] === 'ASC' ? '>' : '<', $values[$index]);
                    }, $index === 0 ? 'AND' : 'OR');
                }
                return $outer;
            });
        }
        foreach ($query->orderFields as $index => $field) {
            $query->columns[] = $this->dialect->identifier($field['column']) . ' AS ' . $this->dialect->identifier('__type_cursor_' . $index);
        }
        $rows = $query->limit($perPage + 1)->get();
        $more = count($rows) > $perPage;
        if ($more) {
            array_pop($rows);
        }
        $values = [];
        if ($rows !== []) {
            foreach ($query->orderFields as $index => $field) {
                $values[] = $rows[count($rows) - 1]['__type_cursor_' . $index];
            }
        }
        foreach ($rows as &$row) {
            foreach ($query->orderFields as $index => $field) {
                unset($row['__type_cursor_' . $index]);
            }
        }
        unset($row);
        return new CursorPage($rows, $more ? CursorToken::encode($shape, $values) : null);
    }

    /** @param Closure(array): mixed $consumer 接收本批行；可返回 void，仅 false 提前退出。 */
    public function chunk(int $size, Closure $consumer, string $primaryKey = 'id'): int
    {
        $cursor = null;
        $count = 0;
        do {
            $page = $this->cursorPaginate($size, $cursor, $primaryKey);
            $items = $page->items();
            if ($items === []) {
                break;
            }
            $count += count($items);
            $cursor = $page->next();
            if ($consumer($items) === false) {
                break;
            }
            unset($items, $page);
        } while ($cursor !== null);
        return $count;
    }

    private function pageSize(int $size): void
    {
        if ($size < 1 || $size > 1000) {
            throw new DatabaseException('分页大小必须在 1 至 1000 之间');
        }
    }

    private function paginationQuery(string $primaryKey): Query
    {
        if ($this->complex(false) || $this->limit !== 0 || $this->lock !== '') {
            throw new DatabaseException('确定分页只接受没有 Join、聚合、行锁或已有 LIMIT 的单表查询');
        }
        foreach ($this->columns as $column) {
            if (str_contains($column, '__type_cursor_')) {
                throw new DatabaseException('分页投影占用了内部别名前缀');
            }
        }
        $columns = [];
        $primary = [];
        foreach ($this->connection->columns($this->tableName) as $column) {
            if (str_starts_with($column['name'], '__type_cursor_')) {
                throw new DatabaseException('表列占用了分页内部别名前缀');
            }
            $columns[$column['name']] = $column;
            if ($column['primary']) {
                $primary[] = $column['name'];
            }
        }
        if ($primary !== [$primaryKey]) {
            throw new DatabaseException('分页需要真实的单列主键作为唯一排序收尾');
        }
        $query = $this;
        $ordered = [];
        foreach ($this->orderFields as $field) {
            $orderedColumn = $this->paginationColumn($field['column']);
            if (in_array($orderedColumn, $ordered, true)) {
                throw new DatabaseException('分页排序列不能重复');
            }
            $ordered[] = $orderedColumn;
        }
        if (!in_array($primaryKey, $ordered, true)) {
            $query = $query->orderBy(($this->aliasName === '' ? '' : $this->aliasName . '.') . $primaryKey);
        }
        if (count($query->orderFields) > 8) {
            throw new DatabaseException('分页最多支持八个排序列');
        }
        foreach ($query->orderFields as $field) {
            $column = $columns[$this->paginationColumn($field['column'])] ?? null;
            if ($column === null || $column['nullable'] || preg_match('/json|blob|bytea|binary|real|double|float/i', $column['type'])) {
                throw new DatabaseException('分页排序只接受真实的非 NULL、非浮点、非 JSON/二进制列');
            }
        }
        return $query;
    }

    /** 分页校验对应真实表列，SQL 排序仍保留本表别名。 */
    private function paginationColumn(string $column): string
    {
        return $this->aliasName !== '' && str_starts_with($column, $this->aliasName . '.')
            ? substr($column, strlen($this->aliasName) + 1) : $column;
    }

    /** 预览参数化 SQL，不执行目标查询，也不要求预览时已经开启事务。 */
    public function toSql(): string
    {
        return $this->selectStatement(false)[0];
    }

    /** 返回原始绑定值，仅供调用方显式检查；查询监听使用独立脱敏策略。 */
    public function bindings(): array
    {
        return $this->selectStatement(false)[1];
    }

    /** @internal 组合子查询时验证连接身份与锁边界。 */
    public function compileFor(Connection $connection): array
    {
        if ($connection !== $this->connection || $this->lock !== '') {
            throw new DatabaseException('子查询必须来自同一连接且不能携带行锁');
        }
        return $this->selectStatement(false);
    }

    private function selectStatement(bool $executing = true): array
    {
        if ($this->lock !== '' && (($executing && $this->connection->transactionDepth() === 0) || $this->groups !== [] || $this->having->sql() !== '' || $this->aggregated || $this->distinct || $this->unions !== [])) {
            throw new DatabaseException('行锁读取需要活动事务和非聚合查询');
        }
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . implode(', ', $this->columns) . ' FROM ' . $this->table;
        if ($this->alias !== '') {
            $sql .= ' AS ' . $this->alias;
        }
        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        if ($this->conditions->sql() !== '') {
            $sql .= ' WHERE ' . $this->conditions->sql();
        }
        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }
        if ($this->having->sql() !== '') {
            $sql .= ' HAVING ' . $this->having->sql();
        }
        $parameters = array_merge($this->selectParameters, $this->fromParameters, $this->joinParameters, $this->conditions->parameters(), $this->having->parameters());
        if ($this->unions !== []) {
            $sql = 'SELECT * FROM (' . $sql . ') AS ' . $this->dialect->identifier('__type_union_0');
            foreach ($this->unions as $index => $union) {
                $sql .= ($union[1] ? ' UNION ALL ' : ' UNION ') . 'SELECT * FROM (' . $union[0][0]
                    . ') AS ' . $this->dialect->identifier('__type_union_' . ($index + 1));
                $parameters = array_merge($parameters, $union[0][1]);
            }
            $sql = 'SELECT * FROM (' . $sql . ') AS ' . $this->dialect->identifier('__type_union');
        }
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $parameters[] = $this->limit;
            $parameters[] = $this->offset;
        }
        $sql .= $this->lock;

        return [$sql, $parameters];
    }

    /**
     * 按当前偏移最多读取一行；未命中返回 null。
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $rows = $this->limit(1, $this->offset)->get();

        return $rows[0] ?? null;
    }

    /** 不加载全部结果，只检查当前结果集是否非空。 */
    public function exists(): bool
    {
        return $this->first() !== null;
    }

    /** 读取单列首值；没有结果返回 null。 */
    public function value(string $column): mixed
    {
        $source = $this->extractionQuery();
        $row = $source->select(['__type_value' => $column])->first();
        return $row === null ? null : $row['__type_value'];
    }

    /** 读取单列列表；提供键列时拒绝重复键，避免静默丢失结果。 */
    public function pluck(string $column, ?string $key = null): array
    {
        $projection = ['__type_value' => $column];
        if ($key !== null) {
            $projection['__type_key'] = $key;
        }
        $values = [];
        foreach ($this->extractionQuery()->select($projection)->get() as $row) {
            if ($key === null) {
                $values[] = $row['__type_value'];
            } else {
                $identity = $row['__type_key'];
                if ((!is_int($identity) && !is_string($identity)) || array_key_exists($identity, $values)) {
                    throw new DatabaseException('pluck 键必须是唯一的整数或字符串');
                }
                $values[$identity] = $row['__type_value'];
            }
        }
        return $values;
    }

    /** 已形成的集合/分组投影从结果列提取，不能只改写 UNION 首支的列数。 */
    private function extractionQuery(): Query
    {
        return $this->unions !== [] || $this->groups !== [] || $this->aggregated || $this->distinct
            ? self::fromSub($this, '__type_values') : $this;
    }

    /** 空结果使用稳定模型错误码报告。 */
    public function firstOrFail(): array
    {
        return $this->first() ?? throw new ModelException('not_found', '查询结果不存在');
    }

    /** 按显式主键列获取一行，空结果抛出异常。 */
    public function findOrFail(mixed $id, string $primaryKey = 'id'): array
    {
        return $this->where($primaryKey, '=', $id)->firstOrFail();
    }

    /** 执行单值聚合并保留数据库返回类型；分组或分页查询需另建聚合查询。 */
    public function aggregate(string $function, string $column = '*'): mixed
    {
        $rows = $this->aggregateQuery($function, $column)->get();
        return $rows[0]['value'];
    }

    /** 生成单值聚合查询以供组合；不会提前执行子查询。 */
    public function aggregateQuery(string $function, string $column = '*'): Query
    {
        if ($this->groups !== [] || $this->having->sql() !== '' || $this->limit !== 0 || $this->lock !== '' || $this->distinct || $this->unions !== []) {
            throw new DatabaseException('标量聚合不允许分组、HAVING、分页或行锁，请使用独立聚合查询');
        }
        $next = clone $this;
        $next->columns = [$this->dialect->aggregate($function, $column) . ' AS ' . $this->dialect->identifier('value')];
        $next->orders = [];
        $next->selectParameters = [];
        $next->orderFields = [];
        $next->aggregated = true;
        return $next;
    }

    /** 显式允许无约束更新或删除；仅对返回的新查询生效。 */
    public function allowAll(): Query
    {
        $next = clone $this;
        $next->all = true;

        return $next;
    }

    /**
     * 读取实际驱动及版本对应的能力与影响行数语义。
     *
     * @return array<string, bool|string>
     */
    public function capabilities(): array
    {
        return $this->dialect->capabilities();
    }

    /**
     * 插入一行并返回数据库影响行数，不触发模型字段策略或模型事件。
     *
     * @param array<string, scalar|null> $row 实际数据库列到值的映射。
     */
    public function insert(array $row): int
    {
        return $this->insertMany([$row]);
    }

    /** 单条 INSERT，不隐式分批；原子性由实际表的事务能力保证。 */
    public function insertMany(array $rows): int
    {
        $this->writeShape(false);
        if ($rows === []) {
            return 0;
        }
        $statement = $this->insertStatement($rows);

        return $this->connection->execute($statement[0], $statement[1]);
    }

    /**
     * 在支持 RETURNING 的方言插入并读取指定列，不模拟 MySQL 返回能力。
     *
     * @param list<array<string, scalar|null>> $rows
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    public function insertReturning(array $rows, array $columns = ['*']): array
    {
        $this->dialect->requireCapability('insert-returning');
        $this->writeShape(false);
        if ($columns === [] || !array_is_list($columns)) {
            throw new DatabaseException('RETURNING 必须指定列列表');
        }
        $returning = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new DatabaseException('RETURNING 列必须是标识符');
            }
            $returning[] = $this->dialect->identifier($column, true, false);
        }
        if ($rows === []) {
            return [];
        }
        $statement = $this->insertStatement($rows);

        return $this->connection->query($statement[0] . ' RETURNING ' . implode(', ', $returning), $statement[1]);
    }

    /** PostgreSQL / SQLite 的冲突目标必须对应数据库中的非部分唯一约束。 */
    public function upsert(array $rows, array $uniqueBy, array $updateColumns): int
    {
        $this->dialect->requireCapability('upsert-conflict-target');
        $this->writeShape(false);
        if ($rows === []) {
            return 0;
        }
        $statement = $this->insertStatement($rows);
        $target = $this->writeColumns($uniqueBy, $statement[2]);
        $updates = $this->writeColumns($updateColumns, $statement[2]);
        $assignments = [];
        foreach ($updates as $column) {
            $assignments[] = $column . ' = excluded.' . $column;
        }
        $sql = $statement[0] . ' ON CONFLICT (' . implode(', ', $target) . ') DO UPDATE SET ' . implode(', ', $assignments);

        return $this->connection->execute($sql, $statement[1]);
    }

    /** MySQL 明确接受任意唯一键冲突；不接受、也不忽略伪装成指定目标的参数。 */
    public function upsertAnyUnique(array $rows, array $updateColumns): int
    {
        $this->dialect->requireCapability('upsert-any-unique');
        $this->writeShape(false);
        if ($rows === []) {
            return 0;
        }
        $statement = $this->insertStatement($rows);
        $updates = $this->writeColumns($updateColumns, $statement[2]);
        $assignments = [];
        foreach ($updates as $column) {
            $assignments[] = $column . ' = type_upsert_values.' . $column;
        }
        $sql = $statement[0] . ' AS type_upsert_values ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        return $this->connection->execute($sql, $statement[1]);
    }

    /**
     * 执行单条集合 UPDATE；缺少有效条件须显式 allowAll，不隐式逐行加载。
     *
     * @param array<string, scalar|null> $values
     */
    public function update(array $values): int
    {
        return $this->updateValues($values, null);
    }

    /** @internal 模型集合写入；版本列须为真实非空整数列，失败由外层事务回滚。 */
    public function updateGuarded(array $values, ?string $version): int
    {
        if ($version !== null && array_key_exists($version, $values)) {
            throw new DatabaseException('普通赋值不能覆盖版本推进');
        }
        return $this->updateValues($values, $version, true);
    }

    private function updateValues(array $values, ?string $version, bool $guarded = false): int
    {
        $this->writeShape(true);
        $this->requireWriteIntent();
        $row = $this->rows([$values]);
        $assignments = [];
        foreach ($row[0] as $column) {
            $assignments[] = $this->dialect->identifier($column, false, false) . ' = ?';
        }
        if ($version !== null) {
            $assignments[] = $this->versionAssignment($version);
        }
        $sql = ($guarded && $this->connection->driverName() === 'sqlite' ? 'UPDATE OR ABORT ' : 'UPDATE ')
            . $this->table . ' SET ' . implode(', ', $assignments) . $this->whereSql($this->conditions);

        return $this->connection->execute($sql, array_merge($row[1], $this->conditions->parameters()));
    }

    /** 单条条件 UPDATE 原子增加；返回数据库报告的影响行数，不预读。 */
    public function increment(string $column, int $amount = 1): int
    {
        return $this->adjust($column, $amount, false);
    }

    /** 单条条件 UPDATE 原子减少；负数与零增量拒绝，方向由方法明确。 */
    public function decrement(string $column, int $amount = 1): int
    {
        return $this->adjust($column, $amount, true);
    }

    /** @internal 模型原子写入同时递增已验证的非空整数版本列，须由事务包裹。 */
    public function adjust(string $column, int $amount, bool $decrement, ?string $version = null): int
    {
        $this->writeShape(true);
        $this->requireWriteIntent();
        if ($amount < 1) {
            throw new DatabaseException('原子增减的数量必须为正整数');
        }
        $quoted = $this->dialect->identifier($column, false, false);
        $assignment = $quoted . ' = ' . $quoted . ($decrement ? ' - ?' : ' + ?');
        if ($version !== null) {
            if ($version === $column) {
                throw new DatabaseException('原子增减目标不能同时作为版本列');
            }
            $assignment .= ', ' . $this->versionAssignment($version);
        }
        $prefix = $version !== null && $this->connection->driverName() === 'sqlite' ? 'UPDATE OR ABORT ' : 'UPDATE ';
        return $this->connection->execute($prefix . $this->table . ' SET ' . $assignment
            . $this->whereSql($this->conditions), array_merge([$amount], $this->conditions->parameters()));
    }

    /** 让数据库在同一语句中拒绝非法版本，避免 SQLite 溢出升 REAL 或竞争窗口。 */
    private function versionAssignment(string $version): string
    {
        $column = $this->dialect->identifier($version, false, false);
        $valid = $column . ' >= 1 AND ' . $column . ' < ' . PHP_INT_MAX;
        if ($this->connection->driverName() === 'sqlite') {
            $valid .= ' AND typeof(' . $column . ") = 'integer'";
        }
        // 非法分支在赋值求值时失败，不依赖可能被触发器跳过的 NOT NULL 检查。
        // 含列的表达式防止 PostgreSQL/MySQL 在有效分支执行前折叠常量错误。
        $failure = match ($this->connection->driverName()) {
            'sqlite' => 'abs(-9223372036854775807 - 1)',
            'pgsql' => '1 / (' . $column . ' - ' . $column . ')',
            'mysql' => 'CAST(9223372036854775807 AS SIGNED) + CAST(' . $column . ' - ' . $column . ' + 1 AS SIGNED)',
        };
        return $column . ' = CASE WHEN ' . $valid . ' THEN ' . $column . ' + 1 ELSE ' . $failure . ' END';
    }

    /** 每行通过一个非 NULL 键匹配；单条 CASE UPDATE 不隐式逐条重试。 */
    public function updateMany(string $key, array $rows): int
    {
        $this->writeShape(true);
        $keySql = $this->dialect->identifier($key, false, false);
        if ($rows === []) {
            return 0;
        }
        $shape = $this->rows($rows);
        if (!in_array($key, $shape[0], true) || count($shape[0]) < 2) {
            throw new DatabaseException('批量更新每行必须含匹配键和至少一个更新列');
        }
        $keys = [];
        $seen = [];
        foreach ($rows as $row) {
            $value = $row[$key];
            if (!is_int($value) && !is_string($value)) {
                throw new DatabaseException('批量更新匹配键只接受整数或字符串');
            }
            $identity = (string) $value;
            if (in_array($identity, $seen, true)) {
                throw new DatabaseException('批量更新不能重复同一个匹配键');
            }
            $seen[] = $identity;
            $keys[] = $value;
        }
        $parameters = [];
        $assignments = [];
        foreach ($shape[0] as $column) {
            if ($column === $key) {
                continue;
            }
            $columnSql = $this->dialect->identifier($column, false, false);
            $parts = [];
            foreach ($rows as $row) {
                $parts[] = 'WHEN ? THEN ?';
                $parameters[] = $this->dialect->value($row[$key]);
                $parameters[] = $this->dialect->value($row[$column]);
            }
            $assignments[] = $columnSql . ' = CASE ' . $keySql . ' ' . implode(' ', $parts) . ' ELSE ' . $columnSql . ' END';
        }
        $conditions = $this->conditions->whereIn($key, $keys);
        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $assignments) . $this->whereSql($conditions);

        return $this->connection->execute($sql, array_merge($parameters, $conditions->parameters()));
    }

    /** 执行单条集合 DELETE 并返回数据库影响行数；默认拒绝无约束写入。 */
    public function delete(): int
    {
        $this->writeShape(true);
        $this->requireWriteIntent();

        return $this->connection->execute('DELETE FROM ' . $this->table . $this->whereSql($this->conditions), $this->conditions->parameters());
    }

    private function requireWriteIntent(): void
    {
        if (!$this->all && $this->conditions->unconstrained()) {
            throw new DatabaseException('无约束更新或删除必须显式调用 allowAll');
        }
    }

    /** @internal 添加模型默认范围之前验证调用者提供的写入条件。 */
    public function assertWriteIntent(): void
    {
        $this->requireWriteIntent();
    }

    /** @internal 集合写入在读取目标前拒绝不能保留的查询形态。 */
    public function assertWritable(): void
    {
        $this->writeShape(true);
        $this->requireWriteIntent();
    }

    private function writeShape(bool $conditions): void
    {
        if ($this->complex() || $this->orders !== [] || $this->limit !== 0 || $this->columns !== ['*'] || $this->lock !== '') {
            throw new DatabaseException('写入不接受别名、Join、分组、聚合、排序、分页、行锁或投影，不能静默忽略查询状态');
        }
        if (!$conditions && $this->conditions->sql() !== '') {
            throw new DatabaseException('新增或 upsert 不接受查询筛选条件');
        }
    }

    private function whereSql(Conditions $conditions): string
    {
        return $conditions->sql() === '' ? '' : ' WHERE ' . $conditions->sql();
    }

    private function insertStatement(array $rows): array
    {
        $shape = $this->rows($rows);
        $columns = [];
        foreach ($shape[0] as $column) {
            $columns[] = $this->dialect->identifier($column, false, false);
        }
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', array_fill(0, count($rows), $tuple));

        return [$sql, $shape[1], $shape[0]];
    }

    private function rows(array $rows): array
    {
        if ($rows === [] || !array_is_list($rows) || !is_array($rows[0]) || $rows[0] === []) {
            throw new DatabaseException('批次必须是非空的关联行列表');
        }
        $columns = array_keys($rows[0]);
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new DatabaseException('写入列必须是标识符');
            }
            $this->dialect->identifier($column, false, false);
        }
        $parameters = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== count($columns)) {
                throw new DatabaseException('批次每行必须包含相同列');
            }
            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    throw new DatabaseException('批次每行必须包含相同列');
                }
                $parameters[] = $this->dialect->value($row[$column]);
            }
        }

        return [$columns, $parameters];
    }

    private function writeColumns(array $columns, array $available): array
    {
        if ($columns === [] || !array_is_list($columns)) {
            throw new DatabaseException('冲突目标和更新列必须是非空列表');
        }
        $quoted = [];
        foreach ($columns as $column) {
            if (!is_string($column) || !in_array($column, $available, true)) {
                throw new DatabaseException('冲突目标和更新列必须出现在新增数据中');
            }
            $sql = $this->dialect->identifier($column, false, false);
            if (in_array($sql, $quoted, true)) {
                throw new DatabaseException('冲突目标和更新列不允许重复');
            }
            $quoted[] = $sql;
        }

        return $quoted;
    }
}
