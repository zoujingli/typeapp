<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$settings = json_decode(file_get_contents($root . '/docs/build-config/type-queue.json'), true, 512, JSON_THROW_ON_ERROR);
$file = tempnam(sys_get_temp_dir(), 'type_jobs_');
expect($file !== false, '无法准备任务注册文件');
file_put_contents($file, (new Type\Build\JobCompiler())->generate($settings['queue']));
try {
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($file, true)
            . '; require ' . var_export($root . '/examples/queue/Increment.php', true)
            . '; require ' . var_export($root . '/examples/queue-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher, '--'];
    }
    expect(str_contains(successful([...$command, '--help']), '队列验证'), '队列帮助入口失败');
    [$status, $stdout, $stderr] = execute($command);
    expect($status === 0 && $stdout === "Streams 任务注册、独立作用域、幂等消费、原子确认与停止通过。\n" && $stderr === '', '队列验收失败：' . $stdout . $stderr);
    $invalid = $settings['queue'];
    $invalid['jobs'][] = $invalid['jobs'][0];
    $rejected = false;
    try {
        (new Type\Build\JobCompiler())->generate($invalid);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '重复任务注册没有在生成时拒绝');
    echo $stdout;
} finally {
    unlink($file);
}
