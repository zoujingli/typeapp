<?php

declare(strict_types=1);

namespace Type\Cache;

final class InvalidCacheArgument extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
