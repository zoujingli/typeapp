<?php

declare(strict_types=1);

namespace Type\Mqtt;

use Closure;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/**
 * 一个有截止的持久 worker 及其精确后端清理；通过 Swoole PROC hook 的进程管道交换有界消息，不执行阻塞 PDO。
 * 未证明远端后端消失的结果 released=false，调用者必须保留该资源配额，不能无限重启。
 */
final class PendingCommit
{
    /** @var resource|null Swoole PROC hook 管理的独立命令进程。 */
    private mixed $process = null;
    /** @var array<int, resource> 子进程标准输入与合并输出；只关闭本实例持有的句柄。 */
    private array $pipes = [];
    private string $token = '';
    private string $input = '';
    private string $output = '';
    private string $serializedRequest;
    private bool $authenticated = false;
    private bool $killed = false;
    private bool $cleaning = false;
    private ?CommitResult $received = null;
    private ?CommitResult $result = null;
    private Deadline $deadline;
    public readonly string $operationId;

    /**
     * @param list<string> $command 显式编译应用命令，不经过 shell；该入口须识别 --store-worker-pipe。
     * @param array<string,mixed> $request 由 PostgresStore 定义的消息/交付操作；不包含连接凭据。
     * @throws \RuntimeException 无法取得本地 IPC 或进程资源。
     */
    public function __construct(private array $command, array $request, private float $seconds = 5.0)
    {
        if ($command === [] || !array_is_list($command) || !is_finite($seconds) || $seconds < 1.0 || $seconds > 10.0
            || !is_string($request['operation_id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $request['operation_id']) !== 1) {
            throw new \InvalidArgumentException('MQTT 持久 worker 命令或预算无效');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('MQTT 持久 worker 命令参数无效');
            }
        }
        $this->operationId = $request['operation_id'];
        CoroutineRuntime::enableIo();
        $this->serializedRequest = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($this->serializedRequest) > 2097152) {
            throw new ProtocolError(0x97);
        }
        CoroutineRuntime::run(function (): void {
            $this->launch();
        });
    }

    /** 返回空表示仍有本地工作；最终 released=false 表示远端资源无法证明回收，须隔离容量。 */
    public function poll(): ?CommitResult
    {
        return CoroutineRuntime::run(function (): ?CommitResult {
            return $this->pollCurrent();
        });
    }

