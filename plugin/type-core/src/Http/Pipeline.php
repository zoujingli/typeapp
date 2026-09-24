<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/** 按显式次序组合 PSR-15 中间件，每次请求保留独立的后续处理位置。 */
final class Pipeline implements RequestHandlerInterface
{
    private array $factories;
    private RequestHandlerInterface $handler;
    private int $position;

    /** @param list<\Closure(): MiddlewareInterface> $factories 每次处理都调用零参数工厂。 */
    public function __construct(array $factories, RequestHandlerInterface $handler, int $position = 0)
    {
        $this->factories = $factories;
        $this->handler = $handler;
        $this->position = $position;
    }

    /** 执行当前位置的中间件，末尾交给最终处理器；异常按调用链向外传播。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->position >= count($this->factories)) {
            return $this->handler->handle($request);
        }
        $middleware = ($this->factories[$this->position])();
        if (!$middleware instanceof MiddlewareInterface) {
            throw new RuntimeException('中间件工厂没有返回 PSR-15 中间件');
        }

        return $middleware->process($request, new Pipeline($this->factories, $this->handler, $this->position + 1));
    }
}
