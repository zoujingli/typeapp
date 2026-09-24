<?php

declare(strict_types=1);

namespace TypeApp\QueueExample;

use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Runtime\ManagedResource;
use Type\Runtime\ExecutionScope;

/** 队列作用域的可观测资源，累计开启/关闭并可注入清理失败。 */
final class Resource implements ManagedResource
{
    public static int $opened = 0;
    public static int $closed = 0;
    public static bool $failStop = false;
    /** 累计资源启动次数，不建立外部连接。 */
    public function start(): void
    {
        self::$opened++;
    }
    /** 正常累计关闭次数，failStop 时故意失败以阻止提前确认投递。 */
    public function stop(): void
    {
        if (self::$failStop) {
            throw new \RuntimeException('controlled queue cleanup failure');
        }
        self::$closed++;
    }
}

/** 在 Redis 中执行带消息身份的累加，验证任务实例与作用域不跨投递复用。 */
final class Increment implements Job
{
    public static ?ExecutionScope $lastScope = null;
    private string $prefix;
    private int $calls = 0;
    /** 记录演练键前缀，避免不同任务批次共享业务效果。 */
    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }
    /**
     * 校验作用域与整数载荷，在租约保护中按稳定消息 ID 去重并累加。
     *
     * @param array{amount: int} $payload 待累计的整数。
     */
    public function handle(JobContext $context, array $payload): void
    {
        if (ExecutionScope::current() !== $context->scope() || ExecutionScope::current()->binding('tenant_id') !== null) {
            throw new \RuntimeException('任务当前作用域错误，或普通消息关联数据进入可信绑定');
        }
        $context->assertActive();
        if (++$this->calls !== 1 || !is_int($payload['amount'] ?? null)) {
            throw new \RuntimeException('任务实例被复用或载荷无效');
        }
        $context->scope()->open(new Resource());
        self::$lastScope = $context->scope();
        if ($context->scope()->context()['message_id'] !== $context->message()->id()) {
            throw new \RuntimeException('消息关联标识被输入覆盖');
        }
        $script = <<<'LUA'
local old=redis.call('GET',KEYS[1]); if old then if old~=ARGV[1] then return redis.error_reply('idempotency conflict') end; return 0 end
redis.call('SET',KEYS[1],ARGV[1],'PX',60000); redis.call('INCRBY',KEYS[2],ARGV[2]); return 1
LUA;
        $context->reservation()->effect(
            $script,
            [$this->prefix . ':done:' . $context->message()->id(), $this->prefix . ':total'],
            [hash('sha256', $context->message()->encode()), $payload['amount']]
        );
    }
}
