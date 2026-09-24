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

/** 仅用于故意保留上次请求 Logger，验证跨作用域使用会被拒绝。 */
final class PreviousLogger
{
    private ?Logger $logger = null;
    /** 保存已绑定请求的 Logger，下一请求用它执行负向检查。 */
    public function save(Logger $logger): void
    {
        $this->logger = $logger;
    }
    /** 尝试使用上次请求 Logger；只有已失效调用被明确拒绝才返回 true。 */
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

/** 为每个请求建立日志管理与关联，资源生命周期交给该请求 Scope。 */
final class LogMiddleware implements MiddlewareInterface
{
    private string $file;
    /** 登记专属 HTTP 日志文件，不在构造阶段打开输出。 */
    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /** 检查请求 Scope 后绑定 Logger，再将其通过请求属性传给处理器。 */
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

/** 输出请求关联与失败记录，核对旧 Logger 不能污染下一次请求。 */
final class Handler implements RequestHandlerInterface
{
    private PreviousLogger $previous;
    /** 注入持有旧 Logger 的演练对象，不把它作为生产日志共享方式。 */
    public function __construct(PreviousLogger $previous)
    {
        $this->previous = $previous;
    }

    /** 使用当前请求 Logger 写日志，按 /fail 触发受控失败并验证关联隔离。 */
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
            \Swoole\Coroutine::sleep(0.01);
            $marker = $request->getHeaderLine('X-Test-Marker');
            $logger->notice('request-step', ['marker' => $marker, 'password' => 'http-step-secret']);
            $value = ['marker' => $marker];
        }
        $messages = new Factory();
        return $messages->createResponse()->withHeader('Content-Type', 'application/json')
            ->withBody($messages->createStream((string) json_encode($value, JSON_THROW_ON_ERROR)));
    }
}
