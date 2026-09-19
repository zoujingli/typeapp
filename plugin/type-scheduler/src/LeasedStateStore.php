<?php

declare(strict_types=1);

namespace Type\Scheduler;

interface LeasedStateStore extends StateStore
{
    public function lease(): ExecutionLease;
}
