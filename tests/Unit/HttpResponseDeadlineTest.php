<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\Socket;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\ResponseEmitter;
use Type\Runtime\Cancellation;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 通过真实挂起检查资源清理是否被已经结束的响应错误取消。 */
final class ResponseDeadlineCleanup implements ManagedResource
{
    public float $elapsed = 0.0;
    public bool $completed = false;

    /** 不分配外部资源，只登记后续真实挂起的清理阶段。 */
    public function start(): void
    {
    }

    /** 清理需要独立完成一次原生等待，取消或提前返回均由调用者观察。 */
    public function stop(): void
    {
        $started = hrtime(true);
        $this->completed = Coroutine::sleep(0.05);
        $this->elapsed = (hrtime(true) - $started) / 1000000000.0;
    }
}

/** 独立 TCP 客户端停止读取后，直接观察 HTTP 发送、作用域及原生收尾结果。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HttpResponseDeadlineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('需要实际 Swoole 验证 HTTP 背压截止');
        }
    }

    /** 等待发送期间缩短同一个 Deadline，必须在客户端断开前使响应退出。 */
    public function testShortenedDeadlineInterruptsTheExistingWrite(): void
    {
        $record = $this->sendToStalledClient('shorten');
        self::assertSame('', $record['failure']);
        self::assertSame('closed', $record['state']);
        self::assertLessThan(0.3, $record['elapsed']);
    }

    /** 通知快照可能仍持有响应监听；发送结束后不得再次取消清理阶段的等待。 */
    public function testExpiredResponseObserverDoesNotCancelFollowingCleanup(): void
    {
        $record = $this->sendToStalledClient('cleanup');
        self::assertSame('', $record['failure']);
        self::assertTrue($record['cleanup_completed']);
        self::assertGreaterThanOrEqual(0.04, $record['cleanup_elapsed']);
        self::assertSame('closed', $record['state']);
    }

    /** 响应自己的截止不能取消应用共享的外部取消对象或另一独立作用域。 */
    public function testExternalCancellationOwnershipSurvivesResponseTimeout(): void
    {
        $record = $this->sendToStalledClient('external');
        self::assertSame('', $record['failure']);
        self::assertFalse($record['cancelled_before_close']);
        self::assertTrue($record['other_scope_active']);
        self::assertSame('closed', $record['state']);
    }

    /** @return array<string, mixed> 客户端保持连接时服务端已完成的发送与清理结果。 */
    private function sendToStalledClient(string $mode): array
    {
        $directory = dirname(__DIR__, 2) . '/build/http-response-deadline-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $file = $directory . '/body.bin';
        try {
            $stream = fopen($file, 'w+b');
            self::assertIsResource($stream);
            try {
                self::assertTrue(ftruncate($stream, 67108864));
            } finally {
                fclose($stream);
            }
            $record = CoroutineRuntime::run(static function () use ($file, $mode): array {
                $server = new Server('127.0.0.1', 0);
                $server->set(['socket_timeout' => 2.0, 'http_compression' => false]);
                $done = new Channel(1);
                $server->handle('/', static function (Request $request, Response $output) use ($file, $mode, $done): void {
                    $deadline = new Deadline($mode === 'shorten' ? 1.0 : 0.05);
                    $cancellation = $mode === 'external' ? new Cancellation() : null;
                    $scope = new ExecutionScope($deadline, cancellation: $cancellation);
                    $other = $cancellation === null ? null : new ExecutionScope(new Deadline(2.0), cancellation: $cancellation);
                    $cleanup = new ResponseDeadlineCleanup();
                    if ($mode === 'cleanup') {
                        $scope->open($cleanup);
                        $cid = Coroutine::getCid();
                        $scope->cancellation()->subscribe(static function () use ($cid): void {
                            if (Coroutine::getCid() !== $cid && Coroutine::exists($cid)) {
                                Coroutine::cancel($cid);
                            }
                        });
                    }
                    if ($mode === 'shorten') {
                        \Swoole\Timer::after(30, static function () use ($deadline): void {
                            $deadline->shorten(0.02);
                        });
                    }
                    $factory = new Factory();
                    $message = $factory->createResponse()->withBody($factory->createStreamFromFile($file));
                    $started = hrtime(true);
                    $failure = '';
                    $cancelled = false;
                    $otherActive = false;
                    try {
                        (new ResponseEmitter())->emit($message, $output, false, $scope);
                        $cancelled = $scope->cancellation()->cancelled();
                        if ($other !== null) {
                            $other->assertActive();
                            $otherActive = true;
                        }
                    } catch (\Throwable $error) {
                        $failure = $error->getMessage();
                    } finally {
                        try {
                            $scope->close();
                            $other?->close();
                        } catch (\Throwable $error) {
                            $failure = $error->getMessage();
                        }
                        $done->push(['state' => $scope->state(), 'failure' => $failure,
                            'elapsed' => (hrtime(true) - $started) / 1000000000.0,
                            'cleanup_elapsed' => $cleanup->elapsed, 'cleanup_completed' => $cleanup->completed,
                            'cancelled_before_close' => $cancelled, 'other_scope_active' => $otherActive]);
                    }
                });
                Coroutine::create(static function () use ($server): void {
                    $server->start();
                });
                $client = new Socket(AF_INET, SOCK_STREAM);
                try {
                    if (!$client->setOption(SOL_SOCKET, SO_RCVBUF, 8192) || !$client->connect('127.0.0.1', $server->port, 1.0)) {
                        throw new \RuntimeException('HTTP 截止客户端未连接');
                    }
                    $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
                    if ($client->sendAll($request, 1.0) !== strlen($request)
                        || !str_starts_with((string) $client->recv(1024, 1.0), 'HTTP/1.1 200')) {
                        throw new \RuntimeException('HTTP 截止响应未实际开始');
                    }
                    $result = $done->pop(0.5);
                    if (!is_array($result)) {
                        throw new \RuntimeException('客户端保持连接时 HTTP 发送仍未结束');
                    }
                    return $result;
                } finally {
                    $client->close();
                    $server->shutdown();
                }
            });
            self::assertSame(0, Coroutine::stats()['coroutine_num']);
            return $record;
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
