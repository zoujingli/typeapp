<?php

declare(strict_types=1);

namespace TypeApp\RoutingExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/** 记录分层中间件轨迹，并拒绝同一实例被多个请求复用。 */
final class TraceMiddleware implements MiddlewareInterface
{
    private string $marker;
    private int $calls = 0;

    /** 固定本层轨迹标记，调用计数从当前实例开始。 */
    public function __construct(string $marker)
    {
        $this->marker = $marker;
    }

    /** 追加进入轨迹并调用后续处理器，响应携带本层的顺序观察。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->calls++;
        if ($this->calls !== 1) {
            throw new RuntimeException('中间件实例跨请求复用');
        }
        $response = $handler->handle($request->withAttribute('trace', $request->getAttribute('trace', '') . $this->marker));
        return $response->withHeader('X-Return', $response->getHeaderLine('X-Return') . $this->marker);
    }
}
