<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\Socket;
use Swoole\Thread;
use Type\Core\Http\HttpControl;
use Type\Core\Http\Authentication;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Pipeline;
use Type\Core\Http\RequestLimits;
use Type\Core\Http\RequestPolicy;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Runtime\CapacityException;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;
use Type\Runtime\TaskException;
use Type\Runtime\ThreadSupervisor;

/** 观察每次 PSR 请求结束时的资源关闭，不依赖连接协程退出。 */
final class HttpThreadResource implements ManagedResource
{
    public bool $closed = false;

    public function start(): void
    {
    }

    public function stop(): void
    {
        $this->closed = true;
    }
}

/** 原生故障契约的显式状态；不把 Socket 暴露给业务处理链。 */
final class HttpRetainedConnection
{
    public ?Socket $socket = null;
    public ?Server $server = null;
    public bool $started = false;
    public int $warnings = 0;
    public string $response = '';
}

/** 安装后消费者的真实 PSR handler；生产请求转换和收尾使用 type-core。 */
final class HttpThreadHandler implements RequestHandlerInterface
{
    private array $previous = [];
    private int $sequence = 0;

    public function __construct(private int $worker, private int $generation, private string $directory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new RuntimeException('missing request scope');
        }
        $scope->assertActive();
        $connection = $request->getHeaderLine('X-Test-Connection');
        if ($connection !== '' && isset($this->previous[$connection])) {
            $previous = $this->previous[$connection];
            if ($previous['scope']->state() !== 'closed' || !$previous['resource']->closed || $previous['scope'] === $scope) {
                throw new RuntimeException('previous keep-alive request was not independently closed');
            }
        }
        $resource = new HttpThreadResource();
        $scope->open($resource);
        if ($connection !== '') {
            $this->previous[$connection] = ['scope' => $scope, 'resource' => $resource];
        }
        $this->sequence++;
        $path = $request->getUri()->getPath();
        if ($path === '/capacity') {
            throw new CapacityException('private-capacity');
        }
        if ($path === '/deadline') {
            throw new TaskException('deadline_exceeded', 'private-deadline');
        }
        if ($path === '/fail') {
            throw new RuntimeException('private-failure');
        }
        if ($path === '/slow') {
            file_put_contents($this->directory . '/in-flight', 'active');
            Coroutine::sleep(0.15);
        }
        if ($path === '/child') {
            $task = $scope->spawn(static function (ExecutionScope $child): string {
                $child->assertActive();
                Coroutine::sleep(0.001);
                return 'child-complete';
            });
            if ($task->await() !== 'child-complete') {
                throw new RuntimeException('child result mismatch');
            }
        }
        $data = ['worker' => $this->worker, 'generation' => $this->generation, 'thread' => Thread::getId(),
            'sequence' => $this->sequence, 'token' => $request->getHeaderLine('X-Test-Token'),
            'headers' => $request->getHeaders(), 'cookies' => $request->getCookieParams(), 'query' => $request->getQueryParams(),
            'version' => $request->getProtocolVersion(), 'identity' => $request->getAttribute('type.identity')?->subject(),
            'path' => $path, 'body' => (string) $request->getBody(), 'port' => $request->getServerParams()['server_port']];
        $factory = new Factory();
        return $factory->createResponse()->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
    }
}

