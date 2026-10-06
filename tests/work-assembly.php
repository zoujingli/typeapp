<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\JobCompiler;
use Type\Build\ScheduleCompiler;

$job = ['type' => 'reports.daily', 'version' => 1, 'class' => 'App\\DailyReport'];
$schedule = ['id' => 'reports.daily', 'class' => 'App\\DailyReport',
    'schedule' => ['cron' => '0 9 * * *', 'timezone' => 'Asia/Shanghai']];
$jobs = new JobCompiler();
$schedules = new ScheduleCompiler();
expect($jobs->validate([$job])[0]['resources'] === [], 'Job 无资源声明未规范化');
$valid = $schedules->validate([$schedule, ['id' => 'reports.interval', 'service' => 'report',
    'schedule' => ['interval' => 10, 'anchor' => 0], 'misfire' => 'catch-up', 'catch-up-limit' => 3, 'lookback-seconds' => 60]]);
expect($valid[0]['schedule']['overlap'] === 'first' && $valid[1]['grace-seconds'] === 59, '调度默认策略与公共接口不一致');
expect(!class_exists('Cron\\CronExpression', false) && !class_exists('Type\\Scheduler\\CronSchedule', false), '构建校验提前加载待适配生产类');
$invalidJobs = [[$job, $job], [array_replace($job, ['version' => 0])], [array_replace($job, ['type' => 'shell;exec'])],
    [array_replace($job, ['service' => 'also-service'])], [array_replace($job, ['resources' => ['same', 'same']])],
    [['type' => 'legacy', 'version' => 1, 'handler' => 'App\\Legacy']], ['name' => $job]];
foreach ($invalidJobs as $index => $rows) {
    $rejected = false;
    try {
        $jobs->validate($rows);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '无效 Job 声明没有拒绝：' . $index);
}
$invalidSchedules = [[$schedule, $schedule], [array_replace($schedule, ['command' => 'touch forbidden'])],
    [array_replace($schedule, ['schedule' => ['cron' => 'not a cron']])],
    [array_replace($schedule, ['schedule' => ['cron' => '* * * * *', 'timezone' => 'Invalid/Timezone']])],
    [array_replace($schedule, ['schedule' => ['cron' => '* * * * *', 'overlap' => 'never']])],
    [array_replace($schedule, ['schedule' => ['cron' => '* * * * *', 'interval' => 1]])],
    [array_replace($schedule, ['schedule' => ['interval' => 0]])],
    [array_replace($schedule, ['schedule' => ['interval' => 1, 'anchor' => -1]])],
    [array_replace($schedule, ['catch-up-limit' => 1001])], [array_replace($schedule, ['lookback-seconds' => 31622401])],
    [array_replace($schedule, ['grace-seconds' => 3601])], [array_replace($schedule, ['misfire' => 'unbounded'])]];
foreach ($invalidSchedules as $index => $rows) {
    $rejected = false;
    try {
        $schedules->validate($rows);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '无效调度声明没有拒绝：' . $index);
}
echo '任务声明通过：Job 类型版本、调度身份、上游时间策略、有限边界与生产符号隔离，共 ' . (count($invalidJobs) + count($invalidSchedules)) . " 个拒绝场景。\n";

$fixture = __DIR__ . '/fixtures/work-assembly.php';
$sources = (new Type\Build\BuildIdentity())->sources([dirname(__DIR__) . '/plugin/type-runtime/src',
    dirname(__DIR__) . '/plugin/type-queue/src', dirname(__DIR__) . '/plugin/type-scheduler/src', $fixture]);
$application = ['enabled' => ['test/application'], 'jobs' => [['type' => 'report', 'version' => 1, 'class' => 'TypeApp\\WorkFixture\\InheritedJob']],
    'schedules' => [['id' => 'report', 'class' => 'TypeApp\\WorkFixture\\ReportTask', 'schedule' => ['interval' => 60]]]];
$assembly = new Type\Build\CommandAssembly();
$report = $assembly->generate($application, ['test/application' => $application], $sources);
expect(count($report['jobs']) === 1 && count($report['schedules']) === 1, '任务依赖根没有进入统一装配报告');
$invalidGraphs = [];
foreach (['MissingDependencyJob', 'CircularJob'] as $class) {
    $row = $application;
    $row['jobs'][0]['class'] = 'TypeApp\\WorkFixture\\' . $class;
    $invalidGraphs[] = $row;
}
foreach (['jobs' => 'InheritedJob', 'schedules' => 'ReportTask'] as $collection => $class) {
    $row = $application;
    $row['services'] = [['id' => 'bad', 'class' => 'TypeApp\\WorkFixture\\' . $class, 'lifetime' => 'singleton']];
    unset($row[$collection][0]['class']);
    $row[$collection][0]['service'] = 'bad';
    $invalidGraphs[] = $row;
}
$row = $application;
$row['jobs'][0]['resources'] = ['bad'];
$row['services'] = [['id' => 'bad', 'class' => 'TypeApp\\WorkFixture\\ReportTask']];
$invalidGraphs[] = $row;
$row = $application;
$row['schedules'][0]['resources'] = ['bad'];
$row['services'] = [['id' => 'bad', 'class' => 'TypeApp\\WorkFixture\\Resource', 'lifetime' => 'singleton']];
$invalidGraphs[] = $row;
foreach ($invalidGraphs as $index => $invalid) {
    $rejected = false;
    try {
        $assembly->generate($invalid, ['test/application' => $invalid], $sources);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '无效任务依赖图没有拒绝：' . $index);
}
$temporary = dirname(__DIR__) . '/build/work-signatures-' . bin2hex(random_bytes(5));
expect(mkdir($temporary, 0700), '无法准备任务签名fixture');
try {
    foreach (['public function handle(array $payload): void {}', ''] as $index => $method) {
        $invalidSource = $temporary . '/InvalidJob.php';
        file_put_contents($invalidSource, '<?php namespace TypeApp\\WorkFixture; final class InvalidJob implements \\Type\\Queue\\Job {' . $method . '}');
        $row = $application;
        $row['jobs'][0]['class'] = 'TypeApp\\WorkFixture\\InvalidJob';
        $rejected = false;
        try {
            $assembly->generate($row, ['test/application' => $row], [...$sources, $invalidSource]);
        } catch (RuntimeException) {
            $rejected = true;
        }
        expect($rejected, '无效或缺失Job执行方法未拒绝：' . $index);
    }
} finally {
    removeTestDirectory($temporary);
}
echo "Job/Task 统一图验证通过：构造器依赖、继承方法及 8 个依赖和生命周期拒绝场景。\n";
