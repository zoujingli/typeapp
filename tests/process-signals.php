<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Runtime\ProcessSignals;
use Type\Runtime\TaskException;

expect(PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_signal'), '此契约用例需要真实Unix PCNTL；Windows控制事件由原生HTTP进程用例验收');
$child = new Type\Testing\Process([PHP_BINARY, '-r', 'echo "ready"; while (true) { usleep(10000); }']);
try {
    $childPid = $child->pid();
    $states = unixProcessStates(getmypid());
    expect(is_int($childPid) && isset($states[$childPid]) && !str_starts_with($states[$childPid]['state'], 'Z'), '真实活子进程被误报为已清空');
    expect(unixProcessStates($childPid) === [], '子进程观察混入不属于该父进程的身份');
    $child->stop();
    expect(!isset(unixProcessStates(getmypid())[$childPid]), '已退出的子进程仍在观察清单');
    $originalPath = getenv('PATH');
    try {
        putenv('PATH=' . __DIR__ . '/does-not-exist');
        $observationRejected = false;
        try {
            @unixProcessStates(getmypid());
        } catch (RuntimeException) {
            $observationRejected = true;
        }
        expect($observationRejected, '子进程查询失败被误报为空清单');
    } finally {
        putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
    }
    echo "真实子进程归属、退出及观察失败拒绝通过。\n";
} finally {
    $child->stop();
}
$zombiePid = pcntl_fork();
expect($zombiePid >= 0, '无法创建退出状态尚未回收的真实子进程');
if ($zombiePid === 0) {
    exit(0);
}
try {
    $deadline = microtime(true) + 2;
    do {
        $states = unixProcessStates(getmypid());
        expect(isset($states[$zombiePid]), '未回收的子进程记录被隐藏');
        if (str_starts_with($states[$zombiePid]['state'], 'Z')) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect(str_starts_with($states[$zombiePid]['state'], 'Z'), '已退出但尚未回收的子进程被当作仍在执行');
} finally {
    pcntl_waitpid($zombiePid, $zombieStatus);
}
expect(!isset(unixProcessStates()[$zombiePid]), '回收后仍保留子进程记录');
echo "真实僵尸子进程与回收完成状态区分通过。\n";
$originalTerm = pcntl_signal_get_handler(SIGTERM);
$originalInt = pcntl_signal_get_handler(SIGINT);
$originalAsync = pcntl_async_signals();
$previousCalls = 0;
$calls = 0;
$previousHandler = static function (int $signal, mixed $information) use (&$previousCalls): void {
    $previousCalls++;
};
pcntl_signal(SIGTERM, $previousHandler);
pcntl_async_signals(false);
$signals = new ProcessSignals();
try {
    $signals->attach(static function () use (&$calls): void {
        $calls++;
    });
    expect(pcntl_async_signals(), '没有启用可及时处理的Unix信号');
    $rejected = false;
    try {
        (new ProcessSignals())->attach(static function (): void {
        });
    } catch (TaskException $error) {
        $rejected = $error->errorCode() === 'signal_owner_busy';
    }
    expect($rejected, '第二个所有者覆盖了既有停止处理器');
    expect(posix_kill(getmypid(), SIGTERM) && posix_kill(getmypid(), SIGINT), '无法给本测试进程发送信号');
    $signals->dispatch();
    expect($calls === 1 && $previousCalls === 0, '停止通知未严格执行一次或泄漏到旧处理器');
    $signals->close();
    $signals->close();
    expect(!pcntl_async_signals() && pcntl_signal_get_handler(SIGTERM) === $previousHandler
        && pcntl_signal_get_handler(SIGINT) === $originalInt, '没有恢复原有处理器和异步模式');
    expect(posix_kill(getmypid(), SIGTERM), '无法复验原处理器');
    pcntl_signal_dispatch();
    expect($previousCalls === 1 && $calls === 1, '关闭后仍由已经释放的所有者接收停止');
    $signals->attach(static function () use (&$calls): void {
        $calls++;
    });
    expect(posix_kill(getmypid(), SIGTERM), '无法验证重新注册');
    expect($calls === 2, '明确关闭后无法重新取得停止所有权');
    echo "Unix停止所有权、真实信号、一次通知、幂等关闭与原状态恢复通过。\n";
} finally {
    $signals->close();
    pcntl_signal(SIGTERM, $originalTerm);
    pcntl_signal(SIGINT, $originalInt);
    pcntl_async_signals($originalAsync);
}