/** 控制文件仅属于验收驱动；线程、监听和 HTTP 处理采用实际编译与原生入口。 */
final class HttpThreadProbe
{
    public static function run(string $payload): int
    {
        if ($payload === 'socket-less') {
            if (count(Thread::getArguments()) !== 1) {
                throw new RuntimeException('unexpected socket-less argument');
            }
            return 23;
        }
        $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        if (isset($data['supervisor_probe'])) {
            return self::supervisorProbe($data);
        }
        if (isset($data['exit_gate'])) {
            if (!type_test_thread_exit_gate((string) $data['exit_gate'])) {
                throw new RuntimeException('thread exit gate already set');
            }
            return 23;
        }
        $arguments = Thread::getArguments();
        $listener = $arguments[1] ?? null;
        if (!$listener instanceof Socket) {
            throw new RuntimeException('native socket argument missing');
        }
        $directory = (string) $data['directory'];
        $worker = (int) $data['worker'];
        $generation = (int) $data['generation'];
        $control = new HttpControl((int) $data['request_limit'], (int) $data['connection_limit'], 2.0, 1.0, 0.5, 2);
        $state = $arguments[2] ?? new Swoole\Thread\Map(['stop' => false, 'ready' => false, 'pulse' => 0, 'failed' => false]);
        $supervised = isset($arguments[2]);
        $deadline = microtime(true) + 15.0;
        Swoole\Timer::tick(20, static function (int $timerId) use ($state, $directory, $worker, $generation, $supervised, $deadline): void {
            $ready = $directory . '/ready-' . $worker . '-' . $generation . '.json';
            if ($state['ready'] && !is_file($ready)) {
                file_put_contents($ready, json_encode(['thread' => Thread::getId(), 'worker' => $worker, 'generation' => $generation], JSON_THROW_ON_ERROR));
            }
            if (!$supervised && (is_file($directory . '/stop-' . $worker) || microtime(true) >= $deadline)) {
                $state['stop'] = true;
            }
            if ($state['stop'] || $state['failed']) {
                Swoole\Timer::clear($timerId);
            }
        });
        $factory = new Factory();
        $handler = new HttpThreadHandler($worker, $generation, $directory);
        $authentication = new Authentication(static function (string $token): ?Identity {
            return match ($token) {
                'reader-one', 'reader-two' => new Identity($token, ['reader']),
                'blocked' => new Identity($token),
                default => null,
            };
        }, static fn (Identity $identity, CanonicalRequest $request, string $method): bool => in_array('reader', $identity->roles(), true), $factory, $factory);
        // 认证只包住受保护路由；普通路由继续观察错误及容量行为。
        $secure = new Pipeline([static fn (): Authentication => $authentication], $handler);
        $entry = new Router($factory, $factory);
        $entry->add('GET', '/secure', static fn (): Pipeline => $secure);
        foreach (['/normal', '/child', '/capacity', '/deadline', '/fail', '/slow'] as $path) {
            $entry->add('GET', $path, static fn (): HttpThreadHandler => $handler);
        }
        $entry->add('POST', '/post', static fn (): HttpThreadHandler => $handler);
        $port = $listener->getsockname()['port'];
        $pipeline = new Pipeline([static fn (): RequestPolicy => new RequestPolicy(['localhost', '127.0.0.1:' . $port])], $entry);
        $adapter = new SwooleServer(
            $pipeline,
            $factory,
            $factory,
            $factory,
            new RequestLimits(bytes: 8192, fields: 3, fileBytes: 8192, fieldBytes: 8192),
            $control
        );
        $adapter->serveThread($listener, $state);
        $statistics = $control->statistics();
        if ($statistics['in_flight'] !== 0 || $statistics['quarantined'] !== 0 || $statistics['cleanup_failures'] !== 0) {
            throw new RuntimeException('request cleanup incomplete');
        }
        file_put_contents($directory . '/stopped-' . $worker . '-' . $generation . '.json', json_encode($statistics, JSON_THROW_ON_ERROR));
        return 0;
    }

    /** 控制与故障通过已编译入口发生；阻塞夹具只持有原生线程，不假装协程 sleep。 */
    private static function supervisorProbe(array $data): int
    {
        $arguments = Thread::getArguments();
        if (count($arguments) !== 3 || $arguments[1] !== null || !$arguments[2] instanceof Swoole\Thread\Map) {
            throw new RuntimeException('control-only native argument shape mismatch');
        }
        $state = $arguments[2];
        $directory = (string) $data['directory'];
        $slot = (int) $data['slot'];
        $mode = (string) $data['supervisor_probe'];
        if ($mode === 'exit') {
            return 23;
        }
        if ($mode === 'exit-gate') {
            type_test_thread_exit_gate($directory);
            return 0;
        }
        if ($mode === 'startup-stall') {
            type_test_thread_block(5000);
            return 0;
        }
        Coroutine::create(static function () use ($state, $directory, $slot, $mode): void {
            $state['ready'] = true;
            $state['pulse'] = hrtime(true);
            while (!$state['stop']) {
                file_put_contents($directory . '/probe-progress-' . $slot, (string) hrtime(true));
                $state['pulse'] = hrtime(true);
                Coroutine::sleep(0.02);
                if ($mode === 'stall') {
                    file_put_contents($directory . '/block-entered', 'entered');
                    type_test_thread_block(5000);
                }
            }
            $state['ready'] = false;
        });
        Swoole\Event::wait();
        file_put_contents($directory . '/probe-stopped-' . $slot, 'stopped');
        return 0;
    }
}

