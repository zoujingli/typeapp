<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$generated = (new Type\Build\ModelCompiler())->compile([$root . '/examples/model/Models.php'])['code'];
$file = tempnam($root . '/build', 'type_models_');
expect($file !== false, '无法创建模型生成文件');
file_put_contents($file, $generated);
try {
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($file, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/model-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    $driver = $argv[2] ?? 'sqlite';
    [$status, $stdout, $stderr] = execute([...$command, $driver]);
    expect($status === 0 && $stdout === "模型 CRUD、生成水合、部分字段、安全输出与状态检查通过。\n" && $stderr === '', '模型验收失败：' . $stdout . $stderr);
    $rejected = false;
    try {
        (new Type\Build\ModelCompiler())->assertConfiguration(['models' => []]);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '旧模型配置未报告迁移错误');
    echo $stdout;
} finally {
    unlink($file);
}
