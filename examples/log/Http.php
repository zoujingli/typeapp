<?php

declare(strict_types=1);

namespace TypeApp\LogExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;
use Type\Core\Http\Message\Factory;
use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Logger;
use Type\Log\Output;
use Type\Runtime\ExecutionScope;

final class PreviousLogger
{
    private ?Logger $logger = null;
    public function save(Logger $logger): void
    {
        $this->logger = $logger;
    }
    public function rejected(): bool
    {
        if ($this->logger === null) {
            return false;
        }
        try {
            $this->logger->info('expired-request');
        } catch (RuntimeException $error) {
            return true;
        }
        return false;
    }
}

final class LogMiddleware implements MiddlewareInterface
{
    private string $file;
    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new RuntimeException('HTTP 日志需要请求作用域');
        }
        $logs = new LogManager('http-build-one', ['app' => new Channel(Output::file($this->file, maxRecordBytes: 16384))], stopSeconds: 0.05);
        $scope->open($logs);
        $logger = $logs->logger($scope, ['request_id' => $request->getHeaderLine('X-Test-Marker')]);
        $logger->info('request-start');
        try {
            $response = $handler->handle($request->withAttribute('type.logger', $logger));
            $logger->info('request-complete', ['status' => $response->getStatusCode()]);
            return $response;
        } catch (Throwable $error) {
            $logger->error('request-failed', ['exception' => $error, 'authorization' => 'Bearer http-context-secret']);
            throw $error;
        }
    }
}

final class Handler implements RequestHandlerInterface
{
    private PreviousLogger $previous;
    public function __construct(PreviousLogger $previous)
    {
        $this->previous = $previous;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $logger = $request->getAttribute('type.logger');
        if (!$logger instanceof Logger) {
            throw new RuntimeException('没有注入请求日志');
        }
        if ($request->getUri()->getPath() === '/fail') {
            throw new RuntimeException('HTTP 失败 password=http-exception-secret');
        }
        if ($request->getUri()->getPath() === '/previous') {
            $value = ['previous_rejected' => $this->previous->rejected()];
        } else {
            $this->previous->save($logger);
            if ((getenv('TYPE_HTTP_DRIVER') ?: 'swoole') === 'stream') {
                usleep(10000);
            } else {
                \Swoole\Coroutine::sleep(0.01);
            }
            $marker = $request->getHeaderLine('X-Test-Marker');
            $logger->notice('request-step', ['marker' => $marker, 'password' => 'http-step-secret']);
            $value = ['marker' => $marker];
        }
        $messages = new Factory();
        return $messages->createResponse()->withHeader('Content-Type', 'application/json')
            ->withBody($messages->createStream((string) json_encode($value, JSON_THROW_ON_ERROR)));
    }
}