function httpThreadStart(string $directory, int $worker, int $generation, Socket $listener): Thread
{
    // 退役代 join 后才补位，最多同时两线程；请求总额 4、已接入连接总额 16。
    $connections = new DeploymentBudget(16, 1, 0, 1, 0, 2);
    return CoroutineRuntime::startThread('http', json_encode(
        ['directory' => $directory, 'worker' => $worker, 'generation' => $generation,
            'request_limit' => intdiv(4, 2), 'connection_limit' => $connections->statistics()['per_thread']],
        JSON_THROW_ON_ERROR
    ), $listener);
}

function main(int $argc, array $argv): void
{
    $directory = $argv[1];
    if (($argv[2] ?? '') !== '') {
        httpThreadSupervised($directory, $argv[2]);
        return;
    }
    $joinChecks = httpThreadJoinContracts($directory);
    $contracts = httpThreadContracts();
    $listener = new Socket(AF_INET, SOCK_STREAM, 0);
    if (!$listener->bind('127.0.0.1', 0) || !$listener->listen(128)) {
        throw new RuntimeException('listener startup failed');
    }
    $port = $listener->getsockname()['port'];
    $threads = [];
    $exits = [];
    try {
        $threads[1] = httpThreadStart($directory, 1, 1, $listener);
        $threads[2] = httpThreadStart($directory, 2, 1, $listener);
        file_put_contents($directory . '/listener.json', json_encode(['port' => $port, 'process' => getmypid()], JSON_THROW_ON_ERROR));
        $retired = false;
        $replaced = false;
        $deadline = microtime(true) + 15.0;
        while (!is_file($directory . '/stop') && microtime(true) < $deadline) {
            if (!$retired && is_file($directory . '/retire')) {
                file_put_contents($directory . '/stop-1', 'stop');
                $threads[1]->join();
                $exits[] = $threads[1]->getExitStatus();
                unset($threads[1]);
                $retired = true;
                file_put_contents($directory . '/retired', 'retired');
            }
            if ($retired && !$replaced && is_file($directory . '/replace')) {
                unlink($directory . '/stop-1');
                $threads[1] = httpThreadStart($directory, 1, 2, $listener);
                $replaced = true;
            }
            usleep(10000);
        }
    } finally {
        foreach ([1, 2] as $worker) {
            file_put_contents($directory . '/stop-' . $worker, 'stop');
        }
        foreach ($threads as $thread) {
            $thread->join();
            $exits[] = $thread->getExitStatus();
        }
        $listener->close();
        unset($listener);
        Swoole\Event::wait();
    }
    echo json_encode(['exits' => $exits, 'active_threads' => Thread::activeCount(), 'port' => $port,
        'contracts' => $contracts, 'join_checks' => $joinChecks], JSON_THROW_ON_ERROR), "\n";
}

