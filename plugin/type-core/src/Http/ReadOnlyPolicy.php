<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** 路由匹配后的只读边界；应用显式列出允许 POST 的认证路由，不根据 URL 前缀放行。 */
final class ReadOnlyPolicy implements MiddlewareInterface
{
    /** @param list<string> $authenticationRoutes 允许普通登录、退出等认证操作的准确路由名。 */
    public function __construct(private array $authenticationRoutes = [])
    {
    }

    /** 读取仍经过原有认证与授权；业务处理器和正文中间件在拒绝时均不执行。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $route = (string) $request->getAttribute('type.route', '');
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            && !($method === 'POST' && $route !== '' && in_array($route, $this->authenticationRoutes, true))) {
            throw new HttpError(403, 'demo_readonly');
        }
        return $handler->handle($request);
    }
}
