<?php

declare(strict_types=1);

namespace Type\Scheduler;

use Throwable;

/** 独立调度命令；生产守护进程可循环调用，测试使用有界 work 次数。 */
final class SchedulerConsole
{
    private Scheduler $scheduler;

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function run(array $arguments): int
    {
        try {
            $action = $arguments[0] ?? 'help';
            if ($action === 'help' && count($arguments) <= 1) {
                echo "调度命令：once、history、work <次数> <间隔毫秒>。任务来自编译注册，不接受 shell 或源码路径。\n";
                return 0;
            }
            if ($action === 'history' && count($arguments) === 1) {
                echo json_encode($this->scheduler->history(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
                return 0;
            }
            $ticks = 1;
            $milliseconds = 0;
            if ($action === 'work' && count($arguments) === 3
                && preg_match('/^[1-9][0-9]{0,5}$/D', (string) $arguments[1])
                && preg_match('/^[1-9][0-9]{0,4}$/D', (string) $arguments[2])) {
                $ticks = (int) $arguments[1];
                $milliseconds = (int) $arguments[2];
                if ($milliseconds > 60000) {
                    throw new \InvalidArgumentException('TYPE_SCHEDULER_CONFIG：轮询间隔最多 60000 毫秒');
                }
            } elseif ($action !== 'once' || count($arguments) !== 1) {
                throw new \InvalidArgumentException('TYPE_SCHEDULER_CONFIG：调度命令参数无效');
            }
            $status = 0;
            for ($tick = 0; $tick < $ticks; $tick++) {
                $records = $this->scheduler->tick();
                foreach ($records as $record) {
                    if ($record['state'] !== 'succeeded') {
                        $status = 70;
                    }
                }
                echo json_encode($records, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
                if (!$this->scheduler->ready()) {
                    break;
                }
                if ($tick + 1 < $ticks) {
                    usleep($milliseconds * 1000);
                }
            }

            return $status;
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return str_contains($error->getMessage(), 'TYPE_SCHEDULER_BUSY') ? 75 : 70;
        }
    }
}
