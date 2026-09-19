<?php

declare(strict_types=1);

namespace Type\Queue;

interface Job
{
    public function handle(JobContext $context, array $payload): void;
}
