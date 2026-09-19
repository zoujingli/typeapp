<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TenantResolver implements MiddlewareInterface
{
    private array $tenants = [];
    private Closure $authorize;
    /** @param Closure(Identity, Tenant): bool $authorize 依次接收已认证身份和目标租户。 */
    public function __construct(array $tenants, Closure $authorize)
    {
        if ($tenants === [] || count($tenants) > 64) {
            throw new \InvalidArgumentException('最多声明 64 个固定租户资源');
        }
        $connections = [];
        $cacheSpaces = [];
        foreach ($tenants as $tenant) {
            if (!$tenant instanceof Tenant || isset($this->tenants[$tenant->id()]) || isset($connections[$tenant->connection()]) || isset($cacheSpaces[$tenant->cacheNamespace()])) {
                throw new \InvalidArgumentException('租户资源映射重复或无效');
            }
            $this->tenants[$tenant->id()] = $tenant;
            $connections[$tenant->connection()] = true;
            $cacheSpaces[$tenant->cacheNamespace()] = true;
        }
        $this->authorize = $authorize;
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        $values = $request->getHeader('X-Tenant');
        if (count($values) !== 1 || !isset($this->tenants[$values[0]])) {
            throw new HttpError(403, 'tenant_forbidden');
        }
        $tenant = $this->tenants[$values[0]];
        if (($this->authorize)($identity, $tenant) !== true) {
            throw new HttpError(403, 'tenant_forbidden');
        }
        return $handler->handle($request->withoutHeader('X-Tenant')->withAttribute('type.tenant', $tenant));
    }
}
