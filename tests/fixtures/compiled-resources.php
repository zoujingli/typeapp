<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Type\Orm\Database;
use Type\Orm\DatabaseManager;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\CapacityException;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;
use Type\Runtime\ResourceBudget;
use Type\Runtime\ResourcePool;
use Type\Runtime\ReusableResource;
use Type\Runtime\TaskException;
use Type\Runtime\WorkLifecycle;

/** 真实文件句柄；失败后由同一作用域继续收尾，支持部分启动与重入观察。 */
final class ScopeFileResource implements ManagedResource
{
    public static array $stops = [];
    public int $attempts = 0;
    private mixed $handle = null;

    public function __construct(private string $file, private ExecutionScope $scope, private int $failures = 0, private bool $failStart = false)
    {
    }

    public function start(): void
    {
        $this->handle = fopen($this->file, 'w+');
        PoolProbe::check(is_resource($this->handle), '无法打开清理测试文件');
        if ($this->failStart) {
            throw new RuntimeException('controlled start failure');
        }
    }

    public function stop(): void
    {
        $this->attempts++;
        self::$stops[] = basename($this->file);
        $this->scope->close();
        if ($this->failures > 0) {
            $this->failures--;
            throw new RuntimeException('controlled stop failure');
        }
        PoolProbe::check(fclose($this->handle), '文件没有实际关闭');
        $this->handle = null;
    }

    public function open(): bool
    {
        return is_resource($this->handle);
    }
}

/** 真实 SQLite 资源；测试门闩将业务完成与物理关闭分开观察。 */
final class GatedSqliteResource implements ReusableResource
{
    private ?PDO $pdo;
    public ?Channel $closeGate = null;
    public int $closeFailures = 0;
    public bool $resetFailure = false;

    public function __construct(string $file)
    {
        $this->pdo = new PDO('sqlite:' . $file);
    }

    public function read(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM items')->fetchColumn();
    }

    public function reset(): bool
    {
        if ($this->resetFailure) {
            throw new RuntimeException('controlled reset failure');
        }
        return false;
    }

    public function close(): void
    {
        if ($this->closeGate !== null) {
            PoolProbe::check($this->closeGate->pop(2) === true, '关闭门闩超时');
            $this->closeGate = null;
        }
        if ($this->closeFailures > 0) {
            $this->closeFailures--;
            throw new RuntimeException('controlled close failure');
        }
        $this->pdo = null;
    }
}

