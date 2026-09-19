<?php

declare(strict_types=1);

namespace TypeApp\HttpExample;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

final class Greeting
{
    private string $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function text(): string
    {
        return $this->message;
    }
}

final class RequestResource implements ManagedResource
{
    private string $file;
    private string $marker;

    public function __construct(string $file, string $marker)
    {
        $this->file = $file;
        $this->marker = $marker;
    }

    public function start(): void
    {
        if ($this->file !== '') {
            file_put_contents($this->file, 'open:' . $this->marker . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    public function stop(): void
    {
        if ($this->file !== '') {
            file_put_contents($this->file, 'close:' . $this->marker . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}

final class MarkerMiddleware implements MiddlewareInterface
{
    private string $marker;
    private int $calls = 0;

    public function __construct(string $marker)
    {
        $this->marker = $marker;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->calls++;
        $request = $request->withAttribute('trace', (string) $request->getAttribute('trace', '') . $this->marker)
            ->withAttribute('calls', $this->calls);
        if (\Swoole\Coroutine::getCid() >= 0) {
            \Swoole\Coroutine::sleep(0.005);
        }

        return $handler->handle($request)->withHeader('X-Middleware-' . $this->marker, (string) $this->calls);
    }
}

final class HealthHandler implements RequestHandlerInterface
{
    private Greeting $greeting;
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;
    private string $traceFile;
    private int $calls = 0;
    private string $marker = '';

    public function __construct(Greeting $greeting, ResponseFactoryInterface $responses, StreamFactoryInterface $streams, string $traceFile)
    {
        $this->greeting = $greeting;
        $this->responses = $responses;
        $this->streams = $streams;
        $this->traceFile = $traceFile;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new RuntimeException('请求缺少自己的执行作用域');
        }
        $marker = $request->getHeaderLine('X-Test-Marker');
        $this->calls++;
        $this->marker = $marker;
        $scope->open(new RequestResource($this->traceFile, $marker));
        \Swoole\Coroutine::sleep($marker === 'draining' ? 0.2 : 0.01);
        $body = json_encode(['message' => $this->greeting->text(), 'marker' => $this->marker,
            'trace' => $request->getAttribute('trace'), 'calls' => $request->getAttribute('calls'), 'handler_calls' => $this->calls], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->responses->createResponse(200)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) $body));
    }
}

final class FailingHandler implements RequestHandlerInterface
{
    private string $traceFile;

    public function __construct(string $traceFile)
    {
        $this->traceFile = $traceFile;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        $scope->open(new RequestResource($this->traceFile, 'failed'));
        throw new RuntimeException('不能暴露的密码 password=example');
    }
}
