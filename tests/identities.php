<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$file = tempnam(sys_get_temp_dir(), 'type_identity_sqlite_');
expect($file !== false, '无法准备 SQLite 身份验证文件');
putenv('TYPE_SQLITE_FILE=' . $file);
try {
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
            . '; require ' . var_export($root . '/examples/identity-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    [$status, $stdout, $stderr] = execute([...$command, $argv[2] ?? 'sqlite']);
    expect($status === 0 && $stdout === "三库连接身份、会话基线、只读用途、代次轮换与实际 fork 通过。\n" && $stderr === '', '连接身份验收失败：' . $stdout . $stderr);
    echo $stdout;
} finally {
    putenv('TYPE_SQLITE_FILE');
    foreach ([$file, $file . '-wal', $file . '-shm'] as $target) {
        if (is_file($target)) {
            unlink($target);
        }
    }
}
