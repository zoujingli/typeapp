<?php

declare(strict_types=1);

namespace Type\Orm\Helper;

use InvalidArgumentException;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Orm\Page;
use Type\Orm\Query;

/** 在既有查询约束上追加受信字段筛选；每次链式调用返回新对象。 */
final class QueryHelper
{
    private Query|ModelQuery $query;

    /** @var array<string, mixed> 仅从调用者显式接收的输入。 */
    private array $input;

    /** @var array<string, bool> 模型助手已声明的输入键；分页键由固定分页入口保留。 */
    private array $declared = ['page' => true, 'page_size' => true];

    /**
     * 查询已由调用者绑定连接、模型与租户范围，助手不会另建查询。
     *
     * @param array<string, mixed> $input 明确的筛选输入。
     */
    public function __construct(Query|ModelQuery $query, array $input = [])
    {
        $this->query = $query;
        $this->input = $input;
    }

    /**
     * 追加等值条件；缺失和空字符串跳过，0/false有效，null生成IS NULL。
     *
     * @param array<string, string>|list<string>|string $fields 输入名到列/模型字段的受信映射，或同名字段列表。
     * @throws InvalidArgumentException 字段声明或选中输入不是合法标量。
     */
    public function equal(array|string $fields): QueryHelper
    {
        return $this->filter($fields, '=');
    }

    /**
     * 添加包含式 LIKE，保留数据库的百分号、下划线和大小写规则。
     *
     * @param array<string, string>|list<string>|string $fields 开发者明确允许的文本字段。
     * @throws InvalidArgumentException 选中值不是文本或字段声明无效。
     */
    public function like(array|string $fields): QueryHelper
    {
        return $this->filter($fields, 'LIKE');
    }

    /**
     * 追加最多1000个绑定值的IN条件；空列表匹配零行，不跳过筛选。
     *
     * @param array<string, string>|list<string>|string $fields 开发者明确允许的字段。
     * @throws InvalidArgumentException 输入不是标量列表或逗号分隔文本，或者超过限额。
     */
    public function in(array|string $fields): QueryHelper
    {
        return $this->filter($fields, 'IN');
    }

    /**
     * 用两个AND条件表达闭区间，不猜测日期格式、时区或日末边界。
     *
     * @param array<string, string>|list<string>|string $fields 输入必须是两个非空标量组成的列表。
     * @throws InvalidArgumentException 区间不是完整的上下界。
     */
    public function between(array|string $fields): QueryHelper
    {
        return $this->filter($fields, 'BETWEEN');
    }

