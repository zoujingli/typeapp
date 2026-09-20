<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Type\Core\TcpSocket;
use Type\Runtime\CapacityException;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

/** 安装后公共接口上的真实 TCP/TLS 消费者；没有私有状态访问或原生 Socket 逃逸。 */
final class TcpProbe
{
    private static int $checks = 0;
    private static ?Throwable $failure = null;
    private static array $observations = [];

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        self::$checks++;
    }

    /** @param Closure(): mixed $operation */
    public static function rejected(Closure $operation, string $reason): void
    {
        try {
            $operation();
        } catch (TaskException $error) {
            self::check($error->errorCode() === $reason, $reason . ' / ' . $error->errorCode());
            return;
        } catch (CapacityException $error) {
            self::check($reason === 'capacity', '容量错误原因不符');
            return;
        } catch (RuntimeException $error) {
            self::check(str_contains($error->getMessage(), $reason), '拒绝原因不符：' . $error->getMessage());
            return;
        }
        throw new RuntimeException('应该拒绝：' . $reason);
    }

    public static function run(string $payload): int
    {
        $input = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        try {
            self::check(defined('SWOOLE_LIBRARY'), '工作线程未加载 Swoole 官方内置库');
            $foreign = unserialize(base64_decode($input['owner']), ['allowed_classes' => [ExecutionOwner::class]]);
            self::check($foreign instanceof ExecutionOwner, '缺少线程身份');
            if ($input['mode'] === 'thread') {
                self::rejected(static fn (): mixed => $foreign->assertCurrent(), '线程请求');
            }
            $outside = TcpSocket::client(new ResourceBudget(1), '127.0.0.1', 9);
            self::rejected(static fn (): mixed => $outside->start(), 'tcp_coroutine_required');
            $outside->stop();
            Coroutine::create(static function () use ($input): void {
                try {
                    self::check(defined('SWOOLE_LIBRARY') && class_exists('Swoole\\ConnectionPool', false), '首次协程缺少 Swoole 官方内置库');
                    $plan = new DeploymentBudget(24, 1, 1, 1, 0, 2);
                    self::check($plan->statistics()['per_thread'] === 6, '线程预算被复制');
                    $budget = $plan->poolBudget();
                    $peers = $input['peers'];
                    foreach (['socket_buffer_size' => null, 'open_ssl' => null, 'write_timeout' => 1.0] as $key => $value) {
                        self::rejected(static fn (): TcpSocket => TcpSocket::client($budget, '127.0.0.1', 9, 1024, [$key => $value]), 'tcp_invalid_configuration');
                    }
                    self::rejected(static fn (): TcpSocket => TcpSocket::client($budget, 'tcp://localhost', 9), 'tcp_invalid_configuration');
                    self::rejected(static fn (): TcpSocket => TcpSocket::listener($budget, 'localhost'), 'tcp_invalid_configuration');
                    self::rejected(static fn (): TcpSocket => TcpSocket::client($budget, 'localhost', 9, 1024, ['open_ssl' => true, 'ssl_verify_peer' => false]), 'tcp_invalid_configuration');
                    self::echo('127.0.0.1', $peers['echo4']['port'], [], $budget);
                    self::echo('::1', $peers['echo6']['port'], [], $budget);
                    self::echo('localhost', $peers['echo4']['port'], [], $budget);
                    self::echo('tcp.typeapp.test', $peers['echo4']['port'], [], $budget);
                    self::echo('localhost', $peers['tls']['port'], ['open_ssl' => true, 'ssl_cafile' => $peers['certificate']], $budget);
                    self::echo('127.0.0.1', $peers['tls']['port'], ['open_ssl' => true, 'ssl_cafile' => $peers['certificate']], $budget);
                    self::echo('::1', $peers['tls6']['port'], ['open_ssl' => true, 'ssl_cafile' => $peers['certificate']], $budget);
                    self::failures($peers, $budget);
                    self::duplex($peers, $budget);
                    self::stopDuplex($peers, $budget);
                    self::backpressure($peers, $budget);
                    self::server($peers, $budget, '127.0.0.1', false, false);
                    self::server($peers, $budget, '::1', false, false);
                    self::server($peers, $budget, '127.0.0.1', true, false);
                    self::server($peers, $budget, '::1', true, false);
                    self::server($peers, $budget, '127.0.0.1', true, true);
                    self::retirement($peers, $budget);
                    self::check($budget->statistics()['allocated'] === 0, '套件退出仍占有连接额度');
                } catch (Throwable $error) {
                    self::$failure = $error;
                }
            });
            Swoole\Event::wait();
            self::check(defined('SWOOLE_LIBRARY'), '协程退出时丢失 Swoole 官方内置库');
            if (self::$failure !== null) {
                throw self::$failure;
            }
            file_put_contents($input['output'], json_encode(['checks' => self::$checks, 'process' => getmypid(),
                'native_id' => Swoole\Thread::getNativeId(), 'coroutines' => Coroutine::stats()['coroutine_num'],
                'observations' => self::$observations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return 0;
        } catch (Throwable $error) {
            file_put_contents($input['output'] . '.failure', get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString());
            return 1;
        }
    }

    private static function echo(string $host, int $port, array $options, ResourceBudget $budget): void
    {
        $scope = new ExecutionScope();
        $socket = TcpSocket::client($budget, $host, $port, 1024, $options);
        try {
            $scope->open($socket);
            $socket->start();
            self::check($socket->addresses()['peer']['port'] === $port && $socket->addresses()['local']['port'] > 0, '连接地址无效');
            self::check($socket->send('') === 0, '空发送长度错误');
            foreach (["\0", "\x00\xff\x80\r\n", str_repeat('a', 1024)] as $data) {
                self::check($socket->send($data) === strlen($data), '提交长度不符');
                $received = '';
                while (strlen($received) < strlen($data)) {
                    $part = $socket->receive();
                    self::check($part !== '' && strlen($part) <= 1024, '分段或 EOF 错误');
                    $received .= $part;
                    self::check(strlen($received) <= strlen($data), '回声超长');
                }
                self::check($received === $data, '二进制回声损坏');
            }
            self::rejected(static fn (): int => $socket->send(str_repeat('x', 1025)), 'tcp_chunk_too_large');
            self::rejected(static fn (): string => $socket->receive(0), 'tcp_invalid_timeout');
            self::rejected(static fn (): string => $socket->receive(0.02), 'tcp_timeout');
            self::rejected(static fn (): string => serialize($socket), 'resource_transfer_forbidden');
            $socket->shutdownWrite();
            $socket->shutdownWrite();
            self::rejected(static fn (): int => $socket->send('x'), 'tcp_write_closed');
            self::check($socket->receive() === '' && $socket->receive() === '', '半关闭或 EOF 不稳定');
            $stats = $socket->statistics();
            self::check($stats['read_closed'] && $stats['write_closed'] && $stats['queued_bytes'] === 0, '半关闭状态或队列错误');
            self::check($stats['received_bytes'] === 1030 && $stats['sent_bytes'] === 1030, '字节计数错误');
        } finally {
            $scope->close();
            self::closed($socket);
        }
    }

    private static function failures(array $peers, ResourceBudget $budget): void
    {
        foreach ([['open_ssl' => true, 'ssl_cafile' => $peers['certificate'], 'ssl_host_name' => 'wrong.invalid'],
            ['open_ssl' => true, 'ssl_cafile' => $peers['certificate'], 'ssl_host_name' => '127.0.0.2'],
            ['open_ssl' => true, 'ssl_cafile' => $peers['certificate'], 'ssl_host_name' => '::2'],
            ['open_ssl' => true]] as $options) {
            $client = TcpSocket::client($budget, '127.0.0.1', $peers['tls']['port'], 1024, $options);
            self::rejected(static fn (): mixed => $client->start(0.5), 'tcp_connect_failed');
            self::closed($client);
        }
        foreach (['nxdomain' => 'tcp_connect_failed', 'slow' => 'tcp_timeout', 'late' => 'tcp_timeout'] as $name => $reason) {
            $dns = TcpSocket::client($budget, $name . '.typeapp.test', $peers['echo4']['port']);
            $began = hrtime(true);
            self::rejected(static fn (): mixed => $dns->start(0.05), $reason);
            self::closed($dns);
            self::check((hrtime(true) - $began) / 1000000000.0 < 0.5, 'DNS 越过合计截止');
        }
        $slow = TcpSocket::client($budget, '127.0.0.1', $peers['idle']['port'], 1024, ['open_ssl' => true, 'ssl_cafile' => $peers['certificate']]);
        $progress = new Channel(1);
        Coroutine::create(static function () use ($peers, $budget, $progress): void {
            try {
                self::echo('127.0.0.1', $peers['echo4']['port'], [], $budget);
                $progress->push(true);
            } catch (Throwable $error) {
                $progress->push($error);
            }
        });
        $began = hrtime(true);
        self::rejected(static fn (): mixed => $slow->start(0.15), 'tcp_timeout');
        self::check($progress->pop(1) === true, '慢握手阻塞独立连接');
        self::closed($slow);
        self::$observations['slow_client_handshake_seconds'] = (hrtime(true) - $began) / 1000000000.0;
        $stopped = TcpSocket::client($budget, '127.0.0.1', $peers['idle']['port'], 1024, ['open_ssl' => true]);
        Swoole\Timer::after(20, static function () use ($stopped): void {
            $stopped->stop();
        });
        self::rejected(static fn (): mixed => $stopped->start(1), 'tcp_stopped');
        self::closed($stopped);
        $reset = TcpSocket::client($budget, '127.0.0.1', $peers['reset']['port']);
        try {
            $reset->start();
            $reset->send('reset');
            self::rejected(static fn (): string => $reset->receive(), 'tcp_receive_failed');
        } finally {
            $reset->stop();
            self::closed($reset);
        }
    }

    private static function duplex(array $peers, ResourceBudget $budget): void
    {
        $socket = TcpSocket::client($budget, '127.0.0.1', $peers['echo4']['port'], 1024);
        $socket->start();
        try {
            $done = new Channel(1);
            $reader = Coroutine::create(static function () use ($socket, $done): void {
                try {
                    $done->push($socket->receive());
                } catch (Throwable $error) {
                    $done->push($error);
                }
            });
            self::check($socket->statistics()['reading_bytes'] === 1024, '读取没有挂起或缓冲未计入');
            self::rejected(static fn (): string => $socket->receive(), 'tcp_operation_active');
            $socket->send('duplex');
            self::check($done->pop(1) === 'duplex', '同线程一读一写没有并行');
            $reader = Coroutine::create(static function () use ($socket, $done): void {
                try {
                    self::rejected(static fn (): string => $socket->receive(), 'tcp_cancelled');
                    $done->push(true);
                } catch (Throwable $error) {
                    $done->push($error);
                }
            });
            self::check(Coroutine::cancel($reader), '原生读取取消失败');
            self::check($done->pop(1) === true, '取消读取没有返回');
            $socket->send('after-cancel');
            self::check($socket->receive() === 'after-cancel', '短取消损坏字节流');
            Coroutine::create(static function () use ($socket, $done): void {
                try {
                    self::rejected(static fn (): string => $socket->receive(), 'tcp_stopped');
                    // 仍在 stop 的原生取消调用栈内，不能已归还额度。
                    self::check($socket->statistics()['allocated'], '取消返回前提前结算');
                    $socket->stop();
                    $done->push(true);
                } catch (Throwable $error) {
                    $done->push($error);
                }
            });
            $socket->stop();
            self::check($done->pop(1) === true && $socket->statistics()['state'] === 'stopping', '停止重入状态错误');
        } finally {
            $socket->stop();
            self::closed($socket);
        }
    }

    private static function backpressure(array $peers, ResourceBudget $budget): void
    {
        $socket = TcpSocket::client($budget, '127.0.0.1', $peers['idle']['port'], 1048576);
        $socket->start();
        $done = new Channel(1);
        Coroutine::create(static function () use ($socket, $done, $peers, $budget): void {
            Coroutine::sleep(0.02);
            try {
                self::check($socket->statistics()['writing_bytes'] === 1048576, '背压没有持有有界待发片段');
                self::rejected(static fn (): int => $socket->send('overlap'), 'tcp_operation_active');
                self::echo('127.0.0.1', $peers['echo4']['port'], [], $budget);
                $done->push(true);
            } catch (Throwable $error) {
                $done->push($error);
            }
        });
        $written = 0;
        $began = hrtime(true);
        try {
            for ($attempt = 0; $attempt < 32; $attempt++) {
                $written += $socket->send(str_repeat('p', 1048576), 0.15);
            }
            throw new RuntimeException('独立慢对端未产生背压');
        } catch (TaskException $error) {
            self::check(in_array($error->errorCode(), ['tcp_timeout', 'tcp_partial_write'], true), '背压错误原因不符');
            self::check($done->pop(1) === true, '背压期间独立连接或重复写检查失败');
            self::$observations['backpressure'] = ['completed_bytes' => $written, 'reason' => $error->errorCode(),
                'seconds' => (hrtime(true) - $began) / 1000000000.0, 'stats' => $socket->statistics()];
        } finally {
            $socket->stop();
            self::closed($socket);
        }
    }

    private static function stopDuplex(array $peers, ResourceBudget $budget): void
    {
        $socket = TcpSocket::client($budget, '127.0.0.1', $peers['idle']['port'], 1048576);
        $socket->start();
        self::rejected(static fn (): mixed => $socket->awaitClosed(), 'tcp_not_stopped');
        $done = new Channel(2);
        Coroutine::create(static function () use ($socket, $done): void {
            try {
                self::rejected(static fn (): string => $socket->receive(), 'tcp_stopped');
                $done->push(true);
            } catch (Throwable $error) {
                $done->push($error);
            }
        });
        Coroutine::create(static function () use ($socket, $done): void {
            try {
                self::rejected(static fn (): int => $socket->send(str_repeat('w', 1048576)), 'tcp_stopped');
                self::check($socket->statistics()['allocated'], '写取消退出前提前归还额度');
                $socket->stop();
                $done->push(true);
            } catch (Throwable $error) {
                $done->push($error);
            }
        });
        try {
            self::check($socket->statistics()['in_flight'] === 2, '未同时持有两个真实等待方向');
            $socket->stop();
            self::check($done->pop(1) === true && $done->pop(1) === true, '停止没有唤醒读写两端');
            self::check($socket->statistics()['state'] === 'stopping' && $socket->statistics()['allocated'], '两端退出后仍须等待原生 FD 关闭');
        } finally {
            $socket->stop();
            self::closed($socket);
        }
    }

    private static function server(array $peers, ResourceBudget $budget, string $host, bool $tls, bool $slow): void
    {
        $scope = new ExecutionScope();
        $options = $tls ? ['open_ssl' => true, 'ssl_cert_file' => $peers['certificate'], 'ssl_key_file' => $peers['key']] : [];
        $listener = TcpSocket::listener($budget, $host, 0, 1024, $options);
        $control = TcpSocket::client($budget, '127.0.0.1', $peers['control']['port'], 1024);
        $slowDone = new Channel(1);
        try {
            $scope->open($listener);
            $port = $listener->addresses()['local']['port'];
            $conflict = TcpSocket::listener($budget, $host, $port);
            self::rejected(static fn (): mixed => $conflict->start(), 'tcp_listen_failed');
            self::closed($conflict);
            $scope->open($control);
            $control->send(json_encode(['host' => $host, 'port' => $port, 'tls' => $tls, 'slow' => $slow], JSON_THROW_ON_ERROR) . "\n");
            if ($slow) {
                $pending = $listener->accept();
                self::check($pending->statistics()['state'] === 'accepted', 'accept 提前阻塞 TLS 握手');
                $scope->spawn(static function (ExecutionScope $childScope) use ($pending, $slowDone): void {
                    try {
                        self::rejected(static fn (): mixed => $pending->start(0.15), 'tcp_timeout');
                        $slowDone->push(true);
                    } finally {
                        $pending->stop();
                        $pending->awaitClosed();
                    }
                });
            }
            $connection = $listener->accept();
            self::check($connection->statistics()['allocated'], '接入未提前预留额度');
            $task = $scope->spawn(static function (ExecutionScope $childScope) use ($connection): void {
                $childScope->open($connection);
                $total = 0;
                while (($part = $connection->receive()) !== '') {
                    self::check(strlen($part) <= 1024, '服务端接收片段超限');
                    $total += strlen($part);
                    self::check($total <= 4096, '测试请求总长越界');
                    $connection->send($part);
                }
                self::check($total === 4096, '独立客户端数据没有收全');
                $connection->shutdownWrite();
            });
            $acknowledgement = json_decode($control->receive(), true, 512, JSON_THROW_ON_ERROR);
            self::check($acknowledgement === ['ok' => true, 'bytes' => 4096], '独立标准客户端未验证服务端回声');
            if ($slow) {
                self::check($pending->statistics()['state'] === 'starting', '慢握手阻塞了后续独立 TLS 连接');
                self::check($slowDone->pop(1) === true, '服务端慢握手没有按期退出');
                self::$observations['slow_server_handshake_fast_peer_completed'] = true;
            }
        } finally {
            $scope->close();
            self::closed($listener);
            self::closed($control);
        }
        self::check($budget->statistics()['allocated'] === 0, '服务作用域退役后仍占额度');
    }

    private static function retirement(array $peers, ResourceBudget $budget): void
    {
        $tiny = new ResourceBudget(1);
        $full = TcpSocket::listener($tiny, '127.0.0.1');
        $full->start();
        self::rejected(static fn (): TcpSocket => $full->accept(), 'capacity');
        self::check($full->statistics()['in_flight'] === 0 && $tiny->statistics()['allocated'] === 1, '容量拒绝泄漏接入槽');
        $full->stop();
        self::closed($full);
        $listener = TcpSocket::listener($budget, '127.0.0.1');
        $listener->start();
        $port = $listener->addresses()['local']['port'];
        $done = new Channel(1);
        Coroutine::create(static function () use ($listener, $done): void {
            try {
                self::rejected(static fn (): TcpSocket => $listener->accept(), 'tcp_stopped');
                $done->push(true);
            } catch (Throwable $error) {
                $done->push($error);
            }
        });
        self::check($budget->statistics()['allocated'] === 2, '等待 accept 未预留连接额度');
        $listener->stop();
        self::check($done->pop(1) === true, '监听停止未取消 accept');
        self::check($budget->statistics()['allocated'] === 1, '监听 defer 前提前归还额度');
        self::closed($listener);
        $replacement = TcpSocket::listener($budget, '127.0.0.1', $port);
        $replacement->start();
        $client = TcpSocket::client($budget, '127.0.0.1', $port);
        $client->start();
        $accepted = $replacement->accept();
        // 模拟作用域任务拒绝/交接失败：未运行 TLS/PHP 回调，已接入 FD 仍须完整回收。
        $accepted->stop();
        self::closed($accepted);
        self::check($client->receive() === '', '接入交接失败没有关闭独立连接');
        $client->stop();
        self::closed($client);
        $replacement->stop();
        self::closed($replacement);
        $refused = TcpSocket::client($budget, '127.0.0.1', $port);
        self::rejected(static fn (): mixed => $refused->start(0.2), 'tcp_connect_failed');
        self::closed($refused);
        self::check($budget->statistics()['allocated'] === 0, '线程退役/监听重用未归还全部额度');
    }

    private static function closed(TcpSocket $socket): void
    {
        $socket->awaitClosed(1);
        self::check($socket->statistics()['state'] === 'closed' && !$socket->statistics()['allocated'], '原生关闭未完成');
        $socket->stop();
        self::rejected(static fn (): mixed => $socket->start(), 'tcp_stopped');
    }
}

function main(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === 'example') {
        tcpExampleMain($argc - 1, array_slice($argv, 1));
        return;
    }
    CoroutineRuntime::enableIo();
    $input = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $mode = $argv[2];
    $root = $argv[3];
    // 固定上游 c-ares 支持此标准设置；只改变本测试进程，不修改系统 DNS 或主机网络。
    Coroutine::set(['dns_server' => '127.0.0.1:' . $input['dns']['port'], 'log_file' => $root . '/native.log']);
    $owner = base64_encode(serialize(new ExecutionOwner(false)));
    $main = Swoole\Thread::getNativeId();
    $exits = [];
    if ($mode === 'thread') {
        $threads = [];
        foreach (['left', 'right'] as $role) {
            $threads[] = CoroutineRuntime::startThread('tcp', json_encode(['mode' => $mode, 'peers' => $input,
                'owner' => $owner, 'output' => $root . '/' . $role . '.json'], JSON_THROW_ON_ERROR));
        }
        foreach ($threads as $thread) {
            TcpProbe::check($thread->join(), '工作线程未 join');
            $exits[] = $thread->getExitStatus();
        }
    } else {
        $exits[] = TcpProbe::run(json_encode(['mode' => $mode, 'peers' => $input, 'owner' => $owner,
            'output' => $root . '/main.json'], JSON_THROW_ON_ERROR));
    }
    echo json_encode(['process' => getmypid(), 'main_thread' => $main, 'active_threads' => Swoole\Thread::activeCount(),
        'exits' => $exits], JSON_THROW_ON_ERROR) . "\n";
    if (array_sum($exits) !== 0) {
        throw new RuntimeException('TCP 消费者失败，见线程 failure 文件');
    }
}