final class PoolProbe
{
    public static int $checks = 0;
    public static array $observations = [];
    public static ?Throwable $failure = null;

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        self::$checks++;
    }

    /** @param Closure(): mixed $operation 只在公共入口观察失败。 */
    public static function rejected(Closure $operation, string $reason): bool
    {
        try {
            $operation();
        } catch (TaskException $error) {
            self::check($error->errorCode() === $reason, '任务失败原因不符：' . $error->errorCode() . ' / ' . $reason);
            return true;
        } catch (CapacityException $error) {
            self::check($reason === 'capacity', '容量失败原因不符');
            return true;
        } catch (PDOException $error) {
            self::check($reason === 'pdo', 'PDO 失败类型不符');
            return true;
        } catch (RuntimeException $error) {
            self::check(str_contains($error->getMessage(), $reason), '失败原因不符：' . $error->getMessage() . ' / ' . $reason);
            return true;
        }
        throw new RuntimeException('应该拒绝：' . $reason);
    }

    public static function run(string $payload): int
    {
        $input = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $directory = $input['directory'];
        $role = $input['role'];
        $file = $directory . '/' . $role . '.sqlite';
        try {
            $hooks = Swoole\Runtime::getHookFlags();
            self::rejected(static fn (): mixed => CoroutineRuntime::enableIo(), 'swoole_hook_startup_required');
            self::check(Swoole\Runtime::getHookFlags() === $hooks, '子线程拒绝配置时改变了原生选项');
            $foreign = unserialize(base64_decode($input['owner']), ['allowed_classes' => [ExecutionOwner::class]]);
            self::check($foreign instanceof ExecutionOwner, '跨线程归属观察数据无效');
            self::rejected(static fn (): mixed => $foreign->assertCurrent(), '线程请求');
            $budget = new DeploymentBudget(60, 3, 1, 6, 12, 2);
            self::check($budget->statistics()['per_process'] === 2 && $budget->statistics()['per_thread'] === 1
                && $budget->statistics()['maximum_application_connections'] === 48, '线程划分放大部署额度');
            self::rejected(static fn (): DeploymentBudget => new DeploymentBudget(60, 3, 1, 6, 12, 3), 'capacity');
            $database = new Database(new SqliteDriver($file), 2, 0, $budget, 2, 0.2);
            $scope = new ExecutionScope();
            $connection = $database->connect($scope);
            $connection->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $connection->execute('INSERT INTO items VALUES (1, ?)', ['initial']);
            self::check($connection->query('SELECT value FROM items')[0]['value'] === 'initial', 'SQLite 初始写入未读取到');
            $other = new ExecutionScope();
            self::rejected(static fn (): mixed => $database->connect($other), 'capacity');
            $other->close();
            self::rejected(static fn (): string => serialize($connection), 'resource_transfer_forbidden');
            self::check($budget->poolBudget()->statistics()['allocated'] === 1, '同步拒绝错误归还额度');
            file_put_contents($directory . '/' . $role . '.ready', json_encode(['allocated' => 1], JSON_THROW_ON_ERROR));
            self::waitFile($directory . '/go');
            $scope->close();
            $database->close();
            Coroutine::create(static function () use ($file, $budget): void {
                try {
                    $sleepCompleted = new Channel(1);
                    Coroutine::create(static function () use ($sleepCompleted): void {
                        usleep(30000);
                        $sleepCompleted->push(true);
                    });
                    self::check($sleepCompleted->isEmpty(), '子线程未复用主线程安装的原生 sleep hook');
                    self::check($sleepCompleted->pop(1) === true, '原生 sleep hook 未恢复');
                    self::queue($file, $budget);
                    self::deadlines($file, $budget);
                    self::retirement($file, $budget);
                    self::completion($file, $budget->poolBudget());
                    self::failures($file, $budget->poolBudget());
                    self::taskCompletion();
                    self::scopeCompletion($file);
                } catch (Throwable $error) {
                    self::$failure = $error;
                }
            });
            Swoole\Event::wait();
            if (self::$failure !== null) {
                throw self::$failure;
            }
            self::check($budget->poolBudget()->statistics()['allocated'] === 0 && $budget->poolBudget()->statistics()['waiters'] === 0, '线程退出前预算或等待登记未清空');
            file_put_contents($directory . '/' . $role . '.json', json_encode(['checks' => self::$checks,
                'native_id' => Swoole\Thread::getNativeId(), 'process' => getmypid(), 'allocated' => $budget->poolBudget()->statistics()['allocated'],
                'remaining_coroutines' => Coroutine::stats()['coroutine_num'], 'observations' => self::$observations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return 0;
        } catch (Throwable $error) {
            file_put_contents($directory . '/' . $role . '.failure', get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString());
            return 1;
        }
    }

    private static function queue(string $file, DeploymentBudget $budget): void
    {
        $database = new Database(new SqliteDriver($file), 1, 0, $budget, 2, 0.5);
        $parent = new ExecutionScope(new Deadline(2), ['request_id' => 'queue'], 4);
        try {
            $connection = $database->connect($parent);
            $thread = Swoole\Thread::getNativeId();
            $child = $parent->spawn(static function (ExecutionScope $scope) use ($database, $connection, $thread): int {
                self::check(Swoole\Thread::getNativeId() === $thread && $scope->context()['request_id'] === 'queue', 'spawn 迁移线程或丢失上下文');
                self::rejected(static fn (): array => $connection->query('SELECT 1'), '执行者');
                $borrowed = $database->connect($scope);
                $borrowed->execute('INSERT INTO items VALUES (2, ?)', ['child']);
                return (int) $borrowed->query('SELECT count(*) AS n FROM items')[0]['n'];
            });
            $second = $parent->spawn(static function (ExecutionScope $scope) use ($database): string {
                return $database->connect($scope)->query('SELECT value FROM items WHERE id = 2')[0]['value'];
            });
            self::check($database->statistics()['waiters'] === 2 && $budget->poolBudget()->statistics()['waiters'] === 2, '队列与共享预算登记不符');
            $overflow = $parent->spawn(static function (ExecutionScope $scope) use ($database): bool {
                return self::rejected(static fn (): mixed => $database->connect($scope), 'capacity');
            });
            self::check($overflow->await() === true, '队列满没有及时拒绝');
            self::rejected(static fn (): mixed => $database->connect($parent, 0), 'capacity');
            $connection->close();
            self::check($child->await() === 2 && $second->await() === 'child', '排队借用未按顺序读写真实 SQLite');
            self::check($database->statistics()['waiters'] === 0 && $database->statistics()['rejected'] === 2, '排队结束仍有登记或拒绝次数错误');
            self::check($database->statistics()['wait_seconds'] > 0, '没有记录真实等待时长');
            self::$observations['queue'] = $database->statistics();
        } finally {
            $parent->close();
            $database->close();
        }
    }

    private static function deadlines(string $file, DeploymentBudget $budget): void
    {
        $database = new Database(new SqliteDriver($file), 1, 0, $budget, 2, 0.05);
        $parent = new ExecutionScope(new Deadline(2));
        try {
            $connection = $database->connect($parent);
            $child = $parent->spawn(static function (ExecutionScope $scope) use ($database): bool {
                return self::rejected(static fn (): mixed => $database->connect($scope), 'cancelled');
            });
            self::check($database->statistics()['waiters'] === 1, '取消前等待未登记');
            $child->cancel();
            self::check($child->await() === true && $database->statistics()['waiters'] === 0, '取消后仍留在队列');
            self::check($budget->poolBudget()->statistics()['allocated'] === 1, '取消等待释放了其他执行者的连接');
            $state = (object) ['cid' => 0];
            $nativeCancelled = $parent->spawn(static function (ExecutionScope $scope) use ($database, $state): bool {
                $state->cid = Coroutine::getCid();
                return self::rejected(static fn (): mixed => $database->connect($scope), 'cancelled');
            });
            self::check(Coroutine::cancel($state->cid), 'Swoole 未取消池等待');
            self::check($nativeCancelled->await() === true && $database->statistics()['waiters'] === 0, '原生取消后未撤销等待');
            $draining = new ExecutionScope(new Deadline(1));
            try {
                $shortened = $draining->spawn(static function (ExecutionScope $scope) use ($database): bool {
                    return self::rejected(static fn (): mixed => $database->connect($scope), 'deadline_exceeded');
                });
                self::check($database->statistics()['waiters'] === 1, '缩短截止前没有等待者');
                $draining->deadline()->shorten(0);
                self::check($shortened->finished() && $shortened->await() === true, '排空缩短截止未立即唤醒等待');
            } finally {
                $draining->close();
            }
            $started = hrtime(true);
            $bounded = $parent->spawn(static function (ExecutionScope $scope) use ($database): bool {
                return self::rejected(static fn (): mixed => $database->connect($scope, 0.01), 'pool_timeout');
            });
            self::check($bounded->await() === true && (hrtime(true) - $started) / 1000000000.0 < 0.2, '本次较小等待未生效');
            $expired = new ExecutionScope(new Deadline(0.01));
            try {
                self::rejected(static fn (): mixed => $database->connect($expired), 'deadline_exceeded');
            } finally {
                $expired->close();
            }
            $cycle = $parent->spawn(static function (ExecutionScope $scope) use ($database): bool {
                return self::rejected(static fn (): mixed => $database->connect($scope, 1), 'pool_timeout');
            });
            self::check($cycle->await() === true, '父持有最后连接等待子任务没有有界失败');
            self::check($database->statistics()['waiters'] === 0 && $budget->poolBudget()->statistics()['waiters'] === 0, '截止后等待登记未撤销');
            self::check($connection->query('SELECT count(*) AS n FROM items')[0]['n'] === 2, '等待失败污染父连接');
            $connection->close();
            self::$observations['deadlines'] = $database->statistics();
        } finally {
            $parent->close();
            $database->close();
        }
        self::check($parent->activeTasks() === 0 && $parent->state() === 'closed', '等待环没有完整收尾');
    }

    private static function retirement(string $file, DeploymentBudget $budget): void
    {
        $manager = new DatabaseManager(['default' => new SqliteDriver($file)], 1, 0, $budget, 2, 0.3);
        $parent = new ExecutionScope(new Deadline(2));
        try {
            $old = $manager->connect($parent);
            $waiting = $parent->spawn(static function (ExecutionScope $scope) use ($manager): bool {
                return self::rejected(static fn (): mixed => $manager->connect($scope), '退役');
            });
            self::check($manager->statistics()['active']['default']['waiters'] === 1, '轮换前没有等待者');
            $manager->rotate('default', new SqliteDriver($file, 1000, true, 2));
            self::check($waiting->await() === true && $budget->poolBudget()->statistics()['waiters'] === 0, '旧池退役没有撤销等待');
            self::rejected(static fn (): mixed => $manager->connect($parent, 'default', 0), 'capacity');
            self::check($manager->statistics()['retired-generations']['default'] === 1, '仍持有租约的旧代被回收');
            $new = $parent->spawn(static function (ExecutionScope $scope) use ($manager): int {
                $connection = $manager->connect($scope);
                self::check($connection->identity()['credential-generation'] === 2, '新池拿到旧凭据租约');
                return (int) $connection->query('SELECT count(*) AS n FROM items')[0]['n'];
            });
            self::check($manager->statistics()['active']['default']['waiters'] === 1, '新凭据代次绕过总预算');
            self::check($old->query('SELECT count(*) AS n FROM items')[0]['n'] === 2, '退役提前销毁旧租约');
            $old->close();
            self::check($new->await() === 2 && $manager->statistics()['retired-generations']['default'] === 0, '旧代完成未唤醒新池或未回收');
            $live = $manager->connect($parent);
            $closing = $parent->spawn(static function (ExecutionScope $scope) use ($manager): bool {
                return self::rejected(static fn (): mixed => $manager->connect($scope), '关闭');
            });
            $manager->close();
            self::check($closing->await() === true, '关闭未唤醒等待');
            $live->close();
            self::check($budget->poolBudget()->statistics()['allocated'] === 0, '关闭后仍有额度占用');
        } finally {
            $parent->close();
            $manager->close();
        }
    }

    private static function completion(string $file, ResourceBudget $budget): void
    {
        $resource = new GatedSqliteResource($file);
        $resource->closeGate = new Channel(1);
        $closeGate = $resource->closeGate;
        $operationGate = new Channel(1);
        $pool = new ResourcePool(static fn (): ReusableResource => $resource, 1, 0, $budget, 2, 0.5);
        $nextPool = new ResourcePool(static fn (): ReusableResource => new GatedSqliteResource($file), 1, 0, $budget, 2, 0.5);
        $parent = new ExecutionScope(new Deadline(2));
        try {
            $running = $parent->spawn(static function (ExecutionScope $scope) use ($pool, $operationGate): int {
                $lease = $pool->borrow($scope);
                self::rejected(static fn (): string => serialize($lease), 'resource_transfer_forbidden');
                return $lease->hold(static function (ReusableResource $item) use ($scope, $operationGate): int {
                    self::check($item instanceof GatedSqliteResource, '真实资源类型错误');
                    $scope->close();
                    self::check($operationGate->pop(2) === true, '操作门闩超时');
                    return $item->read();
                });
            });
            self::check($pool->statistics()['in_flight'] === 1 && $pool->statistics()['quarantined'] === 1
                && $pool->statistics()['leased'] === 1 && $budget->statistics()['allocated'] === 1, '关闭意图提前释放在途租约');
            $next = $parent->spawn(static function (ExecutionScope $scope) use ($nextPool): int {
                return $nextPool->borrow($scope)->hold(static function (ReusableResource $item): int {
                    self::check($item instanceof GatedSqliteResource, '新资源类型错误');
                    return $item->read();
                });
            });
            self::check($nextPool->statistics()['waiters'] === 1, '未完成操作的额度被新池复用');
            $operationGate->push(true);
            self::check($pool->statistics()['closing'] === 1 && $pool->statistics()['created'] === 1
                && $budget->statistics()['allocated'] === 1 && !$running->finished(), '操作退出被当作物理关闭完成');
            self::$observations['closing'] = $pool->statistics();
            $closeGate->push(true);
            self::check($running->await() === 2 && $next->await() === 2, '原资源关闭后未恢复借用');
            self::check($budget->statistics()['allocated'] === 0 && $pool->statistics()['created'] === 0
                && $nextPool->statistics()['waiters'] === 0, '晚完成后资源未收尾');
        } finally {
            $parent->close();
            $pool->close();
            $nextPool->close();
        }
    }

    private static function failures(string $file, ResourceBudget $budget): void
    {
        $scope = new ExecutionScope();
        $broken = new ResourcePool(static fn (): ReusableResource => new GatedSqliteResource($file . '/missing.sqlite'), 1, 0, $budget);
        try {
            self::rejected(static fn (): mixed => $broken->borrow($scope), 'pdo');
            self::check($broken->statistics()['created'] === 0 && $budget->statistics()['allocated'] === 0, '建连失败没有归还额度');
        } finally {
            $scope->close();
            $broken->close();
        }
        $resource = new GatedSqliteResource($file);
        $resource->resetFailure = true;
        $resource->closeFailures = 1;
        $pool = new ResourcePool(static fn (): ReusableResource => $resource, 1, 0, $budget);
        $owner = new ExecutionScope();
        $lease = $pool->borrow($owner);
        self::check($lease->hold(static function (ReusableResource $item): int {
            self::check($item instanceof GatedSqliteResource, '故障资源类型错误');
            return $item->read();
        }) === 2, '故障前实际查询失败');
        $owner->close();
        self::check($pool->statistics()['quarantined'] === 1 && $pool->statistics()['cleanup_failures'] === 2
            && $budget->statistics()['allocated'] === 1, '关闭失败被当作资源已释放');
        self::$observations['quarantined'] = $pool->statistics();
        $peer = new ExecutionScope();
        self::rejected(static fn (): mixed => $pool->borrow($peer, 0), 'capacity');
        $peer->close();
        $pool->close();
        self::check($pool->statistics()['created'] === 0 && $pool->statistics()['quarantined'] === 0
            && $budget->statistics()['allocated'] === 0, '确认关闭后未归还隔离额度');
        $pool->close();
        self::check($budget->statistics()['allocated'] === 0, '重复关闭多归还额度');
    }

    /** 只通过任务结果、共享额度和原生定时器观察多级后代的真实收尾。 */
    private static function taskCompletion(): void
    {
        $parent = new ExecutionScope(null, [], 3, 0);
        $immediate = $parent->spawn(static fn (ExecutionScope $scope): int => 7);
        self::check($immediate->finished() && $immediate->await() === 7 && $immediate->await() === 7
            && $parent->activeTasks() === 0, '先完成再等待丢失通知或重复释放额度');
        $gate = new Channel(1);
        $state = new stdClass();
        $state->cid = 0;
        $timerCount = Swoole\Timer::stats()['num'];
        $branch = $parent->spawn(static function (ExecutionScope $scope) use ($gate, $state): int {
            $state->cid = Coroutine::getCid();
            $scope->spawn(static function (ExecutionScope $middle) use ($gate): void {
                $middle->spawn(static function (ExecutionScope $leaf) use ($gate): void {
                    self::check($gate->pop() === true, '后代完成门闩未放行');
                });
            });
            return 9;
        });
        self::check(!$branch->finished() && $parent->activeTasks() === 3, '清理超时提前归还子树额度');
        self::check(Swoole\Timer::stats()['num'] === $timerCount, '等待后代收尾仍在周期轮询');
        self::rejected(static fn (): mixed => $parent->spawn(static fn (ExecutionScope $scope): int => 0), 'child_limit');
        self::check(Coroutine::cancel($state->cid), '原生取消未唤醒收尾等待');
        self::check(!$branch->finished() && $parent->activeTasks() === 3
            && Swoole\Timer::stats()['num'] === $timerCount, '原生取消导致提前完成或忙等');
        $gate->push(true);
        self::check($branch->finished() && $parent->activeTasks() === 0, '最后后代退出未直接通知全部祖先');
        self::rejected(static fn (): mixed => $branch->await(), '子任务清理超时');
        self::rejected(static fn (): mixed => $branch->await(), '子任务清理超时');
        $parent->close();
        $parent->close();
        self::check($parent->state() === 'closed' && $parent->activeTasks() === 0, '重复关闭改变最终状态或额度');
        self::$observations['task_completion'] = ['active' => $parent->activeTasks(), 'timers_added' => Swoole\Timer::stats()['num'] - $timerCount];
    }

    private static function scopeCompletion(string $file): void
    {
        $scope = new ExecutionScope(null, [], 1, 0);
        $first = new ScopeFileResource($file . '.first', $scope, 1);
        $last = new ScopeFileResource($file . '.last', $scope);
        $scope->open($first);
        $scope->open($last);
        $lifecycle = new WorkLifecycle();
        self::check($lifecycle->begin(), '角色没有接收首项工作');
        $lifecycle->attach($scope);
        $gate = new Channel(1);
        $child = $scope->spawn(static function (ExecutionScope $childScope) use ($gate): void {
            self::check($gate->pop() === true, '迟到子任务未放行');
        });
        $timers = Swoole\Timer::stats()['num'];
        self::rejected(static fn (): mixed => $scope->close(), 'controlled stop failure');
        self::check($scope->state() === 'closing' && $first->open() && !$last->open()
            && $first->attempts === 1 && $last->attempts === 1, '清理失败资源被遗忘或重入重复关闭');
        self::check(ScopeFileResource::$stops === [basename($file) . '.last', basename($file) . '.first'], '资源没有逆序关闭');
        $lifecycle->finish();
        self::check(!$lifecycle->ready() && $lifecycle->statistics()['in_flight'] === 1
            && $lifecycle->statistics()['state'] === 'draining' && !$lifecycle->begin(), '角色提前释放额度或继续预取');
        $replacement = new ExecutionScope();
        self::rejected(static fn (): mixed => $lifecycle->attach($replacement), 'cleanup_incomplete');
        $replacement->close();
        $gate->push(true);
        self::check($child->finished() && $scope->activeTasks() === 0 && $scope->state() === 'closing', '迟到后代完成越过失败资源');
        $scope->close();
        $scope->close();
        $scope->awaitClosed();
        self::check($scope->state() === 'closed' && !$first->open() && $first->attempts === 2 && $last->attempts === 1, '再次清理没有只处理失败资源');
        self::check($lifecycle->statistics()['in_flight'] === 0 && $lifecycle->statistics()['state'] === 'stopped', '真实清理完成后角色仍错误占用');
        self::check(Swoole\Timer::stats()['num'] === $timers, '清理失败引入周期重试');
        $partial = new ExecutionScope();
        $started = new ScopeFileResource($file . '.partial', $partial, 1, true);
        self::rejected(static fn (): mixed => $partial->open($started), 'controlled start failure');
        self::rejected(static fn (): mixed => $partial->close(), 'controlled stop failure');
        self::check($partial->state() === 'closing' && $started->open(), '部分启动的句柄丢失收尾责任');
        $partial->close();
        self::check($partial->state() === 'closed' && !$started->open(), '部分启动没有真实关闭');
        self::$observations['scope_completion'] = ['first_attempts' => $first->attempts, 'last_attempts' => $last->attempts,
            'partial_attempts' => $started->attempts, 'timers_added' => Swoole\Timer::stats()['num'] - $timers,
            'state' => $scope->state(), 'role' => $lifecycle->statistics()];
    }

    public static function waitFile(string $file): void
    {
        $deadline = new Deadline(5);
        while (!is_file($file)) {
            if ($deadline->expired()) {
                throw new RuntimeException('线程屏障超时：' . basename($file));
            }
            usleep(1000);
        }
    }
}

function main(int $argc, array $argv): void
{
    $directory = $argv[1];
    CoroutineRuntime::assertAvailable();
    PoolProbe::check(Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_UDP), '无法准备已有原生 hook');
    $owner = base64_encode(serialize(new ExecutionOwner(false)));
    $threads = [];
    try {
        foreach (['left', 'right'] as $role) {
            $threads[] = CoroutineRuntime::startThread('probe', json_encode(['directory' => $directory, 'role' => $role, 'owner' => $owner], JSON_THROW_ON_ERROR));
        }
        $hooks = Swoole\Runtime::getHookFlags();
        CoroutineRuntime::enableIo();
        PoolProbe::check($hooks === (SWOOLE_HOOK_TCP | SWOOLE_HOOK_UDP | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_STREAM_FUNCTION)
            && Swoole\Runtime::getHookFlags() === $hooks, '启动线程未补齐原生 hook 或重复配置改变已有值');
        PoolProbe::waitFile($directory . '/left.ready');
        PoolProbe::waitFile($directory . '/right.ready');
        $left = json_decode((string) file_get_contents($directory . '/left.ready'), true, 512, JSON_THROW_ON_ERROR);
        $right = json_decode((string) file_get_contents($directory . '/right.ready'), true, 512, JSON_THROW_ON_ERROR);
        PoolProbe::check($left['allocated'] + $right['allocated'] === 2, '两个存活线程的实际占用超出进程额度');
    } finally {
        file_put_contents($directory . '/go', 'go');
        $exits = [];
        foreach ($threads as $thread) {
            $thread->join();
            $exits[] = $thread->getExitStatus();
        }
    }
    echo json_encode(['exits' => $exits, 'active_threads' => Swoole\Thread::activeCount(), 'main_thread' => Swoole\Thread::getNativeId(),
        'process' => getmypid(), 'source_free' => get_included_files() === [], 'file_abi' => defined('SWOOLE_FILE_IO_ABI')], JSON_THROW_ON_ERROR), "\n";
}
