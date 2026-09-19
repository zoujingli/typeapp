<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

$root = dirname(__DIR__);
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知版本验证驱动');
$compiler = new Type\Build\ModelCompiler();
$generated = tempnam(sys_get_temp_dir(), 'type_version_models_');
$sqlite = tempnam(sys_get_temp_dir(), 'type_version_db_');
expect($generated !== false && $sqlite !== false, '无法准备版本验证');
file_put_contents($generated, $compiler->compile([$root . '/examples/model/Models.php'])['code']);
$environmentKey = $driver === 'sqlite' ? 'TYPE_SQLITE_FILE' : 'TYPE_' . strtoupper($driver) . '_DATABASE';
$previous = getenv($environmentKey);
$admin = null;
$created = false;
$databaseName = 'type_version_test_' . bin2hex(random_bytes(6));
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
            . '; require ' . var_export($root . '/examples/optimistic-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    [$status, $stdout, $stderr] = execute([...$command, $driver]);
    expect($status === 0 && $stdout === "原子版本、冲突、未找到、无变更与回滚状态通过。\n" && $stderr === '', '版本检查失败：' . $stdout . $stderr);
    $barrier = tempnam(sys_get_temp_dir(), 'type_version_barrier_');
    expect($barrier !== false, '无法准备并发屏障');
    putenv('TYPE_OPTIMISTIC_BARRIER=' . $barrier);
    $children = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $output = tmpfile();
            expect($output !== false, '无法创建并发输出');
            $process = proc_open([...$command, $driver, 'race'], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
            expect(is_resource($process), '无法启动并发版本写入');
            $children[] = [$process, $output];
        }
        $results = [];
        foreach ($children as [$process, $output]) {
            $status = proc_close($process);
            rewind($output);
            $value = stream_get_contents($output);
            expect($status === 0, '并发写入发生意外错误：' . $value);
            $results[] = trim($value);
        }
        sort($results);
        expect($results === ['conflict', 'updated'], '并发旧版本没有且只有一个写入成功');
        $scope = new Type\Runtime\ExecutionScope();
        $database = new Type\Orm\Database(TypeApp\ModelExample\Drivers::create($driver), 1, 0);
        try {
            $row = $database->connect($scope)->table('type_model_counters')->where('id', '=', 3)->first();
            expect((int) $row['version'] === 2 && (int) $row['value'] === 1, '并发写入发生丢失更新或重复推进版本');
        } finally {
            $scope->close();
            $database->close();
        }
    } finally {
        foreach ($children as [$process, $output]) {
            if (is_resource($process)) {
                proc_terminate($process, 15);
                proc_close($process);
            }
            fclose($output);
        }
        putenv('TYPE_OPTIMISTIC_BARRIER');
        unlink($barrier);
    }
    echo "版本化模型、两个独立进程并发写入与持久结果通过。\n";
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