/** 同一安装后应用入口验证生产监督；文件仅连接独立验收驱动。 */
function httpThreadSupervised(string $directory, string $mode): void
{
    $signalHandler = PHP_OS_FAMILY === 'Windows' ? null : pcntl_signal_get_handler(SIGTERM);
    $asyncSignals = PHP_OS_FAMILY === 'Windows' ? false : pcntl_async_signals();
    $supervisor = new ThreadSupervisor(2, 0.5, 0.25, 0.5);
    $listener = null;
    $payloads = [];
    if ($mode === 'supervised-http' || $mode === 'supervised-signal') {
        $listener = new Socket(AF_INET, SOCK_STREAM, 0);
        if (!$listener->bind('127.0.0.1', 0) || !$listener->listen(128)) {
            throw new RuntimeException('supervised listener startup failed');
        }
        foreach ([1, 2] as $worker) {
            $payloads[] = json_encode(['directory' => $directory, 'worker' => $worker, 'generation' => 1,
                'request_limit' => 2, 'connection_limit' => 8], JSON_THROW_ON_ERROR);
        }
        file_put_contents($directory . '/listener.json', json_encode(['port' => $listener->getsockname()['port'], 'process' => getmypid()], JSON_THROW_ON_ERROR));
    } else {
        $payloads[] = json_encode(['directory' => $directory, 'slot' => 1, 'supervisor_probe' => 'loop'], JSON_THROW_ON_ERROR);
        $payloads[] = $mode === 'partial' ? "\xff" : json_encode(['directory' => $directory, 'slot' => 2, 'supervisor_probe' => $mode], JSON_THROW_ON_ERROR);
    }
    $deadline = microtime(true) + 10.0;
    Swoole\Timer::tick(20, static function (int $timerId) use ($supervisor, $directory, $deadline): void {
        $state = $supervisor->statistics();
        if (is_file($directory . '/stop') || microtime(true) >= $deadline) {
            $supervisor->stop();
        }
        if ($state['state'] === 'stopping' || $state['state'] === 'stopped') {
            file_put_contents($directory . '/supervisor-stopping.json', json_encode($state, JSON_THROW_ON_ERROR));
            Swoole\Timer::clear($timerId);
        } else {
            file_put_contents($directory . '/supervisor-progress.json', json_encode($state + ['time' => hrtime(true)], JSON_THROW_ON_ERROR));
        }
    });
    $result = [];
    try {
        $result['exits'] = $supervisor->run('http', $payloads, $listener);
    } catch (JsonException $error) {
        $result['error'] = 'invalid_startup_json';
    } catch (TaskException $error) {
        $result['error'] = $error->errorCode();
    } finally {
        $listener?->close();
    }
    $result['statistics'] = $supervisor->statistics();
    $result['active_threads'] = Thread::activeCount();
    $result['signals_restored'] = PHP_OS_FAMILY === 'Windows'
        || (pcntl_signal_get_handler(SIGTERM) === $signalHandler && pcntl_async_signals() === $asyncSignals);
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
}

/** 在真正的 TLS 析构期间验证等待上限与所有权，控制文件仅供独立验收。 */
function httpThreadJoinContracts(string $directory): int
{
    if (!defined('Swoole\\Thread::TYPEAPP_JOIN_ABI') || Thread::TYPEAPP_JOIN_ABI !== 1) {
        throw new RuntimeException('native thread completion candidate unavailable');
    }
    $thread = CoroutineRuntime::startThread('http', json_encode(['exit_gate' => $directory], JSON_THROW_ON_ERROR));
    $checks = 0;
    try {
        $deadline = microtime(true) + 3.0;
        while (!is_file($directory . '/gate-entered')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('final thread destructor did not start');
            }
            usleep(1000);
            clearstatcache(true, $directory . '/gate-entered');
        }
        foreach ([-1, 60001] as $invalidWait) {
            try {
                $thread->joinWithin($invalidWait);
                throw new RuntimeException('invalid join wait accepted');
            } catch (ValueError) {
                $checks++;
            }
        }
        foreach ([0, 10] as $wait) {
            $started = hrtime(true);
            if ($thread->joinWithin($wait) || !$thread->joinable() || hrtime(true) - $started > 500000000) {
                throw new RuntimeException('join released ownership or ignored the completion wait');
            }
            $checks++;
        }
    } finally {
        file_put_contents($directory . '/gate-release', 'release');
        // 测试析构自身最多等十秒；失败也先回收该测试线程再报告。
        $joined = $thread->joinWithin(5000);
        if (!$joined && $thread->joinable()) {
            $thread->join();
        }
    }
    if (!$joined || $thread->getExitStatus() !== 23 || $thread->joinable() || Thread::activeCount() !== 1) {
        throw new RuntimeException('final thread completion was not joined');
    }
    $checks++;
    if ($thread->joinWithin(0)) {
        throw new RuntimeException('thread joined twice');
    }
    return $checks + 1;
}

