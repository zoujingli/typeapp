<?php

declare(strict_types=1);

namespace Type\Orm;

use RuntimeException;

/** 数据库配置、SQL 或资源状态不满足约定时的基础异常。 */
class DatabaseException extends RuntimeException
{
}
