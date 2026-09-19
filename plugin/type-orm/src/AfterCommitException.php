<?php

declare(strict_types=1);

namespace Type\Orm;

final class AfterCommitException extends TransactionException
{
    private array $errors;

    public function __construct(array $errors)
    {
        parent::__construct(TransactionOutcome::COMMITTED, '数据库已经提交，但部分提交后回调失败', $errors[0] ?? null);
        $this->errors = $errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