/** 实际扩展入口验证拒绝、借用引用和原两参数兼容，不使用反射或替代对象。 */
function httpThreadContracts(): int
{
    if (!defined('Swoole\\Coroutine\\Http\\Server::TYPEAPP_LISTENER_ABI')
        || Server::TYPEAPP_LISTENER_ABI !== 1
        || !defined('Swoole\\Coroutine\\Http\\Server::TYPEAPP_CONNECTION_LIMIT_ABI')
        || Server::TYPEAPP_CONNECTION_LIMIT_ABI !== 1
        || !defined('Swoole\\Coroutine\\Http\\Server::TYPEAPP_HTTP1_INPUT_ABI')
        || Server::TYPEAPP_HTTP1_INPUT_ABI !== 1) {
        throw new RuntimeException('HTTP listener candidate unavailable');
    }
    $checks = 0;
    foreach ([null, 0, 1, '', []] as $invalidInput) {
        $invalidServer = new Server('127.0.0.1', 0, false, false);
        $invalidServer->set(['typeapp_http1_input' => $invalidInput]);
        try {
            $invalidServer->start();
            throw new RuntimeException('invalid HTTP input option accepted');
        } catch (ValueError) {
            $checks++;
        }
        unset($invalidServer);
    }
    foreach ([-1, 0, 100001, 3.0, '3', null, true] as $invalidLimit) {
        $invalidServer = new Server('127.0.0.1', 0, false, false);
        $invalidServer->set(['typeapp_max_connections' => $invalidLimit]);
        try {
            $invalidServer->start();
            throw new RuntimeException('invalid native connection limit accepted');
        } catch (ValueError) {
            $checks++;
        }
        unset($invalidServer);
    }
    Swoole\Event::wait();
    foreach ([[1, false], [2, false], [2, true]] as $retention) {
        httpThreadRetention((int) $retention[0], (bool) $retention[1]);
        $checks++;
    }
    $socket = new Socket(AF_INET, SOCK_STREAM, 0);
    try {
        Server::fromSocket($socket);
        throw new RuntimeException('unbound socket accepted');
    } catch (ValueError) {
        $checks++;
    }
    if (!$socket->bind('127.0.0.1', 0)) {
        throw new RuntimeException('contract bind failed');
    }
    foreach ([-1, PHP_INT_MAX] as $backlog) {
        try {
            Server::fromSocket($socket, $backlog);
            throw new RuntimeException('invalid backlog accepted');
        } catch (ValueError) {
            $checks++;
        }
    }
    $server = Server::fromSocket($socket);
    try {
        $server->__construct('127.0.0.1', 0);
        throw new RuntimeException('HTTP server initialized twice');
    } catch (Error $error) {
        if ($error->getMessage() !== 'HTTP server is already initialized') {
            throw $error;
        }
        $checks++;
    }
    unset($server);
    if ($socket->getsockname() === false) {
        throw new RuntimeException('server destruction closed caller socket');
    }
    $checks++;
    $waiting = Coroutine::create(static function () use ($socket): void {
        $accepted = $socket->accept(1.0);
        if ($accepted !== false) {
            $accepted->close();
            throw new RuntimeException('unexpected contract connection');
        }
    });
    try {
        Server::fromSocket($socket);
        throw new RuntimeException('busy socket accepted');
    } catch (ValueError) {
        $checks++;
    } finally {
        Coroutine::cancel($waiting);
        $socket->close();
    }
    try {
        Server::fromSocket($socket);
        throw new RuntimeException('closed socket accepted');
    } catch (ValueError) {
        $checks++;
    }
    try {
        CoroutineRuntime::startThread('http', 'socket-less', $socket);
        throw new RuntimeException('closed socket copied');
    } catch (Swoole\Exception) {
        $checks++;
    }
    unset($socket);
    $datagram = new Socket(AF_INET, SOCK_DGRAM, 0);
    if (!$datagram->bind('127.0.0.1', 0)) {
        throw new RuntimeException('contract datagram bind failed');
    }
    try {
        Server::fromSocket($datagram);
        throw new RuntimeException('datagram listener accepted');
    } catch (ValueError) {
        $checks++;
    } finally {
        $datagram->close();
    }
    unset($datagram);
    $held = new Socket(AF_INET, SOCK_STREAM, 0);
    if (!$held->bind('127.0.0.1', 0)) {
        throw new RuntimeException('held listener bind failed');
    }
    $port = $held->getsockname()['port'];
    $borrower = Server::fromSocket($held);
    unset($held);
    $conflict = new Socket(AF_INET, SOCK_STREAM, 0);
    if (@$conflict->bind('127.0.0.1', $port)) {
        throw new RuntimeException('borrowed listener was released early');
    }
    $conflict->close();
    unset($conflict, $borrower);
    Swoole\Event::wait();
    $released = new Socket(AF_INET, SOCK_STREAM, 0);
    if (!$released->bind('127.0.0.1', $port)) {
        throw new RuntimeException('borrowed listener was not released');
    }
    $released->close();
    unset($released);
    Swoole\Event::wait();
    $checks++;
    if (Thread::activeCount() !== 1) {
        throw new RuntimeException('rejected start created a thread');
    }
    $plain = CoroutineRuntime::startThread('http', 'socket-less');
    $plain->join();
    if ($plain->getExitStatus() !== 23 || Thread::activeCount() !== 1) {
        throw new RuntimeException('two-argument thread startup changed');
    }
    return $checks + 1;
}

