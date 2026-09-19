<?php

declare(strict_types=1);

namespace Type\Orm;

/** @internal 克隆共享失效状态，不能复制出绕过事务登记的持久化对象。 */
final class ModelState
{
    private bool $valid = true;
    private bool $writing = false;

    public function invalidate(): void
    {
        $this->valid = false;
    }

    public function enterWrite(): void
    {
        $this->assertValid();
        if ($this->writing) {
            throw new ModelException('reentrant_write', '模型写入不能重入');
        }
        $this->writing = true;
    }

    public function leaveWrite(): void
    {
        $this->writing = false;
    }

    public function assertValid(): void
    {
        if (!$this->valid) {
            throw new ModelException('model_invalid', '模型已经失效，请重新查询');
        }
    }
}
