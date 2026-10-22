<?php

declare(strict_types=1);

namespace Type\Mqtt;

use Swoole\Coroutine\Socket;

/**
 * @internal Windows 隔离 worker 的本机 IPC；Swoole 拥有收发等待，调用者拥有进程、硬截止和业务结果。
 * 每次只接受一个连接，使用一次性环境密钥相互验证，认证前不交换业务请求或凭据。
 */
final class WorkerSocket
{
    private ?Socket $listener = null;
    private ?Socket $connection = null;
    private string $identity = '';
    private string $acknowledgement = '';
    private bool $verified = false;
    private bool $authenticated = false;
    private int $port;

    /** 创建仅绑定 IPv4 回环的短生命周期入口；只能在所属业务协程内调用。 */
    public function __construct(private string $token)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('MQTT worker IPC 身份无效');
        }
        $this->listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        if (!$this->listener->bind('127.0.0.1', 0) || !$this->listener->listen(1)) {
            $this->close();
            throw new \RuntimeException('MQTT worker IPC 监听失败');
        }
        $address = $this->listener->getsockname();
        if (!is_array($address) || !is_int($address['port'] ?? null) || $address['port'] < 1 || $address['port'] > 65535) {
            $this->close();
            throw new \RuntimeException('MQTT worker IPC 地址无效');
        }
        $this->port = $address['port'];
    }

    /** @return array<string,string> 只传给本次子进程，不放入命令参数或日志。 */
    public function environment(): array
    {
        return ['MQTT_WORKER_PORT' => (string) $this->port, 'MQTT_WORKER_TOKEN' => $this->token];
    }

    /** 父进程是否已核对双方身份并发完确认；业务请求只能在此后发送。 */
    public function authenticated(): bool
    {
        return $this->authenticated;
    }

    /** 有限等待一次数据；空值交由现有调用者在原操作截止内继续观察。 */
    public function read(int $length, float $seconds): string|false
    {
        if (!$this->ready($seconds) || $this->connection === null) {
            return false;
        }
        return $this->connection->recv($length, $seconds);
    }

    /** 有限发送一个块；暂未认证或本次等待到期返回 0，其他错误返回 false。 */
    public function write(string $bytes, float $seconds): int|false
    {
        if (!$this->ready($seconds) || $this->connection === null) {
            return 0;
        }
        $written = $this->connection->send($bytes, $seconds);
        return $written === false && $this->connection->errCode === SOCKET_ETIMEDOUT ? 0 : $written;
    }

    /** 释放本实例持有的本地描述符，不推断数据库操作是否提交或远端后端是否退出。 */
    public function close(): void
    {
        $this->connection?->close();
        $this->listener?->close();
        $this->connection = null;
        $this->listener = null;
        $this->identity = '';
        $this->acknowledgement = '';
        $this->verified = false;
        $this->authenticated = false;
    }

    /** 子进程只连接父进程传入的回环端口；双向证明采用不同标签，密钥本身不经过网络。 */
    public static function connect(): Socket
    {
        $port = (string) getenv('MQTT_WORKER_PORT');
        $token = (string) getenv('MQTT_WORKER_TOKEN');
        if (preg_match('/^[1-9][0-9]{0,4}$/D', $port) !== 1 || (int) $port > 65535
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \RuntimeException('MQTT worker IPC 启动身份无效');
        }
        $socket = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        try {
            $proof = self::proof('worker', $token);
            if (!$socket->connect('127.0.0.1', (int) $port, 1.0) || $socket->sendAll($proof, 1.0) !== strlen($proof)) {
                throw new \RuntimeException('MQTT worker IPC 连接失败');
            }
            $response = $socket->recvLine(65, 1.0);
            if (!is_string($response) || !hash_equals(self::proof('parent', $token), $response)) {
                throw new \RuntimeException('MQTT worker IPC 父进程身份无效');
            }
            return $socket;
        } catch (\Throwable $error) {
            $socket->close();
            throw $error;
        }
    }

    /** 一次只推进有界握手；外层操作截止继续约束不发送完整身份的连接。 */
    private function ready(float $seconds): bool
    {
        if ($seconds <= 0.0 || !is_finite($seconds) || $seconds > 0.01) {
            throw new \InvalidArgumentException('MQTT worker IPC 等待预算无效');
        }
        if ($this->authenticated) {
            return true;
        }
        if ($this->listener === null) {
            return false;
        }
        if ($this->connection === null) {
            $accepted = $this->listener->accept($seconds);
            if ($accepted === false) {
                return false;
            }
            $this->connection = $accepted;
        }
        if (!$this->verified) {
            $chunk = $this->connection->recv(65 - strlen($this->identity), $seconds);
            if ($chunk === false) {
                return false;
            }
            $this->identity .= $chunk;
            if ($chunk !== '' && strlen($this->identity) < 65) {
                return false;
            }
            if (!hash_equals(self::proof('worker', $this->token), $this->identity)) {
                $this->connection->close();
                $this->connection = null;
                $this->identity = '';
                return false;
            }
            $this->verified = true;
            $this->identity = '';
            $this->acknowledgement = self::proof('parent', $this->token);
        }
        $written = $this->connection->send($this->acknowledgement, $seconds);
        if ($written === false || $written === 0) {
            return false;
        }
        $this->acknowledgement = substr($this->acknowledgement, $written);
        if ($this->acknowledgement !== '') {
            return false;
        }
        $this->authenticated = true;
        $this->listener->close();
        $this->listener = null;
        return true;
    }

    private static function proof(string $role, string $token): string
    {
        return hash_hmac('sha256', 'typeapp-worker-' . $role, $token) . "\n";
    }
}