/** 验证准入等待、accept 等待及监听已停止后的晚失败都保留原始错误和 FD 所有权。 */
function httpThreadRetention(int $limit, bool $stopInside): void
{
    $state = new HttpRetainedConnection();
    $server = new Server('127.0.0.1', 0, false, false);
    $state->server = $server;
    $port = $server->port;
    $server->set(['typeapp_max_connections' => $limit, 'socket_timeout' => 1.0]);
    set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($state): bool {
        if ($severity !== E_WARNING || !str_contains($message, 'bounded HTTP handler retained its native connection')) {
            return false;
        }
        $state->warnings++;
        return true;
    });
    try {
        $server->handle('/', static function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($state, $stopInside): void {
            $state->socket = $response->socket;
            $response->end('retained');
            if ($stopInside) {
                $owner = $state->server;
                if ($owner !== null) {
                    $owner->shutdown();
                }
            }
        });
        Coroutine::create(static function () use ($server, $state): void {
            $state->started = $server->start();
        });
        Coroutine::create(static function () use ($port, $state): void {
            $client = new Socket(AF_INET, SOCK_STREAM, 0);
            try {
                if (!$client->connect('127.0.0.1', $port, 1.0)
                    || $client->sendAll("GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n") === false) {
                    throw new RuntimeException('retained connection request failed');
                }
                $bytes = $client->recv(8192, 1.0);
                $state->response = is_string($bytes) ? $bytes : '';
            } finally {
                $client->close();
            }
        });
        Swoole\Event::wait();
        if ($server->errCode !== SOCKET_EBUSY || $state->warnings !== 1 || (!$stopInside && $state->started)
            || $state->socket === null || !str_ends_with($state->response, 'retained')) {
            throw new RuntimeException('retained native connection failure was lost');
        }
        // settings 是上游公开属性；删除键也不能绕过有限服务的单次生命周期。
        $server->settings = [];
        try {
            $server->start();
            throw new RuntimeException('bounded HTTP server restarted');
        } catch (Error $error) {
            if ($error->getMessage() !== 'bounded HTTP server can only be started once') {
                throw $error;
            }
        }
    } finally {
        restore_error_handler();
        $state->socket = null;
        $state->server = null;
        unset($server);
        Swoole\Event::wait();
    }
    $released = new Socket(AF_INET, SOCK_STREAM, 0);
    try {
        if (!$released->bind('127.0.0.1', $port) || !$released->listen(1)) {
            throw new RuntimeException('retained connection listener was not released');
        }
    } finally {
        $released->close();
    }
    unset($released);
    Swoole\Event::wait();
}
