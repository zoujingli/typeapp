<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

$root = dirname(__DIR__);
$driver = $argv[2] ?? 'mysql';
expect(in_array($driver, ['mysql', 'pgsql'], true), '密码验证请选择网络数据库');
$admin = TypeApp\ModelExample\Drivers::create($driver)->connect();
$user = 'type_rotate_' . bin2hex(random_bytes(6));
$first = bin2hex(random_bytes(24));
$second = bin2hex(random_bytes(24));
$directory = sys_get_temp_dir() . '/type_rotation_' . bin2hex(random_bytes(8));
expect(mkdir($directory, 0700), '无法创建密码轮换同步目录');
$created = false;
$process = null;
$log = tmpfile();
expect($log !== false, '无法创建轮换日志');
try {
    if ($driver === 'mysql') {
        $admin->exec('CREATE USER ' . $admin->quote($user) . "@'%' IDENTIFIED BY " . $admin->quote($first));
        $created = true;
        $database = getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test';
        $admin->exec('GRANT SELECT ON `' . str_replace('`', '``', $database) . '`.* TO ' . $admin->quote($user) . "@'%'");
    } else {
        $admin->exec('CREATE ROLE "' . $user . '" LOGIN PASSWORD ' . $admin->quote($first));
        $created = true;
    }
    $environment = getenv();
    $environment['TYPE_' . strtoupper($driver) . '_USER'] = $user;
    $environment['TYPE_' . strtoupper($driver) . '_PASSWORD'] = $first;
    $environment['TYPE_IDENTITY_NEXT_PASSWORD'] = $second;
    $environment['TYPE_IDENTITY_READY'] = $directory . '/ready';
    $environment['TYPE_IDENTITY_CONTINUE'] = $directory . '/continue';
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($root . '/examples/identity-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    $process = proc_open([...$command, $driver, 'credentials'], [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
    expect(is_resource($process), '无法启动密码轮换验证');
    $deadline = microtime(true) + 10;
    while (!is_file($directory . '/ready')) {
        expect(proc_get_status($process)['running'] && microtime(true) < $deadline, '旧凭据连接未就绪');
        usleep(1000);
    }
    $admin->exec($driver === 'mysql' ? 'ALTER USER ' . $admin->quote($user) . "@'%' IDENTIFIED BY " . $admin->quote($second)
        : 'ALTER ROLE "' . $user . '" PASSWORD ' . $admin->quote($second));
    file_put_contents($directory . '/continue', 'continue');
    $status = proc_close($process);
    $process = null;
    rewind($log);
    $output = stream_get_contents($log);
    expect($status === 0 && $output === "真实数据库密码轮换与旧租约排空通过。\n", '密码轮换验收失败：' . $output);
    if ($driver === 'pgsql') {
        $schema = $user;
        $admin->exec('CREATE SCHEMA "' . $schema . '" AUTHORIZATION "' . $user . '"');
        try {
            $baselineEnvironment = getenv();
            $baselineEnvironment['TYPE_IDENTITY_SCHEMA'] = $schema;
            $baselineEnvironment['TYPE_IDENTITY_ROLE'] = $user;
            $baseline = new Type\Testing\Process([...$command, $driver, 'session-baseline'], $root, $baselineEnvironment);
            try {
                $baselineResult = $baseline->wait(30);
                expect($baselineResult->successful() && $baselineResult->stderr === ''
                    && $baselineResult->stdout === "PostgreSQL 物理会话复用与角色、schema、读写用途恢复通过。\n", 'role/schema 原生入口验收失败：' . $baselineResult->stdout . $baselineResult->stderr);
            } finally {
                $baseline->stop();
            }
        } finally {
            $admin->exec('DROP SCHEMA "' . $schema . '"');
        }
    }
    echo $output;
} finally {
    if (is_resource($process)) {
        proc_terminate($process, 15);
        proc_close($process);
    }
    if ($created) {
        $admin->exec($driver === 'mysql' ? 'DROP USER ' . $admin->quote($user) . "@'%'" : 'DROP ROLE "' . $user . '"');
    }
    fclose($log);
    foreach ([$directory . '/ready', $directory . '/continue'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}
