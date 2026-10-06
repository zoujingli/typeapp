<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/outbox-application.php';
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
$otherCreated = false;
$otherDatabase = $driver === 'sqlite' ? $databaseName . '-other' : $databaseName . '_other';
$applicationName = 'type_outbox_' . bin2hex(random_bytes(8));
$roles = [];
$application = null;
$record = $root . '/build/outbox-roles-' . $driver . '-' . bin2hex(random_bytes(6));
expect(mkdir($record, 0700), '无法记录独立Outbox角色');
$report = ['status' => 'running', 'driver' => $driver, 'mode' => ($argv[1] ?? '--php') === '--php' ? 'php' : 'native',
    'source_sha256' => hash_file('sha256', $root . '/examples/outbox-command.php'),
    'store_sha256' => hash_file('sha256', $root . '/plugin/type-orm/src/Outbox/Store.php'),
    'relay_sha256' => hash_file('sha256', $root . '/plugin/type-orm/src/Outbox/Relay.php')];
try {
    if ($driver !== 'sqlite') {
        $admin = TypeApp\ModelExample\Drivers::create($driver)->connect();
        $admin->exec('CREATE DATABASE ' . $databaseName);
        $created = true;
        $admin->exec('CREATE DATABASE ' . $otherDatabase);
        $otherCreated = true;
    }
    putenv($key . '=' . $databaseName);
    putenv('TYPE_OUTBOX_APPLICATION=' . $applicationName);
    putenv('TYPE_OUTBOX_OTHER_DATABASE=' . $otherDatabase);
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $application = outboxApplication($root);
        $command = $application['command'];
    }
    $run = static function (string $role) use ($command, $driver, $root, &$roles): string {
        $environment = in_array($role, ['help', 'setup'], true)
            ? array_replace(getenv(), ['TYPE_REDIS_HOST' => '127.0.0.1', 'TYPE_REDIS_PORT' => '1']) : getenv();
        $child = new Process([...$command, $driver, $role], $root, $environment);
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
    // 使用不可用的明确端点验证离线角色，不让其他测试的 Redis 连接影响计数。
    expect(str_contains($run('help'), 'Outbox 独立角色'), 'Outbox离线帮助入口失败');
    expect($run('setup') === "业务与消息意图同事务提交通过。\n", 'Outbox 原子提交失败');
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
    expect($run('lifecycle') === "Relay异常、超时、停止与清理所有权通过。\n", 'Relay故障生命周期失败');
    expect($run('prepare-concurrent') === "并发消息意图已提交。\n", '并发消息准备失败');
    $relay = new Process([...$command, $driver, 'concurrent-relay'], $root);
    $control = new Redis();
    try {
        expect($control->connect(getenv('TYPE_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_REDIS_PORT') ?: 6379)), '无法观测并发投递');
        $signal = (string) getenv('TYPE_OUTBOX_APPLICATION');
        $until = microtime(true) + 5.0;
        while ($control->get($signal . ':accepted') === false) {
            expect($relay->running() && microtime(true) < $until, 'Relay没有在预算内真实发布：' . $relay->stdout() . $relay->stderr());
            usleep(1000);
        }
        expect($run('consume-early') === "实际消费先于Outbox接受登记完成。\n", '消费先于登记的并发路径失败');
        $result = $relay->wait(10);
        expect($result->successful() && $result->stdout === "消息发布及 token 标记通过。\n" && $result->stderr === '', '并发Relay没有完成接受凭据登记');
        expect($run('verify-concurrent') === "接受与消费凭据按真实先后独立保留。\n", '两类凭据没有独立保留');
        $roles['concurrent-relay'] = ['pid' => $relay->pid(), 'exit' => $result->exitCode, 'output_sha256' => hash('sha256', $result->stdout)];
    } finally {
        $relay->stop();
        if ($control->isConnected()) {
            $control->del((string) getenv('TYPE_OUTBOX_APPLICATION') . ':accepted', (string) getenv('TYPE_OUTBOX_APPLICATION') . ':consumed');
            $control->close();
        }
    }
    echo "Outbox 真实三方事务、SIGKILL 重发、幂等消费与凭据保留通过。\n";
    $report['status'] = 'passed';
} catch (Throwable $error) {
    $report['status'] = 'failed';
    $report['failure'] = $error->getMessage();
    throw $error;
} finally {
    $report['roles'] = $roles;
    if ($application !== null) {
        foreach (glob($application['directory'] . '/build/development/*/manifest.json') ?: [] as $manifest) {
            $identity = basename(dirname($manifest));
            copy($manifest, $record . '/generation-' . $identity . '.json');
            $report['generations'][$identity] = hash_file('sha256', $manifest);
        }
    }
    file_put_contents($record . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if ($otherCreated) {
        $admin->exec('DROP DATABASE ' . $otherDatabase . ($driver === 'pgsql' ? ' WITH (FORCE)' : ''));
    }
    if ($created) {
        $admin->exec('DROP DATABASE ' . $databaseName . ($driver === 'pgsql' ? ' WITH (FORCE)' : ''));
    }
    if ($driver === 'sqlite') {
        foreach ([$databaseName, $databaseName . '-wal', $databaseName . '-shm', $otherDatabase, $otherDatabase . '-wal', $otherDatabase . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    putenv($previous === false ? $key : $key . '=' . $previous);
    cleanupOutboxQueue($applicationName);
    putenv('TYPE_OUTBOX_APPLICATION');
    putenv('TYPE_OUTBOX_OTHER_DATABASE');
    if ($application !== null) {
        removeTestDirectory($application['directory']);
    }
}
file_put_contents($record . '/verification.json', json_encode(array_replace($report, [
    'offline_setup_with_unavailable_redis' => true, 'business_effects' => 2, 'named_source' => 'outbox',
    'default_source_borrowed' => false, 'wrong_source_rejected' => true,
    'consumption_before_acceptance' => true, 'resources_closed' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '独立角色证据：' . substr($record, strlen($root) + 1) . "/verification.json\n";
