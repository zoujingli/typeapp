<?php

declare(strict_types=1);

namespace app\catalog\service;

use app\catalog\event\ProductChanged;
use app\catalog\model\Delivery;
use app\catalog\runtime\FixedClock;
use app\catalog\runtime\Infrastructure;
use app\catalog\runtime\QueuePublisher;
use app\catalog\runtime\WorkObservation;
use RuntimeException;
use Type\Core\BusinessEvents;
use Type\Core\Configuration;
use Type\Core\Http\Client;
use Type\Generated\CommandApplication;
use Type\Orm\Db;
use Type\Orm\Outbox\Relay;
use Type\Orm\Outbox\Store;
use Type\Queue\Worker;
use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\QueueException;
use Type\Queue\Registry;
use Type\Queue\RetryPolicy;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskException;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\Definition;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SystemClock;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

/** 同一目录模块的显式可靠性角色；状态通过持久数据库、队列与游标衔接。 */
final class Reliability
{
    /** @var list<WorkObservation> 仅显式任务演练保留本轮实例，返回结果前均须收尾。 */
    private array $workObservations = [];

    /** 基础设施由命令资源声明启动；业务服务仍由共同构造装配提供。 */
    public function __construct(private ProductService $products, private Infrastructure $infrastructure, private Store $store, private BusinessEvents $events)
    {
    }

    /** @return array<string,mixed> 有限命令步骤与稳定结果，不执行隐式重试。 */
    public function execute(string $marker): array
    {
        if ($marker === 'cache') {
            return ExecutionScope::current()->run(fn (ExecutionScope $scope): array => $this->cache(), ['tenant_id' => 'catalog-a']);
        }
        if ($marker === 'relay' || $marker === 'relay-fail') {
            $relay = new Relay(
                $this->infrastructure->database,
                $this->store,
                new QueuePublisher($this->infrastructure->queue(), $this->infrastructure->database, $marker === 'relay-fail'),
                'catalog',
                5000
            );
            try {
                $accepted = $relay->runOnce(10);
                return ['accepted' => $accepted, 'completed' => false];
            } catch (RuntimeException $error) {
                if ($marker !== 'relay-fail' || $error->getMessage() !== 'catalog_accepted_without_receipt') {
                    throw $error;
                }
                return ['accepted' => 'unknown', 'error' => 'catalog_accepted_without_receipt', 'retry' => false];
            } finally {
                $relay->stop();
                self::expect(!$relay->ready() && $relay->runOnce() === 0, 'catalog_relay_stop_failed');
            }
        }
        if ($marker === 'consume') {
            $application = new CommandApplication(new Configuration([]));
            $queue = $this->infrastructure->queue();
            $worker = new Worker($queue, $application->jobs(), 'catalog-' . getmypid());
            try {
                $processed = $worker->run(10);
                return ['processed' => $processed, 'remaining' => $queue->statistics()['messages'],
                    'effects' => count(Delivery::query()->limit(20)->get())];
            } finally {
                $worker->stop();
                self::expect(!$worker->ready() && !$worker->runOnce(), 'catalog_worker_stop_failed');
            }
        }
        if (in_array($marker, ['queue-invalid', 'queue-cancel', 'queue-cleanup'], true)) {
            return $this->queueLifecycle($marker);
        }
        if (in_array($marker, ['schedule', 'schedule-failure', 'schedule-cancel', 'schedule-cleanup'], true)) {
            return $this->schedule($marker);
        }
        if (in_array($marker, ['https', 'https-host', 'https-timeout', 'https-cancel'], true)) {
            return $this->https($marker);
        }
        if ($marker === 'status') {
            $record = Db::connection('catalog', true)->table('catalog_outbox')->orderBy('id')->first();
            return ['state' => $record['state'] ?? null, 'accepted' => ($record['accepted_receipt'] ?? null) !== null,
                'consumed' => ($record['consumed_receipt'] ?? null) !== null, 'effects' => count(Delivery::query()->limit(20)->get())];
        }
        throw new \InvalidArgumentException('catalog_unknown_operation');
    }

