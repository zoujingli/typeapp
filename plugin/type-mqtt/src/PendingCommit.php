<?php

declare(strict_types=1);

namespace Type\Mqtt;

use Closure;
use Type\Runtime\Deadline;

/**
 * 一个有截止的持久 worker 及其精确后端清理；网络循环只轮询回环 IPC，不执行阻塞 PDO。
 * 未证明远端后端消失的结果 released=false，调用者必须保留该资源配额，不能无限重启。
 */
final class PendingCommit
{
    private mixed $process = null;
    private mixed $listener = null;
    private mixed $socket = null;
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
     * @param list<string> $command 显式编译应用命令，不经过 shell；该入口须识别 --store-worker。
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
        $this->serializedRequest = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($this->serializedRequest) > 2097152) {
            throw new ProtocolError(0x97);
        }
        $this->launch();
    }

    /** 返回空表示仍有本地工作；最终 released=false 表示远端资源无法证明回收，须隔离容量。 */
    public function poll(): ?CommitResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        if (!$this->killed) {
            $this->exchange();
        }
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            // worker 正常退出时大响应仍可能留在内核接收缓冲；在同一截止内继续有界读取到完整结果或 EOF。
            if ($this->received === null && is_resource($this->socket) && !feof($this->socket) && !$this->deadline->expired()) {
                return null;
            }
            proc_close($this->process);
            $this->process = null;
            $this->closeStreams();
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
            proc_terminate($this->process, 9);
            $this->killed = true;
            $this->closeStreams();
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
        $errorNumber = 0;
        $errorMessage = '';
        $this->listener = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if ($this->listener === false || !stream_set_blocking($this->listener, false)) {
            $this->closeStreams();
            throw new \RuntimeException('MQTT 持久 IPC 监听失败');
        }
        $endpoint = stream_socket_get_name($this->listener, false);
        $environment = getenv();
        $environment['MQTT_WORKER_TOKEN'] = $this->token;
        $discard = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $this->process = @proc_open(
            [...$this->command, '--store-worker=' . $endpoint],
            [0 => ['file', $discard, 'r'], 1 => ['file', $discard, 'w'], 2 => ['file', $discard, 'w']],
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true, 'suppress_errors' => true]
        );
        if (!is_resource($this->process)) {
            $this->closeStreams();
            throw new \RuntimeException('MQTT 持久 worker 启动失败');
        }
    }

    private function exchange(): void
    {
        if (!is_resource($this->socket)) {
            $candidate = @stream_socket_accept($this->listener, 0);
            if ($candidate === false) {
                return;
            }
            stream_set_blocking($candidate, false);
            $this->socket = $candidate;
        }
        $chunk = @fread($this->socket, 65536);
        if ($chunk === false || strlen($this->input) + strlen($chunk) > 2097152) {
            $this->cancel();
            return;
        }
        $this->input .= $chunk;
        $newline = strpos($this->input, "\n");
        if ($newline !== false) {
            $line = substr($this->input, 0, $newline);
            $this->input = substr($this->input, $newline + 1);
            if (!$this->authenticated) {
                if (!hash_equals($this->token, $line)) {
                    fclose($this->socket);
                    $this->socket = null;
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
            $written = @fwrite($this->socket, $this->output, min(strlen($this->output), 65536));
            if ($written === false) {
                $this->cancel();
            } else {
                $this->output = substr($this->output, $written);
            }
        }
    }

    private function closeStreams(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
        $this->socket = null;
        $this->listener = null;
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
        if (preg_match('/^127\.0\.0\.1:[1-9][0-9]{0,4}$/D', $endpoint) !== 1) {
            throw new \InvalidArgumentException('MQTT 持久 worker 只接受明确回环端点');
        }
        $token = (string) getenv('MQTT_WORKER_TOKEN');
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \RuntimeException('MQTT 持久 worker 缺少调用身份');
        }
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client('tcp://' . $endpoint, $errorNumber, $errorMessage, 2.0);
        if ($socket === false) {
            throw new \RuntimeException('MQTT 持久 worker 无法连接调用方');
        }
        try {
            stream_set_timeout($socket, 8);
            self::write($socket, $token . "\n");
            $line = fgets($socket, 2097153);
            if (!is_string($line) || !str_ends_with($line, "\n")) {
                throw new \RuntimeException('MQTT 持久请求超过字节或等待预算');
            }
            $request = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($request) || !is_string($request['operation_id'] ?? null)) {
                throw new \RuntimeException('MQTT 持久请求身份无效');
            }
            if (($request['action'] ?? '') === 'cleanup') {
                $result = new CommitResult($request['operation_id'], 'unknown', 0x88, [], $store->cleanup($request['operation_id']));
            } else {
                $result = $operation === null ? $store->execute($request) : $operation($request);
            }
            self::write($socket, json_encode($result->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        } finally {
            fclose($socket);
        }
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
