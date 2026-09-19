<?php

declare(strict_types=1);

namespace Type\Log;

use Psr\Log\AbstractLogger;
use Stringable;

final class Logger extends AbstractLogger
{
    private LogManager $manager;
    private LogContext $context;
    private string $name;

    public function __construct(LogManager $manager, LogContext $context, string $name)
    {
        $manager->assertChannel($name);
        $this->manager = $manager;
        $this->context = $context;
        $this->name = $name;
    }

    public function channel(string $name): Logger
    {
        return new Logger($this->manager, $this->context, $name);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->manager->write($this->name, $level, $message, $context, $this->context->values());
    }
}
