<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$command = nativeCommand($argv[1] ?? '');
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "PSR 消息原生行为验证通过。\n" && $stderr === '', 'PSR 消息行为失败：' . $stdout . $stderr);
echo "PSR-7/17 原生产物验证通过。\n";