    /** 显式演练仍执行原生成工厂；不把失败模式写入消息或正常业务服务。 */
    private function queueLifecycle(string $mode): array
    {
        $application = new CommandApplication(new Configuration([]));
        $generated = $application->jobs();
        $queue = $this->infrastructure->queue();
        $messageId = 'lifecycle:' . bin2hex(random_bytes(12));
        $setup = new ExecutionScope();
        try {
            $productId = $setup->run(function (ExecutionScope $scope) use ($messageId): int {
                return Db::transaction(function () use ($messageId): int {
                    $product = $this->products->create(['code' => $messageId, 'name' => '任务收尾演练']);
                    $this->store->enqueue($messageId, 'catalog.deliver', 1, ['product_id' => $product['id']], [], 'catalog');
                    return $product['id'];
                }, 'catalog');
            }, ['tenant_id' => 'catalog-a']);
        } finally {
            $setup->close();
        }
        $registry = new Registry();
        $registry->register('catalog.deliver', 1, function (JobContext $context) use ($generated, $queue, $mode): Job {
            $observation = new WorkObservation(
                $context->scope(),
                ['message' => $context->message()->id(), 'attempt' => $context->reservation()->attempt()],
                fn (): array => ['queue' => $queue->statistics(), 'database-leases' => $this->databaseLeases()]
            );
            $this->workObservations[] = $observation;
            $context->scope()->open($observation);
            $job = $generated->create($context);
            $observation->role = $job;
            if (count($this->workObservations) === 1) {
                $observation->failClose = $mode === 'queue-cleanup';
                if ($mode === 'queue-cancel') {
                    $context->scope()->cancellation()->cancel();
                }
            }
            return $job;
        });
        $worker = new Worker($queue, $registry, 'catalog-lifecycle', new RetryPolicy(3, 1, 1, 5000));
        $recovery = null;
        $report = ['message' => $messageId];
        $queue->publish(new Message($messageId, 'catalog.deliver', 1, ['product_id' => $mode === 'queue-invalid' ? 0 : $productId]));
        try {
            try {
                self::expect($worker->runOnce(), 'catalog_work_not_received');
            } catch (QueueException $failure) {
                self::expect($mode === 'queue-cleanup' && $failure->errorCode() === 'cleanup_incomplete', 'catalog_wrong_work_failure');
                $report['error'] = $failure->errorCode();
            }
            $report['first'] = ['worker' => $worker->statistics(), 'queue' => $queue->statistics(), 'effects' => $this->deliveryEffects($messageId)];
            if ($mode === 'queue-cleanup') {
                $observation = $this->workObservations[0];
                $report['pending-scope'] = $observation->scope->state();
                $observation->failClose = false;
                $observation->scope->close();
                $report['after-cleanup'] = $worker->statistics();
                self::expect(!$worker->runOnce(), 'catalog_failed_worker_resumed');
                $recovery = new Worker($queue, $registry, 'catalog-lifecycle-recovery', new RetryPolicy(3, 1, 1, 5000));
                // 根据 Redis 实际租约状态等待重领，不以一次固定休眠推断到期。
                $this->receive($recovery, 1);
                $report['recovery'] = $recovery->statistics();
            } else {
                $this->receive($worker, $mode === 'queue-invalid' ? 3 : 2);
            }
            $report['worker'] = $worker->statistics();
            $report['queue'] = $queue->statistics();
            $report['effects'] = $this->deliveryEffects($messageId);
            $report['quarantine'] = [];
            foreach ($queue->quarantined() as $record) {
                if (Message::decode($record['message'])->id() === $messageId) {
                    $report['quarantine'][] = ['attempt' => $record['attempt'], 'reason' => $record['reason']];
                }
            }
        } finally {
            $this->closeObservations();
            $worker->stop();
            $recovery?->stop();
            self::expect(!$worker->ready() && !$worker->runOnce() && ($recovery === null || (!$recovery->ready() && !$recovery->runOnce())), 'catalog_lifecycle_stop_failed');
        }
        return $report + ['stopped' => $worker->statistics(), 'observations' => $this->observations()];
    }

