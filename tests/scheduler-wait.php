<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Timer;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SchedulerConsole;
use Type\Scheduler\SystemClock;

/**
 * 在公开控制台入口核对间隔、停止与取消；文件状态仅属于调用者提供的测试目录。
 * @return list<array{case: string, status: int, lines: int, ready: bool, elapsed_ms: float}>
 */
function schedulerWaitChecks(string $directory): array
{
    $results = [];
    foreach (['interval', 'stopped', 'stop', 'invalid-stop', 'storage-failure', 'cancel'] as $case) {
        $scheduler = new Scheduler(new SystemClock(), new FileStateStore($directory . '/wait-' . $case . '.json'), []);
        $interval = $case === 'stop' || $case === 'storage-failure' ? '60000' : '80';
        if ($case === 'stopped') {
            $scheduler->stop(0.0);
        }
        $cid = Coroutine::getCid();
        $acted = false;
        $guarded = false;
        $actionFailed = false;
        $observer = 0;
        $published = false;
        $output = '';
        ob_start(static function (string $bytes, int $phase) use (&$output, &$published): string {
            $output .= $bytes;
            $published = str_contains($output, "\n");
            return '';
        }, 1);
        $started = hrtime(true);
        // 先观察首轮公开输出，下一次事件分发时控制台已经进入间隔等待。
        if (in_array($case, ['stop', 'invalid-stop', 'storage-failure', 'cancel'], true)) {
            $observer = (int) Timer::tick(1, static function (int $timerId) use ($case, $scheduler, $directory, $cid, &$published, &$acted, &$actionFailed): void {
                if (!$published) {
                    return;
                }
                Timer::clear($timerId);
                $acted = true;
                if ($case === 'stop') {
                    $scheduler->stop(0.0);
                    $scheduler->stop(0.0);
                } elseif ($case === 'cancel') {
                    Coroutine::cancel($cid);
                } elseif ($case === 'invalid-stop') {
                    try {
                        $scheduler->stop(NAN);
                        $actionFailed = true;
                    } catch (InvalidArgumentException) {
                    }
                } else {
                    file_put_contents($directory . '/wait-' . $case . '.json', '{');
                    try {
                        $scheduler->tick();
                        $actionFailed = true;
                    } catch (JsonException) {
                    }
                }
            });
        }
        $guard = (int) Timer::after(1000, static function () use ($scheduler, &$guarded): void {
            $guarded = true;
            $scheduler->stop(0.0);
        });
        try {
            $status = (new SchedulerConsole($scheduler))->run(['work', '2', $interval]);
            $elapsed = (hrtime(true) - $started) / 1000000.0;
        } finally {
            ob_end_flush();
            foreach ([$observer, $guard] as $timer) {
                if ($timer > 0 && Timer::exists($timer)) {
                    Timer::clear($timer);
                }
            }
        }
        $stopped = in_array($case, ['stopped', 'stop', 'storage-failure'], true);
        $lines = $case === 'stopped' ? 0 : ($stopped ? 1 : 2);
        if ($status !== 0 || $output !== str_repeat("[]\n", $lines) || $scheduler->ready() === $stopped || $guarded || $actionFailed
            || ($observer > 0 && !$acted) || (!$stopped && $elapsed < 80.0)
            || ($case === 'storage-failure' && $scheduler->statistics()['storage_failures'] !== 1)) {
            throw new RuntimeException('调度等待行为变化：' . $case . ' ' . json_encode([
                'status' => $status, 'output' => $output, 'ready' => $scheduler->ready(),
                'guarded' => $guarded, 'acted' => $acted, 'action_failed' => $actionFailed, 'elapsed_ms' => $elapsed,
            ], JSON_THROW_ON_ERROR));
        }
        $results[] = ['case' => $case, 'status' => $status, 'lines' => $lines, 'ready' => $scheduler->ready(), 'elapsed_ms' => $elapsed];
    }
    return $results;
}
