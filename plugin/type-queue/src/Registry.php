<?php

declare(strict_types=1);

namespace Type\Queue;

use Closure;

final class Registry
{
    private array $factories = [];
    private bool $started = false;
    /** @param Closure(JobContext): Job $factory 始终接收本次消息上下文。 */
    public function register(string $type, int $version, Closure $factory): void
    {
        $key = $type . ':' . $version;
        if ($this->started || isset($this->factories[$key]) || !preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $type) || $version < 1) {
            throw new QueueException('invalid_registration', '任务注册重复、非法或已开始执行');
        }
        $this->factories[$key] = $factory;
    }
    public function supports(Message $message): bool
    {
        return isset($this->factories[$message->type() . ':' . $message->version()]);
    }
    public function create(JobContext $context): Job
    {
        $this->started = true;
        $message = $context->message();
        if (!$this->supports($message)) {
            throw new QueueException('unsupported_version', '任务类型或版本没有编译注册');
        }
        $job = ($this->factories[$message->type() . ':' . $message->version()])($context);
        if (!$job instanceof Job) {
            throw new QueueException('invalid_factory', '任务工厂没有返回 Job');
        }
        return $job;
    }
}
