<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Event;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\TaskException;

/** 隔离进程级协程选项，通过公开入口观察根任务是否执行及失败后能否继续使用。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CoroutineRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('需要实际 Swoole 验证协程调度入口');
        }
    }

    /** 原生 Scheduler 完成等待不能被当作提交成功；满额的根任务必须明确拒绝。 */
    public function testRootAdmissionFailureDoesNotReturnAnUnexecutedResult(): void
    {
        $options = Coroutine::getOptions() ?? [];
        Coroutine::set(['max_coroutine' => 1]);
        $held = Coroutine::create(static function (): void {
            Coroutine::sleep(0.01);
        });
        self::assertNotFalse($held);
        $called = false;
        $failure = '';
        $warnings = [];
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
            if ($severity === E_WARNING && str_contains($message, 'exceed max number of coroutine')) {
                $warnings[] = $message;
                return true;
            }
            return false;
        });
        try {
            CoroutineRuntime::run(static function () use (&$called): int {
                $called = true;
                return 7;
            });
        } catch (TaskException $error) {
            $failure = $error->errorCode();
        } finally {
            restore_error_handler();
            Event::wait();
            Coroutine::set(['max_coroutine' => $options['max_coroutine'] ?? 0]);
        }
        self::assertFalse($called);
        self::assertSame('coroutine_start_failed', $failure);
        self::assertCount(1, $warnings);
        self::assertFalse(Coroutine::exists($held));
        self::assertSame(0, Coroutine::stats()['coroutine_num']);
        self::assertSame(11, CoroutineRuntime::run(static fn (): int => 11));
    }

    /** 空结果仍是成功；已有协程内的调用保持身份，并原样传回业务异常。 */
    public function testExecutedNullResultsNestedCallsAndExceptionsRemainObservable(): void
    {
        self::assertNull(CoroutineRuntime::run(static fn (): mixed => null));
        self::assertTrue(CoroutineRuntime::run(static function (): bool {
            $cid = Coroutine::getCid();
            return $cid >= 0 && CoroutineRuntime::run(static fn (): int => Coroutine::getCid()) === $cid;
        }));
        $expected = new \RuntimeException('operation-failed');
        $actual = null;
        try {
            CoroutineRuntime::run(static function () use ($expected): void {
                throw $expected;
            });
        } catch (\RuntimeException $error) {
            $actual = $error;
        }
        self::assertSame($expected, $actual);
        self::assertSame(0, Coroutine::stats()['coroutine_num']);
    }
}
