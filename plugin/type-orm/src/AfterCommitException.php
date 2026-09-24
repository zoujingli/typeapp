<?php

declare(strict_types=1);

namespace Type\Orm;

/** 数据库已提交但提交后操作失败；不表示原事务回滚。 */
final class AfterCommitException extends TransactionException
{
    private array $errors;

    /**
     * 记录最终提交之后的回调错误；数据库提交事实保持 COMMITTED。
     *
     * @param list<\Throwable> $errors 各回调按执行顺序产生的错误。
     */
    public function __construct(array $errors)
    {
        parent::__construct(TransactionOutcome::COMMITTED, '数据库已经提交，但部分提交后回调失败', $errors[0] ?? null);
        $this->errors = $errors;
    }

    /**
     * 取得已收集的提交后错误，便于补偿或告警，不应用于重跑事务。
     *
     * @return list<\Throwable>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
