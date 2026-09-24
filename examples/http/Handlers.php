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

/** 被容器注入的简单问候值，用于观察 HTTP 处理器依赖装配。 */
final class Greeting
{
    private string $message;

    /** 保存启动配置中的问候文本，不读取请求状态。 */
    public function __construct(string $message)
    {
        $this->message = $message;
    }

    /** 返回固定问候文本，便于识别配置快照。 */
    public function text(): string
    {
        return $this->message;
    }
}

/** 记录单次请求资源的开启和关闭，外部测试用迹线检查异常路径收尾。 */
final class RequestResource implements ManagedResource
{
    private string $file;
    private string $marker;

    /** 登记本次资源的迹线路径与标记，空路径表示不输出。 */
    public function __construct(string $file, string $marker)
    {
        $this->file = $file;
        $this->marker = $marker;
    }

    /** 追加开启标记，证明资源已经进入请求作用域。 */
    public function start(): void
    {
        if ($this->file !== '') {
            file_put_contents($this->file, 'open:' . $this->marker . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    /** 追加关闭标记，证明成功或异常请求均执行清理。 */
    public function stop(): void
    {
        if ($this->file !== '') {
            file_put_contents($this->file, 'close:' . $this->marker . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}

/** 通过请求属性和响应记录中间件顺序及调用次数，供并发隔离验收。 */
final class MarkerMiddleware implements MiddlewareInterface
{
    private string $marker;
    private int $calls = 0;

    /** 固定当前中间件的顺序标记，不保存全局请求。 */
    public function __construct(string $marker)
    {
        $this->marker = $marker;
    }

    /** 追加本实例轨迹并让出一次协程，观察并发请求是否串用中间件状态。 */
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

/** 返回问候与请求关联，并登记可观察的资源清理，供基础 HTTP 验收。 */
final class HealthHandler implements RequestHandlerInterface
{
    private Greeting $greeting;
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;
    private string $traceFile;
    private int $calls = 0;
    private string $marker = '';

    /** 注入共享消息工厂、问候依赖与专属迹线位置。 */
    public function __construct(Greeting $greeting, ResponseFactoryInterface $responses, StreamFactoryInterface $streams, string $traceFile)
    {
        $this->greeting = $greeting;
        $this->responses = $responses;
        $this->streams = $streams;
        $this->traceFile = $traceFile;
    }

    /** 要求请求携带自身 Scope，登记资源后构造健康响应。 */
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

/** 故意在资源开启后失败，验证 HTTP 异常收尾和敏感信息隔离。 */
final class FailingHandler implements RequestHandlerInterface
{
    private string $traceFile;

    /** 登记故障请求使用的迹线路径。 */
    public function __construct(string $traceFile)
    {
        $this->traceFile = $traceFile;
    }

    /**
     * 登记资源后抛出含占位秘密的错误，响应层不得原样泄漏该消息。
     *
     * @throws RuntimeException 此故障入口总是失败。
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        $scope->open(new RequestResource($this->traceFile, 'failed'));
        throw new RuntimeException('不能暴露的密码 password=example');
    }
}
