<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

use Type\Testing\Process;

$root = dirname(__DIR__);
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知 Outbox 驱动');
$databaseName = $driver === 'sqlite' ? tempnam(sys_get_temp_dir(), 'type_outbox_db_') : 'type_outbox_test_' . bin2hex(random_bytes(6));
expect($databaseName !== false, '无法准备消息意图数据库');
$key = $driver === 'sqlite' ? 'TYPE_SQLITE_FILE' : 'TYPE_' . strtoupper($driver) . '_DATABASE';
$previous = getenv($key);
$admin = null;
$created = false;
$roles = [];
try {
    if ($driver !== 'sqlite') {
        $admin = TypeApp\ModelExample\Drivers::create($driver)->connect();
        $admin->exec('CREATE DATABASE ' . $databaseName);
        $created = true;
    }
    putenv($key . '=' . $databaseName);
    putenv('TYPE_OUTBOX_APPLICATION=type_outbox_' . bin2hex(random_bytes(8)));
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/outbox/Adapters.php', true) . '; require ' . var_export($root . '/examples/outbox-command.php', true) . '; main($argc,$argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    $run = static function (string $role) use ($command, $driver, $root, &$roles): string {
        $child = new Process([...$command, $driver, $role], $root);
        $pid = $child->pid();
        try {
            $result = $child->wait(30);
            expect(is_int($pid) && $pid > 0 && $result->successful() && $result->stderr === '', '独立Outbox角色失败：' . $role . ' ' . $result->stdout . $result->stderr);
            $roles[$role] = ['pid' => $pid, 'exit' => $result->exitCode, 'output_sha256' => hash('sha256', $result->stdout)];
            return $result->stdout;
        } finally {
            $child->stop();
        }
    };
    $observer = new Redis();
    try {
        expect($observer->connect(getenv('TYPE_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_REDIS_PORT') ?: 6379)), '无法观测专用Redis连接计数');
        $connections = $observer->info('stats')['total_connections_received'];
        expect(str_contains($run('help'), 'Outbox 独立角色'), 'Outbox离线帮助入口失败');
        expect($run('setup') === "业务与消息意图同事务提交通过。\n", 'Outbox 原子提交失败');
        expect($observer->info('stats')['total_connections_received'] === $connections, '帮助或纯数据库迁移意外初始化Redis队列连接');
    } finally {
        $observer->close();
    }
    $output = tmpfile();
    expect($output !== false, '无法准备 relay 输出');
    $process = proc_open([...$command, $driver, 'crash'], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
    expect(is_resource($process), '无法启动 relay');
    $deadline = microtime(true) + 10;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    if ($state['running']) {
        proc_terminate($process, 9);
    } proc_close($process);
    rewind($output);
    $log = stream_get_contents($output);
    fclose($output);
    expect(!$state['running'] && $state['signaled'] && $state['termsig'] === 9 && $log === '', 'relay 没有在发布后真实崩溃：' . $log);
    $roles['crash'] = ['pid' => $state['pid'], 'signal' => $state['termsig'], 'output_sha256' => hash('sha256', $log)];
    usleep(150000);
    expect($run('relay') === "消息发布及 token 标记通过。\n", 'relay 恢复失败');
    expect($run('consume') === "重复投递幂等消费与保留凭据通过。\n", '消费凭据或幂等失败');
    expect($run('replay') === "消息发布及 token 标记通过。\n", '显式重放失败');
    expect($run('consume-replay') === "重复投递幂等消费与保留凭据通过。\n", '重放后的幂等处理失败');
    usleep(1100000);
    expect($run('collect') === "已消费消息保留期回收通过。\n", '保留期回收失败');
    expect($run('tokens') === "Outbox 旧 token 拒绝与未消费意图保留通过。\n", 'Outbox token 或未确认效果保留失败');
    echo "Outbox 真实三方事务、SIGKILL 重发、幂等消费与凭据保留通过。\n";
} finally {
    if ($created) {
        $admin->exec('DROP DATABASE ' . $databaseName . ($driver === 'pgsql' ? ' WITH (FORCE)' : ''));
    }
    if ($driver === 'sqlite') {
        foreach ([$databaseName, $databaseName . '-wal', $databaseName . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    putenv($previous === false ? $key : $key . '=' . $previous);
    putenv('TYPE_OUTBOX_APPLICATION');
}
$record = $root . '/build/outbox-roles-' . $driver . '-' . bin2hex(random_bytes(6));
expect(mkdir($record, 0700), '无法记录独立Outbox角色');
file_put_contents($record . '/verification.json', json_encode(['status' => 'passed', 'driver' => $driver,
    'mode' => ($argv[1] ?? '--php') === '--php' ? 'php' : 'native', 'roles' => $roles,
    'setup_redis_connections' => 0, 'business_effects' => 1, 'resources_closed' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '独立角色证据：' . substr($record, strlen($root) + 1) . "/verification.json\n";
