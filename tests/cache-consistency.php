<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';
$root = dirname(__DIR__);
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知缓存一致性驱动');
$databases = [];
$previous = [];
$admin = $driver === 'sqlite' ? null : TypeApp\ModelExample\Drivers::create($driver)->connect();
try {
    foreach (['primary', 'replica'] as $role) {
        $name = $driver === 'sqlite' ? tempnam(sys_get_temp_dir(), 'type_consistency_' . $role . '_') : 'type_consistency_' . $role . '_' . bin2hex(random_bytes(6));
        expect($name !== false, '无法准备缓存一致性数据库');
        if ($admin !== null) {
            $admin->exec('CREATE DATABASE ' . $name);
        }
        $databases[] = $name;
        $key = 'TYPE_' . strtoupper($role) . ($driver === 'sqlite' ? '_FILE' : '_DATABASE');
        $previous[$key] = getenv($key);
        putenv($key . '=' . $name);
    }
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($root . '/examples/consistency/Inventory.php', true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/cache-consistency-command.php', true) . '; main($argc,$argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    [$status, $stdout, $stderr] = execute([...$command, $driver]);
    expect($status === 0 && $stdout === "缓存回填交错、提交失效、回滚保留、TTL 与强一致回源通过。\n" && $stderr === '', '缓存一致性验收失败：' . $stdout . $stderr);
    echo $stdout;
} finally {
    foreach ($previous as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
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
}
