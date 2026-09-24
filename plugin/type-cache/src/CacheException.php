<?php

declare(strict_types=1);

namespace Type\Cache;

use RuntimeException;

/** 缓存格式、完整性或存储确认失败；具体回源策略由调用者决定。 */
class CacheException extends RuntimeException
{
}
