<?php

declare(strict_types=1);

namespace Type\Testing;

/** 断言不成立时的统一异常，原始异常可作为 previous 保留供诊断。 */
final class AssertionFailed extends \RuntimeException
{
}
