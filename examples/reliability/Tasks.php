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

/** 以受管 Redis 效果观察队列持久恢复、延迟和不合作任务停止。 */
final class DurableJob implements Job
{
    private string $key;
    private string $mode;
    /** 选择专属效果键和延迟故障模式，不在构造时获取租约。 */
    public function __construct(string $key, string $mode = 'normal')
    {
        $this->key = $key;
        $this->mode = $mode;
    }
    /**
     * 记录开始事实后按模式等待，在有效检查点处理消息效果。
     *
     * @param array<array-key, mixed> $payload 演练消息数据。
     */
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

/** 在调度租约保护下修改持久计数，供 Redis 重启后核对游标与效果。 */
final class DurableSchedule implements Task
{
    private RedisConnection $redis;
    private string $key;
    /** 借用与调度状态同一目标的 Redis 连接和独立计数键。 */
    public function __construct(RedisConnection $redis, string $key)
    {
        $this->redis = $redis;
        $this->key = $key;
    }
    /**
     * 要求支持原子脚本的调度租约，再完成一次有保护的计数。
     *
     * @return array<string, mixed> 本次效果与计划摘要。
     */
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

    /** 绑定当前调度器，供任务内部触发受控停止，不接管其资源。 */
    public function attach(Scheduler $scheduler): void
    {
        $this->scheduler = $scheduler;
    }
    /**
     * 读取停止瞬间的生命周期快照。
     *
     * @return array<string, mixed>
     */
    public function stopping(): array
    {
        return $this->stopping;
    }
    /** 返回停止缩短截止后捕获到的错误标识。 */
    public function deadlineError(): string
    {
        return $this->deadlineError;
    }

    /**
     * 在任务内部触发短预算停止，并验证当前上下文的后续有效性。
     *
     * @return array<string, mixed> 停止与截止观察结果。
     */
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
