<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/** 请求额度包含发送及清理；关闭未完成的作用域继续占用，不伪装成空闲。 */
final class HttpControl
{
    private array $scopes = [];
    private array $quarantined = [];
    private bool $accepting = true;
    private ?Deadline $drain = null;
    private int $rejected = 0;
    private int $cleanupFailures = 0;
    private int $completed = 0;
    private int $peak = 0;
    public function __construct(
        public readonly int $maximumRequests = 64,
        public readonly int $maximumConnections = 256,
        public readonly float $requestSeconds = 30.0,
        public readonly float $drainSeconds = 5.0,
        public readonly float $cleanupSeconds = 1.0,
        public readonly int $maximumChildren = 16,
        public readonly bool $probes = false,
    ) {
        if ($maximumRequests < 1 || $maximumRequests > 10000 || $maximumConnections < $maximumRequests || $maximumConnections > 100000
            || !is_finite($requestSeconds) || $requestSeconds <= 0 || $requestSeconds > 3600
            || !is_finite($drainSeconds) || $drainSeconds <= 0 || $drainSeconds > 60
            || !is_finite($cleanupSeconds) || $cleanupSeconds < 0 || $cleanupSeconds > $drainSeconds || $maximumChildren < 1 || $maximumChildren > 1024) {
            throw new \InvalidArgumentException('HTTP 并发、截止或排空预算无效');
        }
    }
    public function begin(): ?ExecutionScope
    {
        $this->reap();
        if (!$this->ready()) {
            $this->rejected++;
            return null;
        }
        $scope = new ExecutionScope(new Deadline($this->requestSeconds), [], $this->maximumChildren, $this->cleanupSeconds);
        $this->scopes[spl_object_id($scope)] = $scope;
        $this->peak = max($this->peak, count($this->scopes));
        return $scope;
    }
    public function finish(ExecutionScope $scope, bool $cleanupFailed): void
    {
        $id = spl_object_id($scope);
        if ($cleanupFailed) {
            $this->cleanupFailures++;
        }
        if ($scope->state() === 'closed') {
            unset($this->scopes[$id]);
            $this->completed++;
        } else {
            $this->quarantined[$id] = true;
            $this->accepting = false;
        }
    }
    public function stop(): void
    {
        $this->accepting = false;
        $this->drain ??= new Deadline($this->drainSeconds);
        foreach ($this->scopes as $scope) {
            $scope->deadline()->shorten($this->drain->remaining() ?? 0.0);
            $scope->limitCleanup($this->drain);
        }
    }
    public function ready(): bool
    {
        return $this->accepting && count($this->scopes) < $this->maximumRequests;
    }
    public function stopping(): bool
    {
        return !$this->accepting;
    }
    public function probe(string $path): ?array
    {
        if (!$this->probes || !in_array($path, ['/readyz', '/livez'], true)) {
            return null;
        }
        return ['status' => $path === '/livez' || $this->ready() ? 200 : 503,
            'body' => $path === '/livez' ? ['live' => true] : ['ready' => $this->ready()]];
    }
    public function mustTerminate(): bool
    {
        $this->reap();
        return $this->quarantined !== [] || ($this->drain !== null && $this->drain->expired() && $this->scopes !== []);
    }
    public function drained(): bool
    {
        $this->reap();
        return !$this->accepting && $this->scopes === [];
    }
    public function statistics(): array
    {
        $this->reap();
        return ['ready' => $this->ready(), 'live' => true, 'accepting' => $this->accepting, 'in_flight' => count($this->scopes),
            'request_limit' => $this->maximumRequests, 'connection_limit' => $this->maximumConnections, 'child_limit' => $this->maximumChildren,
            'quarantined' => count($this->quarantined), 'rejected' => $this->rejected, 'cleanup_failures' => $this->cleanupFailures, 'completed' => $this->completed,
            'peak_in_flight' => $this->peak];
    }
    private function reap(): void
    {
        foreach ($this->quarantined as $id => $unused) {
            if ($this->scopes[$id]->state() === 'closed') {
                unset($this->quarantined[$id], $this->scopes[$id]);
                $this->completed++;
            }
        }
    }
}
