<?php

declare(strict_types=1);

namespace Type\Core\Http;

use InvalidArgumentException;
use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Throwable;
use Type\Runtime\ExecutionScope;
use Type\Runtime\CapacityException;
use Type\Runtime\TaskException;

/** 以 Swoole 承接 HTTP；Unix worker、Windows 协程宿主及编译业务线程共用 PSR 处理链。 */
final class SwooleServer implements HttpServerInterface
{
    private RequestHandlerInterface $handler;
    private ServerRequestFactoryInterface $requests;
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;
    private RequestLimits $limits;
    private HttpControl $control;
    private ?int $watchdog = null;
    private ?Closure $onWorkerStop;
    private bool $threadStarted = false;
    private ?Throwable $threadFailure = null;

    /**
     * @param Closure():void|null $onWorkerStop Unix worker 停止、Windows 协程 HTTP 退出或编译线程请求完整收尾时的清理；硬终止无法保证回调。
     */
    public function __construct(
        RequestHandlerInterface $handler,
        ServerRequestFactoryInterface $requests,
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        ?RequestLimits $limits = null,
        ?HttpControl $control = null,
        ?Closure $onWorkerStop = null
    ) {
        $this->handler = $handler;
        $this->requests = $requests;
        $this->responses = $responses;
        $this->streams = $streams;
        $this->limits = $limits ?? new RequestLimits();
        $this->control = $control ?? new HttpControl();
        $this->onWorkerStop = $onWorkerStop;
    }