    /**
     * 追加白名单排序；业务查询中已有的列及方向优先，不替换原排序或放宽范围。
     *
     * sort/direction 均缺失或为空串时采用默认映射。指定 sort 后方向缺失/空串为 ASC，
     * 方向不与未选中的默认排序混用；只给方向而没有 sort 明确拒绝。
     *
     * @param array<string, string>|list<string> $allowed 公开排序名到受信列/模型字段的映射，或同名列表。
     * @param array<string, string> $defaults 按优先级排列的公开排序名到 ASC/DESC，最多八列且映射列不能重复。
     * @throws InvalidArgumentException 声明、输入名、客户端排序字段或方向不符合白名单。
     * @throws \Type\Orm\DatabaseException 底层标识符或模型字段无效；保留原查询的验证语义。
     */
    public function order(array $allowed, array $defaults = [], string $fieldKey = 'sort', string $directionKey = 'direction'): QueryHelper
    {
        $mapping = $this->fields($allowed);
        foreach ($mapping as $alias => $column) {
            if (strlen($alias) > 128) {
                throw new InvalidArgumentException('公开排序名不能超过128字节');
            }
        }
        if ($fieldKey === $directionKey || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $fieldKey) !== 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $directionKey) !== 1
            || count($defaults) > 8 || ($defaults !== [] && array_is_list($defaults))) {
            throw new InvalidArgumentException('排序输入名或默认排序声明无效');
        }
        $orders = [];
        foreach ($defaults as $defaultAlias => $defaultDirection) {
            if (!is_string($defaultAlias) || !array_key_exists($defaultAlias, $mapping)) {
                throw new InvalidArgumentException('默认排序名不在开发者白名单中');
            }
            $defaultColumn = $mapping[$defaultAlias];
            if (array_key_exists($defaultColumn, $orders)) {
                throw new InvalidArgumentException('默认排序不能把多个公开名映射到同一列');
            }
            $orders[$defaultColumn] = $this->direction($defaultDirection);
        }
        $selected = array_key_exists($fieldKey, $this->input) ? $this->input[$fieldKey] : '';
        $directionValue = array_key_exists($directionKey, $this->input) ? $this->input[$directionKey] : '';
        if ($selected === '') {
            if ($directionValue !== '') {
                throw new InvalidArgumentException('必须选择排序字段后才能指定方向');
            }
        } else {
            if (!is_string($selected) || strlen($selected) > 128 || !array_key_exists($selected, $mapping)) {
                throw new InvalidArgumentException('排序字段必须是开发者白名单中的单个名称');
            }
            $orders = [$mapping[$selected] => $directionValue === '' ? 'ASC' : $this->direction($directionValue)];
        }
        $copy = clone $this;
        $copy->declared[$fieldKey] = true;
        $copy->declared[$directionKey] = true;
        foreach ($orders as $orderColumn => $orderDirection) {
            $copy->query = $copy->query->orderByIfAbsent($orderColumn, $orderDirection);
        }

        return $copy;
    }

    /**
     * 返回保留既有范围约束的查询，执行与资源生命周期仍由原查询负责。
     *
     * @throws ModelException 模型助手包含未声明的输入键。
     */
    public function query(): Query|ModelQuery
    {
        $this->validateInput();
        return $this->query;
    }

    /**
     * 从显式输入page/page_size读取正整数并执行受限分页，不隐式修改排序。
     *
     * @throws InvalidArgumentException 页码、条数不是规范正整数或超过开发者限额。
     * @throws ModelException 模型助手包含未声明的输入键。
     */
    public function paginatePage(int $defaultPerPage = 20, int $maxPerPage = 100, int $maxPage = 10000): Page
    {
        $this->validateInput();
        if ($defaultPerPage < 1 || $maxPerPage < $defaultPerPage || $maxPerPage > 1000 || $maxPage < 1 || $maxPage > 1000000) {
            throw new InvalidArgumentException('分页默认值和限额无效');
        }
        $page = $this->positiveInteger('page', 1, $maxPage);
        $perPage = $this->positiveInteger('page_size', $defaultPerPage, $maxPerPage);
        return $this->query->paginate($page, $perPage);
    }

    /**
     * 操作符只能来自本类公开方法，不接受输入对象中的列名或SQL。
     *
     * @param array<string, string>|list<string>|string $fields 受信字段声明。
     */
    private function filter(array|string $fields, string $operator): QueryHelper
    {
        $mapping = $this->fields($fields);
        $copy = clone $this;
        foreach ($mapping as $inputName => $column) {
            $copy->declared[$inputName] = true;
            if (!array_key_exists($inputName, $this->input) || $this->input[$inputName] === '') {
                continue;
            }
            $value = $this->input[$inputName];
            if ($operator === 'IN') {
                $copy->query = $copy->query->whereIn($column, $this->list($value));
            } elseif ($operator === 'BETWEEN') {
                if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
                    throw new InvalidArgumentException('区间筛选需要两个明确的上下界');
                }
                $this->scalar($value[0], false);
                $this->scalar($value[1], false);
                if ($value[0] === '' || $value[1] === '') {
                    throw new InvalidArgumentException('区间筛选不能使用空字符串边界');
                }
                $copy->query = $copy->query->where($column, '>=', $value[0])->where($column, '<=', $value[1]);
            } elseif ($operator === 'LIKE') {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('模糊筛选只接受文本');
                }
                $this->scalar($value, false);
                $copy->query = $copy->query->where($column, 'LIKE', '%' . $value . '%');
            } else {
                $this->scalar($value, true);
                $copy->query = $copy->query->where($column, '=', $value);
            }
        }
        return $copy;
    }

    /** 链式声明完成后再验证，避免后续方法尚未声明的输入被提前拒绝。 */
    private function validateInput(): void
    {
        if (!$this->query instanceof ModelQuery) {
            return;
        }
        foreach ($this->input as $name => $value) {
            if (!array_key_exists($name, $this->declared)) {
                throw new ModelException('unknown_search_field', '模型筛选包含未声明的输入键');
            }
        }
    }

    /** @return array<string, string> 已校验输入名到列名的映射。 */
    private function fields(array|string $fields): array
    {
        $declarations = is_string($fields) ? explode(',', $fields) : $fields;
        if ($declarations === [] || count($declarations) > 100) {
            throw new InvalidArgumentException('筛选必须声明1到100个受信字段');
        }
        $mapping = [];
        foreach ($declarations as $declaredInput => $declaredColumn) {
            if (!is_string($declaredColumn)) {
                throw new InvalidArgumentException('筛选列必须是明确的标识符');
            }
            $column = trim($declaredColumn);
            $input = is_int($declaredInput) ? $column : $declaredInput;
            $columnPattern = $this->query instanceof ModelQuery
                ? '/^[A-Za-z_][A-Za-z0-9_]*$/D'
                : '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D';
            if (!is_string($input) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $input) !== 1
                || preg_match($columnPattern, $column) !== 1 || isset($mapping[$input])) {
                throw new InvalidArgumentException('筛选字段名称无效或重复；列映射只能来自开发者白名单');
            }
            $mapping[$input] = $column;
        }
        return $mapping;
    }

    /** @return list<int|float|string|bool> 已验证并有界的IN值。 */
    private function list(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > 16384) {
                throw new InvalidArgumentException('IN文本输入超过16KiB');
            }
            $items = array_map('trim', explode(',', $value));
        } elseif (is_array($value) && array_is_list($value)) {
            $items = $value;
        } else {
            throw new InvalidArgumentException('IN筛选需要列表或逗号分隔文本');
        }
        if (count($items) > 1000) {
            throw new InvalidArgumentException('IN筛选最多支持1000个值');
        }
        foreach ($items as $item) {
            $this->scalar($item, false);
            if ($item === '') {
                throw new InvalidArgumentException('IN筛选不能夹带空字符串');
            }
        }
        return $items;
    }

    /** 不在这里猜测字段类型；模型查询继续执行自身的字段类型校验。 */
    private function scalar(mixed $value, bool $nullable): void
    {
        if ($value === null && $nullable) {
            return;
        }
        if (!is_scalar($value) || (is_float($value) && !is_finite($value)) || (is_string($value) && strlen($value) > 16384)) {
            throw new InvalidArgumentException('筛选值必须是有限标量且文本不超过16KiB');
        }
    }

    private function positiveInteger(string $key, int $fallback, int $maximum): int
    {
        if (!array_key_exists($key, $this->input) || $this->input[$key] === '') {
            return $fallback;
        }
        $value = $this->input[$key];
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && strlen($value) <= 10) {
            $integer = (int) $value;
        } else {
            throw new InvalidArgumentException('分页输入必须是规范正整数');
        }
        if ($integer < 1 || $integer > $maximum) {
            throw new InvalidArgumentException('分页输入超过允许范围');
        }
        return $integer;
    }

    /** 不 trim 或解析 SQL 片段；大小写不敏感的 ASC/DESC 是唯一方向白名单。 */
    private function direction(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 4 || !in_array(strtoupper($value), ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('排序方向只接受 ASC 或 DESC');
        }

        return strtoupper($value);
    }
}
