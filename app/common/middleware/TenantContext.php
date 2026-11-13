<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\bootstrap\ApplicationContext;
use app\platform\model\Tenant;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\HttpError;
use Type\Runtime\ExecutionScope;

/** 规范化入口到不可变 客户系统 范围；域名和路径只是定位，人员资格仍由会话验证。 */
final class TenantContext implements MiddlewareInterface
{
    private string $baseDomain;

    /** 构造只读取已校验配置，不借用数据库。 */
    public function __construct(ApplicationContext $context)
    {
        $this->baseDomain = strtolower($context->settings()->text('app.tenant.base_domain'));
    }

    /** 应用归属名映射到 ORM 的通用租户上下文，子作用域同时保留 客户系统 语义。 */
    public static function bindings(string $tenantId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $tenantId) !== 1) {
            throw new HttpError(403, 'tenant_context_required');
        }
        return ['tenant_id' => $tenantId];
    }

    /** 只读取服务端请求属性，客户端同名头不能建立上下文。 */
    public static function id(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('app.tenant_id', '');
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new HttpError(403, 'tenant_context_required');
        }
        return $id;
    }

    /** RequestPolicy 在规范化代理与 Host 后调用；仅接受登记的一级客户子域名。 */
    public static function allowsAuthority(string $authority, string $baseDomain): bool
    {
        $host = (string)parse_url('http://' . $authority, PHP_URL_HOST);
        $label = self::label($host, $baseDomain);
        return $label !== '' && Tenant::query()->master()->where('subdomain', '=', $label)->exists();
    }

    /** 绑定入口建立新执行作用域；子域名不能进入平台，混用两个客户标识直接拒绝。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $canonical = $request->getAttribute('type.request');
        $scope = $request->getAttribute('type.scope');
        if (!$canonical instanceof CanonicalRequest || !$scope instanceof ExecutionScope) {
            throw new \LogicException('tenant_context_requires_canonical_scope');
        }
        foreach (['X-Tenant-Id', 'X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
            if ($request->hasHeader($header)) {
                throw new HttpError(403, 'identity_context_invalid');
            }
        }
        $path = $request->getUri()->getPath();
        $label = self::label($request->getUri()->getHost(), $this->baseDomain);
        $tenant = $label === '' ? null : Tenant::query()->master()->where('subdomain', '=', $label)->first();
        if ($label !== '' && $tenant === null) {
            throw new HttpError(404, 'tenant_not_found');
        }
        $prefix = '';
        if (preg_match('#^/s/([a-f0-9]{32})(/.*)?$#D', $path, $matches) === 1) {
            if ($tenant !== null && $tenant->get('id') !== $matches[1]) {
                throw new HttpError(403, 'tenant_context_mismatch');
            }
            $tenant = Tenant::query()->master()->find($matches[1]);
            if ($tenant === null) {
                throw new HttpError(404, 'tenant_not_found');
            }
            $prefix = '/s/' . $matches[1];
            $path = $matches[2] ?? '/';
        }
        if ($tenant !== null && !$tenant->get('enabled')) {
            throw new HttpError(403, 'tenant_disabled');
        }
        if ($tenant !== null && ($path === '/platform' || str_starts_with($path, '/platform/'))) {
            throw new HttpError(404, 'not_found');
        }
        if ($tenant === null && (str_starts_with($path, '/system/') || str_starts_with($path, '/center/'))) {
            throw new HttpError(403, 'tenant_context_required');
        }
        $uri = $request->getUri()->withPath($path);
        $tenantId = $tenant === null ? '' : (string)$tenant->get('id');
        $request = $request->withUri($uri)->withAttribute('type.request', new CanonicalRequest(
            (string)$uri,
            $path,
            $uri->getQuery(),
            $prefix === '' ? $canonical->segments() : array_slice($canonical->segments(), 2),
            $canonical->clientIp()
        ))->withAttribute('app.tenant_id', $tenantId)->withAttribute('app.tenant_base', $prefix)
            ->withAttribute('app.tenant_bound', $tenant !== null);
        if ($tenant === null) {
            return $handler->handle($request);
        }
        return $scope->run(static function (ExecutionScope $current) use ($request, $handler): ResponseInterface {
            return $handler->handle($request->withAttribute('type.scope', $current));
        }, self::bindings($tenantId));
    }

    /** 空值表示主域名入口；主域名本身不会被解释为客户。 */
    private static function label(string $host, string $baseDomain): string
    {
        if ($baseDomain === '' || !str_ends_with($host, '.' . $baseDomain)) {
            return '';
        }
        $label = substr($host, 0, -strlen($baseDomain) - 1);
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) === 1 ? $label : '';
    }
}
