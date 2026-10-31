<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Timer;
use Type\Runtime\CoroutineRuntime;

use function TypeTests\Fixtures\taskDeadlineCompletionObservation;
use function TypeTests\Fixtures\taskDeadlineObservation;

/** 缩短共享截止必须结束当前等待，同时保留未退出子任务的额度和外部取消所有权。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ManagedTaskDeadlineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('需要实际 Swoole 验证原生等待截止');
        }
        require_once dirname(__DIR__, 2) . '/vendor/swoole/typephp/src/polyfills.php';
        require_once dirname(__DIR__) . '/fixtures/task-deadline.php';
    }

    /** @return iterable<string, array{?float, bool, ?float, float}> 共享截止与单次等待的真实组合。 */
    public static function deadlines(): iterable
    {
        yield 'finite' => [0.8, false, null, 0.01];
        yield 'unlimited' => [null, false, null, 0.01];
        yield 'external-finite' => [0.8, true, null, 0.01];
        yield 'external-unlimited' => [null, true, null, 0.01];
        yield 'shorter-await' => [0.8, false, 0.03, 0.5];
    }

    /** 等待到期不强行中断子任务；join 和再次 await 仍观察真实完成及其结果。 */
    #[DataProvider('deadlines')]
    public function testAwaitUsesTheLiveDeadline(?float $initial, bool $external, ?float $waitSeconds, float $shortenSeconds): void
    {
        $result = CoroutineRuntime::run(static function () use ($initial, $external, $waitSeconds, $shortenSeconds): array {
            return taskDeadlineObservation($initial, $external, $waitSeconds, $shortenSeconds);
        });
        self::assertSame('task_timeout', $result['error']);
        self::assertNull($result['value']);
        self::assertLessThan(150, $result['elapsed_ms']);
        self::assertFalse($result['finished_after_wait']);
        self::assertSame(1, $result['held_after_wait']);
        self::assertFalse($result['external_cancelled']);
        self::assertTrue($result['joined']);
        self::assertSame(7, $result['late_result']);
        self::assertTrue($result['child_cancelled']);
        self::assertTrue($result['sleep_completed']);
        self::assertSame(0, $result['held_after_join']);
        self::assertSame('closed', $result['state']);
        self::assertSame(0, Timer::stats()['num'] ?? 0);
    }

    /** 任务在其他到期监听中完成时，保留其结果，后续原生等待不能被旧回调取消。 */
    public function testCompletedAwaitDoesNotCancelFollowingCleanup(): void
    {
        $result = CoroutineRuntime::run(static fn (): array => taskDeadlineCompletionObservation());
        self::assertSame(7, $result['value']);
        self::assertTrue($result['sleep_completed']);
        self::assertGreaterThanOrEqual(40, $result['cleanup_ms']);
        self::assertSame('closed', $result['state']);
        self::assertSame(0, Timer::stats()['num'] ?? 0);
    }
}
