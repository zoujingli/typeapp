<?php

declare(strict_types=1);

use Type\Runtime\CoroutineRuntime;

/** 数据库等待与原生定时器的最小接缝；不依赖应用、ORM 或测试替身。 */
final class PdoProgressProbe
{
    private static int $pulses = 0;

    /** 已编译线程入口只接收驱动名，连接在该线程的协程内创建。 */
    public static function run(string $driver): int
    {
        CoroutineRuntime::run(static function () use ($driver): void {
            $prefix = 'TYPE_' . strtoupper($driver) . '_';
            $dsn = $driver . ':host=' . getenv($prefix . 'HOST') . ';port=' . getenv($prefix . 'PORT')
                . ';dbname=' . getenv($prefix . 'DATABASE');
            if ($driver === 'pgsql') {
                $dsn .= ';sslmode=disable';
            }
            $connection = new PDO(
                $dsn,
                (string) getenv($prefix . 'USER'),
                (string) getenv($prefix . 'PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            self::$pulses = 0;
            $timer = Swoole\Timer::tick(10, static function (int $timerId): void {
                self::$pulses++;
            });
            if ($timer === false) {
                throw new RuntimeException('pdo_progress_timer_failed');
            }
            $started = hrtime(true);
            try {
                $statement = $connection->prepare($driver === 'mysql' ? 'SELECT SLEEP(0.5)' : 'SELECT pg_sleep(0.5)');
                $statement->execute();
                $statement->fetchAll(PDO::FETCH_ASSOC);
                $statement->closeCursor();
            } finally {
                Swoole\Timer::clear($timer);
            }
            echo json_encode(['driver' => $driver, 'seconds' => (hrtime(true) - $started) / 1000000000,
                'pulses' => self::$pulses, 'coroutine' => Swoole\Coroutine::getCid(),
                'hooks' => Swoole\Runtime::getHookFlags(), 'thread' => !Swoole\Thread::getInfo()['is_main_thread']], JSON_THROW_ON_ERROR), "\n";
        });
        return 0;
    }
}

/** 同一原生文件分别观察主线程和业务线程，不修改运行期间的全局 hook。 */
function main(int $argc, array $argv): void
{
    $driver = (string) getenv('TYPE_DB_PROBE_DRIVER');
    if ($argc !== 2 || !in_array($driver, ['mysql', 'pgsql'], true) || !in_array($argv[1], ['main', 'thread'], true)) {
        throw new RuntimeException('pdo_progress_arguments');
    }
    CoroutineRuntime::enableIo();
    if ($argv[1] === 'main') {
        PdoProgressProbe::run($driver);
        return;
    }
    $thread = CoroutineRuntime::startThread('pdo', $driver);
    if (!$thread->joinWithin(10000) || $thread->getExitStatus() !== 0) {
        throw new RuntimeException('pdo_progress_thread_failed');
    }
}
