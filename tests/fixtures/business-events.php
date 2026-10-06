<?php

declare(strict_types=1);

namespace TypeApp\BusinessEventFixture;

use Type\Core\BusinessEvents;
use Type\Core\Command;
use Type\Core\Configuration;
use Type\Orm\AfterCommitException;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Sqlite\SqliteDriver;

final class Saved
{
    public array $trace = [];
    public array $transactions = [];
    public int $sequence = 0;
    public function __construct(public string $marker)
    {
    }
}

final class Failed
{
    public array $trace = [];
}

final class Ignored
{
}

final class State implements \Type\Runtime\ManagedResource
{
    public static int $active = 0;
    public int $calls = 0;
    public function start(): void
    {
        self::$active++;
    }
    public function stop(): void
    {
        self::$active--;
    }
}

final class First
{
    public function __construct(private State $state)
    {
    }
    public function saved(Saved $event): void
    {
        $event->trace[] = 'first:' . $event->marker;
        $event->transactions[] = Db::inTransaction();
        $this->state->calls++;
        $event->sequence = $this->state->calls;
        \Swoole\Coroutine::sleep(0.001);
    }
    public function failed(Failed $event): void
    {
        $event->trace[] = 'first';
        $this->state->calls++;
    }
}

final class Second
{
    public function __construct(private State $state)
    {
    }
    public function saved(Saved $event): void
    {
        $event->trace[] = 'second:' . $event->marker;
        $this->state->calls++;
    }
    public function failed(Failed $event): void
    {
        $event->trace[] = 'unreachable';
        $this->state->calls++;
    }
}

final class Reject
{
    public function failed(Failed $event): void
    {
        throw new \DomainException('listener_failed');
    }
}

final class Cyclic
{
    public function __construct(private Cyclic $dependency)
    {
    }
    public function saved(Saved $event): void
    {
    }
}

/** 公开命令演示同步、无监听、失败中断和真实 SQLite 提交后边界。 */
final class Scenario implements Command
{
    public function __construct(private BusinessEvents $events, private State $state)
    {
    }

    public function run(Configuration $configuration, array $arguments): int
    {
        $mode = (string) ($arguments[0] ?? 'ordered');
        $event = new Saved($mode);
        if ($mode === 'empty') {
            $this->events->dispatch(new Ignored());
            echo json_encode(['calls' => $this->state->calls], JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        }
        if ($mode === 'failure') {
            $failed = new Failed();
            try {
                $this->events->dispatch($failed);
                throw new \RuntimeException('listener_failure_missing');
            } catch (\DomainException $error) {
                echo json_encode(['trace' => $failed->trace, 'calls' => $this->state->calls, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL;
            }
            return 0;
        }
        $committed = false;
        if (in_array($mode, ['commit', 'rollback', 'after-failure', 'sync'], true)) {
            Db::configure(new DatabaseManager(['default' => new SqliteDriver((string) $arguments[1])], 1, 0));
            Db::connection('default', true)->execute('CREATE TABLE IF NOT EXISTS events_test (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)');
            try {
                Db::transaction(function () use ($mode, $event): void {
                    Db::connection('default', true)->execute('INSERT INTO events_test (marker) VALUES (?)', [$mode]);
                    if ($mode === 'sync') {
                        $this->events->dispatch($event);
                    } elseif ($mode === 'after-failure') {
                        Db::afterCommit(function (): void {
                            $this->events->dispatch(new Failed());
                        });
                    } else {
                        Db::afterCommit(function () use ($event): void {
                            $this->events->dispatch($event);
                        });
                    }
                    if ($mode !== 'sync' && $this->state->calls !== 0) {
                        throw new \RuntimeException('after_commit_ran_before_commit');
                    }
                    if ($mode === 'rollback') {
                        throw new \DomainException('rollback');
                    }
                });
                $committed = true;
            } catch (AfterCommitException $error) {
                $committed = true;
            } catch (\DomainException $error) {
                if ($mode !== 'rollback') {
                    throw $error;
                }
            }
            $rows = Db::connection('default', true)->query('SELECT marker FROM events_test');
            echo json_encode(['trace' => $event->trace, 'calls' => $this->state->calls, 'transactions' => $event->transactions,
                'committed' => $committed, 'rows' => count($rows)], JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        }
        $this->events->dispatch($event);
        $this->events->dispatch($event);
        echo json_encode(['trace' => $event->trace, 'calls' => $this->state->calls], JSON_THROW_ON_ERROR) . PHP_EOL;
        return 0;
    }
}


/** 作用域隔离与取消使用同一命令源码，PHP和AOT均可运行。 */
final class ScopeScenario implements \Type\Core\Command
{
    public function run(\Type\Core\Configuration $configuration, array $arguments): int
    {
        $application = new \Type\Generated\CommandApplication(new \Type\Core\Configuration([]));
        $scope = new \Type\Runtime\ExecutionScope();
        try {
            $scope->run(function (\Type\Runtime\ExecutionScope $parent) use ($application): void {
                $events = $application->events();
                if ($events !== $application->events()) {
                    throw new \RuntimeException('not_reused');
                }
                $first = new \TypeApp\BusinessEventFixture\Saved('parent');
                $events->dispatch($first);
                $tasks = [];
                for ($index = 0; $index < 4; $index++) {
                    $tasks[] = $parent->spawn(function (\Type\Runtime\ExecutionScope $child) use ($application, $events, $index): void {
                        $own = $application->events();
                        if ($own === $events) {
                            throw new \RuntimeException('child_dispatcher_inherited');
                        }
                        try {
                            $events->dispatch(new \TypeApp\BusinessEventFixture\Ignored());
                            throw new \LogicException('parent_dispatcher_allowed');
                        } catch (\RuntimeException $expected) {
                        }
                        $event = new \TypeApp\BusinessEventFixture\Saved('child-' . $index);
                        $own->dispatch($event);
                        if ($event->sequence !== 1 || $event->trace !== ['first:child-' . $index, 'second:child-' . $index]) {
                            throw new \RuntimeException('child_state_contaminated');
                        }
                    });
                }
                foreach ($tasks as $task) {
                    $task->await();
                }
                if ($first->trace !== ['first:parent', 'second:parent']) {
                    throw new \RuntimeException('parent_event_mutated');
                }
                try {
                    $events->dispatch(new \stdClass());
                    throw new \LogicException('unknown_event_allowed');
                } catch (\InvalidArgumentException $expected) {
                    if ($expected->getMessage() !== 'event_not_declared') {
                        throw $expected;
                    }
                }
                $parent->cancellation()->cancel();
                try {
                    $events->dispatch(new \TypeApp\BusinessEventFixture\Ignored());
                    throw new \LogicException('cancelled_dispatch_allowed');
                } catch (\RuntimeException $expected) {
                }
            });
        } finally {
            $scope->close();
        }
        echo "scope checks passed\n";
        return 0;
    }
}

/** 顶层角色负责在共同命令结束后确认资源已经归还。 */
final class Application
{
    public static function run(array $arguments, bool $development): void
    {
        $application = new \Type\Generated\CommandApplication(new \Type\Core\Configuration([]));
        $status = $application->run($arguments[1] ?? 'help', array_slice($arguments, 2));
        if (State::$active !== 0) {
            throw new \RuntimeException('event_resource_leaked');
        }
        if ($status !== 0) {
            exit($status);
        }
    }
}
