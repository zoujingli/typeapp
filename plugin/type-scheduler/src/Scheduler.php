<?php

declare(strict_types=1);

namespace Type\Scheduler;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Throwable;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Runtime\Deadline;
use Type\Runtime\WorkLifecycle;

/** 每次 tick 同步运行有限任务；状态游标仅前进，回拨时钟不会重复计划时刻。 */
final class Scheduler
{
    private ClockInterface $clock;
    private StateStore $store;
    private array $definitions;
    private int $historyLimit;
    private int $tickLimit;
    private int $executionMilliseconds;
    private WorkLifecycle $lifecycle;
    private array $counts = ['triggered' => 0, 'failed' => 0, 'interrupted' => 0, 'storage_failures' => 0, 'cleanup_failures' => 0, 'lease_conflicts' => 0, 'limit_reached' => 0];

    /**
     * 登记固定任务与有限执行预算；状态存储随 tick 获取和释放，连接由应用管理。
     *
     * @param list<Definition> $definitions 任务 ID 唯一的定义，最多 1000 项。
     * @param int $executionMilliseconds 每次任务的作用域预算，1 至 3600000 毫秒。
     * @throws InvalidArgumentException 定义重复、类型错误或预算超出范围。
     */
    public function __construct(ClockInterface $clock, StateStore $store, array $definitions, int $historyLimit = 1000, int $tickLimit = 100, int $executionMilliseconds = 30000)
    {
        if ($historyLimit < 1 || $historyLimit > 10000 || count($definitions) > 1000 || $tickLimit < 1 || $tickLimit > 1000
            || $executionMilliseconds < 1 || $executionMilliseconds > 3600000) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_CONFIG：历史保留 1 至 10000 条，最多注册 1000 个任务');
        }
        $registered = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof Definition || isset($registered[$definition->id()])) {
                throw new InvalidArgumentException('TYPE_SCHEDULER_CONFIG：调度定义非法或任务身份重复');
            }
            $registered[$definition->id()] = $definition;
        }
        $this->clock = $clock;
        $this->store = $store;
        $this->definitions = array_values($registered);
        $this->historyLimit = $historyLimit;
        $this->tickLimit = $tickLimit;
        $this->executionMilliseconds = $executionMilliseconds;
        $this->lifecycle = new WorkLifecycle();
    }

    /** 状态存储与调度器在同一 Swoole 协程内装配；每次任务调用独立绑定作用域。 */
    public function tick(): array
    {
        CoroutineRuntime::assertAvailable();
        if (\Swoole\Coroutine::getCid() < 0) {
            throw new \Type\Runtime\TaskException('coroutine_required', '调度入口需要先在 Swoole 协程内装配资源');
        }
        if (!$this->lifecycle->begin()) {
            return [];
        }
        try {
            return $this->executeTick();
        } catch (Throwable $error) {
            if ($error instanceof LeaseException && $error->reason() === 'busy') {
                $this->counts['lease_conflicts']++;
            } else {
                $this->counts['storage_failures']++;
                $this->lifecycle->stop(0.0);
            }
            throw $error;
        } finally {
            $this->lifecycle->finish();
        }
    }

    /** 撤销就绪并按秒数预算等待收尾；不撤销已发生效果，不关闭外部 RedisManager。 */
    public function stop(float $drainSeconds = 5.0): void
    {
        $this->lifecycle->stop($drainSeconds);
    }
    /** 返回是否继续接受新 tick 及本轮剩余计划。 */
    public function ready(): bool
    {
        return $this->lifecycle->ready();
    }
    /**
     * 读取生命周期与累计执行统计，不打开状态存储。
     *
     * @return array<string, int|bool|string> 包含任务/存储/清理失败和 tick 上限。
     */
    public function statistics(): array
    {
        return $this->lifecycle->statistics() + $this->counts + ['tick_limit' => $this->tickLimit];
    }

    private function executeTick(): array
    {
        $this->store->acquire();
        $failure = null;
        try {
            $state = $this->store->load();
            $now = $this->clock->now()->getTimestamp();
            $results = [];
            $triggered = 0;
            foreach ($state['records'] as $index => $record) {
                if ($record['state'] === 'running') {
                    $state['records'][$index]['state'] = 'interrupted';
                    $state['records'][$index]['finished_at'] = $now;
                    $state['records'][$index]['error'] = ['type' => 'TYPE_SCHEDULER_INTERRUPTED', 'message' => '上次执行未记录完成，业务效果需要核对'];
                    $results[] = $state['records'][$index];
                    $this->counts['interrupted']++;
                }
            }
            $this->store->save($state);
            foreach ($this->definitions as $definition) {
                $id = $definition->id();
                $cursor = $state['cursors'][$id] ?? null;
                if ($cursor !== null && !is_int($cursor)) {
                    throw new InvalidArgumentException('TYPE_SCHEDULER_STORE：状态游标必须为 UTC 秒');
                }
                foreach ($definition->due($cursor, $now) as $scheduledAt) {
                    if (!$this->lifecycle->ready()) {
                        return $results;
                    }
                    if ($triggered >= $this->tickLimit) {
                        $this->counts['limit_reached']++;
                        return $results;
                    }
                    $scope = new ExecutionScope(new Deadline($this->executionMilliseconds / 1000.0));
                    $this->lifecycle->attach($scope);
                    $context = new TaskContext($id, $scheduledAt, $scope, $this->store instanceof LeasedStateStore ? $this->store->lease() : null);
                    $record = ['occurrence_id' => $context->occurrenceId(), 'task_id' => $id, 'scheduled_at' => $scheduledAt,
                        'schedule' => $definition->schedule()->description(), 'revision' => $definition->revision(), 'state' => 'running',
                        'lease_generation' => $context->lease()?->generation(),
                        'started_at' => $this->clock->now()->getTimestamp(), 'finished_at' => null, 'result' => null, 'error' => null, 'cleanup_error' => null];
                    $state['cursors'][$id] = $scheduledAt;
                    $state['records'][] = $record;
                    $state['records'] = array_slice($state['records'], -$this->historyLimit);
                    $index = count($state['records']) - 1;
                    $this->store->save($state);
                    try {
                        $record['result'] = $scope->run(static function (ExecutionScope $current) use ($definition, $context): array {
                            $context->assertActive();
                            $result = $definition->create($context)->run($context);
                            $context->assertActive();
                            return $result;
                        });
                        $encoded = json_encode($record['result'], JSON_THROW_ON_ERROR);
                        if (strlen($encoded) > 65536) {
                            throw new InvalidArgumentException('TYPE_SCHEDULER_RESULT：任务结果超过 64 KiB');
                        }
                        $record['state'] = 'succeeded';
                    } catch (Throwable $error) {
                        $record['state'] = 'failed';
                        $record['result'] = null;
                        $record['error'] = ['type' => $error::class, 'message' => substr($error->getMessage(), 0, 4096)];
                    } finally {
                        try {
                            $scope->close();
                        } catch (Throwable $error) {
                            $record['state'] = 'failed';
                            $record['cleanup_error'] = ['type' => $error::class, 'message' => substr($error->getMessage(), 0, 4096)];
                        }
                    }
                    $cleanupIncomplete = $scope->state() !== 'closed';
                    $record['finished_at'] = $cleanupIncomplete ? null : $this->clock->now()->getTimestamp();
                    $state['records'][$index] = $record;
                    $this->store->save($state);
                    $results[] = $record;
                    $triggered++;
                    $this->counts['triggered']++;
                    if ($record['state'] === 'failed') {
                        $this->counts['failed']++;
                    }
                    if ($cleanupIncomplete) {
                        $this->counts['cleanup_failures']++;
                        $this->lifecycle->stop(0.0);
                        return $results;
                    }
                }
                if (!$this->lifecycle->ready()) {
                    return $results;
                }
                $state['cursors'][$id] = max($now, $cursor ?? $now);
                $this->store->save($state);
            }

            return $results;
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try {
                $this->store->release();
            } catch (Throwable $error) {
                if ($failure === null) {
                    throw $error;
                }
            }
        }
    }

    /**
     * 短暂持有存储执行权读取历史，不运行任务、不修复 interrupted 状态。
     *
     * @return list<array<string, mixed>> 已持久记录，时刻为 UTC Unix 秒。
     */
    public function history(): array
    {
        $this->store->acquire();
        $failure = null;
        try {
            return $this->store->load()['records'];
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try {
                $this->store->release();
            } catch (Throwable $error) {
                if ($failure === null) {
                    throw $error;
                }
            }
        }
    }
}
