<?php

declare(strict_types=1);

/** 仅供编译消费者设置最终线程析构检查点，不保留 PHP 对象。 */
function type_test_thread_exit_gate(string $directory): bool
{
}

/** 仅供真实业务线程阻塞故障，最多五秒；不进入 Swoole hook。 */
function type_test_thread_block(int $milliseconds): void
{
}
