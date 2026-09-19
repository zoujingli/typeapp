<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use Throwable;
use Type\Runtime\ManagedResource;

/** 作用域拥有的有界查询监听；停止后解除连接引用，记录仍可供调用方检查。 */
final class QueryLog implements ManagedResource
{
    private array $records = [];
    private int $bytes = 0;
    private int $dropped = 0;
    private int $listenerFailures = 0;
    private bool $active = false;

    /** @param Closure(QueryEvent): mixed|null $listener 查询完成后调用，异常单独记录。 */
    public function __construct(
        private ?Closure $listener,
        private ?Closure $detach,
        private int $maxRecords,
        private int $maxBytes,
        private bool $includeValues,
        private float $slowMilliseconds
    ) {
        if ($maxRecords < 1 || $maxRecords > 10000 || $maxBytes < 512 || $maxBytes > 16777216
            || !is_finite($slowMilliseconds) || $slowMilliseconds < 0) {
            throw new DatabaseException('查询监听的数量、字节或慢查询阈值无效');
        }
    }

    /** @internal 由 ExecutionScope 启动。 */
    public function start(): void
    {
        $this->active = true;
    }

    /** 解除注册且幂等；不会清除调用者需要保存的验收记录。 */
    public function stop(): void
    {
        $this->active = false;
        $detach = $this->detach;
        $this->detach = null;
        $this->listener = null;
        if ($detach !== null) {
            $detach();
        }
    }

    /** 事件与监听错误共用数量和字节预算。 */
    public function records(): array
    {
        return $this->records;
    }

    /** 被逐出或过大的记录计入 dropped；监听异常不复用数据库失败指标。 */
    public function statistics(): array
    {
        return ['records' => count($this->records), 'bytes' => $this->bytes, 'dropped' => $this->dropped,
            'listener_failures' => $this->listenerFailures, 'active' => $this->active];
    }

    /** @internal 只保留有界标量；默认 SQL 和参数仅保留身份与数量，杜绝原生 SQL 字面值泄露。 */
    public function record(array $event, string $sql, array $parameters): void
    {
        if (!$this->active) {
            return;
        }
        $event['type'] = 'query';
        $event['sql_sha256'] = hash('sha256', $sql);
        $event['parameter_count'] = count($parameters);
        $event['redacted'] = !$this->includeValues;
        $event['slow'] = $event['duration_ms'] >= $this->slowMilliseconds;
        if ($this->includeValues) {
            $truncated = strlen($sql) > 4096 || count($parameters) > 128;
            $event['sql'] = substr($sql, 0, 4096);
            $event['parameters'] = [];
            foreach (array_slice($parameters, 0, 128) as $value) {
                if (is_string($value) && strlen($value) > 2048) {
                    $truncated = true;
                }
                $event['parameters'][] = is_string($value) ? substr($value, 0, 2048)
                    : (is_int($value) || is_bool($value) || is_float($value) || $value === null ? $value : '[non-scalar]');
            }
            $event['truncated'] = $truncated;
        }
        $this->append($event);
        if ($this->listener !== null) {
            try {
                ($this->listener)(new QueryEvent($event));
            } catch (Throwable $error) {
                $this->listenerFailures++;
                $this->append(['type' => 'listener_error', 'error_class' => get_class($error), 'sql_sha256' => $event['sql_sha256']]);
            }
        }
    }

    private function append(array $record): void
    {
        $size = (int) strlen(json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        if ($size > $this->maxBytes) {
            $this->dropped++;
            return;
        }
        while ($this->records !== [] && (count($this->records) >= $this->maxRecords || $this->bytes + $size > $this->maxBytes)) {
            $removed = array_shift($this->records);
            $removedBytes = (int) strlen(json_encode($removed, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            $this->bytes -= $removedBytes;
            $this->dropped++;
        }
        $this->records[] = $record;
        $this->bytes += $size;
    }
}
