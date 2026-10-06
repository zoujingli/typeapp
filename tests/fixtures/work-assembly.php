<?php

declare(strict_types=1);

namespace TypeApp\WorkFixture;

use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Runtime\ManagedResource;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

/** 装配图正向资源，不产生外部效果。 */
final class Resource implements ManagedResource
{
    public function start(): void
    {
    }
    public function stop(): void
    {
    }
}

/** 具体资源依赖自动推导。 */
class ReportJob implements Job
{
    public function __construct(Resource $resource)
    {
    }
    public function handle(JobContext $context, array $payload): void
    {
    }
}

/** 继承执行方法必须与自身执行方法一样接受静态校验。 */
final class InheritedJob extends ReportJob
{
}

/** 依赖缺失时不能等到领取消息后才失败。 */
final class MissingDependencyJob implements Job
{
    public function __construct(Absent $value)
    {
    }
    public function handle(JobContext $context, array $payload): void
    {
    }
}

/** 循环依赖必须在构建时失败。 */
final class CircularJob implements Job
{
    public function __construct(CircularDependency $value)
    {
    }
    public function handle(JobContext $context, array $payload): void
    {
    }
}

final class CircularDependency
{
    public function __construct(CircularJob $job)
    {
    }
}

/** 调度图正向任务。 */
final class ReportTask implements Task
{
    public function __construct(Resource $resource)
    {
    }
    public function run(TaskContext $context): array
    {
        return [];
    }
}
