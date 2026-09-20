<?php

declare(strict_types=1);

namespace app\iot\middleware;

use app\common\service\IdentityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\Authentication;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Runtime\ExecutionScope;

/** 将人员会话认证接入既有 Bearer 中间件，连接仍由当前请求作用域持有。 */
final class IotAuthentication implements MiddlewareInterface
{
    /** 构造不借用数据库；只保存进程内受管资源入口。 */
    public function __construct(private IdentityService $identities, private Factory $messages)
    {
    }

    /** 角色在每次认证时读取，租户动作由业务服务按当前成员事实进一步校验。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('人员认证需要受管请求作用域');
        }
        $authentication = new Authentication(
            fn (string $token): ?Identity => $this->identities->authenticate($token),
            static fn (Identity $identity, CanonicalRequest $canonical, string $method): bool => true,
            $this->messages,
            $this->messages
        );
        return $authentication->process($request, $handler);
    }
}
