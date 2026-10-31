<?php

declare(strict_types=1);

namespace TypeTests\Fixtures;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Type\Runtime\Cancellation;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskBudget;
use Type\Runtime\TaskException;

/**
 * 通过公开任务入口观察等待超时与真实收尾；任务的原生 sleep 故意只在结束后检查取消。
 * @return array<string, mixed> 等待、资源额度、外部取消所有权及后续真实完成的观察值。
 */
function taskDeadlineObservation(?float $initial, bool $external, ?float $waitSeconds = null, float $shortenSeconds = 0.01): array
{
    $deadline = new Deadline($initial);
    $cancellation = new Cancellation();
    $budget = new TaskBudget(1);
    $parent = new ExecutionScope($deadline, cancellation: $external ? $cancellation : null, taskBudget: $budget);
    $other = $external ? new ExecutionScope(new Deadline(1.0), cancellation: $cancellation) : null;
    $childCancelled = false;
    $slept = false;
    $task = $parent->spawn(static function (ExecutionScope $current) use (&$childCancelled, &$slept): int {
        $slept = Coroutine::sleep(0.25);
        $childCancelled = $current->cancellation()->cancelled();
        return 7;
    });
    $timer = Timer::after(10, static function () use ($deadline, $shortenSeconds): void {
        $deadline->shorten($shortenSeconds);
    });
    $started = hrtime(true);
    $errorCode = '';
    $value = \std::any(null);
    try {
        try {
            $value = $task->await($waitSeconds);
        } catch (TaskException $error) {
            $errorCode = $error->errorCode();
        }
        $elapsed = (hrtime(true) - $started) / 1000000.0;
        $finished = $task->finished();
        $held = $budget->active();
        $cancelled = $cancellation->cancelled();
        $other?->assertActive();
        $joined = $task->join(new Deadline(1.0));
        $lateResult = $task->await();
    } finally {
        if ($timer !== false && Timer::exists($timer)) {
            Timer::clear($timer);
        }
        try {
            $parent->close();
        } finally {
            $other?->close();
        }
    }
    return ['error' => $errorCode, 'value' => $value, 'elapsed_ms' => $elapsed,
        'finished_after_wait' => $finished, 'held_after_wait' => $held,
        'external_cancelled' => $cancelled, 'joined' => $joined, 'late_result' => $lateResult,
        'child_cancelled' => $childCancelled, 'sleep_completed' => $slept,
        'held_after_join' => $budget->active(), 'state' => $parent->state()];
}

/**
 * 截止通知中完成的任务同步唤醒 await；其撤销后的回调快照不能取消接下来的独立清理。
 * @return array{value:int,sleep_completed:bool,cleanup_ms:float,state:string}
 */
function taskDeadlineCompletionObservation(): array
{
    $deadline = new Deadline(0.8);
    $parent = new ExecutionScope($deadline);
    $release = new Channel(1);
    $task = $parent->spawn(static function (ExecutionScope $current) use ($release): int {
        $release->pop();
        return 7;
    });
    $subscription = $deadline->watch(static function () use ($deadline, $release): void {
        if ($deadline->expired()) {
            $release->push(true);
        }
    });
    $shortener = Timer::after(10, static function () use ($deadline): void {
        $deadline->shorten(0.0);
    });
    try {
        $value = $task->await();
        $started = hrtime(true);
        $completed = Coroutine::sleep(0.05);
        $elapsed = (hrtime(true) - $started) / 1000000.0;
    } finally {
        if ($shortener !== false && Timer::exists($shortener)) {
            Timer::clear($shortener);
        }
        $deadline->unwatch($subscription);
        $release->close();
        $parent->close();
    }
    return ['value' => $value, 'sleep_completed' => $completed, 'cleanup_ms' => $elapsed, 'state' => $parent->state()];
}
