<?php

declare(strict_types=1);

namespace Type\Scheduler;

use Type\Redis\RedisConnection;
use Type\Runtime\ExecutionOwner;

/** 同一命名调度组串行 tick；共享游标避免新旧进程重复同一计划时刻。 */
final class RedisStateStore implements LeasedStateStore
{
    private RedisConnection $redis;
    private string $root;
    private int $milliseconds;
    private ?RedisLease $lease = null;
    private ?ExecutionOwner $owner = null;

    /**
     * 绑定稳定应用/组名与借用的 script 连接，存储不负责关闭外层连接。
     *
     * @param int $leaseMilliseconds 整个 tick 的租约时长，10 至 3600000 毫秒。
     * @throws LeaseException 身份或租约配置无效。
     */
    public function __construct(RedisConnection $redis, string $application, string $name = 'default', int $leaseMilliseconds = 30000)
    {
        if ($application === '' || strlen($application) > 500 || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name)
            || $leaseMilliseconds < 10 || $leaseMilliseconds > 3600000) {
            throw new LeaseException('config', 'Redis 调度组身份或租约时长无效');
        }
        $this->redis = $redis;
        $this->root = 'type:scheduler:{' . hash('sha256', $application . "\0" . $name) . '}';
        $this->milliseconds = $leaseMilliseconds;
    }

    /** 返回由应用和组名派生的 Redis 键前缀；不同实例应保持相同业务分组。 */
    public function identity(): string
    {
        return $this->root;
    }

    /**
     * 获取组租约并仅在首代初始化状态；已有代次但状态丢失时停止以保留现场。
     *
     * @throws LeaseException 已占用、租约冲突或共享状态缺失。
     */
    public function acquire(): void
    {
        if ($this->lease !== null) {
            throw new LeaseException('busy', '当前调度存储已经取得执行租约');
        }
        $this->lease = RedisLease::acquire($this->redis, $this->root, $this->milliseconds);
        $this->owner = new ExecutionOwner();
        try {
            $initialized = $this->lease->effect(<<<'LUA'
if redis.call('EXISTS',KEYS[1])==1 then return 1 end
if ARGV[1]~='1' then return 0 end
redis.call('SET',KEYS[1],ARGV[2]); return 1
LUA, [$this->root . ':state'], [$this->lease->generation(), StateCodec::encode(['protocol' => 1, 'cursors' => [], 'records' => []])]);
            if ($initialized !== 1) {
                throw new LeaseException('store', '已有租约代次但共享游标缺失，停止调度并恢复状态');
            }
        } catch (\Throwable $error) {
            try {
                $this->release();
            } catch (\Throwable) {
            }
            throw $error;
        }
    }

    /**
     * 返回本次 acquire() 的租约，供任务包装为自身作用域权限。
     *
     * @throws LeaseException 当前存储未持有租约。
     */
    public function lease(): ExecutionLease
    {
        if ($this->lease === null) {
            throw new LeaseException('lease_lost', '调度存储尚未取得租约');
        }
        return $this->lease;
    }

    /**
     * 在租约保护的读取中取得并验证共享状态，不自动重置丢失游标。
     *
     * @return array{protocol: int, cursors: array<string, int>, records: list<array<string, mixed>>}
     */
    public function load(): array
    {
        if ($this->lease === null) {
            throw new LeaseException('lease_lost', '读取状态前需要取得租约');
        }
        $encoded = $this->lease->effect("return redis.call('GET',KEYS[1])", [$this->root . ':state']);
        if ($encoded === false || $encoded === null) {
            throw new LeaseException('store', '共享调度状态丢失，不能自动重置游标');
        }
        if (!is_string($encoded)) {
            throw new LeaseException('store', 'Redis 调度状态格式错误');
        }
        return StateCodec::decode($encoded);
    }

    /**
     * 验证编码预算，先续租再在持有权保护下覆盖状态，不给状态和代次设置 TTL。
     *
     * @param array{protocol: int, cursors: array<string, int>, records: list<array<string, mixed>>} $state 待保存的完整状态。
     */
    public function save(array $state): void
    {
        if ($this->lease === null) {
            throw new LeaseException('lease_lost', '保存状态前需要取得租约');
        }
        $encoded = StateCodec::encode($state);
        $this->lease->renew();
        $this->lease->effect("redis.call('SET',KEYS[1],ARGV[1]); return 1", [$this->root . ':state'], [$encoded]);
    }

    /** 清除本地持有关系并条件释放当前组租约，外层 Redis 连接继续由应用管理。 */
    public function release(): void
    {
        $this->owner?->assertCurrent();
        $lease = $this->lease;
        $this->lease = null;
        $this->owner = null;
        $lease?->release();
    }
}
