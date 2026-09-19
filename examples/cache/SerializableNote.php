<?php

declare(strict_types=1);

namespace TypeApp\CacheExample;

final class SerializableNote
{
    private string $text;
    public static int $awakened = 0;
    public function __construct(string $text)
    {
        $this->text = $text;
    }
    public function text(): string
    {
        return $this->text;
    }
    public function __serialize(): array
    {
        return ['text' => $this->text];
    }
    public function __unserialize(array $data): void
    {
        self::$awakened++;
        $this->text = $data['text'];
    }
}
