<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;
use Composer\Autoload\ClassLoader;

/** 校验静态时间计划并生成 Definition 表达式，不构造业务 Task。 */
final class ScheduleCompiler
{
    /**
     * @param list<array<string, mixed>> $schedules application.schedules 声明。
     * @return list<array<string, mixed>> 默认值已补齐的调度声明。
     * @throws RuntimeException 任务身份、时间计划、执行策略或构造目标无效。
     */
    public function validate(array $schedules): array
    {
        if (!array_is_list($schedules) || count($schedules) > 1000) {
            throw new RuntimeException('application.schedules 必须是最多 1000 项的列表');
        }
        $result = [];
        $seen = [];
        foreach ($schedules as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D', $row['id'])
                || array_diff(array_keys($row), ['id', 'class', 'service', 'schedule', 'misfire', 'catch-up-limit', 'lookback-seconds', 'grace-seconds', 'revision', 'resources']) !== []) {
                throw new RuntimeException('Schedule 身份或字段无效');
            }
            if (isset($seen[$row['id']])) {
                throw new RuntimeException('Schedule 身份重复：' . $row['id']);
            }
            $seen[$row['id']] = true;
            $target = array_intersect_key($row, array_flip(['class', 'service', 'resources']));
            $job = (new JobCompiler())->validate([['type' => 'schedule', 'version' => 1] + $target])[0];
            $row['resources'] = $job['resources'];
            $schedule = $row['schedule'] ?? null;
            if (!is_array($schedule) || count(array_intersect(['cron', 'interval'], array_keys($schedule))) !== 1) {
                throw new RuntimeException('Schedule 必须声明唯一 cron 或 interval 时间计划');
            }
            try {
                if (array_key_exists('cron', $schedule)) {
                    $schedule += ['timezone' => 'UTC', 'overlap' => 'first'];
                    if (array_diff(array_keys($schedule), ['cron', 'timezone', 'overlap']) !== [] || !is_string($schedule['cron'])
                        || !is_string($schedule['timezone']) || !is_string($schedule['overlap'])) {
                        throw new RuntimeException('Cron 字段无效');
                    }
                } else {
                    $schedule += ['anchor' => 0];
                    if (array_diff(array_keys($schedule), ['interval', 'anchor']) !== [] || !is_int($schedule['interval']) || !is_int($schedule['anchor'])) {
                        throw new RuntimeException('Interval 字段无效');
                    }
                }
            } catch (\Throwable $error) {
                throw new RuntimeException('Schedule 时间计划无效：' . $row['id'] . '：' . $error->getMessage(), 0, $error);
            }
            $row['schedule'] = $schedule;
            $row += ['misfire' => 'skip', 'catch-up-limit' => 1, 'lookback-seconds' => 3600, 'grace-seconds' => 59, 'revision' => ''];
            if (!in_array($row['misfire'], ['skip', 'catch-up'], true)
                || !is_int($row['catch-up-limit']) || $row['catch-up-limit'] < 1 || $row['catch-up-limit'] > 1000
                || !is_int($row['lookback-seconds']) || $row['lookback-seconds'] < 1 || $row['lookback-seconds'] > 31622400
                || !is_int($row['grace-seconds']) || $row['grace-seconds'] < 0 || $row['grace-seconds'] > $row['lookback-seconds']
                || !is_string($row['revision']) || strlen($row['revision']) > 200) {
                throw new RuntimeException('Schedule 错过策略、补跑边界或修订标识无效：' . $row['id']);
            }
            $result[] = $row;
        }
        $this->validatePlans(array_column($result, 'schedule'));
        return $result;
    }

    /** 上游纯时间校验在受限子进程内完成，避免开发进程提前加载待适配的生产 Cron 类。 */
    private function validatePlans(array $plans): void
    {
        if ($plans === []) {
            return;
        }
        $files = [];
        foreach (array_keys(ClassLoader::getRegisteredLoaders()) as $vendor) {
            $installed = json_decode((string) file_get_contents($vendor . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
            $packages = array_column($installed['packages'] ?? [], null, 'name');
            if (!isset($packages['dragonmantank/cron-expression'], $packages['zoujingli/type-scheduler'])) {
                continue;
            }
            foreach (['dragonmantank/cron-expression' => '/src/Cron', 'zoujingli/type-scheduler' => '/src'] as $name => $relative) {
                $path = realpath($vendor . '/composer/' . $packages[$name]['install-path'] . $relative);
                if ($path === false) {
                    throw new RuntimeException('Schedule 上游实现目录缺失：' . $name);
                }
                $files[] = $path;
            }
            break;
        }
        if (count($files) !== 2) {
            throw new RuntimeException('Schedule 声明需要安装 type-scheduler 及其 Cron 依赖');
        }
        $code = <<<'PHP'
$directories = ['Cron\\' => $argv[1], 'Type\\Scheduler\\' => $argv[2]];
spl_autoload_register(static function (string $class) use ($directories): void {
    foreach ($directories as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            require $directory . '/' . substr($class, strlen($prefix)) . '.php';
            return;
        }
    }
});
foreach (json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR) as $plan) {
    if (array_key_exists('cron', $plan)) {
        new Type\Scheduler\CronSchedule($plan['cron'], $plan['timezone'], $plan['overlap']);
    } else {
        new Type\Scheduler\IntervalSchedule($plan['interval'], $plan['anchor']);
    }
}
PHP;
        $environment = new BuildEnvironment();
        try {
            $environment->run(
                [PHP_BINARY, '-n', '-r', $code, '--', $files[0], $files[1], json_encode($plans, JSON_THROW_ON_ERROR)],
                __DIR__,
                $environment->environment(),
                10.0
            );
        } catch (RuntimeException $error) {
            throw new RuntimeException('Schedule 时间计划无效：' . $error->getMessage(), 0, $error);
        }
    }

    /** 将已校验声明与由依赖图生成的 TaskContext 闭包表达式组合。 */
    public function renderDefinition(array $row, string $factoryExpression): string
    {
        $plan = $row['schedule'];
        $schedule = array_key_exists('cron', $plan)
            ? 'new \\Type\\Scheduler\\CronSchedule(' . var_export($plan['cron'], true) . ', ' . var_export($plan['timezone'], true) . ', ' . var_export($plan['overlap'], true) . ')'
            : 'new \\Type\\Scheduler\\IntervalSchedule(' . $plan['interval'] . ', ' . $plan['anchor'] . ')';
        return 'new \\Type\\Scheduler\\Definition(' . var_export($row['id'], true) . ', ' . $schedule . ', ' . $factoryExpression . ', '
            . var_export($row['misfire'], true) . ', ' . $row['catch-up-limit'] . ', ' . $row['lookback-seconds'] . ', ' . $row['grace-seconds'] . ', '
            . var_export($row['revision'], true) . ')';
    }
}
