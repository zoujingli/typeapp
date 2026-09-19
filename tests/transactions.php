<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$compiler = new Type\Build\ModelCompiler();
$file = tempnam(sys_get_temp_dir(), 'type_transactions_');
expect($file !== false, '无法准备事务模型');
file_put_contents($file, $compiler->compile([$root . '/examples/model/Models.php'])['code']);
try {
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($file, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/model/TransactionExercise.php', true)
            . '; require ' . var_export($root . '/examples/transaction-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    [$status, $stdout, $stderr] = execute([...$command, $argv[2] ?? 'sqlite']);
    expect($status === 0 && $stdout === "嵌套事务、回滚模型失效、执行者隔离与活动租约收尾通过。\n" && $stderr === '', '事务验收失败：' . $stdout . $stderr);
    echo $stdout;
} finally {
    unlink($file);
}
