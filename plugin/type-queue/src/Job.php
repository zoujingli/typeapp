<?php

declare(strict_types=1);

namespace Type\Queue;

/** 已编译注册的任务处理器；一次投递创建一个实例，业务效果由处理器负责幂等。 */
interface Job
{
    /**
     * 处理一次消息，正常返回后仍须由 Worker 完成资源清理再确认。
     *
     * @param array<array-key, mixed> $payload 消息中的 JSON 数据，业务字段仍需自行校验。
     * @throws \Throwable 业务失败，交给 Worker 按重试策略转移或隔离。
     */
    public function handle(JobContext $context, array $payload): void;
}
