<?php

declare(strict_types=1);

namespace Type\Cache;

interface Codec
{
    public function format(): string;
    public function encode(mixed $value): string;
    public function decode(string $payload): mixed;
}
