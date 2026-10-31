<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Type\Runtime\Cancellation;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskException;

/** 通过作用域、通知与原生计时器统计验证共享截止，不访问实现字段。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DeadlineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('需要实际 Swoole 验证截止通知');
        }
    }

    /** 同一个截止只保留一个原生定时器；撤销部分作用域不能影响其余所有者。 */
    public function testScopesShareOneNativeTimerAndReleaseItAfterTheLastClose(): void
    {
        CoroutineRuntime::run(static function (): void {
            $before = self::timerCount();
            $deadline = new Deadline(5.0);
            $scopes = [];
            try {
                for ($index = 0; $index < 4; $index++) {
                    $scopes[] = new ExecutionScope($deadline);
                }
                self::assertSame(1, self::timerCount() - $before);
                $scopes[0]->close();
                self::assertSame(1, self::timerCount() - $before);
                self::assertFalse($scopes[1]->cancellation()->cancelled());
            } finally {
                foreach ($scopes as $scope) {
                    $scope->close();
                }
            }
            self::assertSame($before, self::timerCount());
        });
    }

    /** 观察器收到缩短和真实到期；有限等待由原生唤醒，不需要调用者周期检查。 */
    public function testWatchersObserveShorteningAndExpiration(): void
    {
        $events = CoroutineRuntime::run(static function (): array {
            $deadline = new Deadline(1.0);
            $done = new Channel(1);
            $events = [];
            $subscription = $deadline->watch(static function () use ($deadline, $done, &$events): void {
                $events[] = $deadline->expired() ? 'expired' : 'shortened';
                if ($deadline->expired()) {
                    $done->push(true);
                }
            });
            try {
                $deadline->shorten(0.02);
                self::assertSame(['shortened'], $events);
                self::assertTrue($done->pop(0.5));
                self::assertTrue($deadline->expired());
                return $events;
            } finally {
                $deadline->unwatch($subscription);
            }
        });
        self::assertSame(['shortened', 'expired'], $events);
        self::assertSame(0, self::timerCount());
    }

    /** 共享外部取消对象不转移截止所有权；较早的截止不会取消另一作用域。 */
    public function testExternalCancellationKeepsItsIndependentOwner(): void
    {
        CoroutineRuntime::run(static function (): void {
            $cancellation = new Cancellation();
            $early = new ExecutionScope(new Deadline(0.02), cancellation: $cancellation);
            $later = new ExecutionScope(new Deadline(1.0), cancellation: $cancellation);
            $done = new Channel(1);
            $deadline = $early->deadline();
            $subscription = $deadline->watch(static function () use ($deadline, $done): void {
                if ($deadline->expired()) {
                    $done->push(true);
                }
            });
            try {
                self::assertTrue($done->pop(0.5));
                self::assertFalse($cancellation->cancelled());
                $later->assertActive();
                $failure = '';
                try {
                    $early->assertActive();
                } catch (TaskException $error) {
                    $failure = $error->errorCode();
                }
                self::assertSame('deadline_exceeded', $failure);
            } finally {
                $deadline->unwatch($subscription);
                $early->close();
                $later->close();
            }
        });
        self::assertSame(0, self::timerCount());
    }

    /** 取消通知必须覆盖全部观察器，仍将第一个监听失败交还调用方。 */
    public function testListenerFailureDoesNotSkipOtherExpiredWatchers(): void
    {
        CoroutineRuntime::run(static function (): void {
            $deadline = new Deadline(1.0);
            $expected = new \RuntimeException('deadline-listener-failed');
            $first = $deadline->watch(static function () use ($expected): void {
                throw $expected;
            });
            $notified = false;
            $second = $deadline->watch(static function () use (&$notified): void {
                $notified = true;
            });
            $actual = null;
            try {
                $deadline->shorten(0.0);
            } catch (\RuntimeException $error) {
                $actual = $error;
            } finally {
                $deadline->unwatch($first);
                $deadline->unwatch($second);
            }
            self::assertSame($expected, $actual);
            self::assertTrue($notified);
        });
    }

    /** 关闭必须撤销原生回调及其对象引用，即使外部仍长期持有 Deadline。 */
    public function testClosedScopeIsNotRetainedByTheSharedDeadline(): void
    {
        CoroutineRuntime::run(static function (): void {
            $before = self::timerCount();
            $deadline = new Deadline(5.0);
            $scope = new ExecutionScope($deadline);
            $weak = \WeakReference::create($scope);
            $scope->close();
            unset($scope);
            self::assertSame($before, self::timerCount());
            self::assertNull($weak->get());
        });
    }

    private static function timerCount(): int
    {
        return (Timer::stats() ?: [])['num'] ?? 0;
    }
}
