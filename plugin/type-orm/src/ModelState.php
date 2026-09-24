<?php

declare(strict_types=1);

namespace Type\Orm;

/** @internal 克隆共享失效状态，不能复制出绕过事务登记的持久化对象。 */
final class ModelState
{
    private bool $valid = true;
    private bool $writing = false;

    /** 永久标记当前共享状态失效，克隆也不能绕过回滚后的失效事实。 */
    public function invalidate(): void
    {
        $this->valid = false;
    }

    /** 取得模型写入标记；事件回调重入同一模型写入会被拒绝。 */
    public function enterWrite(): void
    {
        $this->assertValid();
        if ($this->writing) {
            throw new ModelException('reentrant_write', '模型写入不能重入');
        }
        $this->writing = true;
    }

    /** 释放写入标记，须由写入入口的 finally 调用。 */
    public function leaveWrite(): void
    {
        $this->writing = false;
    }

    /** 拒绝失效模型，调用者必须在当前作用域重新查询。 */
    public function assertValid(): void
    {
        if (!$this->valid) {
            throw new ModelException('model_invalid', '模型已经失效，请重新查询');
        }
    }
}
