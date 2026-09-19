<?php

declare(strict_types=1);

namespace Type\Scheduler;

use Type\Redis\RedisConnection;
use Type\Redis\ScriptGuard;
use Type\Runtime\ExecutionOwner;
use Throwable;

/** 单 Redis 权威租约；token 与持久化代次共同参与每一次写入校验。 */
final class RedisLease implements ExecutionLease, ScriptGuard
{
    private RedisConnection $redis;
    private string $key;
    private string $value;
    private string $generation;
    private int $milliseconds;
    private ExecutionOwner $owner;
    private bool $finished = false;
    private bool $lost = false;

    private function __construct(RedisConnection $redis, string $key, string $token, string $generation, int $milliseconds)
    {
        $this->redis = $redis;
        $this->key = $key;
        $this->value = $token . ':' . $generation;
        $this->generation = $generation;
        $this->milliseconds = $milliseconds;
        $this->owner = new ExecutionOwner();
    }

    public static function acquire(RedisConnection $redis, string $namespace, int $milliseconds = 30000): RedisLease
    {
        if ($namespace === '' || strlen($namespace) > 500 || $milliseconds < 10 || $milliseconds > 3600000) {
            throw new LeaseException('config', '租约命名空间或时长无效');
        }
        $token = bin2hex(random_bytes(32));
        $script = <<<'LUA'
if redis.call('EXISTS',KEYS[1])==1 then return false end
redis.call('INCR',KEYS[2]); local generation=redis.call('GET',KEYS[2])
redis.call('SET',KEYS[1],ARGV[1]..':'..generation,'PX',ARGV[2]); return generation
LUA;
        $generation = $redis->script($script, [$namespace . ':lock', $namespace . ':generation'], [$token, $milliseconds]);
        if ($generation === false || $generation === null) {
            throw new LeaseException('busy', '已有调度执行者持有租约');
        }
        if (!is_string($generation) || !preg_match('/^[1-9][0-9]*$/D', $generation)) {
            throw new LeaseException('store', 'Redis 租约代次返回无效');
        }

        return new RedisLease($redis, $namespace . ':lock', $token, $generation, $milliseconds);
    }

    public function generation(): string
    {
        $this->assertLocal();
        return $this->generation;
    }

    public function assertOwned(): void
    {
        $this->execute($this->redis, 'return 1', [], []);
    }

    public function renew(): void
    {
        $this->assertLocal();
        try {
            $result = $this->redis->script(
                "if redis.call('GET',KEYS[1])~=ARGV[1] then return 0 end return redis.call('PEXPIRE',KEYS[1],ARGV[2])",
                [$this->key],
                [$this->value, $this->milliseconds]
            );
        } catch (Throwable $error) {
            $this->lost = true;
            throw new LeaseException('lease_lost', '续租结果未知，停止新的受管效果', $error);
        }
        if ($result !== 1) {
            $this->lost = true;
            throw new LeaseException('lease_lost', '过期或旧持有者不能续租');
        }
    }

    public function effect(string $script, array $keys = [], array $arguments = []): mixed
    {
        return $this->execute($this->redis, $script, $keys, $arguments);
    }

    public function execute(RedisConnection $target, string $script, array $keys, array $arguments): mixed
    {
        $this->assertLocal();
        if ($target !== $this->redis) {
            throw new LeaseException('target_mismatch', '原子租约保护要求投递目标与租约使用同一个 RedisConnection');
        }
        if ($script === '' || strlen($script) > 65536 || !array_is_list($keys) || !array_is_list($arguments)
            || count($keys) > 1000 || count($arguments) > 1000) {
            throw new LeaseException('config', '受管效果需要有界可信脚本和参数列表');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new LeaseException('config', '受管效果键必须为非空字符串');
            }
        }
        foreach ($arguments as $argument) {
            if ((!is_string($argument) && !is_int($argument) && !is_float($argument)) || (is_float($argument) && !is_finite($argument))) {
                throw new LeaseException('config', '受管效果参数只接受字符串、整数与有限浮点数');
            }
        }
        $guard = <<<'LUA'
if redis.call('GET',KEYS[1])~=ARGV[1] then return {0} end
local effectKeys={}; for i=2,#KEYS do effectKeys[#effectKeys+1]=KEYS[i] end
local effectArgs={}; for i=2,#ARGV do effectArgs[#effectArgs+1]=ARGV[i] end
LUA;
        $guard .= "\nlocal function effect(KEYS,ARGV)\n" . $script . "\nend\nreturn {1,effect(effectKeys,effectArgs)}";
        try {
            $result = $target->script($guard, array_merge([$this->key], $keys), array_merge([$this->value], $arguments));
        } catch (Throwable $error) {
            $this->lost = true;
            throw new LeaseException('effect_unknown', '受管效果结果无法确认，停止后续执行并核对实际写入', $error);
        }
        if (!is_array($result) || ($result[0] ?? null) !== 1) {
            $this->lost = true;
            throw new LeaseException('lease_lost', '租约已经失效，实际写入目标拒绝旧执行者');
        }

        return $result[1] ?? null;
    }

    public function release(): void
    {
        $this->owner->assertCurrent();
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        try {
            $result = $this->redis->script(
                "if redis.call('GET',KEYS[1])~=ARGV[1] then return 0 end return redis.call('DEL',KEYS[1])",
                [$this->key],
                [$this->value]
            );
        } catch (Throwable $error) {
            $this->lost = true;
            throw new LeaseException('lease_lost', '释放租约结果未知，等待过期且不重试删除', $error);
        }
        if ($result !== 1) {
            $this->lost = true;
            throw new LeaseException('lease_lost', '旧执行者不能释放当前租约');
        }
    }

    private function assertLocal(): void
    {
        $this->owner->assertCurrent();
        if ($this->finished || $this->lost) {
            throw new LeaseException('lease_lost', '租约已结束或已失去可靠持有状态');
        }
    }
}
