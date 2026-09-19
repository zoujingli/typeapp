<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';

use Type\Queue\Queue;
use Type\Queue\Registry;
use Type\Queue\Worker;
use Type\Queue\JobContext;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use Type\Scheduler\RedisStateStore;
use TypeApp\QueueExample\Increment;

require ($argv[3] ?? '') === '--native' ? dirname(__DIR__) . '/examples/queue/Increment.php' : dirname($argv[2]) . '/app/Increment.php';

$command = ($argv[3] ?? '') === '--native' ? nativeCommand($argv[2]) : [PHP_BINARY, $argv[2]];
$manager = new RedisManager(['default' => new RedisConfiguration(
    getenv('TYPE_REDIS_HOST') ?: '127.0.0.1',
    (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
)]);
$scope = new ExecutionScope();
$commandConnection = $manager->connection($scope);
$scripts = $manager->connection($scope, 'default', Purpose::SCRIPT);
$applications = [];
$children = [];

function coordinationStart(array $command): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    expect($stdout !== false && $stderr !== false, '无法建立调度进程输出缓冲');
    $process = proc_open([...$command, 'once'], [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stderr], $pipes);
    expect(is_resource($process), '无法启动独立调度进程');
    return [$process, $stdout, $stderr];
}

function coordinationWait(array $child): array
{
    [$process, $stdout, $stderr] = $child;
    $deadline = microtime(true) + 6.0;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if ($state['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    rewind($stdout);
    rewind($stderr);
    $out = stream_get_contents($stdout);
    $error = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    expect(!$state['running'], '调度子进程未在期限内退出：' . $out . $error);
    return [$state['exitcode'], $out, $error];
}

function coordinationEnvironment(string $application, string $mode, string $date, string $revision): void
{
    putenv('TYPE_COORDINATION_APP=' . $application);
    putenv('TYPE_COORDINATION_MODE=' . $mode);
    putenv('TYPE_COORDINATION_NOW=' . $date);
    putenv('TYPE_COORDINATION_REVISION=' . $revision);
    putenv('TYPE_COORDINATION_TASK=reports.minute');
}

try {
    $application = 'type_multi_' . bin2hex(random_bytes(8));
    $applications[] = $application;
    coordinationEnvironment($application, 'hold', '2026-09-09T12:00:00Z', 'v1');
    $children[0] = coordinationStart($command);
    $deadline = microtime(true) + 5;
    do {
        $ready = $commandConnection->command('GET', [$application . ':business:ready']);
        if ($ready !== false) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect($ready !== false, '没有观察到第一个调度进程进入任务');
    coordinationEnvironment($application, 'normal', '2026-09-09T12:00:00Z', 'v2');
    [$status, $stdout, $stderr] = execute([...$command, 'once']);
    expect($status === 75 && $stdout === '' && str_contains($stderr, 'TYPE_SCHEDULER_BUSY'), '竞争进程没有被明确拒绝：' . $stdout . $stderr);
    $commandConnection->command('SET', [$application . ':business:continue', 'yes']);
    $holder = $children[0];
    unset($children[0]);
    [$status, $stdout, $stderr] = coordinationWait($holder);
    $completed = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    expect($status === 0 && $stderr === '' && count($completed) === 1 && $completed[0]['state'] === 'succeeded', '获租约进程没有完成实际效果与投递');
    expect($commandConnection->command('GET', [$application . ':business:count']) === '1', '两个实例重复产生业务效果');
    expect(json_decode(successful([...$command, 'once']), true, 512, JSON_THROW_ON_ERROR) === [], '新配置版本重跑了同一计划时刻');
    $queue = new Queue($scripts, $application, 'reports');
    expect($queue->statistics()['messages'] === 1, '多个实例重复投递同一次计划');
    $reservation = $queue->reserve('verification');
    expect($reservation !== null && $reservation->message()->id() === $completed[0]['occurrence_id']
        && $reservation->message()->context()['occurrence_id'] === $completed[0]['occurrence_id'], '队列消息没有保留权威调度身份');
    $reservation->acknowledge();
    putenv('TYPE_COORDINATION_TASK=reports.new-definition');
    $newDefinition = json_decode(successful([...$command, 'once']), true, 512, JSON_THROW_ON_ERROR);
    expect(count($newDefinition) === 1 && $newDefinition[0]['occurrence_id'] !== $completed[0]['occurrence_id'], '真正新任务定义没有得到新身份');
    $registry = new Registry();
    $registry->register('scheduled-report', 1, static fn (JobContext $context): Increment => new Increment($application . ':worker'));
    $worker = new Worker($queue, $registry, 'coordination-worker');
    expect(
        $worker->runOnce() && !$worker->runOnce() && $commandConnection->command('GET', [$application . ':worker:total']) === '1',
        '队列适配消息没有通过真实 worker 完成业务效果'
    );
    $commandConnection->command('DEL', [$application . ':worker:total', $application . ':worker:done:' . $newDefinition[0]['occurrence_id']]);

    $application = 'type_paused_' . bin2hex(random_bytes(8));
    $applications[] = $application;
    coordinationEnvironment($application, 'paused', '2026-09-09T12:00:00Z', 'v1');
    $children[1] = coordinationStart($command);
    $deadline = microtime(true) + 5;
    do {
        $ready = $commandConnection->command('GET', [$application . ':business:ready']);
        if ($ready !== false) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect($ready !== false, '旧执行者未到达暂停检查点');
    expect(defined('SIGSTOP') && defined('SIGCONT'), '该暂停验收需要真实POSIX信号，其他平台须使用其原生暂停入口');
    expect(proc_terminate($children[1][0], SIGSTOP), '无法向旧调度进程发送 SIGSTOP');
    $deadline = microtime(true) + 2;
    do {
        $stopped = proc_get_status($children[1][0]);
        if ($stopped['stopped']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect($stopped['stopped'] && $stopped['stopsig'] === SIGSTOP, '没有证据证明旧调度进程真实暂停');
    usleep(400000);
    coordinationEnvironment($application, 'normal', '2026-09-09T12:01:00Z', 'v2');
    [$status, $stdout, $stderr] = execute([...$command, 'once']);
    $takeover = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    expect($status === 70 && $stderr === '' && array_column($takeover, 'state') === ['interrupted', 'succeeded'], '接管没有记录中断并执行下一时刻');
    expect((int) $takeover[1]['lease_generation'] > (int) $takeover[0]['lease_generation'], '跨进程接管没有增加租约代次');
    expect(proc_terminate($children[1][0], SIGCONT), '无法恢复旧调度进程');
    $paused = $children[1];
    unset($children[1]);
    [$status, $stdout, $stderr] = coordinationWait($paused);
    expect($status === 70 && $stdout === '' && str_contains($stderr, 'TYPE_SCHEDULER_LEASE_LOST'), '恢复的旧执行者没有报告失锁：' . $stdout . $stderr);
    expect($commandConnection->command('GET', [$application . ':business:count']) === '1', '暂停后恢复的旧执行者仍写入业务效果');
    $queue = new Queue($scripts, $application, 'reports');
    expect($queue->statistics()['messages'] === 1, '恢复的旧执行者仍能投递');
    $reservation = $queue->reserve('verification');
    expect($reservation !== null && $reservation->message()->id() === $takeover[1]['occurrence_id'], '接管后队列存在旧计划身份');
    $reservation->acknowledge();
    $history = json_decode(successful([...$command, 'history']), true, 512, JSON_THROW_ON_ERROR);
    expect(array_column($history, 'state') === ['interrupted', 'succeeded'], '旧执行者覆盖了新执行者的完成状态');
    echo "真实多进程调度验证通过：竞争防重入、跨版本身份、SIGSTOP 接管与旧执行者效果拒绝。\n";
} finally {
    foreach ($children as $child) {
        proc_terminate($child[0], 9);
        proc_close($child[0]);
        fclose($child[1]);
        fclose($child[2]);
    }
    foreach ($applications as $application) {
        $root = (new RedisStateStore($scripts, $application, 'reports'))->identity();
        $queueRoot = (new Queue($scripts, $application, 'reports'))->identity();
        $commandConnection->command('DEL', [$root . ':lock', $root . ':generation', $root . ':state', $queueRoot . ':stream', $queueRoot . ':leases',
            $application . ':business:ready', $application . ':business:continue', $application . ':business:count', $application . ':business:applied']);
    }
    $scope->close();
    $manager->close();
}
