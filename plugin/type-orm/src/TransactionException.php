<?php

declare(strict_types=1);

namespace Type\Orm;

use Throwable;

/** 携带事务结果的数据库异常，用于区分未开始、已回滚与提交未知。 */
class TransactionException extends DatabaseException
{
    private string $outcome;

    /** 固定本次事务失败的结果；UNKNOWN 不能解释为已回滚。 */
    public function __construct(string $outcome, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->outcome = $outcome;
    }

    /** 返回异常发生时的事务事实，不受后来发起的新事务覆盖。 */
    public function outcome(): string
    {
        return $this->outcome;
    }
}