    private function pollCurrent(): ?CommitResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        if (!$this->killed) {
            $this->exchange();
        }
        $running = $this->process !== null && proc_get_status($this->process)['running'];
        if (!$running) {
            // worker 正常退出时大响应仍可能留在内核接收缓冲；在同一截止内继续有界读取到完整结果或 EOF。
            if ($this->received === null && $this->process !== null && !$this->deadline->expired()) {
                return null;
            }
            $this->closePipe();
            if ($this->process !== null) {
                proc_close($this->process);
                $this->process = null;
            }
            if ($this->received !== null && $this->received->released && ($this->cleaning || $this->received->state !== 'unknown')) {
                $this->result = $this->received;
            } elseif ($this->cleaning) {
                $this->result = new CommitResult($this->operationId, 'unknown', 0x88, [], false);
            } else {
                $this->cleaning = true;
                try {
                    $this->launch();
                } catch (\Throwable) {
                    $this->result = new CommitResult($this->operationId, 'unknown', 0x88, [], false);
                }
            }
        } elseif ($this->deadline->expired() && !$this->killed) {
            // SyncRep 不检查客户端断线；杀本地 worker 后仍须专用连接执行 pg_terminate_backend。
            // 已收到的结果属于已完成操作；终止本地收尾不能撤销确认，下一轮仍须观察进程退出和 released。
            proc_terminate($this->process, SIGKILL);
            $this->killed = true;
            $this->closePipe();
        }
        return $this->result;
    }

    /** 缩短当前本地等待；已收到的确认不撤销，仍等待本地退出，未确认或未释放的远端工作继续精确清理。 */
    public function cancel(): void
    {
        $this->deadline->shorten(0.0);
    }

    private function launch(): void
    {
        $this->input = '';
        $this->output = '';
        $this->authenticated = false;
        $this->killed = false;
        $this->received = null;
        $this->token = bin2hex(random_bytes(32));
        $this->deadline = new Deadline($this->seconds);
        $command = [...$this->command, '--store-worker-pipe'];
        $environment = getenv();
        $environment['MQTT_WORKER_TOKEN'] = $this->token;
        $pipes = [];
        // Swoole 的 PROC hook 使用 exec 专用原生路径；不会在协程中 fork 后继续执行 PHP 回调。
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $environment);
        $this->pipes = $pipes;
        if (!is_resource($this->process)) {
            $this->process = null;
            $this->closePipe();
            throw new \RuntimeException('MQTT 持久 worker 启动失败');
        }
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    private function exchange(): void
    {
        if ($this->process === null || $this->pipes === []) {
            return;
        }
        $chunk = fread($this->pipes[1], 65536);
        if (is_string($chunk) && strlen($chunk) + strlen($this->input) > 2097152) {
            $this->cancel();
            return;
        }
        if (is_string($chunk) && $chunk !== '') {
            $this->input .= $chunk;
        }
        $newline = strpos($this->input, "\n");
        if ($newline !== false) {
            $line = substr($this->input, 0, $newline);
            $this->input = substr($this->input, $newline + 1);
            if (!$this->authenticated) {
                if (!hash_equals($this->token, $line)) {
                    $this->cancel();
                    $this->closePipe();
                    $this->input = '';
                    return;
                }
                $this->authenticated = true;
                $this->output = $this->cleaning
                    ? json_encode(['operation_id' => $this->operationId, 'action' => 'cleanup'], JSON_THROW_ON_ERROR) . "\n" : $this->serializedRequest;
            } else {
                try {
                    $data = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($data) || ($data['operation_id'] ?? '') !== $this->operationId
                        || !is_string($data['state'] ?? null) || !is_int($data['reason'] ?? null)
                        || !is_array($data['proof'] ?? null) || !is_bool($data['released'] ?? null) || !is_array($data['value'] ?? [])) {
                        throw new \RuntimeException('MQTT 持久 worker 响应格式无效');
                    }
                    $this->received = new CommitResult($this->operationId, $data['state'], $data['reason'], $data['proof'], $data['released'], $data['value'] ?? []);
                } catch (\Throwable) {
                    $this->cancel();
                }
            }
        }
        if ($this->output !== '') {
            $written = fwrite($this->pipes[0], substr($this->output, 0, 65536));
            if ($written === false || $written === 0) {
                $this->cancel();
            } else {
                $this->output = substr($this->output, $written);
            }
        }
    }

    private function closePipe(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        $this->input = '';
        $this->output = '';
    }

    /**
     * 显式应用 worker 入口；本方法拥有 IPC，存储实现拥有数据库租约，返回时二者均已释放。
     * 必须在隔离子进程运行；父进程负责硬截止和未知远端资源隔离。
     * @param (Closure(array<string,mixed>): CommitResult)|null $operation 应用显式绑定的业务执行入口；cleanup始终由存储精确清理。
     */
    public static function work(PostgresStore $store, string $endpoint, ?Closure $operation = null): void
    {
        if ($endpoint !== 'pipe') {
            throw new \InvalidArgumentException('MQTT 持久 worker 只接受 Swoole 管理的进程管道');
        }
        $token = (string) getenv('MQTT_WORKER_TOKEN');
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \RuntimeException('MQTT 持久 worker 缺少调用身份');
        }
        self::write(STDOUT, $token . "\n");
        $line = fgets(STDIN, 2097153);
        if (!is_string($line) || !str_ends_with($line, "\n")) {
            throw new \RuntimeException('MQTT 持久请求超过字节或等待预算');
        }
        $request = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($request) || !is_string($request['operation_id'] ?? null)) {
            throw new \RuntimeException('MQTT 持久请求身份无效');
        }
        $result = CoroutineRuntime::run(static function () use ($request, $store, $operation): CommitResult {
            $scope = new ExecutionScope();
            try {
                return $scope->run(static function (ExecutionScope $current) use ($request, $store, $operation): CommitResult {
                    if (($request['action'] ?? '') === 'cleanup') {
                        return new CommitResult($request['operation_id'], 'unknown', 0x88, [], $store->cleanup($request['operation_id']));
                    }
                    return $operation === null ? $store->execute($request) : $operation($request);
                });
            } finally {
                try {
                    $scope->close();
                } finally {
                    if ($scope->state() !== 'closed') {
                        $scope->awaitClosed();
                    }
                }
            }
        });
        self::write(STDOUT, json_encode($result->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @param resource $socket */
    private static function write(mixed $socket, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = @fwrite($socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('MQTT 持久 IPC 写入失败');
            }
            $offset += $written;
        }
    }
}
