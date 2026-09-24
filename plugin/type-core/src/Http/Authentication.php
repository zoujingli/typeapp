<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/** 通过应用认证回调建立可信 Identity，再向后续处理链传递。 */
final class Authentication implements MiddlewareInterface
{
    private Closure $authenticate;
    private Closure $authorize;
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    /**
     * @param Closure(string): ?Identity $authenticate 接收 Bearer 令牌。
     * @param Closure(Identity, CanonicalRequest, string): bool $authorize 依次接收身份、规范请求和 HTTP 方法。
     */
    public function __construct(Closure $authenticate, Closure $authorize, ResponseFactoryInterface $responses, StreamFactoryInterface $streams)
    {
        $this->authenticate = $authenticate;
        $this->authorize = $authorize;
        $this->responses = $responses;
        $this->streams = $streams;
    }

    /** 校验本次请求的认证结果并设置 type.identity；失败返回认证错误。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $canonical = $request->getAttribute('type.request');
        if (!$canonical instanceof CanonicalRequest) {
            throw new RuntimeException('鉴权前必须执行 RequestPolicy');
        }
        $headers = $request->getHeader('Authorization');
        $matches = [];
        if (count($headers) !== 1 || !preg_match('/^Bearer ([A-Za-z0-9._~+\/-]+=*)$/D', $headers[0], $matches)) {
            return $this->error(401, 'unauthenticated')->withHeader('WWW-Authenticate', 'Bearer');
        }
        $identity = ($this->authenticate)($matches[1]);
        if ($identity === null) {
            return $this->error(401, 'unauthenticated')->withHeader('WWW-Authenticate', 'Bearer');
        }
        if (!$identity instanceof Identity) {
            throw new RuntimeException('认证器必须返回 Identity 或 null');
        }
        if (($this->authorize)($identity, $canonical, $request->getMethod()) !== true) {
            return $this->error(403, 'forbidden');
        }
        return $handler->handle($request->withAttribute('type.identity', $identity));
    }

    private function error(int $status, string $code): ResponseInterface
    {
        return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode(['error' => $code], JSON_THROW_ON_ERROR)));
    }
}
