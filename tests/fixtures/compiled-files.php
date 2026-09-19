<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\UploadStorage;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskException;

/** 独立安装后的文件消费者；故障通过真实 FIFO、文件锁和无效操作产生。 */
final class FileProbe
{
    public static int $checks = 0;
    public static int $ticks = 0;
    public static int $readerCid = 0;
    public static bool $reading = false;
    public static bool $closed = false;
    public static string $received = '';
    public static int $generation = 0;
    public static array $idle = [];
    public static array $buffers = [];
    public static ?Throwable $failure = null;
    public static mixed $supervisedReader = null;

    /** 直接使用生产线程监督，真实 FIFO 仍由原生工作池执行；心跳与该等待彼此独立。 */
    public static function supervised(string $payload): int
    {
        $input = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $state = Swoole\Thread::getArguments()[2];
        if ($input['role'] === 'blocked') {
            Coroutine::create(static function () use ($input, $state): void {
                try {
                    self::check(Swoole\Coroutine\System::writeFile($input['pipe'], 'ok', FILE_APPEND) === 2, '真实 FIFO 写入没有完成');
                    self::$closed = true;
                } catch (Throwable $error) {
                    self::$failure = $error;
                    $state['failed'] = true;
                }
            });
        }
        $state['ready'] = true;
        Swoole\Timer::tick(20, static function (int $id) use ($state, $input): void {
            $state['pulse'] = hrtime(true);
            self::$ticks++;
            if (self::$ticks === 3) {
                $stats = Coroutine::stats();
                self::check($stats['aio_process_pending'] > 0, '心跳证明必须与真实在途文件操作重叠');
                echo 'heartbeat-with-pending:', $input['role'], "\n";
            }
            if ($state['stop'] === true) {
                $state['ready'] = false;
                Swoole\Timer::clear($id);
            }
        });
        Swoole\Event::wait();
        self::check(self::$failure === null && ($input['role'] !== 'blocked' || self::$closed), '停止提前遗忘真实文件操作');
        return 0;
    }

    /** 测试驱动逐次推进主控 reactor；保留其原生 AIO 接收者直到全部业务线程退出。 */
    public static function dispatch(): void
    {
        self::check(Swoole\Event::dispatch(), '主控事件循环无法推进');
    }

    public static function drain(): void
    {
        $deadline = new Deadline(5);
        while (Coroutine::stats()['coroutine_num'] !== 0) {
            self::check(!$deadline->expired(), '测试主控协程没有完成');
            self::dispatch();
        }
    }

    public static function join(Swoole\Thread $thread): void
    {
        $deadline = new Deadline(5);
        while (!$thread->joinWithin(0)) {
            self::check(!$deadline->expired(), '文件线程没有完整退出');
            self::dispatch();
        }
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        self::$checks++;
    }

    public static function run(string $payload): int
    {
        $input = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $directory = $input['directory'];
        $mode = $input['mode'];
        try {
            $loops = $mode === 'capacity' ? 12 : 1;
            for ($index = 0; $index < $loops; $index++) {
                Coroutine::create(static function () use ($directory, $mode, $index): void {
                    try {
                        if ($mode === 'rebuild') {
                            self::check(file_get_contents($directory . '/ready-data') === 'readonly', '重建线程文件读取失败');
                        } elseif ($mode === 'normal') {
                            self::files($directory);
                            self::locks($directory);
                            self::lateCompletion($directory);
                        } elseif ($mode === 'buffers') {
                            self::buffers($directory);
                        } elseif ($mode === 'buffer-capacity') {
                            $content = Swoole\Coroutine\System::readFile($directory . '/pipe-0');
                            self::check(is_string($content) && $content === str_repeat('b', 524288), '未知长度读取丢失原生缓冲内容');
                        } else {
                            // 无读者时 open 在原生工作线程阻塞，每个调用只占一个提交。
                            self::check(Swoole\Coroutine\System::writeFile($directory . '/pipe-' . $index, 'ok', FILE_APPEND) === 2, 'FIFO 原生写入失败');
                        }
                    } catch (Swoole\Exception $error) {
                        self::$failure = $error;
                    } catch (Throwable $error) {
                        self::$failure = $error;
                    }
                });
            }
            self::$generation = Coroutine::stats()['aio_owner_generation'];
            self::check(self::$generation > 0, '原生完成所有者没有代次');
            file_put_contents($directory . '/ready', 'ready');
            Swoole\Event::wait();
            if (self::$failure !== null) {
                throw self::$failure;
            }
            $stats = Coroutine::stats();
            self::check($stats['coroutine_num'] === 0 && $stats['aio_task_num'] === 0, '线程退出前仍有文件操作');
            file_put_contents($directory . '/' . ($mode === 'rebuild' ? 'rebuild.json' : 'result.json'), json_encode(['checks' => self::$checks, 'ticks' => self::$ticks, 'generation' => self::$generation,
                'process' => getmypid(), 'native_id' => Swoole\Thread::getNativeId(), 'stats' => $stats, 'buffers' => self::$buffers], JSON_THROW_ON_ERROR));
            return 0;
        } catch (Throwable $error) {
            file_put_contents($directory . '/failure', get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString());
            return 1;
        }
    }

