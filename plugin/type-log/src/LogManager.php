<?php

declare(strict_types=1);

namespace Type\Log;

use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

final class LogManager implements ManagedResource
{
    private string $build;
    private array $channels = [];
    private array $counts = [];
    private bool $stopped = false;
    private ?int $process = null;
    private float $stopSeconds;
    private Formatter $formatter;

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

    public function start(): void
    {
        $this->assertOpen();
    }

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
