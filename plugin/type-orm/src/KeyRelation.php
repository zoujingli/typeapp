<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use InvalidArgumentException;

/** @internal 单外键关联的有界预加载实现。 */
final class KeyRelation extends Relation
{
    private Closure $target;
    private string $source;
    private string $related;
    private bool $many;
    private int $batchSize;

    /** @param Closure(Connection): ModelQuery $target 接收关联查询所用连接。 */
    public function __construct(Closure $target, string $source, string $related, bool $many, int $batchSize)
    {
        if ($batchSize < 1 || $batchSize > 1000 || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $source)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $related)) {
            throw new InvalidArgumentException('关系键或批量大小无效');
        }
        $this->target = $target;
        $this->source = $source;
        $this->related = $related;
        $this->many = $many;
        $this->batchSize = $batchSize;
    }

    /** 返回父模型关联属性名，匹配时读取存储值而非展示获取器。 */
    public function sourceKey(): string
    {
        return $this->source;
    }

    /**
     * 批量加载关系并写回父模型，沿用父查询已经选定的连接。
     *
     * @param list<Model> $models
     */
    public function load(Connection $connection, array $models, string $name): void
    {
        $this->loadRows($connection, $models, $name);
    }

    /**
     * 在共享行数预算内批量加载；超限拒绝，不把截断关系交给业务。
     *
     * @param list<Model> $models
     */
    public function loadBounded(Connection $connection, array $models, string $name, ReadBudget $budget): void
    {
        $this->loadRows($connection, $models, $name, $budget);
    }

    private function loadRows(Connection $connection, array $models, string $name, ?ReadBudget $budget = null): void
    {
        $keys = [];
        foreach ($models as $model) {
            $key = $model->rawValue($this->source);
            if ($key !== null) {
                if (!is_int($key) && !is_string($key)) {
                    throw new ModelException('invalid_relation_key', '关系键必须是整数或字符串');
                }
                $keys['key:' . (string) $key] = $key;
            }
        }
        $grouped = [];
        foreach (array_chunk(array_values($keys), $this->batchSize) as $chunk) {
            $target = ($this->target)($connection);
            if (!$target instanceof ModelQuery) {
                throw new ModelException('invalid_relation_factory', '关系工厂必须返回 ModelQuery');
            }
            // 保留目标工厂的显式筛选及排序，补齐关联键与稳定主键顺序。
            $query = $target->including([$this->related])->whereIn($this->related, $chunk)->orderedForRelation();
            $rows = $budget === null ? $query->get() : $query->getBounded($budget->remaining(), $budget);
            foreach ($rows as $row) {
                $key = 'key:' . (string) $row->rawValue($this->related);
                if (!array_key_exists($key, $keys)) {
                    throw new ModelException('relation_key_comparison', '数据库关联键比较与模型值不一致，请统一键的规范化和排序规则');
                }
                if (!$this->many && isset($grouped[$key])) {
                    throw new ModelException('non_unique_relation', '单条关系匹配多条记录，请核对唯一约束');
                }
                $grouped[$key][] = $row;
            }
        }
        foreach ($models as $model) {
            $key = $model->rawValue($this->source);
            $related = $key === null ? [] : ($grouped['key:' . (string) $key] ?? []);
            $model->setRelation($name, $this->many ? $related : ($related[0] ?? null));
        }
    }
}
