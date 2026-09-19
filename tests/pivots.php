<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

$root = dirname(__DIR__);
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知多对多验证驱动');
$compiler = new Type\Build\ModelCompiler();
$generated = tempnam(sys_get_temp_dir(), 'type_pivot_models_');
$sqlite = tempnam(sys_get_temp_dir(), 'type_pivot_db_');
expect($generated !== false && $sqlite !== false, '无法准备关系验证');
file_put_contents($generated, $compiler->compile([$root . '/examples/model/Models.php'])['code']);
$environmentKey = $driver === 'sqlite' ? 'TYPE_SQLITE_FILE' : 'TYPE_' . strtoupper($driver) . '_DATABASE';
$previous = getenv($environmentKey);
$admin = null;
$created = false;
$databaseName = 'type_pivot_test_' . bin2hex(random_bytes(6));
try {
    if ($driver === 'sqlite') {
        putenv($environmentKey . '=' . $sqlite);
    } else {
        $admin = TypeApp\ModelExample\Drivers::create($driver)->connect();
        $admin->exec('CREATE DATABASE ' . $databaseName);
        $created = true;
        putenv($environmentKey . '=' . $databaseName);
    }
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($generated, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/model/ArticleTags.php', true)
            . '; require ' . var_export($root . '/examples/pivot-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    [$status, $stdout, $stderr] = execute([...$command, $driver]);
    expect($status === 0 && $stdout === "多对多挂载、解除、同步、事务、中间表字段与批量读取通过。\n" && $stderr === '', '多对多验收失败：' . $stdout . $stderr);
    echo $stdout;
    $barrier = tempnam(sys_get_temp_dir(), 'type_pivot_barrier_');
    expect($barrier !== false, '无法准备并发同步文件');
    putenv('TYPE_PIVOT_BARRIER=' . $barrier);
    $children = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $output = tmpfile();
            expect($output !== false, '无法准备并发输出');
            $process = proc_open([...$command, $driver, 'race'], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
            expect(is_resource($process), '无法启动并发关系写入');
            $children[] = [$process, $output];
        }
        $results = [];
        foreach ($children as [$process, $output]) {
            $status = proc_close($process);
            rewind($output);
            $value = stream_get_contents($output);
            expect($status === 0, '并发挂载失败：' . $value);
            $results[] = trim($value);
        }
        sort($results);
        expect($results === ['attached', 'existing'], '并发挂载没有保持单一关系');
        echo "两个独立进程并发挂载同一关系通过。\n";
    } finally {
        foreach ($children as [$process, $output]) {
            if (is_resource($process)) {
                proc_terminate($process, 15);
                proc_close($process);
            }
            fclose($output);
        }
        putenv('TYPE_PIVOT_BARRIER');
        unlink($barrier);
    }
} finally {
    if ($created) {
        $admin->exec('DROP DATABASE ' . $databaseName . ($driver === 'pgsql' ? ' WITH (FORCE)' : ''));
    }
    putenv($previous === false ? $environmentKey : $environmentKey . '=' . $previous);
    unlink($generated);
    foreach ([$sqlite, $sqlite . '-wal', $sqlite . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
