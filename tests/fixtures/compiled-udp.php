<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Type\Core\UdpSocket;
use Type\Runtime\CapacityException;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

/** 从安装组件的公共入口观察 UDP，不导出 Socket 或读取私有状态。 */
final class UdpProbe
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
            self::check(!defined('SWOOLE_LIBRARY'), '业务线程启动时加载了 PHP 内置库');
            $foreign = unserialize(base64_decode($input['owner']), ['allowed_classes' => [ExecutionOwner::class]]);
            self::check($foreign instanceof ExecutionOwner, '缺少线程归属证明');
            if ($input['mode'] === 'thread') {
                self::rejected(static fn (): mixed => $foreign->assertCurrent(), '线程请求');
            }
            $outside = new UdpSocket(new ResourceBudget(1), '127.0.0.1');
            self::rejected(static fn (): mixed => $outside->start(), 'udp_coroutine_required');
            $outside->stop();
            self::rejected(static fn (): UdpSocket => new UdpSocket(new ResourceBudget(1), '127.0.0.1', 0, 8192, ['socket_buffer_size' => null]), 'udp_invalid_configuration');
            self::rejected(static fn (): UdpSocket => new UdpSocket(new ResourceBudget(1), '127.0.0.1', 0, 8192, ['write_timeout' => null]), 'udp_invalid_configuration');
            Coroutine::create(static function () use ($input): void {
                try {
                    self::check(!defined('SWOOLE_LIBRARY') && !class_exists('Swoole\\ConnectionPool', false), '原生协程偷偷解释了 PHP 内置库');
                    $plan = new DeploymentBudget(8, 1, 1, 1, 0, 2);
                    self::check($plan->statistics()['per_thread'] === 2, '部署额度被线程复制');
                    foreach (['udp4' => '127.0.0.1', 'udp6' => '::1'] as $kind => $host) {
                        self::packets($host, $input['peers'][$kind]['port'], $plan->poolBudget());
                        self::cancellation($host, $input['peers'][$kind]['port']);
                        self::stopWhileWaiting($host, $input['peers'][$kind]['port']);
                    }
                    self::check($plan->poolBudget()->statistics()['allocated'] === 0, '套件退出额度不为零');
                } catch (Throwable $error) {
                    self::$failure = $error;
                }
            });
            Swoole\Event::wait();
            self::check(!defined('SWOOLE_LIBRARY'), '协程关闭时加载了 PHP 内置库');
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

    private static function packets(string $host, int $port, ResourceBudget $budget): void
    {
        $scope = new ExecutionScope();
        $socket = new UdpSocket($budget, $host, 0, 1024, ['write_timeout' => 0.125]);
        try {
            $scope->open($socket);
            $socket->start();
            $local = $socket->localAddress();
            self::check(abs($socket->statistics()['write_timeout'] - 0.125) < 0.000002, 'UDP 原生发送期限没有生效');
            self::check($local['address'] === $host && $local['port'] > 0, '本地绑定地址无效');
            foreach (['', "\0", "\x00\xff\x80\r\n", str_repeat('a', 1024)] as $data) {
                self::check($socket->sendTo($host, $port, $data) === strlen($data), '发送长度不符');
                $packet = $socket->receive();
                self::check($packet === ['data' => $data, 'address' => $host, 'port' => $port], '边界、数据或来源不符');
            }
            self::rejected(static fn (): int => $socket->sendTo($host, $port, str_repeat('x', 1025)), 'udp_datagram_too_large');
            self::rejected(static fn (): int => $socket->sendTo('localhost', $port, ''), 'udp_invalid_peer');
            self::rejected(static fn (): int => $socket->sendTo($host === '::1' ? '127.0.0.1' : '::1', $port, ''), 'udp_invalid_peer');
            self::rejected(static fn (): array => $socket->receive(0), 'udp_invalid_timeout');
            self::rejected(static fn (): array => $socket->receive(0.02), 'udp_timeout');
            $socket->sendTo($host, $port, '@oversize');
            self::rejected(static fn (): array => $socket->receive(), 'udp_datagram_too_large');
            self::check($socket->receive()['data'] === 'after-oversize', '超长报文污染下一报文');
            self::rejected(static fn (): string => serialize($socket), 'resource_transfer_forbidden');
            $foreign = new Channel(1);
            Coroutine::create(static function () use ($socket, $foreign): void {
                try {
                    self::rejected(static fn (): array => $socket->receive(), '执行者');
                    $foreign->push(true);
                } catch (Throwable $error) {
                    $foreign->push($error);
                }
            });
            self::check($foreign->pop(1) === true, '跨协程误用未拒绝');
            $conflict = new UdpSocket($budget, $host, $local['port']);
            self::rejected(static fn (): mixed => $conflict->start(), 'udp_bind_failed');
            $conflict->stop();
            self::settleCloses();
            self::check($budget->statistics()['allocated'] === 1, '绑定失败没有归还额度');
            $full = new UdpSocket($budget, $host);
            $scope->open($full);
            $overflow = new UdpSocket($budget, $host);
            self::rejected(static fn (): mixed => $scope->open($overflow), 'capacity');
            self::check($budget->statistics()['allocated'] === 2, '容量拒绝改变已分配额度');
            $full->stop();
            $overflow->stop();
            self::settleCloses();
            if ($host === '127.0.0.1') {
                self::rejected(static fn (): int => $socket->sendTo('255.255.255.255', $port, 'x'), 'udp_send_failed');
            }
            // 由另一个本地端点告知独立对端，主动向此监听发送，验证服务端入口。
            $control = new UdpSocket($budget, $host);
            $scope->open($control);
            $control->sendTo($host, $port, '@server:' . $local['port']);
            $request = $socket->receive();
            self::check($request['data'] === 'server-request' && $request['port'] === $port, '服务端未接收独立来源');
            $socket->sendTo($request['address'], $request['port'], $request['data']);
            self::check($socket->receive()['data'] === 'server-request', '服务端回包失败');
            $control->stop();
            self::settleCloses();
            $socket->sendTo($host, $port, '@burst');
            Coroutine::sleep(0.04);
            $seen = [];
            while (true) {
                try {
                    $packet = $socket->receive(0.04);
                } catch (TaskException $error) {
                    self::check($error->errorCode() === 'udp_timeout', '突发结束不能伪装成功');
                    break;
                }
                self::check(strlen($packet['data']) === 1024 && substr($packet['data'], 4) === str_repeat('b', 1020), '突发报文被合并或截断');
                $id = unpack('Nid', substr($packet['data'], 0, 4))['id'];
                self::check($id >= 0 && $id < 256 && !isset($seen[$id]), '突发编号或重复计数不符');
                $seen[$id] = true;
            }
            self::check(count($seen) > 0 && count($seen) <= 256, '突发没有收到有界完整报文');
            self::$observations[$host . '-burst'] = ['sent_by_peer' => 256, 'received' => count($seen), 'stats' => $socket->statistics()];
            $socket->stop();
            self::settleCloses();
            $large = new UdpSocket($budget, $host, 0, 65507, ['socket_buffer_size' => 262144, 'write_timeout' => 1.0]);
            $scope->open($large);
            $maximum = str_repeat('m', 65507);
            self::check($large->sendTo($host, $port, $maximum) === 65507 && $large->receive()['data'] === $maximum, '最大普通报文不完整');
            self::rejected(static fn (): int => $large->sendTo($host, $port, $maximum . 'x'), 'udp_datagram_too_large');
        } finally {
            $scope->close();
            self::settleCloses();
        }
        self::check($budget->statistics()['allocated'] === 0, '作用域没有释放全部端点');
        self::rejected(static fn (): mixed => $socket->start(), 'udp_stopped');
    }

    private static function cancellation(string $host, int $port): void
    {
        $socket = new UdpSocket(new ResourceBudget(1), $host);
        $socket->start();
        $cid = Coroutine::getCid();
        $signal = new Channel(1);
        try {
            Coroutine::create(static function () use ($cid, $signal): void {
                Coroutine::sleep(0.03);
                $signal->push(true);
                Coroutine::cancel($cid);
            });
            self::rejected(static fn (): array => $socket->receive(1), 'udp_cancelled');
            self::check($signal->pop(0.1) === true, 'UDP 等待阻塞了独立协程');
            self::check($socket->statistics()['in_flight'] === 0, '取消后仍有在途登记');
            $socket->sendTo($host, $port, 'after-cancel');
            self::check($socket->receive()['data'] === 'after-cancel', '取消后迟到完成污染下一接收');
        } finally {
            $socket->stop();
            self::settleCloses();
        }
    }

    private static function stopWhileWaiting(string $host, int $port): void
    {
        $budget = new ResourceBudget(1);
        $ready = new Channel(1);
        $finished = new Channel(1);
        Coroutine::create(static function () use ($host, $budget, $ready, $finished): void {
            $socket = new UdpSocket($budget, $host);
            try {
                $socket->start();
                $ready->push(['socket' => $socket, 'local' => $socket->localAddress()]);
                self::rejected(static fn (): array => $socket->receive(), 'udp_stopped');
                // 此时仍在控制方的 native cancel 栈内；不得提前释放最后引用或额度。
                self::check($budget->statistics()['allocated'] === 1, '取消栈内提前归还额度');
                $socket->stop();
                $replacement = new UdpSocket($budget, $host);
                self::rejected(static fn (): mixed => $replacement->start(), 'capacity');
                $replacement->stop();
                $finished->push(true);
            } catch (Throwable $error) {
                $finished->push($error);
            } finally {
                $socket->stop();
            }
        });
        $running = $ready->pop(1);
        self::check(is_array($running) && $running['socket']->statistics()['in_flight'] === 1, '停止前没有真实接收等待');
        $running['socket']->stop();
        $completion = $finished->pop(1);
        if ($completion instanceof Throwable) {
            throw $completion;
        }
        self::check($budget->statistics()['allocated'] === 1 && $running['socket']->statistics()['state'] === 'stopping', '原生延迟关闭前提前归还额度');
        $premature = new UdpSocket($budget, $host);
        self::rejected(static fn (): mixed => $premature->start(), 'capacity');
        $premature->stop();
        self::settleCloses();
        self::check($completion === true && $budget->statistics()['allocated'] === 0, '停止后操作或额度悬挂');
        self::check($running['socket']->statistics()['state'] === 'closed', '停止没有完整关闭');
        $running['socket']->stop();
        $replacement = new UdpSocket($budget, $host, $running['local']['port']);
        try {
            $replacement->start();
            $replacement->sendTo($host, $port, 'replacement');
            self::check($replacement->receive()['data'] === 'replacement', '关闭后原端口不能重用');
        } finally {
            $replacement->stop();
            self::settleCloses();
        }
        self::check($budget->statistics()['allocated'] === 0, '替代代次没有归还额度');
    }

    /** 观察原生延迟关闭之后的状态，不用定时休眠猜测 FD 已释放。 */
    private static function settleCloses(): void
    {
        $completed = new Channel(1);
        Swoole\Event::defer(static function () use ($completed): void {
            $completed->push(true);
        });
        self::check($completed->pop(1) === true, '原生关闭回调未完成');
    }
}

