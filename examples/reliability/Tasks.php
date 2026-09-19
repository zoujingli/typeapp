<?php

declare(strict_types=1);

namespace TypeApp\Reliability;

use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Redis\RedisConnection;
use Type\Redis\ScriptGuard;
use Type\Runtime\TaskException;
use Type\Scheduler\Scheduler;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

final class DurableJob implements Job
{
    private string $key;
    private string $mode;
    public function __construct(string $key, string $mode = 'normal')
    {
        $this->key = $key;
        $this->mode = $mode;
    }
    public function handle(JobContext $context, array $payload): void
    {
        $context->reservation()->effect("return redis.call('SET',KEYS[1],'yes')", [$this->key . ':started']);
        $duration = $this->mode === 'grace' ? 0.15 : ($this->mode === 'slow' ? 2.0 : ($this->mode === 'uncooperative' ? 5.0 : 0.0));
        $until = microtime(true) + $duration;
        while (microtime(true) < $until) {
            usleep(10000);
            if ($this->mode !== 'uncooperative') {
                $context->assertActive();
                $context->reservation()->renew();
            }
        }
        $context->assertActive();
        $context->reservation()->effect(<<<'LUA'
if redis.call('HSETNX',KEYS[1],ARGV[1],'done')==1 then redis.call('INCR',KEYS[2]) end
return 1
LUA, [$this->key . ':done', $this->key . ':count'], [$context->message()->id()]);
    }
}

final class DurableSchedule implements Task
{
    private RedisConnection $redis;
    private string $key;
    public function __construct(RedisConnection $redis, string $key)
    {
        $this->redis = $redis;
        $this->key = $key;
    }
    public function run(TaskContext $context): array
    {
        $guard = $context->lease();
        if (!$guard instanceof ScriptGuard) {
            throw new \RuntimeException('调度缺少租约保护');
        }
        $count = $guard->execute($this->redis, "return redis.call('INCR',KEYS[1])", [$this->key], []);
        return ['count' => $count];
    }
}

/** 在真正执行的任务内停止调度，观测原有截止协议而不替换调度器。 */
final class StoppingSchedule implements Task
{
    private ?Scheduler $scheduler = null;
    private array $stopping = [];
    private string $deadlineError = '';

    public function attach(Scheduler $scheduler): void
    {
        $this->scheduler = $scheduler;
    }
    public function stopping(): array
    {
        return $this->stopping;
    }
    public function deadlineError(): string
    {
        return $this->deadlineError;
    }

    public function run(TaskContext $context): array
    {
        if ($this->scheduler === null) {
            throw new \RuntimeException('停止任务尚未绑定调度器');
        }
        $context->assertActive();
        $this->scheduler->stop(0.01);
        $this->stopping = $this->scheduler->statistics();
        // 停止请求不抢占代码；下一次公开截止检查必须拒绝超预算的工作。
        usleep(30000);
        try {
            $context->assertActive();
        } catch (TaskException $error) {
            $this->deadlineError = $error->errorCode();
            throw $error;
        } finally {
            $this->scheduler = null;
        }
        return [];
    }
}
