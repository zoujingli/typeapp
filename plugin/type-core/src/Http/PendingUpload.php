<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 由请求作用域拥有的待保存上传；未提交时随作用域关闭删除临时内容。 */
final class PendingUpload implements ManagedResource
{
    private UploadStorage $storage;
    private ExecutionScope $scope;
    private string $key;
    private bool $saved = false;
    private bool $created = false;
    private bool $stopped = false;
    /** @internal 由 UploadStorage 为随机存储键建立待保存上传。 */
    public function __construct(UploadStorage $storage, ExecutionScope $scope, string $key)
    {
        $this->storage = $storage;
        $this->scope = $scope;
        $this->key = $key;
    }
    /** 创建本上传独占的临时文件；重复启动或已停止时拒绝。 */
    public function start(): void
    {
        if ($this->created || $this->stopped) {
            throw new \RuntimeException('上传资源不能重复启动');
        }
        $this->storage->create($this->key);
        $this->created = true;
    }
    /**
     * 同步文件并提交为持久内容，返回存储键；提交后文件由业务管理删除。
     * @throws \RuntimeException 作用域不可用或上传未启动、已停止。
     */
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
    /** 幂等清理未保存内容；已保存文件保留给业务所有者。 */
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
