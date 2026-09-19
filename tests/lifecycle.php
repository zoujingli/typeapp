<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$compiler = new Type\Build\ModelCompiler();
$file = tempnam(sys_get_temp_dir(), 'type_lifecycle_models_');
expect($file !== false, '无法准备生命周期模型');
file_put_contents($file, $compiler->compile([$root . '/examples/model/Models.php'])['code']);
try {
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($file, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/model/DocumentObserver.php', true)
            . '; require ' . var_export($root . '/examples/model/LifecycleExercise.php', true)
            . '; require ' . var_export($root . '/examples/lifecycle-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    [$status, $stdout, $stderr] = execute([...$command, $argv[2] ?? 'sqlite']);
    expect($status === 0 && $stdout === "软删除、恢复、强制删除、范围搜索、字段转换与事件事务通过。\n" && $stderr === '', '生命周期验收失败：' . $stdout . $stderr);
    echo $stdout;
} finally {
    unlink($file);
}
