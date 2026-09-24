<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** 将构建器生成的直接控制器调用接入既有 PSR-15 处理链。 */
final class ActionHandler implements RequestHandlerInterface
{
    private Closure $action;

    /** @param Closure(ServerRequestInterface): ResponseInterface $action 接收本次请求。 */
    public function __construct(Closure $action)
    {
        $this->action = $action;
    }

    /** 把本次请求交给构造时的操作，直接返回响应或传播业务异常。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->action)($request);
    }
}
