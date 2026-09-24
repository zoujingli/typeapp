<?php

declare(strict_types=1);

namespace Type\Log;

use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 进程内的日志通道管理器；执行关联由各 Scope 独立持有，输出由管理器统一停止。 */
final class LogManager implements ManagedResource
{
    private string $build;
    private array $channels = [];
    private array $counts = [];
    private bool $stopped = false;
    private ?int $process = null;
    private float $stopSeconds;
    private Formatter $formatter;

    /**
     * 登记明确构建身份和输出通道；停止时间为全部输出共享的总秒数。
     *
     * @param array<string, Channel> $channels 通道名到输出配置，最多 64 项。
     * @throws InvalidArgumentException 构建身份、通道或停止预算无效。
     */
    public function __construct(string $buildId, array $channels, float $stopSeconds = 0.25, ?Formatter $formatter = null)
    {
        if ($buildId === '' || strlen($buildId) > 256 || $channels === [] || count($channels) > 64
            || !is_finite($stopSeconds) || $stopSeconds < 0 || $stopSeconds > 60) {
            throw new InvalidArgumentException('日志构建标识、通道或停止预算无效');
        }
        $this->build = $buildId;
        $this->stopSeconds = $stopSeconds;
        $this->formatter = $formatter ?? new Formatter();
        foreach ($channels as $name => $channel) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) || !$channel instanceof Channel) {
                throw new InvalidArgumentException('日志通道需要合法名称与 Channel 配置');
            }
            $this->channels[$name] = $channel;
            $this->counts[$name] = ['filtered' => 0];
        }
    }

    /** 检查进程归属与停止状态；不能从停止状态重新启动。 */
    public function start(): void
    {
        $this->assertOpen();
    }

    /**
     * 为活动作用域登记日志绑定；同名关联以作用域 context 为准。
     *
     * @param array<array-key, mixed> $context 补充关联，在绑定时复制并脱敏。
     * @throws InvalidArgumentException 通道未注册。
     */
    public function logger(ExecutionScope $scope, array $context = [], string $channel = 'app'): Logger
    {
        $this->assertOpen();
        $this->assertChannel($channel);
        $scope->assertActive();
        $values = $this->formatter->context(array_merge($context, $scope->context()));
        $binding = new LogContext($scope, $values);
        $scope->open($binding);
        return new Logger($this, $binding, $channel);
    }

    /**
     * 检查通道已登记，供同管理器的 Logger 切换通道。
     *
     * @internal
     * @throws InvalidArgumentException 通道未注册。
     */
    public function assertChannel(string $name): void
    {
        if (!isset($this->channels[$name])) {
            throw new InvalidArgumentException('日志通道不存在：' . $name);
        }
    }

    /** @internal Logger 已检查执行者与作用域，本方法只处理进程级输出。 */
    public function write(string $channel, mixed $level, string|Stringable $message, array $context, array $correlation): void
    {
        $this->assertOpen();
        $this->assertChannel($channel);
        $weight = Level::weight($level);
        if (!$this->channels[$channel]->accepts($weight)) {
            $this->counts[$channel]['filtered']++;
            return;
        }
        $output = $this->channels[$channel]->output();
        $output->enqueue($this->formatter->record($this->build, $channel, $level, $message, $context, $correlation));
        $output->drain();
    }

    /**
     * 在一个总秒数预算内排空各独立输出；0 表示不等待后续可写事件。
     *
     * @throws InvalidArgumentException 秒数非有限值或不在 0 至 60 之间。
     */
    public function drain(float $seconds = 0.0): void
    {
        $this->assertOpen();
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 60) {
            throw new InvalidArgumentException('日志排空预算无效');
        }
        $deadline = hrtime(true) / 1e9 + $seconds;
        foreach ($this->outputs() as $output) {
            $output->drain(max(0.0, $deadline - hrtime(true) / 1e9));
        }
    }

    /** 停止所有独立输出，预算到期丢弃余下记录；重复调用不再次关闭。 */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->assertOpen();
        $deadline = hrtime(true) / 1e9 + $this->stopSeconds;
        foreach ($this->outputs() as $output) {
            $output->stop(max(0.0, $deadline - hrtime(true) / 1e9));
        }
        $this->stopped = true;
    }

    /**
     * 读取通道过滤与输出计数；共享输出的计数不能按通道相加。
     *
     * @return array<string, array<string, int|bool>> 通道名称到累计计数与当前状态。
     */
    public function stats(): array
    {
        $stats = [];
        foreach ($this->channels as $name => $channel) {
            $stats[$name] = $this->counts[$name] + $channel->output()->stats();
        }
        return $stats;
    }

    private function outputs(): array
    {
        $outputs = [];
        foreach ($this->channels as $channel) {
            $outputs[spl_object_id($channel->output())] = $channel->output();
        }
        return array_values($outputs);
    }

    private function assertOpen(): void
    {
        if ($this->process === null) {
            $this->process = (int) getmypid();
        } elseif ($this->process !== (int) getmypid()) {
            throw new RuntimeException('日志管理器不能跨进程复用');
        }
        if ($this->stopped) {
            throw new RuntimeException('日志管理器已经停止');
        }
    }
}