    /** Unix 使用经典单 worker，Windows 使用协程 HTTP 与控制事件停止；各路径均要求兼容的 Swoole。 */
    public function serve(string $host, int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('HTTP 监听端口无效');
        }
        \Type\Runtime\CoroutineRuntime::enableIo();
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows 无 Unix worker/信号协议；协程 HTTP 须挂接停止信号，才能响应 CTRL_BREAK 正常排空。
            // embed 原生控制桥须在协程外注册；在 CoroutineRuntime::run 内 attach 曾触发 zend_mm_heap corrupted。
            $signals = new \Type\Runtime\ProcessSignals();
            $signals->attach(function (): void {
                $this->control->stop();
            });
            try {
                \Type\Runtime\CoroutineRuntime::run(function () use ($host, $port, $signals): void {
                    $server = new \Swoole\Coroutine\Http\Server($host, $port);
                    $server->set([
                        'http_parse_post' => false, 'http_parse_files' => false, 'http_parse_cookie' => false,
                        'http_compression' => false, 'package_max_length' => $this->limits->bytes,
                        'socket_timeout' => $this->control->requestSeconds,
                    ]);
                    $server->handle('/', function (Request $request, Response $response): void {
                        $this->handleNative($request, $response);
                    });
                    // tick 固定传入定时器 ID；即使未使用，也须满足 AOT 的完整回调签名。
                    $timer = \Swoole\Timer::tick(20, function (int $timerId) use ($server, $signals): void {
                        $signals->dispatch();
                        if ($this->control->mustTerminate()) {
                            fwrite(STDERR, "HTTP worker 清理超出预算，停止接收并由监督进程回收。\n");
                            $server->shutdown();
                        } elseif ($this->control->drained()) {
                            $server->shutdown();
                        }
                    });
                    if ($timer === false) {
                        throw new RuntimeException('无法启动 Windows HTTP 停止检查');
                    }
                    $this->watchdog = $timer;
                    try {
                        $server->start();
                    } finally {
                        $this->clearThreadTimer();
                        $shutdown = $this->onWorkerStop;
                        if ($shutdown !== null) {
                            $shutdown();
                        }
                    }
                });
            } finally {
                $signals->close();
            }
            return;
        }
        $server = new Server($host, $port, SWOOLE_BASE);
        $settings = ['worker_num' => 1, 'enable_coroutine' => true, 'log_level' => SWOOLE_LOG_ERROR, 'log_file' => '/dev/stderr',
            'package_max_length' => $this->limits->bytes, 'http_parse_post' => false, 'http_parse_files' => false, 'http_parse_cookie' => false,
            'upload_max_filesize' => 0]; // 禁止大 multipart 绕过 package_max_length 进入 Swoole 特殊接收路径。
        $settings['max_connection'] = $this->control->maximumConnections;
        $settings['max_request'] = 100000; // BASE 单 worker 也启用独立 manager 和有限轮换。
        $settings['max_coroutine'] = $this->control->maximumRequests * (1 + $this->control->maximumChildren) + 32;
        $settings['max_wait_time'] = (int) ceil($this->control->drainSeconds + 1);
        $settings['heartbeat_idle_time'] = (int) ceil($this->control->requestSeconds);
        $settings['heartbeat_check_interval'] = max(1, (int) ceil($this->control->requestSeconds / 2));
        $settings['reload_async'] = true;
        if ($this->limits->temporaryDirectory !== null) {
            $settings['upload_tmp_dir'] = $this->limits->temporaryDirectory;
        }
        $server->set($settings);
        $server->on('workerStart', function (Server $server, int $worker): void {
            $this->watchdog = \Swoole\Timer::tick(20, function (int $timerId) use ($server): void {
                if ($this->control->mustTerminate()) {
                    fwrite(STDERR, "HTTP worker 清理超出预算，停止接收并由监督进程回收。\n");
                    \Swoole\Process::kill((int) getmypid(), SIGKILL);
                } elseif ($this->control->drained()) {
                    if ($this->watchdog !== null) {
                        \Swoole\Timer::clear($this->watchdog);
                        $this->watchdog = null;
                    }
                    $server->shutdown();
                }
            });
        });
        $server->on('workerExit', function (Server $server, int $worker): void {
            $this->control->stop();
            if ($this->watchdog !== null) {
                \Swoole\Timer::clear($this->watchdog);
                $this->watchdog = null;
            }
        });
        if ($this->onWorkerStop !== null) {
            $server->on('workerStop', function (Server $server, int $worker): void {
                if ($this->control->statistics()['in_flight'] !== 0) {
                    return;
                }
                $shutdown = $this->onWorkerStop;
                if ($shutdown !== null) {
                    $shutdown();
                }
            });
        }
        $server->on('request', function (Request $request, Response $response): void {
            $this->handleNative($request, $response);
        });
        $server->start();
    }

    /**
     * 在编译业务线程运行共享监听副本，复用原生协程 HTTP 的接收、解析、连接表及停止。
     *
     * 调用方已按部署线程数分配 HttpControl 请求/连接份额；协程上限另包含满额连接的子任务。
     * 本方法拥有该线程事件循环及 listener 副本的关闭，不关闭主控持有的原对象。
     * 控制 Map 遵守 ThreadSupervisor 的四个标量键约定；主控必须独立持有停止期限与 join。
     * 服务就绪后设置 ready；停止先撤销就绪并缩短请求截止，完整事件循环退出后才结算清理。
     * @throws TaskException 缺少编译线程能力、监听失败或请求/连接无法完整收尾。
     */
    public function serveThread(\Swoole\Coroutine\Socket $listener, \Swoole\Thread\Map $state): void
    {
        \Type\Runtime\CoroutineRuntime::assertAvailable();
        if ($this->threadStarted || !class_exists(\Swoole\Thread::class, false) || \Swoole\Thread::getInfo()['is_main_thread']
            || \Swoole\Coroutine::getCid() >= 0) {
            throw new TaskException('http_thread_owner', 'HTTP 线程入口需要独占业务线程事件循环并且只能运行一次');
        }
        $this->threadStarted = true;
        try {
            foreach (['TYPEAPP_LISTENER_ABI', 'TYPEAPP_CONNECTION_LIMIT_ABI', 'TYPEAPP_HTTP1_INPUT_ABI'] as $abi) {
                if (!defined('Swoole\\Coroutine\\Http\\Server::' . $abi) || constant('Swoole\\Coroutine\\Http\\Server::' . $abi) !== 1) {
                    throw new TaskException('http_thread_unavailable', 'HTTP 线程入口需要受控共享监听、连接额度与原始输入能力');
                }
            }
            \Swoole\Coroutine::set(['max_coroutine' => $this->control->maximumConnections
                + $this->control->maximumRequests * $this->control->maximumChildren + 2]);
            $server = \Swoole\Coroutine\Http\Server::fromSocket($listener, 128);
            $server->set(['http_parse_post' => false, 'http_parse_files' => false, 'http_parse_cookie' => false,
                'typeapp_http1_input' => true, 'http_compression' => false, 'package_max_length' => $this->limits->bytes,
                'socket_timeout' => $this->control->requestSeconds, 'typeapp_max_connections' => $this->control->maximumConnections]);
            $server->handle('/', function (Request $request, Response $response): void {
                $this->handleNative($request, $response);
            });
            $created = \Swoole\Coroutine::create(function () use ($server, $state): void {
                try {
                    if ($state['stop'] === true) {
                        $this->control->stop();
                        return;
                    }
                    $timer = \Swoole\Timer::tick(20, function (int $timerId) use ($server, $state): void {
                        try {
                            $state['pulse'] = hrtime(true);
                            if ($this->control->mustTerminate()) {
                                $state['failed'] = true;
                                $this->control->stop();
                            }
                            if ($state['stop'] === true || $this->control->stopping()) {
                                $state['ready'] = false;
                                $this->control->stop();
                                $server->shutdown();
                                $this->clearThreadTimer();
                            }
                        } catch (Throwable $error) {
                            $this->threadFailure = $error;
                            $state['failed'] = true;
                            $state['ready'] = false;
                            $this->clearThreadTimer();
                            $server->shutdown();
                        }
                    });
                    if ($timer === false) {
                        throw new TaskException('http_thread_timer', '无法启动 HTTP 线程停止检查');
                    }
                    $this->watchdog = $timer;
                    $state['pulse'] = hrtime(true);
                    $state['ready'] = true;
                    $server->start();
                } catch (Throwable $error) {
                    $this->threadFailure = $error;
                    $state['failed'] = true;
                } finally {
                    $state['ready'] = false;
                    $this->control->stop();
                    $this->clearThreadTimer();
                }
            });
            if ($created === false) {
                throw new TaskException('http_thread_start_failed', '无法启动 HTTP 监听协程');
            }
            \Swoole\Event::wait();
            if ($this->threadFailure !== null) {
                throw $this->threadFailure;
            }
            $statistics = $this->control->statistics();
            if (($server->errCode !== 0 && $server->errCode !== SOCKET_ECANCELED) || $statistics['in_flight'] !== 0
                || $statistics['quarantined'] !== 0 || $statistics['cleanup_failures'] !== 0) {
                throw new TaskException('http_thread_cleanup_failed', 'HTTP 线程没有完整回收请求或原生连接');
            }
            $shutdown = $this->onWorkerStop;
            if ($shutdown !== null) {
                $shutdown();
            }
        } catch (Throwable $error) {
            $state['failed'] = true;
            throw $error;
        } finally {
            $state['ready'] = false;
            $this->clearThreadTimer();
            $listener->close();
        }
    }

    /**
     * 在编译业务线程内自行绑定监听；用于 Windows 等尚未验收共享监听副本的平台。
     *
     * 控制 Map 与收尾约定同 serveThread；不要求 TYPEAPP_LISTENER_ABI。
     * @throws TaskException 缺少编译线程能力、监听失败或请求无法完整收尾。
     */
    public function serveThreadOwned(string $host, int $port, \Swoole\Thread\Map $state): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('HTTP 监听端口无效');
        }
        \Type\Runtime\CoroutineRuntime::assertAvailable();
        if ($this->threadStarted || !class_exists(\Swoole\Thread::class, false) || \Swoole\Thread::getInfo()['is_main_thread']
            || \Swoole\Coroutine::getCid() >= 0) {
            throw new TaskException('http_thread_owner', 'HTTP 线程入口需要独占业务线程事件循环并且只能运行一次');
        }
        $this->threadStarted = true;
        try {
            \Swoole\Coroutine::set(['max_coroutine' => $this->control->maximumConnections
                + $this->control->maximumRequests * $this->control->maximumChildren + 2]);
            $server = new \Swoole\Coroutine\Http\Server($host, $port);
            $server->set([
                'http_parse_post' => false, 'http_parse_files' => false, 'http_parse_cookie' => false,
                'http_compression' => false, 'package_max_length' => $this->limits->bytes,
                'socket_timeout' => $this->control->requestSeconds,
            ]);
            $server->handle('/', function (Request $request, Response $response): void {
                $this->handleNative($request, $response);
            });
            $created = \Swoole\Coroutine::create(function () use ($server, $state): void {
                try {
                    if ($state['stop'] === true) {
                        $this->control->stop();
                        return;
                    }
                    $timer = \Swoole\Timer::tick(20, function (int $timerId) use ($server, $state): void {
                        try {
                            $state['pulse'] = hrtime(true);
                            if ($this->control->mustTerminate()) {
                                $state['failed'] = true;
                                $this->control->stop();
                            }
                            if ($state['stop'] === true || $this->control->stopping()) {
                                $state['ready'] = false;
                                $this->control->stop();
                                $server->shutdown();
                                $this->clearThreadTimer();
                            }
                        } catch (Throwable $error) {
                            $this->threadFailure = $error;
                            $state['failed'] = true;
                            $state['ready'] = false;
                            $this->clearThreadTimer();
                            $server->shutdown();
                        }
                    });
                    if ($timer === false) {
                        throw new TaskException('http_thread_timer', '无法启动 HTTP 线程停止检查');
                    }
                    $this->watchdog = $timer;
                    $state['pulse'] = hrtime(true);
                    $state['ready'] = true;
                    $server->start();
                } catch (Throwable $error) {
                    $this->threadFailure = $error;
                    $state['failed'] = true;
                } finally {
                    $state['ready'] = false;
                    $this->control->stop();
                    $this->clearThreadTimer();
                }
            });
            if ($created === false) {
                throw new TaskException('http_thread_start_failed', '无法启动 HTTP 监听协程');
            }
            \Swoole\Event::wait();
            if ($this->threadFailure !== null) {
                throw $this->threadFailure;
            }
            $statistics = $this->control->statistics();
            if (($server->errCode !== 0 && $server->errCode !== SOCKET_ECANCELED) || $statistics['in_flight'] !== 0
                || $statistics['quarantined'] !== 0 || $statistics['cleanup_failures'] !== 0) {
                throw new TaskException('http_thread_cleanup_failed', 'HTTP 线程没有完整回收请求或原生连接');
            }
            $shutdown = $this->onWorkerStop;
            if ($shutdown !== null) {
                $shutdown();
            }
        } catch (Throwable $error) {
            $state['failed'] = true;
            throw $error;
        } finally {
            $state['ready'] = false;
            $this->clearThreadTimer();
        }
    }

    /** 由所属引擎等待在途作用域排空；不会提前释放连接资源。 */
    public function stop(): void
    {
        $this->control->stop();
    }

    private function clearThreadTimer(): void
    {
        if ($this->watchdog !== null) {
            \Swoole\Timer::clear($this->watchdog);
            $this->watchdog = null;
        }
    }

    /**
     * 将原生请求交给既有 PSR 链，并在本次响应结束前清理作用域和消息流。
     *
     * @internal 由当前线程的原生 HTTP 回调调用；监听方负责连接预算、就绪、排空与线程生命周期。
     *           可用于经典或协程 HTTP，不能将本方法当作完整服务端的启动或监督入口。
     *           监听方须设置 http_parse_cookie=false，保留原始 Cookie 以执行相同校验。
     */
    public function handleNative(Request $raw, Response $output): void
    {
        if (isset($raw->header['upgrade'])) {
            // HttpServerInterface 没有升级后的会话所有者，探针也不能绕过协议拒绝。
            $output->header('Connection', 'close');
            (new ResponseEmitter())->emit($this->error(501, 'upgrade_not_supported'), $output, strtoupper((string) ($raw->server['request_method'] ?? 'GET')) === 'HEAD');
            $output->close();
            return;
        }
        $probe = $this->control->probe((string) ($raw->server['request_uri'] ?? '/'));
        if ($probe !== null && strtoupper((string) ($raw->server['request_method'] ?? 'GET')) === 'GET') {
            $output->status($probe['status']);
            $output->header('Content-Type', 'application/json');
            $output->end(json_encode($probe['body'], JSON_THROW_ON_ERROR));
            return;
        }
        $scope = $this->control->begin();
        if ($scope === null) {
            $output->status(503);
            $output->header('Content-Type', 'application/json');
            $output->header('Retry-After', '1');
            $output->end('{"error":"overloaded_or_stopping"}');
            return;
        }
        $messages = [];
        $dispatching = false;
        try {
            $method = (string) ($raw->server['request_method'] ?? 'GET');
            $target = (string) ($raw->server['request_uri'] ?? '/');
            $query = (string) ($raw->server['query_string'] ?? '');
            $host = $raw->header['host'] ?? null;
            if (!is_string($host) || $host === '') {
                throw new HttpError(400, 'invalid_host');
            }
            $request = $this->requests->createServerRequest($method, 'http://' . $host . $target . ($query === '' ? '' : '?' . $query), $raw->server ?? []);
            $request = $request->withProtocolVersion(substr((string) ($raw->server['server_protocol'] ?? 'HTTP/1.1'), 5));
            foreach ($raw->header ?? [] as $name => $value) {
                $request = $request->withHeader((string) $name, $value);
            }
            $content = $raw->rawContent();
            $body = new RequestBody($this->limits);
            $scope->open($body);
            $request = $body->parse($request, $content === false ? '' : (string) $content)
                ->withQueryParams($raw->get ?? [])->withCookieParams($body->cookies($request->getHeader('Cookie')))
                ->withAttribute('type.scope', $scope);
            $request = $request->withAttribute('type.raw-target', $target . ($query === '' ? '' : '?' . $query));
            $messages['request'] = $request;
            $dispatching = true;
            $messages['response'] = $scope->run(function (ExecutionScope $current) use ($request): ResponseInterface {
                return $this->handler->handle($request);
            });
        } catch (HttpError $error) {
            $messages['response'] = $this->error($error->status(), $error->errorCode());
        } catch (TaskException $error) {
            $messages['response'] = $this->error($error->errorCode() === 'deadline_exceeded' ? 504 : 503, $error->errorCode());
        } catch (InvalidArgumentException $error) {
            $messages['response'] = $this->error($dispatching ? 500 : 400, $dispatching ? 'internal_error' : 'bad_request');
        } catch (Throwable $error) {
            $messages['response'] = CapacityException::matches($error)
                ? $this->error(503, 'resource_capacity_exceeded')->withHeader('Retry-After', '1')
                : $this->error(500, 'internal_error');
        }
        try {
            (new ResponseEmitter())->emit($messages['response'], $output, strtoupper((string) ($raw->server['request_method'] ?? 'GET')) === 'HEAD', $scope);
        } finally {
            $cleanupFailed = false;
            try {
                $scope->close();
            } catch (Throwable $error) {
                $cleanupFailed = true;
                fwrite(STDERR, "HTTP 作用域清理失败。\n");
            }
            foreach ($messages as $message) {
                try {
                    $message->getBody()->close();
                } catch (Throwable $error) {
                    fwrite(STDERR, "HTTP 消息流清理失败。\n");
                    $cleanupFailed = true;
                }
            }
            $this->control->finish($scope, $cleanupFailed);
        }
    }

    private function error(int $status, string $code): ResponseInterface
    {
        return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode(['error' => $code], JSON_THROW_ON_ERROR)));
    }
}
