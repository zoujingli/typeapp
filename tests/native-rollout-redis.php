<?php

declare(strict_types=1);

use Type\Build\BuildLock;
use Type\Build\BuildPlatform;
use Type\Redis\RedisConfiguration;
use Type\Redis\StoragePolicy;
use Type\Testing\Process;

/** 两套专用原生Redis的端口、数据根、存储策略、就绪和退出由同一所有者管理。 */
final class NativeRolloutRedis
{
    /** @var array<string,Process> */
    private array $processes = [];
    /** @var array<string,string> */
    private array $environment = [];
    private array $evidence = [];
    /** @var array<string,array{command:list<string>,directory:string,pidfile:string,port:int}> 本所有者启动的原始配置，重启不替换数据目录。 */
    private array $launches = [];

    /**
     * @param string $directory 项目build下尚不存在的私有数据根；原有目录不覆盖。
     * @param string $server 已安装、同平台的redis-server原生文件；不接受脚本包装器。
     * @throws RuntimeException 前置条件、真实进程/存储策略或就绪不满足；已启动的本轮进程会清理。
     */
    public function __construct(string $directory, string $server)
    {
        $root = BuildPlatform::resolve(dirname(__DIR__));
        BuildLock::path($directory);
        $parent = BuildPlatform::resolve(dirname($directory));
        $binary = realpath($server);
        expect(in_array(PHP_OS_FAMILY, ['Linux', 'Darwin', 'Windows'], true) && ($parent === $root . '/build' || str_starts_with($parent, $root . '/build/'))
            && !file_exists($directory) && !is_link($directory), '原生Redis只能使用build下新的专用数据根');
        $format = match (PHP_OS_FAMILY) {
            'Darwin' => 'Mach-O', 'Windows' => 'PE', default => 'ELF'
        };
        expect($binary !== false && is_executable($binary) && BuildPlatform::format($binary) === $format, '需要同平台原生redis-server，不能调用容器或脚本替代');
        expect(class_exists(Redis::class) && mkdir($directory, 0700), '需要Redis扩展及可写的专用目录');
        $servers = [];
        try {
            foreach (['queue', 'cache'] as $kind) {
                $data = $directory . '/' . $kind;
                expect(mkdir($data, 0700), '无法创建Redis数据目录');
                $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
                expect(is_resource($socket), '无法选择Redis回环端口');
                $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
                fclose($socket);
                $policy = $kind === 'queue'
                    ? ['--appendonly', 'yes', '--appendfsync', 'always', '--maxmemory', '16mb', '--maxmemory-policy', 'noeviction']
                    : ['--appendonly', 'no', '--maxmemory', '4mb', '--maxmemory-policy', 'allkeys-lru'];
                $pidFile = $data . '/redis.pid';
                $command = [$binary, '--bind', '127.0.0.1', '--protected-mode', 'yes', '--port', (string) $port,
                    '--daemonize', 'no', '--save', '', '--dir', '.', '--pidfile', 'redis.pid', ...$policy];
                $this->launches[$kind] = ['command' => $command, 'directory' => $data, 'pidfile' => $pidFile, 'port' => $port];
                $process = new Process($command, $data, $this->processEnvironment(), 1048576);
                $this->processes[$kind] = $process;
                $info = $this->ready($process, $port, $pidFile);
                $prefix = $kind === 'queue' ? 'TYPE_REDIS_' : 'TYPE_ROLLOUT_CACHE_';
                $this->environment[$prefix . 'HOST'] = '127.0.0.1';
                $this->environment[$prefix . 'PORT'] = (string) $port;
                $servers[$kind] = ['pid' => (int) $info['process_id'], 'native-pid' => $process->pid(), 'version' => $info['redis_version'], 'port' => $port];
            }
            $storage = StoragePolicy::verify(new RedisConfiguration('127.0.0.1', $servers['queue']['port']), new RedisConfiguration('127.0.0.1', $servers['cache']['port']));
            expect($storage['isolated'] && $storage['reliable']['maxmemory'] === 16777216 && $storage['cache']['eviction'] === 'allkeys-lru'
                && $storage['cache']['maxmemory'] === 4194304 && !$storage['cache']['appendonly'], '原生缓存与可靠队列没有维持独立实例和存储策略');
            $this->evidence = ['execution' => 'native', 'platform' => PHP_OS_FAMILY, 'binary' => basename($binary), 'binary-base' => 'explicit-server-argument', 'binary-sha256' => hash_file('sha256', $binary),
                'servers' => $servers, 'checks' => ['native-executable', 'loopback-only', 'private-data', 'foreground-owned-processes', 'pidfile-matches-server', 'separate-queue-and-cache', 'aof-always-noeviction']];
        } catch (Throwable $failure) {
            try {
                $this->close();
            } catch (Throwable $cleanup) {
                throw new RuntimeException($failure->getMessage() . '；清理失败：' . $cleanup->getMessage(), 0, $failure);
            }
            throw $failure;
        }
    }

    /** @return array<string,string> 仅返回本轮Redis连接参数，不读取或返回业务凭据。 */
    public function environment(): array
    {
        return $this->environment;
    }

