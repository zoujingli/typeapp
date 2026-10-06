<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\bootstrap\ApplicationContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\Authentication;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;

/** Broker 探针的独立令牌策略；只消费启动配置，不接受客户或管理会话替代。 */
final class ProbeAuthentication implements MiddlewareInterface
{
    private string $token;

    /** 请求级策略实例复用宿主配置值，不拥有配置或任何连接。 */
    public function __construct(ApplicationContext $context, private Factory $messages)
    {
        $this->token = $context->settings()->text('app.broker.probe_token');
    }

    /** 使用既有 Bearer 解析和固定探针角色，保留未配置时一律拒绝。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authentication = new Authentication(
            fn (string $provided): ?Identity => $this->token !== '' && hash_equals($this->token, $provided) ? new Identity('broker-probe', ['broker_probe']) : null,
            static fn (Identity $identity, CanonicalRequest $canonical, string $method): bool => in_array('broker_probe', $identity->roles(), true),
            $this->messages,
            $this->messages
        );
        return $authentication->process($request, $handler);
    }
}
