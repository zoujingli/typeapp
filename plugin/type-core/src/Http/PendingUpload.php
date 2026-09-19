<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

final class PendingUpload implements ManagedResource
{
    private UploadStorage $storage;
    private ExecutionScope $scope;
    private string $key;
    private bool $saved = false;
    private bool $created = false;
    private bool $stopped = false;
    public function __construct(UploadStorage $storage, ExecutionScope $scope, string $key)
    {
        $this->storage = $storage;
        $this->scope = $scope;
        $this->key = $key;
    }
    public function start(): void
    {
        if ($this->created || $this->stopped) {
            throw new \RuntimeException('上传资源不能重复启动');
        }
        $this->storage->create($this->key);
        $this->created = true;
    }
    public function save(): string
    {
        $this->scope->assertActive();
        if (!$this->created || $this->stopped) {
            throw new \RuntimeException('上传资源不可保存');
        }
        if ($this->saved) {
            return $this->key;
        }
        $this->storage->commit($this->key);
        $this->saved = true;
        return $this->key;
    }
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        if ($this->created && !$this->saved) {
            $this->storage->discard($this->key);
        }
        $this->stopped = true;
    }
}
