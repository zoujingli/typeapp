<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($root . '/examples/queue-lease-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-r', $launcher];
}
expect(successful($command) === "租约续期、过期重领、旧 token 拒绝与原子副作用通过。\n", '租约状态验证失败');
expect(successful([...$command, 'delayed-renew']) === "租约续期、过期重领、旧 token 拒绝与原子副作用通过。\n", '短暂调度延迟使正向续租验收依赖亚秒级排队时间');
putenv('TYPE_QUEUE_APPLICATION=type_crash_' . bin2hex(random_bytes(8)));
$log = tmpfile();
expect($log !== false, '无法准备崩溃输出');
$process = proc_open([...$command, 'crash'], [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes);
expect(is_resource($process), '无法启动真实崩溃 worker');
$deadline = microtime(true) + 10;
do {
    $state = proc_get_status($process);
    if (!$state['running']) {
        break;
    } usleep(10000);
} while (microtime(true) < $deadline);
if ($state['running']) {
    proc_terminate($process, 9);
}
proc_close($process);
rewind($log);
$output = stream_get_contents($log);
fclose($log);
expect(!$state['running'] && $state['signaled'] && $state['termsig'] === 9 && $output === '', '没有证明 worker 在领取后真实 SIGKILL：' . $output);
try {
    expect(successful([...$command, 'recover']) === "真实 worker 崩溃后恢复原消息通过。\n", '新进程未能恢复原消息');
} finally {
    putenv('TYPE_QUEUE_APPLICATION');
}
echo "队列租约、真实 SIGKILL 恢复与旧执行者 fencing 通过。\n";
