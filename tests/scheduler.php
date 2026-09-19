<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Type\Runtime\ManagedResource;
use Type\Scheduler\CronSchedule;
use Type\Scheduler\Definition;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\IntervalSchedule;
use Type\Scheduler\Scheduler;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

final class TestSchedulerClock implements ClockInterface
{
    public function __construct(public int $timestamp)
    {
    }
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}

final class TestScheduledTask implements Task
{
    public static array $scopes = [];
    public static array $events = [];
    private int $calls = 0;

    public function __construct(private bool $failure = false, private bool $cleanupFailure = false)
    {
    }

    public function run(TaskContext $context): array
    {
        self::$scopes[] = $context->scope();
        $context->scope()->open(new TestScheduledResource($this->cleanupFailure));
        $this->calls++;
        self::$events[] = 'run';
        if ($this->failure) {
            throw new RuntimeException('任务失败');
        }
        return ['calls' => $this->calls];
    }
}

final class TestScheduledResource implements ManagedResource
{
    public function __construct(private bool $failure)
    {
    }
    public function start(): void
    {
        TestScheduledTask::$events[] = 'start';
    }
    public function stop(): void
    {
        TestScheduledTask::$events[] = 'stop';
        if ($this->failure) {
            throw new RuntimeException('清理失败');
        }
    }
}

function scheduledTimes(CronSchedule $schedule, string $after, string $through): array
{
    return array_map(
        static fn (int $timestamp): string => gmdate('Y-m-d H:i:s', $timestamp),
        $schedule->occurrences(strtotime($after), strtotime($through), 20)
    );
}

date_default_timezone_set('Asia/Shanghai');
expect(scheduledTimes(new CronSchedule('0 9 * * *'), '2026-09-09T08:59:59Z', '2026-09-09T09:00:00Z') === ['2026-09-09 09:00:00'], '默认 Cron 受进程时区影响');
expect(scheduledTimes(new CronSchedule('0 9 * * *', 'Asia/Shanghai'), '2026-09-09T00:59:59Z', '2026-09-09T01:00:00Z') === ['2026-09-09 01:00:00'], 'Cron 没有采用指定时区');
expect(scheduledTimes(new CronSchedule('30 2 * * *', 'America/New_York'), '2026-03-08T00:00:00Z', '2026-03-09T00:00:00Z') === [], '春季不存在的当地时刻没有跳过');
expect(scheduledTimes(new CronSchedule('30 1 * * *', 'America/New_York'), '2026-11-01T00:00:00Z', '2026-11-02T00:00:00Z') === ['2026-11-01 05:30:00'], '秋季重叠默认没有只执行第一次');
expect(scheduledTimes(new CronSchedule('30 1 * * *', 'America/New_York', 'both'), '2026-11-01T00:00:00Z', '2026-11-02T00:00:00Z') === ['2026-11-01 05:30:00', '2026-11-01 06:30:00'], '秋季显式 both 没有保留两个 UTC 时刻');
expect(scheduledTimes(new CronSchedule('45 1 * * *', 'Australia/Lord_Howe', 'both'), '2026-04-04T12:00:00Z', '2026-04-05T12:00:00Z') === ['2026-04-04 14:45:00', '2026-04-04 15:15:00'], '半小时 DST 重叠被错误假设为一小时');
expect(scheduledTimes(new CronSchedule('0 0 L * *'), '2028-02-28T23:59:59Z', '2028-03-01T00:00:00Z') === ['2028-02-29 00:00:00'], 'Cron 解析库没有处理闰年月末');
expect((new IntervalSchedule(10, 5))->occurrences(5, 46, 3) === [25, 35, 45], '固定锚点间隔或最新补跑上限错误');

