<?php

declare(strict_types=1);

namespace Type\Core\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** 按明确来源、方法与头白名单处理 CORS，并区分预检与业务请求。 */
final class Cors implements MiddlewareInterface
{
    private array $origins;
    private array $methods;
    private array $headers;
    private bool $credentials;
    private ResponseFactoryInterface $responses;

    /**
     * 建立跨域访问白名单，凭据模式不能采用通配来源。
     * @param list<string> $origins 允许的来源。
     * @param list<string> $methods 允许的 HTTP 方法。
     * @param list<string> $headers 允许的请求头。
     */
    public function __construct(
        ResponseFactoryInterface $responses,
        array $origins,
        array $methods = ['GET', 'POST', 'PATCH', 'DELETE'],
        array $headers = ['authorization', 'content-type'],
        bool $credentials = false
    ) {
        if ($origins === [] || ($credentials && in_array('*', $origins, true))) {
            throw new InvalidArgumentException('CORS 凭据必须搭配明确来源');
        }
        foreach ($origins as $origin) {
            if (!is_string($origin) || ($origin !== '*' && !preg_match('/^https?:\/\/[A-Za-z0-9.\[\]:-]+$/D', $origin))) {
                throw new InvalidArgumentException('CORS origin 无效');
            }
        }
        foreach ($methods as $method) {
            if (!is_string($method) || !preg_match('/^[A-Z]+$/D', $method)) {
                throw new InvalidArgumentException('CORS 方法无效');
            }
        }
        foreach ($headers as $header) {
            if (!is_string($header) || !preg_match('/^[a-z0-9-]+$/D', $header)) {
                throw new InvalidArgumentException('CORS 消息头名称无效');
            }
        }
        $this->responses = $responses;
        $this->origins = array_values($origins);
        $this->methods = array_values($methods);
        $this->headers = array_values($headers);
        $this->credentials = $credentials;
    }

    /** 先校验跨域来源与预检，再为业务响应添加适用的 CORS 头。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $handler->handle($request)->withAddedHeader('Vary', 'Origin');
        }
        $allowed = in_array($origin, $this->origins, true) || in_array('*', $this->origins, true);
        if (!$allowed || count($request->getHeader('Origin')) !== 1 || !preg_match('/^https?:\/\/[A-Za-z0-9.\[\]:-]+$/D', $origin)) {
            return $this->responses->createResponse(403)->withHeader('Vary', 'Origin');
        }
        if ($request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            $method = $request->getHeaderLine('Access-Control-Request-Method');
            $headers = $request->getHeaderLine('Access-Control-Request-Headers');
            $requested = $headers === '' ? [] : array_map('trim', explode(',', strtolower($headers)));
            if (!in_array($method, $this->methods, true) || array_diff($requested, $this->headers) !== []) {
                return $this->responses->createResponse(403)->withHeader('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
            }
            $response = $this->responses->createResponse(204)->withHeader('Access-Control-Allow-Methods', implode(', ', $this->methods))
                ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->headers))->withHeader('Access-Control-Max-Age', '600')
                ->withHeader('Vary', 'Access-Control-Request-Method, Access-Control-Request-Headers');
        } else {
            $response = $handler->handle($request);
        }
        $response = $response->withHeader('Access-Control-Allow-Origin', in_array('*', $this->origins, true) ? '*' : $origin)->withAddedHeader('Vary', 'Origin');
        return $this->credentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }
}