function main(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === 'example') {
        udpExampleMain($argc - 1, array_slice($argv, 1));
        return;
    }
    CoroutineRuntime::enableIo();
    $input = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $mode = $argv[2];
    $root = $argv[3];
    $owner = base64_encode(serialize(new ExecutionOwner(false)));
    $main = Swoole\Thread::getNativeId();
    $exits = [];
    if ($mode === 'thread') {
        $threads = [];
        foreach (['left', 'right'] as $role) {
            $threads[] = CoroutineRuntime::startThread('udp', json_encode(['mode' => $mode, 'peers' => $input,
                'owner' => $owner, 'output' => $root . '/' . $role . '.json'], JSON_THROW_ON_ERROR));
        }
        foreach ($threads as $thread) {
            UdpProbe::check($thread->join(), '业务线程未 join');
            $exits[] = $thread->getExitStatus();
        }
    } else {
        $exits[] = UdpProbe::run(json_encode(['mode' => $mode, 'peers' => $input, 'owner' => $owner,
            'output' => $root . '/main.json'], JSON_THROW_ON_ERROR));
    }
    echo json_encode(['process' => getmypid(), 'main_thread' => $main, 'active_threads' => Swoole\Thread::activeCount(),
        'exits' => $exits], JSON_THROW_ON_ERROR) . "\n";
    if (array_sum($exits) !== 0) {
        throw new RuntimeException('UDP 消费者失败，见对应线程 failure 文件');
    }
}