    /** @return array{execution:string,platform:string,binary:string,'binary-sha256':string,servers:array,checks:list<string>} 实际进程与存储策略证据。 */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /**
     * 强制终止本轮可靠存储，确认退出后使用原 AOF 数据和配置重启。
     * Unix 使用 SIGKILL；Windows 只对持有的原生进程发送 taskkill /F。
     *
     * @throws RuntimeException 无法终止本轮持有的进程、对象已关闭或实际退出/就绪不符。
     */
    public function crashAndRestartReliable(): void
    {
        expect(isset($this->processes['queue']), '需要仍由本对象持有的Redis进程');
        $previous = $this->processes['queue'];
        $pid = $previous->pid();
        expect($pid !== null && $pid === $this->evidence['servers']['queue']['native-pid'], '可靠存储PID已变化或退出');
        if (PHP_OS_FAMILY === 'Windows') {
            // Cygwin 的 Redis INFO PID 与 Windows PID 不同；只使用 proc_open 持有的原生 PID。
            $kill = (new Process([(string) getenv('SystemRoot') . '/System32/taskkill.exe', '/PID', (string) $pid, '/T', '/F']))->wait(10);
            expect($kill->successful(), '无法强制终止本轮Windows Redis');
        } else {
            expect(function_exists('posix_kill') && defined('SIGKILL') && posix_kill($pid, SIGKILL), '无法终止本轮可靠存储');
        }
        $result = $previous->wait(5);
        expect(!$previous->running() && !$result->successful() && !$result->timedOut
            && (PHP_OS_FAMILY === 'Windows' || $result->signal === SIGKILL), '没有观察到真实强制退出');
        unset($this->processes['queue']);
        $launch = $this->launches['queue'];
        $process = new Process($launch['command'], $launch['directory'], $this->processEnvironment(), 1048576);
        $this->processes['queue'] = $process;
        $info = $this->ready($process, $launch['port'], $launch['pidfile']);
        expect($process->pid() !== $pid, '可靠存储没有更换进程');
        $this->evidence['restarts'][] = ['previous-pid' => $pid, 'termination' => PHP_OS_FAMILY === 'Windows' ? 'taskkill-force' : 'SIGKILL',
            'signal' => PHP_OS_FAMILY === 'Windows' ? null : SIGKILL, 'pid' => (int) $info['process_id'], 'same-data-and-port' => true];
        $this->evidence['servers']['queue']['pid'] = (int) $info['process_id'];
        $this->evidence['servers']['queue']['native-pid'] = $process->pid();
    }

    /**
     * 幂等停止本对象启动的进程；不按名称终止其他Redis或删除运行数据。
     *
     * @throws RuntimeException 任一进程未正常退出；其余已启动进程仍会尝试清理。
     */
    public function close(): void
    {
        $failures = [];
        foreach ($this->processes as $kind => $process) {
            try {
                if (PHP_OS_FAMILY === 'Windows' && $process->running()) {
                    // Windows 控制事件不是 Redis 的正常退出协议；先通过持有实例的连接收尾。
                    $client = new Redis();
                    try {
                        expect($client->connect('127.0.0.1', $this->launches[$kind]['port'], 1.0), '无法连接本轮Redis执行关闭');
                        $client->rawCommand('SHUTDOWN', 'NOSAVE');
                    } catch (RedisException) {
                        // SHUTDOWN 会主动断开连接；下面仍须观察零退出状态。
                    } finally {
                        $client->close();
                    }
                    $result = $process->wait(10);
                } else {
                    $result = $process->stop(10);
                }
                if (!$result->successful()) {
                    $failures[] = $kind . '未正常退出';
                }
                unset($this->processes[$kind]);
            } catch (Throwable $failure) {
                $failures[] = $kind . '：' . $failure->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(implode('；', $failures));
        }
    }

    /** 测试进程只继承定位系统库需要的环境，不携带业务凭据。 */
    private function processEnvironment(): array
    {
        $environment = ['PATH' => getenv('PATH') ?: '', 'LC_ALL' => 'C'];
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['SystemRoot', 'TEMP', 'TMP'] as $name) {
                $environment[$name] = (string) getenv($name);
            }
        }
        return $environment;
    }

    /** @return array<string,mixed> 来自真实Redis INFO的进程身份，非端口存在性猜测。 */
    private function ready(Process $process, int $port, string $pidFile): array
    {
        $deadline = microtime(true) + 15;
        do {
            expect($process->running(), '本轮原生Redis提前退出：' . $process->stdout() . $process->stderr());
            $client = new Redis();
            $connected = false;
            try {
                $connected = $client->connect('127.0.0.1', $port, 0.2);
                $info = $connected ? $client->info('server') : false;
                if (is_array($info) && is_file($pidFile) && (int) ($info['process_id'] ?? 0) > 0
                    && (int) trim(file_get_contents($pidFile)) === (int) $info['process_id']) {
                    return $info;
                }
            } catch (RedisException) {
            } finally {
                if ($connected) {
                    $client->close();
                }
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('本轮原生Redis没有就绪或PID记录不符');
    }
}
