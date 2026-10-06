<?php

declare(strict_types=1);

namespace TypeApp\HttpAssemblyFixture;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Command;
use Type\Core\Configuration;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 标准生成入口拥有 HTTP 宿主，命令与请求消费同一装配图。 */
final class Application
{
    /** @param list<string> $arguments 生成入口传入的程序名、角色和端口。 */
    public static function run(array $arguments, bool $development): void
    {
        $application = new \Type\Generated\CommandApplication(new Configuration([]));
        if (($arguments[1] ?? '') !== 'serve') {
            $GLOBALS['type_app_exit_status'] = $application->run($arguments[1] ?? 'help', array_slice($arguments, 2));
            if (State::$active !== 0) {
                throw new \RuntimeException('resource_leaked');
            }
            return;
        }
        $messages = new Factory();
        $router = new Router($messages, $messages);
        $application->registerRoutes($router);
        CoroutineRuntime::enableIo();
        (new SwooleServer($router, $messages, $messages, $messages))->serve('127.0.0.1', (int) ($arguments[2] ?? '0'));
    }
}

/** 父子执行作用域共用生成路由，业务资源必须独立并在结束时关闭。 */
final class ChildScopes implements Command
{
    /** @param list<string> $arguments 本场景不消费额外参数。 */
    public function run(Configuration $configuration, array $arguments): int
    {
        $messages = new Factory();
        $router = new Router($messages, $messages);
        (new \Type\Generated\CommandApplication($configuration))->registerRoutes($router);
        $parent = ExecutionScope::current();
        $request = $messages->createServerRequest('GET', '/value')->withHeader('X-Test-Marker', 'parent')->withAttribute('type.scope', $parent);
        $first = json_decode((string) $router->handle($request)->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $task = $parent->spawn(function (ExecutionScope $child) use ($router, $messages): array {
            $request = $messages->createServerRequest('GET', '/value')->withHeader('X-Test-Marker', 'child')->withAttribute('type.scope', $child);
            return json_decode((string) $router->handle($request)->getBody(), true, 16, JSON_THROW_ON_ERROR);
        });
        $second = $task->await();
        if ($first['marker'] !== 'parent' || $second['marker'] !== 'child' || State::$active !== 1) {
            throw new \RuntimeException('child_scope_contamination');
        }
        echo "child scopes passed\n";
        return 0;
    }
}

/** 命令与控制器共用同一可替换业务服务。 */
final class Message
{
    public function __construct(public string $value)
    {
    }
}

/** 测试资源同时记录请求隔离和作用域真实收尾。 */
final class State implements ManagedResource
{
    public static int $active = 0;
    public static int $entered = 0;
    public static bool $released = false;
    public string $marker = '';

    public function start(): void
    {
        self::$active++;
    }

    public function stop(): void
    {
        self::$active--;
    }
}

final class Identify implements MiddlewareInterface
{
    public function __construct(private State $state)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->state->marker !== '') {
            throw new \RuntimeException('execution_state_reused');
        }
        $this->state->marker = $request->getHeaderLine('X-Test-Marker');
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('scope_missing');
        }
        $scope->open($this->state);
        return $handler->handle($request);
    }
}

final class Controller
{
    public function __construct(private Message $message, private State $state, private Factory $messages)
    {
    }

    public function value(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(['message' => $this->message->value, 'marker' => $this->state->marker]);
    }

    public function hold(ServerRequestInterface $request): ResponseInterface
    {
        State::$entered++;
        $deadline = microtime(true) + 5;
        while (!State::$released && microtime(true) < $deadline) {
            \Swoole\Coroutine::sleep(0.001);
        }
        if (!State::$released) {
            throw new \RuntimeException('barrier_timeout');
        }
        return $this->value($request);
    }

    public function metrics(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response(['active' => State::$active, 'entered' => State::$entered]);
    }

    public function release(ServerRequestInterface $request): ResponseInterface
    {
        State::$released = true;
        return $this->metrics($request);
    }

    public function fail(ServerRequestInterface $request): ResponseInterface
    {
        throw new \RuntimeException('secret-action-sentinel');
    }

    private function response(array $value): ResponseInterface
    {
        return $this->messages->createResponse(200)->withBody($this->messages->createStream(json_encode($value, JSON_THROW_ON_ERROR)));
    }
}

final class BrokenController
{
    public function __construct()
    {
        throw new \RuntimeException('secret-constructor-sentinel');
    }

    public function value(ServerRequestInterface $request): ResponseInterface
    {
        throw new \RuntimeException('unreachable');
    }
}

final class Show implements Command
{
    public function __construct(private Message $message)
    {
    }

    public function run(Configuration $configuration, array $arguments): int
    {
        echo $this->message->value . PHP_EOL;
        return 0;
    }
}
