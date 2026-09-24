<?php

declare(strict_types=1);

namespace Type\Cache;

/** 符合 PSR-16 的参数异常，报告非法键、类型策略或 TTL。 */
final class InvalidCacheArgument extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
