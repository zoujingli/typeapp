<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($root . '/examples/queue/Increment.php', true)
        . '; require ' . var_export($root . '/examples/queue/RetryJobs.php', true)
        . '; require ' . var_export($root . '/examples/queue-retry-command.php', true) . '; main($argc,$argv);';
    $command = [PHP_BINARY, '-r', $launcher];
}
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "延迟提升、有限重试、超时、隔离重放、保留期与转移故障恢复通过。\n" && $stderr === '', '重试与隔离验收失败：' . $stdout . $stderr);
echo $stdout;
