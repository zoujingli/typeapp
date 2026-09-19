<?php

declare(strict_types=1);

namespace TypeApp\RoutingExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class TraceMiddleware implements MiddlewareInterface
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
        if ($this->calls !== 1) {
            throw new RuntimeException('中间件实例跨请求复用');
        }
        $response = $handler->handle($request->withAttribute('trace', $request->getAttribute('trace', '') . $this->marker));
        return $response->withHeader('X-Return', $response->getHeaderLine('X-Return') . $this->marker);
    }
}
