<?php

declare(strict_types=1);

namespace TypeApp\QueueExample;

use Type\Queue\Job;
use Type\Queue\JobContext;

/** 故意按载荷延迟或失败，记录尝试次数以观察重试与清理协议。 */
final class RetryJob implements Job
{
    public static array $handled = [];
    /**
     * 为每次尝试登记资源并检查租约，按演练载荷触发超时或业务失败。
     *
     * @param array<array-key, mixed> $payload 可包含 slow 等受控故障字段。
     */
    public function handle(JobContext $context, array $payload): void
    {
        $context->scope()->open(new Resource());
        $context->assertActive();
        $attempt = $context->reservation()->attempt();
        self::$handled[] = [$context->message()->id(), $attempt];
        if (($payload['slow'] ?? false) === true) {
            usleep(30000);
            $context->reservation()->effect("return redis.call('SET',KEYS[1],'must-not-write')", [$payload['effect_key']]);
        }
        if (($payload['failures'] ?? 0) >= $attempt) {
            throw new \RuntimeException('任务需要重试，秘密不进入隔离原因');
        }
    }
}