    /** 沿用原计划与生成 Task；失败历史不意味着同一 occurrence 可以自动重跑。 */
    private function schedule(string $mode): array
    {
        $application = new CommandApplication(new Configuration([]));
        $timestamp = $this->infrastructure->settings->integer('catalog.clock');
        $clock = $timestamp === 0 ? new SystemClock() : new FixedClock($timestamp);
        $cursor = $this->infrastructure->settings->text('catalog.cursor');
        if (!\app\common\bootstrap\Settings::absolutePath($cursor)) {
            $cursor = $this->infrastructure->basePath . '/' . $cursor;
        }
        $store = new FileStateStore($cursor);
        $definitions = $application->schedules();
        if ($mode !== 'schedule') {
            self::expect(count($definitions) === 1, 'catalog_work_schedule_not_unique');
            $generated = $definitions[0];
            // 此教程 make task 的原计划使用默认 skip 策略；只在工厂旁观察生命周期。
            $definitions = [new Definition($generated->id(), $generated->schedule(), function (TaskContext $context) use ($generated, $store, $mode): Task {
                $observation = new WorkObservation(
                    $context->scope(),
                    ['occurrence' => $context->occurrenceId()],
                    fn (): array => ['records' => $store->load()['records'], 'database-leases' => $this->databaseLeases()]
                );
                $this->workObservations[] = $observation;
                $context->scope()->open($observation);
                $task = $generated->create($context);
                $observation->role = $task;
                $observation->failClose = $mode === 'schedule-cleanup';
                if ($mode === 'schedule-cancel') {
                    $context->scope()->cancellation()->cancel();
                }
                return $task;
            }, 'skip', 1, 3600, 59, $generated->revision())];
        }
        if ($mode === 'schedule-failure') {
            // 已关闭的数据源是真实拒绝条件；Task 的 Model 查询必须传播失败。
            $this->infrastructure->database->close();
        }
        $scheduler = new Scheduler($clock, $store, $definitions, 100, 10, 5000);
        $report = [];
        try {
            $report['records'] = $scheduler->tick();
            if ($mode !== 'schedule') {
                $report['before-recovery'] = $scheduler->statistics();
                $this->closeObservations();
                $report['after-cleanup'] = $scheduler->statistics();
                self::expect($scheduler->tick() === [], 'catalog_occurrence_retried');
            }
        } finally {
            $this->closeObservations();
            $scheduler->stop();
            self::expect(!$scheduler->ready() && $scheduler->tick() === [], 'catalog_scheduler_stop_failed');
        }
        return $report + ['stopped' => $scheduler->statistics(), 'observations' => $this->observations()];
    }

    /** 只在显式演练中有限等待真实 Redis 的延迟或租约到期。 */
    private function receive(Worker $worker, int $received): void
    {
        $deadline = microtime(true) + 5.0;
        while ($worker->statistics()['received'] < $received && microtime(true) < $deadline) {
            if (!$worker->runOnce()) {
                \Swoole\Coroutine::sleep(0.01);
            }
        }
        self::expect($worker->statistics()['received'] === $received, 'catalog_work_receive_timeout');
    }

    /** 观察命令不保留数据库租约，避免把控制器连接混入任务清理断言。 */
    private function deliveryEffects(string $messageId): int
    {
        $scope = new ExecutionScope();
        try {
            return $scope->run(static fn (ExecutionScope $current): int => count(Delivery::query()->where('id', '=', $messageId)->get()));
        } finally {
            $scope->close();
        }
    }

    /** 资源逆序收尾到观察者时，任务实际借用的数据库连接应已归还。 */
    private function databaseLeases(): int
    {
        $leased = 0;
        foreach ($this->infrastructure->database->statistics()['active'] as $statistics) {
            $leased += $statistics['leased'];
        }
        return $leased;
    }

    /** 清理失败演练解除后仍由原 scope 关闭，不能丢弃资源再声称完成。 */
    private function closeObservations(): void
    {
        foreach ($this->workObservations as $observation) {
            $observation->failClose = false;
            $observation->scope->close();
        }
    }

    /** @return list<array<string,mixed>> 仅序列化值；保留实例到此处以准确核对作用域和角色不复用。 */
    private function observations(): array
    {
        $result = [];
        foreach ($this->workObservations as $index => $observation) {
            self::expect($observation->role !== null, 'catalog_work_role_missing');
            for ($previous = 0; $previous < $index; $previous++) {
                self::expect($observation->scope !== $this->workObservations[$previous]->scope
                    && $observation->role !== $this->workObservations[$previous]->role, 'catalog_work_instance_reused');
            }
            $result[] = $observation->identity + ['role' => get_class($observation->role), 'scope' => $observation->scope->state(),
                'starts' => $observation->starts, 'stops' => $observation->stops, 'closed' => $observation->closed, 'before-close' => $observation->beforeClose];
        }
        return $result;
    }

