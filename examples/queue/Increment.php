<?php

declare(strict_types=1);

namespace TypeApp\QueueExample;

use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Runtime\ManagedResource;
use Type\Runtime\ExecutionScope;

final class Resource implements ManagedResource
{
    public static int $opened = 0;
    public static int $closed = 0;
    public static bool $failStop = false;
    public function start(): void
    {
        self::$opened++;
    }
    public function stop(): void
    {
        if (self::$failStop) {
            throw new \RuntimeException('controlled queue cleanup failure');
        }
        self::$closed++;
    }
}

final class Increment implements Job
{
    public static ?ExecutionScope $lastScope = null;
    private string $prefix;
    private int $calls = 0;
    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }
    public function handle(JobContext $context, array $payload): void
    {
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