    private static function files(string $directory): void
    {
        $path = $directory . '/large.bin';
        $stream = (new Factory())->createStreamFromFile($path, 'w+b');
        $chunk = str_repeat('file-io-', 8192);
        for ($index = 0; $index < 128; $index++) {
            self::check($stream->write($chunk) === 65536, '大文件分块写入失败');
        }
        self::check($stream->getSize() === 8388608, 'fstat 大小错误');
        $stream->rewind();
        for ($index = 0; $index < 128; $index++) {
            self::check($stream->read(65536) === $chunk, '大文件分块读取错误');
        }
        $resource = $stream->detach();
        self::check(fflush($resource) && fsync($resource), '刷新或 fsync 失败');
        self::check(ftruncate($resource, 16), 'truncate 失败');
        self::check(fclose($resource), '真实 close 失败');
        clearstatcache(true, $path);
        self::check(filesize($path) === 16 && is_file($path), '路径 metadata 错误');
        clearstatcache();
        $linkInfo = lstat($directory . '/metadata-link/../ready-data');
        self::check($linkInfo !== false && $linkInfo['size'] === 8, 'lstat 必须保持 PHP 的路径展开语义');
        self::check(is_link($directory . '/metadata-link'), 'lstat 不应跟随最终符号链接');
        self::check(file_get_contents($directory . '/missing/../ready-data') === 'readonly', '文件打开必须复用 PHP 路径解析');
        self::check(rename($path, $directory . '/renamed'), 'rename 失败');
        self::check(unlink($directory . '/renamed'), 'unlink 失败');

        $readonly = fopen($directory . '/ready-data', 'rb');
        self::check(is_resource($readonly) && @ftruncate($readonly, 0) === false, '原生无效 truncate 不应成功');
        self::check(fclose($readonly), '错误后关闭失败');
        $oversized = (new Factory())->createStreamFromFile($path, 'w+b');
        $rejected = false;
        try {
            $oversized->write(str_repeat('x', 16777216));
        } catch (Swoole\Exception $error) {
            $rejected = $error->getMessage() === 'aio_capacity_exceeded';
        }
        self::check($rejected && $oversized->getSize() === 0, '字节超限必须在提交前拒绝');
        $oversized->close();
        unlink($path);

        $storage = new UploadStorage($directory . '/uploads');
        $scope = new ExecutionScope();
        $body = (new Factory())->createStream(str_repeat('upload', 5000));
        try {
            $upload = $storage->receive($body, $scope);
            $key = $upload->save();
        } finally {
            $scope->close();
            $body->close();
        }
        $download = $storage->open($key);
        self::check($download->getContents() === str_repeat('upload', 5000), '上传提交后内容错误');
        $download->close();
        $storage->remove($key);
        self::check($storage->statistics() === ['files' => 0, 'bytes' => 0, 'pending' => 0], '上传资源未回收');
    }