    private function cache(): array
    {
        $cache = $this->infrastructure->cache();
        $product = $this->products->create(['code' => 'reliability', 'name' => '原商品']);
        $id = $product['id'];
        self::expect($this->products->cached($cache, 'catalog-a', $id)['name'] === '原商品', 'catalog_cache_fill');
        $this->products->cached($cache, 'catalog-a', $id);
        self::expect($this->products->loads() === 1, 'catalog_cache_hit');
        self::expect($this->products->cached($cache, 'catalog-a', 999999) === null, 'catalog_cache_null');
        $this->products->cached($cache, 'catalog-a', 999999);
        self::expect($this->products->loads() === 2, 'catalog_cache_null_miss');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->products->failing($cache, 'catalog-a');
                throw new \LogicException('catalog_failure_cached');
            } catch (RuntimeException $expected) {
                self::expect($expected->getMessage() === 'catalog_loader_failed', 'catalog_wrong_loader_failure');
            }
        }
        self::expect($this->products->loads() === 4, 'catalog_exception_cached');
        $rollbackSync = new ProductChanged($id, 'sync');
        $rollbackAfter = new ProductChanged($id, 'after');
        try {
            $this->products->change($cache, 'catalog-a', $id, '应回滚', $this->events, $rollbackSync, $rollbackAfter, $this->store, true);
            throw new \LogicException('catalog_rollback_missing');
        } catch (RuntimeException $expected) {
            self::expect($expected->getMessage() === 'catalog_change_rolled_back', 'catalog_wrong_rollback');
        }
        self::expect($rollbackSync->transactions === [true] && $rollbackAfter->transactions === [], 'catalog_rollback_event');
        self::expect($this->products->cached($cache, 'catalog-a', $id)['name'] === '原商品' && $this->products->loads() === 5, 'catalog_rollback_evicted');
        self::expect(Db::connection('catalog', true)->table('catalog_outbox')->first() === null, 'catalog_rollback_outbox');
        $sync = new ProductChanged($id, 'sync');
        $after = new ProductChanged($id, 'after');
        Db::transaction(function () use ($cache, $id, $sync, $after): void {
            $visible = $this->products->change($cache, 'catalog-a', $id, '已确认商品', $this->events, $sync, $after, $this->store);
            self::expect($visible['name'] === '已确认商品' && $this->products->loads() === 6, 'catalog_transaction_used_cache');
            self::expect($after->transactions === [], 'catalog_after_commit_too_early');
        }, 'catalog');
        self::expect($sync->transactions === [true] && $after->transactions === [false], 'catalog_event_phase');
        self::expect($this->products->cached($cache, 'catalog-a', $id)['name'] === '已确认商品' && $this->products->loads() === 7, 'catalog_commit_not_evicted');
        return ['product' => $id, 'loads' => 7, 'null_cached' => true, 'failure_cached' => false,
            'rollback_discarded' => true, 'sync' => $sync->transactions, 'after_commit' => $after->transactions, 'outbox' => 'pending'];
    }

    private function https(string $mode): array
    {
        $url = $this->infrastructure->settings->text('catalog.https_url');
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('catalog_https_required');
        }
        $tls = ['ssl_cafile' => $this->infrastructure->settings->text('catalog.https_ca'),
            'ssl_host_name' => $mode === 'https-host' ? 'wrong.invalid' : 'localhost'];
        $task = ExecutionScope::current()->spawn(static function (ExecutionScope $child) use ($mode, $url, $tls): array {
            $timer = null;
            if ($mode === 'https-cancel') {
                $timer = \Swoole\Timer::after(30, static function () use ($child): void {
                    $child->cancellation()->cancel();
                });
            }
            try {
                $response = (new Client())->request('GET', $url, [], '', $mode === 'https-timeout' ? 0.05 : 2.0, 4096, $tls);
                try {
                    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
                } finally {
                    $response->getBody()->close();
                }
            } catch (TaskException $error) {
                return ['error' => $error->errorCode()];
            } finally {
                if ($timer !== null) {
                    \Swoole\Timer::clear($timer);
                }
            }
        });
        try {
            return $task->await();
        } catch (TaskException $error) {
            return ['error' => $error->errorCode()];
        }
    }

    private static function expect(bool $condition, string $error): void
    {
        if (!$condition) {
            throw new RuntimeException($error);
        }
    }
}
