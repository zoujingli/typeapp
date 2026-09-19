<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$application = 'type_cache_check_' . bin2hex(random_bytes(8));
putenv('TYPE_CACHE_APPLICATION=' . $application);
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($root . '/examples/cache/Profile.php', true)
        . '; require ' . var_export($root . '/examples/cache-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-r', $launcher];
}
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "类型化缓存、缺失值、TTL、回源、代次隔离与有界回收通过。\n" && $stderr === '', '缓存验收失败：' . $stdout . $stderr);
$children = [];
try {
    for ($index = 0; $index < 8; $index++) {
        $output = tmpfile();
        expect($output !== false, '无法创建并发缓存输出');
        $process = proc_open([...$command, 'clear'], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
        expect(is_resource($process), '无法启动并发 clear');
        $children[] = [$process, $output];
    }
    $generations = [];
    foreach ($children as [$process, $output]) {
        $status = proc_close($process);
        rewind($output);
        $value = trim(stream_get_contents($output));
        expect($status === 0 && preg_match('/^[a-f0-9]{32}$/D', $value) === 1, '并发代次切换失败：' . $value);
        $generations[] = $value;
    }
    expect(count(array_unique($generations)) === 8, '并发 clear 重用了历史代次');
    successful([...$command, 'collect']);
    echo $stdout . "八个独立进程的 clear 代次不重复。\n";
} finally {
    foreach ($children as [$process, $output]) {
        if (is_resource($process)) {
            proc_terminate($process, 15);
            proc_close($process);
        }
        fclose($output);
    }
    putenv('TYPE_CACHE_APPLICATION');
}
