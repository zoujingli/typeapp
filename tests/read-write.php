<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';
require dirname(__DIR__) . '/examples/read-write-command.php';

$root = dirname(__DIR__);
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知主从驱动');
$databases = [];
$admin = $driver === 'sqlite' ? null : TypeApp\ModelExample\Drivers::create($driver)->connect();
try {
    foreach (['primary', 'replica'] as $role) {
        $name = $driver === 'sqlite' ? tempnam(sys_get_temp_dir(), 'type_rw_' . $role . '_') : 'type_rw_' . $role . '_' . bin2hex(random_bytes(6));
        expect($name !== false, '无法准备主从测试数据库');
        if ($admin !== null) {
            $admin->exec('CREATE DATABASE ' . $name);
        }
        $databases[$role] = $name;
        putenv('TYPE_' . strtoupper($role) . '_DATABASE=' . $name);
        $pdo = readWriteDriver($driver, $name, 'writer')->connect();
        $pdo->exec('CREATE TABLE type_rw_probe (id INTEGER PRIMARY KEY, value VARCHAR(255) NOT NULL)');
        if ($role === 'primary') {
            $pdo->exec("INSERT INTO type_rw_probe VALUES (1, '主库已有')");
        }
        $pdo = null;
    }
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require '
            . var_export($root . '/examples/read-write-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    [$status, $stdout, $stderr] = execute([...$command, $driver]);
    expect($status === 0 && $stdout === "副本延迟、显式主读、事务固定与每次执行粘滞隔离通过。\n" && $stderr === '', '主从路由失败：' . $stdout . $stderr);
    // 受控复制延迟：只有在应用完成延迟检查后才显式复制本次写入。
    $source = readWriteDriver($driver, $databases['primary'], 'writer')->connect();
    $replica = readWriteDriver($driver, $databases['replica'], 'writer')->connect();
    $statement = $replica->prepare('INSERT INTO type_rw_probe (id, value) VALUES (?, ?)');
    foreach ($source->query('SELECT id, value FROM type_rw_probe')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $statement->execute([$row['id'], $row['value']]);
    }
    $statement = null;
    $source = null;
    $replica = null;
    $manager = new Type\Orm\DatabaseManager(['primary' => readWriteDriver($driver, $databases['primary'], 'writer'), 'replica' => readWriteDriver($driver, $databases['replica'], 'reader')]);
    $scope = new Type\Runtime\ExecutionScope();
    try {
        $session = new Type\Orm\ReadWriteSession($manager, $scope);
        expect($session->read()->table('type_rw_probe')->where('id', '=', 2)->first()['value'] === '本次写入', '受控复制追平后副本仍看不到数据');
    } finally {
        $scope->close();
        $manager->close();
    }
    echo $stdout;
} finally {
    foreach ($databases as $name) {
        if ($admin !== null) {
            $admin->exec('DROP DATABASE ' . $name . ($driver === 'pgsql' ? ' WITH (FORCE)' : ''));
        } else {
            foreach ([$name, $name . '-wal', $name . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    putenv('TYPE_PRIMARY_DATABASE');
    putenv('TYPE_REPLICA_DATABASE');
}
