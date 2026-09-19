<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$native = ($argv[2] ?? '') === '--native';
$command = $native ? nativeCommand($argv[1]) : [PHP_BINARY, $argv[1]];
$directory = sys_get_temp_dir() . '/type_scheduler_command_' . bin2hex(random_bytes(8));
expect(mkdir($directory, 0700), '无法建立调度命令状态目录');
putenv('TYPE_SCHEDULER_STATE=' . $directory . '/state.json');
putenv('TYPE_SCHEDULER_NOW=2026-09-09T12:00:00Z');
putenv('TYPE_SCHEDULER_SCENARIO=normal');
$previousExecution = getenv('TYPE_SCHEDULER_EXECUTION_MS');
putenv('TYPE_SCHEDULER_EXECUTION_MS=500');
try {
    expect(str_contains(successful([...$command, 'help']), '调度命令'), '独立调度帮助命令失败');
    expect(!is_file($directory . '/state.json') && !is_file($directory . '/state.json.lock'), '帮助命令提前打开了调度状态');
    $first = json_decode(successful([...$command, 'once']), true, 512, JSON_THROW_ON_ERROR);
    expect(array_column($first, 'state') === ['succeeded', 'succeeded'], '500ms正执行预算下没有真实完成两个任务');
    expect($first[1]['result']['message'] === '计划任务已完成', '独立命令没有返回任务结果');
    putenv('TYPE_SCHEDULER_REVISION=new-build');
    expect(json_decode(successful([...$command, 'once']), true, 512, JSON_THROW_ON_ERROR) === [], '命令重启或构建版本改变重复执行');
    $history = json_decode(successful([...$command, 'history']), true, 512, JSON_THROW_ON_ERROR);
    expect($history[0]['occurrence_id'] === $first[0]['occurrence_id'] && $history[1]['finished_at'] === strtotime('2026-09-09T12:00:00Z'), '执行身份或完成记录没有持久化');
    [$status, , $stderr] = execute([...$command, 'shell', 'touch', $directory . '/forbidden']);
    expect($status === 70 && str_contains($stderr, 'TYPE_SCHEDULER_CONFIG') && !is_file($directory . '/forbidden'), '配置执行了任意命令');
    expect(successful([...$command, 'work', '2', '1']) === "[]\n[]\n", '有界调度循环重复执行同一时刻');

    putenv('TYPE_SCHEDULER_STATE=' . $directory . '/crash.json');
    putenv('TYPE_SCHEDULER_SCENARIO=interrupted');
    $capture = tmpfile();
    expect($capture !== false, '无法建立中断进程输出缓冲');
    $process = proc_open([...$command, 'once'], [0 => ['file', '/dev/null', 'r'], 1 => $capture, 2 => $capture], $pipes);
    expect(is_resource($process), '无法启动中断调度进程');
    $deadline = microtime(true) + 5.0;
    do {
        $state = proc_get_status($process);
        if ($state['running']) {
            usleep(10000);
        }
    } while ($state['running'] && microtime(true) < $deadline);
    if ($state['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    rewind($capture);
    $crashOutput = stream_get_contents($capture);
    fclose($capture);
    expect(!$state['running'] && $state['signaled'] && $state['termsig'] === 9, '调度进程没有实际 SIGKILL：' . $crashOutput);
    $running = json_decode(successful([...$command, 'history']), true, 512, JSON_THROW_ON_ERROR);
    expect(count($running) === 1 && $running[0]['state'] === 'running' && $running[0]['finished_at'] === null, '任务开始未持久化即运行，或中断被误标为完成');
    putenv('TYPE_SCHEDULER_SCENARIO=normal');
    [$status, $stdout, $stderr] = execute([...$command, 'once']);
    $recovered = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    expect($status === 70 && $stderr === '' && array_column($recovered, 'state') === ['interrupted', 'succeeded'], '重启后没有明确中断与后续执行结果');
    expect($recovered[0]['occurrence_id'] === $running[0]['occurrence_id'], '中断后修改了原执行身份');
    expect(json_decode(successful([...$command, 'once']), true, 512, JSON_THROW_ON_ERROR) === [], '中断任务被隐式自动重试');
    putenv('TYPE_SCHEDULER_STATE=' . $directory . '/cleanup.json');
    putenv('TYPE_SCHEDULER_SCENARIO=cleanup-failure');
    [$status, $stdout, $stderr] = execute([...$command, 'work', '3', '1']);
    $cleanup = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
    expect($status === 70 && $stderr === '' && count($cleanup) === 1 && $cleanup[0]['state'] === 'failed'
        && $cleanup[0]['finished_at'] === null && str_contains($cleanup[0]['cleanup_error']['message'], 'controlled scheduler cleanup failure'), '清理失败仍继续补跑或宣称任务已收尾');
    $persisted = json_decode(file_get_contents($directory . '/cleanup.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(count($persisted['records']) === 1 && $persisted['cursors']['summary.minute'] === $cleanup[0]['scheduled_at'], '清理未完成推进了后续游标');
    echo ($native ? '原生' : 'PHP') . " 调度命令验证通过：离线帮助、任务结果、重启游标、有界循环与真实中断恢复。\n";
} finally {
    putenv($previousExecution === false ? 'TYPE_SCHEDULER_EXECUTION_MS' : 'TYPE_SCHEDULER_EXECUTION_MS=' . $previousExecution);
    foreach (glob($directory . '/*') as $generated) {
        unlink($generated);
    }
    rmdir($directory);
}
