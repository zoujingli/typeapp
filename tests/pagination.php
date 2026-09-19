<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$compiler = new Type\Build\ModelCompiler();
$file = tempnam(sys_get_temp_dir(), 'type_pagination_');
expect($file !== false, '无法准备分页模型');
file_put_contents($file, $compiler->compile([$root . '/examples/model/Models.php'])['code']);
try {
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($file, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/pagination-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', '-r', $launcher];
    }
    foreach (isset($argv[2]) ? [$argv[2]] : ['mysql', 'pgsql', 'sqlite'] as $driver) {
        [$status, $stdout, $stderr] = execute([...$command, $driver]);
        expect($status === 0 && $stderr === '', '分页和导出失败：' . $stdout . $stderr);
        $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        expect($result['driver'] === $driver && $result['rows'] === 20000 && $result['sum'] === 200010000, '导出结果不完整或重复');
        expect($result['peak_growth'] < 12 * 1024 * 1024 && $result['model_peak_growth'] < 12 * 1024 * 1024 && $result['leased'] === 0, '导出内存或资源未归还有界');
        echo $driver . ' 分页、关系批次、20000 行流式导出通过，流峰值增加 ' . $result['peak_growth'] . ' 字节，模型峰值增加 ' . $result['model_peak_growth'] . " 字节。\n";
    }
} finally {
    unlink($file);
}
