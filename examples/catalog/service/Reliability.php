<?php

declare(strict_types=1);

namespace app\catalog\service;

use app\catalog\event\ProductChanged;
use app\catalog\model\Delivery;
use app\catalog\runtime\FixedClock;
use app\catalog\runtime\Infrastructure;
use app\catalog\runtime\QueuePublisher;
use RuntimeException;
use Type\Core\BusinessEvents;
use Type\Core\Configuration;
use Type\Core\Http\Client;
use Type\Generated\CommandApplication;
use Type\Orm\Db;
use Type\Orm\Outbox\Relay;
use Type\Orm\Outbox\Store;
use Type\Queue\Worker;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskException;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SystemClock;

/** 同一目录模块的显式可靠性角色；状态通过持久数据库、队列与游标衔接。 */
final class Reliability
{
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
        if ($marker === 'schedule') {
            $application = new CommandApplication(new Configuration([]));
            $timestamp = $this->infrastructure->settings->integer('catalog.clock');
            $clock = $timestamp === 0 ? new SystemClock() : new FixedClock($timestamp);
            $cursor = $this->infrastructure->settings->text('catalog.cursor');
            if (!\app\common\bootstrap\Settings::absolutePath($cursor)) {
                $cursor = $this->infrastructure->basePath . '/' . $cursor;
            }
            $scheduler = new Scheduler($clock, new FileStateStore($cursor), $application->schedules(), 100, 10, 5000);
            try {
                return ['records' => $scheduler->tick()];
            } finally {
                $scheduler->stop();
                self::expect(!$scheduler->ready() && $scheduler->tick() === [], 'catalog_scheduler_stop_failed');
            }
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
