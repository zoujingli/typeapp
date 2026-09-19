<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$compiler = new Type\Build\ModelCompiler();
$file = tempnam(sys_get_temp_dir(), 'type_exact_models_');
expect($file !== false, '无法准备精确字段模型');
file_put_contents($file, $compiler->compile([$root . '/examples/model/Models.php'])['code']);
try {
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($file, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/exact-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    [$status, $stdout, $stderr] = execute([...$command, $argv[2] ?? 'sqlite']);
    expect($status === 0 && $stdout === "精确数值、UTC 微秒、部分字段、可空值与存储兼容检查通过。\n" && $stderr === '', '精确字段验收失败：' . $stdout . $stderr);
    echo $stdout;
} finally {
    unlink($file);
}