    /** 使用原生整文件入口验证已知大小、未知长度增长、拒绝后恢复与返回拷贝预算。 */
    private static function buffers(string $directory): void
    {
        for ($round = 0; $round < 3; $round++) {
            $content = Swoole\Coroutine\System::readFile($directory . '/known.bin');
            self::check(is_string($content) && $content === str_repeat("\0", 65536), '预算内整文件读取错误');
            self::$buffers[] = Coroutine::stats();
            self::check(Swoole\Coroutine\System::readFile($directory . '/empty.bin') === '', '空文件的原生语义改变');
            foreach ([$directory . '/oversized.bin', '/dev/zero'] as $rejectedPath) {
                $rejected = false;
                try {
                    Swoole\Coroutine\System::readFile($rejectedPath);
                } catch (Swoole\Exception $error) {
                    $rejected = $error->getMessage() === 'aio_capacity_exceeded';
                }
                self::check($rejected, '整文件分配或未知长度扩容绕过共享字节预算');
            }
            Coroutine::sleep(0.001);
        }
        self::check(Swoole\Coroutine\System::readFile($directory . '/ready-data') === 'readonly', '容量拒绝后不能恢复原生读取');
        self::check(Coroutine::stats()['aio_process_peak_bytes'] >= 4194304, '实际分配和返回拷贝未计量');
    }

    private static function locks(string $directory): void
    {
        $first = fopen($directory . '/lock', 'c+b');
        $second = fopen($directory . '/lock', 'c+b');
        self::check(flock($first, LOCK_EX | LOCK_NB), '初始锁失败');
        $gate = new Swoole\Coroutine\Channel(1);
        Coroutine::create(static function () use ($second, $gate): void {
            $locked = flock($second, LOCK_EX);
            if ($locked) {
                flock($second, LOCK_UN);
            }
            fclose($second);
            $gate->push($locked);
        });
        Coroutine::sleep(0.025);
        self::check($gate->isEmpty(), '竞争锁提前成功');
        self::$ticks++;
        self::check(flock($first, LOCK_UN) && fclose($first), '解锁与关闭失败');
        self::check($gate->pop(2) === true, '竞争锁没有恢复');
    }

    private static function lateCompletion(string $directory): void
    {
        $parent = new ExecutionScope(new Deadline(2), [], 2, 0.01);
        $task = $parent->spawn(static function (ExecutionScope $child) use ($directory): string {
            self::$readerCid = Coroutine::getCid();
            self::$reading = true;
            file_put_contents($directory . '/reading', 'ready');
            try {
                $content = Swoole\Coroutine\System::readFile($directory . '/slow.bin', FILE_LOCK);
                self::check(is_string($content), '受锁阻塞的原生文件读取失败');
                self::$received = $content;
            } finally {
                self::$closed = true;
            }
            return self::$received;
        });
        while (!self::$reading) {
            Coroutine::sleep(0.001);
        }
        Coroutine::sleep(0.02);
        self::check(!Coroutine::cancel(self::$readerCid), '原生文件等待不应被提前取消');
        $task->cancel();
        try {
            $task->await(0.01);
            throw new RuntimeException('慢文件没有触发等待截止');
        } catch (TaskException $error) {
            self::check($error->errorCode() === 'task_timeout', '等待错误种类不符');
        }
        self::check(!$task->finished() && !self::$closed && $parent->activeTasks() === 1, '取消后提前释放文件或任务');
        for ($tick = 0; $tick < 10; $tick++) {
            Coroutine::sleep(0.001);
            self::$ticks++;
        }
        self::check($task->join(new Deadline(3)), '迟到完成未返回所有者');
        self::check(self::$received === 'late-result' && self::$closed, '真实结果丢失或文件未关闭');
        $parent->close();
        self::check($parent->activeTasks() === 0 && $parent->state() === 'closed', '迟到收尾未归还任务额度');
    }
}