$directory = sys_get_temp_dir() . '/type_scheduler_' . bin2hex(random_bytes(8));
expect(mkdir($directory, 0700), '无法建立调度测试状态目录');
try {
    $clock = new TestSchedulerClock(600);
    $factory = static fn (TaskContext $context): Task => new TestScheduledTask();
    $skip = new Definition('skip', new IntervalSchedule(60), $factory);
    expect($skip->due(0, 650) === [600], '默认跳过旧计划并保留正常轮询宽限失败');
    expect($skip->due(600, 650) === [], '宽限期内重复生成计划时刻');
    $catchup = new Definition('catchup', new IntervalSchedule(60), $factory, 'catch-up', 2, 600, 0, 'v1');
    $store = new FileStateStore($directory . '/state.json');
    $scheduler = new Scheduler($clock, $store, [$catchup]);
    $first = $scheduler->tick();
    expect(array_column($first, 'scheduled_at') === [540, 600], '首次启动的有限补跑没有选择最新时刻');
    expect(array_column($first, 'state') === ['succeeded', 'succeeded'] && $first[0]['result'] === ['calls' => 1] && $first[1]['result'] === ['calls' => 1], '任务没有在独立实例内完成');
    expect(TestScheduledTask::$events === ['start', 'run', 'stop', 'start', 'run', 'stop'], '每次任务未正确清理作用域');
    foreach (TestScheduledTask::$scopes as $scope) {
        $closed = false;
        try {
            $scope->assertActive();
        } catch (RuntimeException) {
            $closed = true;
        }
        expect($closed, '任务完成后作用域仍然活动');
    }
    $clock->timestamp = 590;
    expect($scheduler->tick() === [], '时钟回拨重复执行已计划任务');
    $changed = new Definition('catchup', new CronSchedule('* * * * *', 'Asia/Shanghai'), $factory, 'catch-up', 2, 600, 0, 'v2');
    $clock->timestamp = 600;
    $restart = new Scheduler($clock, new FileStateStore($directory . '/state.json'), [$changed]);
    expect($restart->tick() === [], '重启或定义版本变化重复同一时刻');
    $freshVersion = new Scheduler($clock, new FileStateStore($directory . '/version.json'), [$changed]);
    $versionResult = $freshVersion->tick();
    expect($first[1]['occurrence_id'] === $versionResult[1]['occurrence_id'], '执行身份依赖配置版本或时区文本');
    $clock->timestamp = 900;
    expect(array_column($restart->tick(), 'scheduled_at') === [840, 900], '停机后补跑没有严格限制次数');
    expect(count($restart->history()) === 4, '重启丢失历史执行记录');

    $failure = new Definition('failure', new IntervalSchedule(60), static fn (TaskContext $context): Task => new TestScheduledTask(true));
    $cleanup = new Definition('cleanup', new IntervalSchedule(60), static fn (TaskContext $context): Task => new TestScheduledTask(false, true));
    $failedScheduler = new Scheduler($clock, new FileStateStore($directory . '/failure.json'), [$failure, $cleanup, $skip]);
    $failed = $failedScheduler->tick();
    expect($failed[0]['state'] === 'failed' && $failed[0]['error']['message'] === '任务失败' && $failed[0]['finished_at'] === 900, '业务异常没有记录失败结果与结束时间');
    expect($failed[1]['state'] === 'failed' && str_contains($failed[1]['cleanup_error']['message'], '清理失败'), '资源清理失败被误标为成功');
    expect(count($failed) === 2 && $failed[1]['finished_at'] === null && !$failedScheduler->ready()
        && $failedScheduler->statistics()['in_flight'] === 1 && $failedScheduler->statistics()['state'] === 'draining'
        && $failedScheduler->statistics()['cleanup_failures'] === 1 && $failedScheduler->statistics()['storage_failures'] === 0, '清理未完成仍推进任务或错误归还执行额度');
    expect($failedScheduler->tick() === [], '失败任务被静默自动重试');
    $holding = new FileStateStore($directory . '/failure.json');
    $holding->acquire();
    try {
        $busy = false;
        try {
            (new Scheduler($clock, new FileStateStore($directory . '/failure.json'), [$failure]))->tick();
        } catch (RuntimeException $error) {
            $busy = str_contains($error->getMessage(), 'TYPE_SCHEDULER_BUSY');
        }
        expect($busy, '本地并发进程没有互斥执行');
    } finally {
        $holding->release();
    }

    $clock->timestamp = 960;
    $limited = new Scheduler($clock, new FileStateStore($directory . '/state.json'), [$changed], 2);
    $limited->tick();
    expect(count($limited->history()) === 2, '执行历史没有按配置有界保留');
    file_put_contents($directory . '/corrupt.json', '{损坏的状态');
    $corrupt = new Scheduler($clock, new FileStateStore($directory . '/corrupt.json'), [$changed]);
    $rejected = false;
    try {
        $corrupt->tick();
    } catch (JsonException) {
        $rejected = true;
    }
    expect($rejected && file_get_contents($directory . '/corrupt.json') === '{损坏的状态', '损坏状态被静默重置导致计划重复执行');
    echo "调度公共接口验证通过：UTC、时区、DST、稳定身份、间隔、有限补跑、重启、回拨、作用域与失败状态。\n";
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
