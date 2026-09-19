<?php

declare(strict_types=1);

namespace Type\Orm\Outbox;

final class Record
{
    private array $values;
    public function __construct(array $values)
    {
        $this->values = $values;
    }
    public function id(): string
    {
        return $this->values['id'];
    }
    public function topic(): string
    {
        return $this->values['topic'];
    }
    public function version(): int
    {
        return (int) $this->values['version'];
    }
    public function payload(): array
    {
        return json_decode($this->values['payload'], true, 32, JSON_THROW_ON_ERROR);
    }
    public function context(): array
    {
        return json_decode($this->values['context'], true, 32, JSON_THROW_ON_ERROR);
    }
    public function token(): string
    {
        return $this->values['token'];
    }
    public function attempts(): int
    {
        return (int) $this->values['attempts'];
    }
}