/** 主线程只负责受限启动、故障注入与 join；业务文件操作在编译线程的协程内执行。 */
function main(int $argc, array $argv): void
{
    $directory = $argv[1];
    $mode = $argv[2] ?? 'normal';
    CoroutineRuntime::assertAvailable();
    if (!defined('SWOOLE_FILE_IO_ABI') || constant('SWOOLE_FILE_IO_ABI') !== 2) {
        throw new TaskException('swoole_file_io_unavailable', '文件候选验收需要带有界原生完成协议的 Swoole 扩展');
    }
    swoole_async_set(['aio_core_worker_num' => $mode === 'buffer-capacity' ? 3 : 2, 'aio_worker_num' => 4, 'aio_max_idle_time' => 0.02, 'aio_max_pending' => $mode === 'capacity' ? 32 : 128,
        'aio_max_bytes' => 16777216, 'aio_max_task_time' => $mode === 'watchdog' || $mode === 'supervised-watchdog' ? 0.2 : 10]);
    CoroutineRuntime::enableIo();
    FileProbe::check(Swoole\Runtime::enableCoroutine(Swoole\Runtime::getHookFlags() | SWOOLE_HOOK_FILE), '文件 hook 必须在业务线程启动前启用');
    if ($mode === 'supervised-watchdog' || $mode === 'supervised-stop') {
        $pipe = $directory . '/supervised-pipe';
        FileProbe::check(posix_mkfifo($pipe, 0600), '无法创建监督场景 FIFO');
        $supervisor = new Type\Runtime\ThreadSupervisor(2, 2.0, 0.2, 2.0);
        if ($mode === 'supervised-stop') {
            Swoole\Timer::after(100, static function () use ($supervisor): void {
                $supervisor->stop();
            });
            Swoole\Timer::after(200, static function () use ($pipe): void {
                // O_RDWR 只为受控测试打开真实读端，不等待另一个打开者。
                FileProbe::$supervisedReader = fopen($pipe, 'r+');
            });
        }
        $exits = $supervisor->run('supervised', [json_encode(['role' => 'blocked', 'pipe' => $pipe], JSON_THROW_ON_ERROR),
            json_encode(['role' => 'healthy', 'pipe' => $pipe], JSON_THROW_ON_ERROR)]);
        FileProbe::check($exits === [0, 0] && Swoole\Thread::activeCount() === 1, '停止后业务线程没有真实回收');
        stream_set_blocking(FileProbe::$supervisedReader, false);
        FileProbe::check(fread(FileProbe::$supervisedReader, 2) === 'ok' && fclose(FileProbe::$supervisedReader), '停止后的迟到写入没有交付');
        $stats = Coroutine::stats();
        FileProbe::check($stats['aio_process_pending'] === 0 && $stats['aio_process_bytes'] === 0, '停止后在途或字节未归零');
        echo json_encode(['exits' => $exits, 'statistics' => $supervisor->statistics(), 'stats' => $stats,
            'source_free' => get_included_files() === []], JSON_THROW_ON_ERROR), "\n";
        return;
    }
    // 独立诊断驱动保留原文件场景；生产监督另由下方专门场景验证。
    swoole_async_set(['enable_coroutine' => false]);
    $supervision = Swoole\Timer::tick(20, static function (int $id): void {
        if (!Coroutine::typeappSuperviseIo()) {
            type_runtime_native_control_fail_stop();
        }
    });
    FileProbe::check(Coroutine::typeappSuperviseIo(), '无法在提交前建立主控原生 AIO 所有者');
    $roles = $mode === 'watchdog' || $mode === 'stop' ? ['left'] : ['left', 'right'];
    $holders = [];
    if ($mode === 'buffer-capacity') {
        $recoveryFile = fopen($directory . '/recovery.bin', 'w+b');
        FileProbe::check(ftruncate($recoveryFile, 6291456) && fclose($recoveryFile), '无法准备恢复读取文件');
    }
    foreach ($roles as $role) {
        $path = $directory . '/' . $role;
        mkdir($path);
        mkdir($path . '/uploads');
        file_put_contents($path . '/ready-data', 'readonly');
        if ($mode === 'buffers') {
            foreach (['known.bin' => 65536, 'oversized.bin' => 16777216, 'empty.bin' => 0] as $name => $bytes) {
                $prepared = fopen($path . '/' . $name, 'w+b');
                FileProbe::check(ftruncate($prepared, $bytes) && fclose($prepared), '无法准备整文件缓冲场景');
            }
        }
        mkdir($path . '/metadata-target/inner', 0700, true);
        FileProbe::check(symlink('metadata-target/inner', $path . '/metadata-link'), '无法创建 metadata 测试链接');
        if ($mode === 'normal') {
            $lockedFile = fopen($path . '/slow.bin', 'w+b');
            fwrite($lockedFile, 'late-result');
            FileProbe::check(flock($lockedFile, LOCK_EX), '无法持有慢读锁');
            $holders[] = $lockedFile;
        }
        $count = $mode === 'capacity' ? 12 : 1;
        for ($index = 0; $index < $count; $index++) {
            FileProbe::check(posix_mkfifo($path . '/pipe-' . $index, 0600), '无法创建测试 FIFO');
        }
    }
    $threads = [];
    foreach ($roles as $role) {
        $threads[] = CoroutineRuntime::startThread('probe', json_encode(['directory' => $directory . '/' . $role, 'mode' => $mode], JSON_THROW_ON_ERROR));
    }
    $until = microtime(true) + 5;
    foreach ($roles as $role) {
        $flag = $directory . '/' . $role . '/' . ($mode === 'normal' ? 'reading' : 'ready');
        while (!is_file($flag)) {
            FileProbe::check(microtime(true) < $until, '线程没有进入文件场景');
            usleep(1000);
        }
    }
    $saturated = [];
    if ($mode === 'buffer-capacity') {
        foreach ($roles as $role) {
            $writer = fopen($directory . '/' . $role . '/pipe-0', 'wb');
            $holders[] = $writer;
            for ($part = 0; $part < 64; $part++) {
                FileProbe::check(fwrite($writer, str_repeat('b', 8192)) === 8192, '无法提供慢 EOF 的真实数据');
            }
        }
        while (Coroutine::stats()['aio_process_bytes'] < 4194304) {
            FileProbe::check(microtime(true) < $until, '未知长度缓冲没有登记真实分配');
            usleep(1000);
        }
        $saturated = Coroutine::stats();
        FileProbe::check($saturated['aio_process_pending'] === 2, '读取 EOF 前提前结算原生操作');
        Coroutine::create(static function () use ($directory): void {
            try {
                Swoole\Coroutine\System::readFile($directory . '/recovery.bin');
                throw new RuntimeException('两个线程持有的缓冲没有共同约束后续读取');
            } catch (Swoole\Exception $error) {
                FileProbe::check($error->getMessage() === 'aio_capacity_exceeded', '跨线程缓冲拒绝错误丢失');
            }
            FileProbe::$buffers[] = Coroutine::stats();
        });
        FileProbe::drain();
        foreach ($holders as $eofWriter) {
            FileProbe::check(fclose($eofWriter), '无法交付真实 EOF');
        }
        $holders = [];
    } elseif ($mode === 'capacity') {
        $saturated = Coroutine::stats();
        FileProbe::check($saturated['aio_process_pending'] === 24, '提交预算没有跨业务线程共享');
        $handle = fopen($directory . '/cleanup', 'w+b');
        FileProbe::check(flock($handle, LOCK_EX | LOCK_NB), '无法准备原生关闭解锁场景');
        Coroutine::create(static function () use ($directory, $handle): void {
            try {
                clearstatcache();
                is_file($directory . '/left/ready-data');
                throw new RuntimeException('队列满时 metadata 假装成功或不存在');
            } catch (Swoole\Exception $error) {
                FileProbe::check(\Type\Runtime\CapacityException::matches($error), '真实 metadata 拒绝未识别为容量错误');
            }
            // 上游非阻塞锁与解锁不提交 AIO；关闭流也会通过原生入口释放仍持有的锁。
            FileProbe::check(flock($handle, LOCK_UN) && flock($handle, LOCK_EX | LOCK_NB), '容量饱和不应拒绝原生非阻塞锁与解锁');
            FileProbe::check(Coroutine::stats()['aio_process_pending'] === 24, '非阻塞锁或解锁不应占用 AIO 提交');
            FileProbe::check(fclose($handle), '预留 close 无法真实完成');
            FileProbe::$closed = true;
            Coroutine::sleep(0.12);
            FileProbe::$idle = Coroutine::stats();
            FileProbe::check(FileProbe::$idle['aio_released_worker_num'] >= 2 && FileProbe::$idle['aio_worker_num'] === 2, '原生空闲线程没有按标准配置回收');
        });
        FileProbe::check(!FileProbe::$closed && Coroutine::stats()['aio_process_pending'] === 25, '关闭预留没有占用共享预算');
        foreach ($roles as $role) {
            for ($index = 0; $index < 12; $index++) {
                $openedWriter = fopen($directory . '/' . $role . '/pipe-' . $index, 'r+');
                $holders[] = $openedWriter;
            }
        }
        FileProbe::drain();
        FileProbe::check(FileProbe::$closed, '原生完成后关闭未恢复');
        $unlocked = fopen($directory . '/cleanup', 'r+b');
        FileProbe::check(flock($unlocked, LOCK_EX | LOCK_NB) && fclose($unlocked), '关闭后原生文件锁仍未释放');
    } elseif ($mode === 'normal') {
        usleep(150000);
        foreach ($holders as $releaseWriter) {
            FileProbe::check(flock($releaseWriter, LOCK_UN), '无法释放慢读锁');
        }
    } elseif ($mode === 'stop') {
        // 宿主关闭检查必须发现仍持有原生操作的业务线程。
        foreach ($threads as $stoppingThread) {
            $stoppingThread->detach();
        }
        return;
    }
    $exits = [];
    foreach ($threads as $thread) {
        FileProbe::join($thread);
        $exits[] = $thread->getExitStatus();
    }
    FileProbe::check($exits === array_fill(0, count($roles), 0), '文件业务线程执行失败');
    if ($mode === 'buffer-capacity') {
        Coroutine::create(static function () use ($directory): void {
            $content = Swoole\Coroutine\System::readFile($directory . '/recovery.bin');
            FileProbe::check(is_string($content) && strlen($content) === 6291456, '真实 EOF 和清理之后缓冲额度未恢复');
            FileProbe::$buffers[] = Coroutine::stats();
        });
        FileProbe::drain();
    }
    foreach ($holders as $heldWriter) {
        if ($mode === 'capacity') {
            stream_set_blocking($heldWriter, false);
            FileProbe::check(fread($heldWriter, 2) === 'ok', '已完成原生写入的数据不符');
        }
        fclose($heldWriter);
    }
    if ($mode === 'normal') {
        $previous = json_decode(file_get_contents($directory . '/left/result.json'), true, 512, JSON_THROW_ON_ERROR);
        $rebuilt = CoroutineRuntime::startThread('probe', json_encode(['directory' => $directory . '/left', 'mode' => 'rebuild'], JSON_THROW_ON_ERROR));
        FileProbe::join($rebuilt);
        FileProbe::check($rebuilt->getExitStatus() === 0, '重建文件线程失败');
        $replacement = json_decode(file_get_contents($directory . '/left/rebuild.json'), true, 512, JSON_THROW_ON_ERROR);
        FileProbe::check($replacement['generation'] > $previous['generation'], '新线程复用了旧完成代次');
    }
    $stats = Coroutine::stats();
    FileProbe::check($stats['aio_process_pending'] === 0 && $stats['aio_process_bytes'] === 0, '进程共享原生额度未归还');
    Swoole\Timer::clear($supervision);
    Swoole\Event::wait();
    echo json_encode(['exits' => $exits, 'stats' => $stats, 'saturated' => $saturated, 'idle' => FileProbe::$idle, 'buffers' => FileProbe::$buffers, 'process' => getmypid(),
        'main_thread' => Swoole\Thread::getNativeId(), 'active_threads' => Swoole\Thread::activeCount(),
        'source_free' => get_included_files() === [], 'checks' => FileProbe::$checks], JSON_THROW_ON_ERROR), "\n";
}
