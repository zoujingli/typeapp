<?php

declare(strict_types=1);

namespace TypeApp\Coordination;

use Type\Redis\RedisConnection;
use Type\Redis\ScriptGuard;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

/** 用真实 Redis 效果与投递回执观察调度完成；hold/paused 为进程故障演练入口。 */
final class ReportTask implements Task
{
    private RedisConnection $redis;
    private QueueDispatchTask $dispatch;
    private string $key;
    private string $mode;

    /** 注入同一 Redis 目标、投递任务和故障模式；外部角色负责连接生命周期。 */
    public function __construct(RedisConnection $redis, QueueDispatchTask $dispatch, string $key, string $mode)
    {
        $this->redis = $redis;
        $this->dispatch = $dispatch;
        $this->key = $key;
        $this->mode = $mode;
    }

    /**
     * 在租约保护下记录一次计划效果并投递；hold/paused 模式供外部竞争与暂停演练。
     *
     * @return array{message_id: string, receipt: string, effect_count: int}
     */
    public function run(TaskContext $context): array
    {
        $guard = $context->lease();
        if (!$guard instanceof ScriptGuard) {
            throw new \RuntimeException('任务缺少 Redis 租约保护');
        }
        if ($this->mode === 'hold' || $this->mode === 'paused') {
            $guard->execute($this->redis, "return redis.call('SET',KEYS[1],ARGV[1])", [$this->key . ':ready'], [(string) getmypid()]);
        }
        if ($this->mode === 'hold') {
            $deadline = microtime(true) + 10;
            while ($guard->execute($this->redis, "return redis.call('GET',KEYS[1])", [$this->key . ':continue'], []) !== 'yes') {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('竞争演练等待超时');
                }
                usleep(20000);
                $context->renew();
            }
        } elseif ($this->mode === 'paused') {
            // 外部测试在 ready 后真实 SIGSTOP；恢复时必须由实际目标拒绝失效 token。
            usleep(1000000);
        }
        $effect = <<<'LUA'
if redis.call('HSETNX',KEYS[1],ARGV[1],'applied')==1 then redis.call('INCR',KEYS[2]) end
return tonumber(redis.call('GET',KEYS[2]))
LUA;
        $count = $guard->execute($this->redis, $effect, [$this->key . ':applied', $this->key . ':count'], [$context->occurrenceId()]);
        $result = $this->dispatch->run($context);
        $result['effect_count'] = $count;

        return $result;
    }
}
