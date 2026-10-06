<?php

declare(strict_types=1);

namespace app\catalog\job;

use app\catalog\service\DeliveryService;
use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Queue\QueueException;

/** 由 make job 生成后补全的消费协议；Worker 负责租约、确认与正常停止。 */
final class DeliverProduct implements Job
{
    /** 服务每次任务独立装配，不捕获启动期连接。 */
    public function __construct(private DeliveryService $service)
    {
    }

    /** @param array<array-key,mixed> $payload 固定整数商品身份，其他输入不作为权限。 */
    public function handle(JobContext $context, array $payload): void
    {
        $context->assertActive();
        if (!is_int($payload['product_id'] ?? null) || $payload['product_id'] < 1) {
            throw new QueueException('invalid_payload', '商品身份必须为正整数');
        }
        $this->service->consume($context->message()->id(), $payload['product_id']);
        $context->assertActive();
    }
}
