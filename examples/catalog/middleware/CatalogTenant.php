<?php

declare(strict_types=1);

namespace app\catalog\middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Runtime\ExecutionScope;

/** 教程的固定授权映射：模板应用身份只能访问 catalog-a。 */
final class CatalogTenant implements MiddlewareInterface
{
    /** 已认证身份还须通过租户选择授权；成功后只绑定受信常量并移除选择头。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'authentication_required');
        }
        if ($identity->subject() !== 'application-api' || !in_array('users', $identity->roles(), true)) {
            throw new HttpError(403, 'catalog_forbidden');
        }
        if ($request->getHeader('X-Tenant') !== ['catalog-a']) {
            throw new HttpError(403, 'tenant_forbidden');
        }
        return ExecutionScope::current()->run(
            static fn (ExecutionScope $scope): ResponseInterface => $handler->handle($request->withoutHeader('X-Tenant')),
            ['tenant_id' => 'catalog-a']
        );
    }
}
