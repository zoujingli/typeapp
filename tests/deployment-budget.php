<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
use Type\Orm\DatabaseManager;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\CapacityException;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

$budget = new DeploymentBudget(60, 3, 1, 6, 12);
expect($budget->statistics()['per_process'] === 2 && $budget->statistics()['maximum_application_connections'] === 48, '部署增量或管理预留未计入预算');
$manager = new DatabaseManager(['default' => new SqliteDriver(':memory:'), 'other' => new SqliteDriver(':memory:')], 4, 0, $budget);
$first = new ExecutionScope();
$second = new ExecutionScope();
$third = new ExecutionScope();
try {
    $one = $manager->connect($first);
    $two = $manager->connect($second, 'other');
    $manager->rotate('default', new SqliteDriver(':memory:', 1000, true, 2));
    $rejected = false;
    try {
        $manager->connect($third);
    } catch (CapacityException) {
        $rejected = true;
    }
    expect($rejected && $budget->poolBudget()->statistics()['allocated'] === 2, '凭据轮换绕过仍被旧租约占用的容量');
    expect($one->query('SELECT 1 AS value')[0]['value'] === 1, '轮换提前销毁了旧代活动连接');
    $first->close();
    $manager->connect($third)->query('SELECT 2 AS value');
    expect($budget->poolBudget()->statistics()['allocated'] === 2, '排空后不能重新借用');
    $second->close();
    $third->close();
    $manager->close();
    expect($budget->poolBudget()->statistics()['allocated'] === 0, '作用域关闭后预算没有归还');
    $pid = pcntl_fork();
    expect($pid !== -1, '预算 fork 检查无法启动');
    if ($pid === 0) {
        try {
            $budget->poolBudget();
            exit(1);
        } catch (RuntimeException) {
            exit(0);
        }
    }
    pcntl_waitpid($pid, $status);
    expect(pcntl_wexitstatus($status) === 0, '已初始化预算跨进程复用');
    echo "部署预算：全部角色、副本、滚动增量、管理预留、身份与轮换共享容量、收尾归还和进程隔离通过。\n";
} finally {
    $first->close();
    $second->close();
    $third->close();
    $manager->close();
}
