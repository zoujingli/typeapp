<?php

declare(strict_types=1);

namespace TypeApp\TrustExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Core\Http\RequestSignature;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;

/** 只接受经请求策略规范化与认证的输入，展示可相信的请求身份。 */
final class Endpoint implements RequestHandlerInterface
{
    /** 验证规范请求与身份对象已经就绪，再返回允许观察的字段。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $canonical = $request->getAttribute('type.request');
        $identity = $request->getAttribute('type.identity');
        if (!$canonical instanceof CanonicalRequest || !$identity instanceof Identity) {
            throw new \RuntimeException('请求未经过规范化与认证');
        }
        $factory = new Factory();
        $body = RequestBody::read($request->getBody());
        if ($body !== RequestBody::read($request->getBody())) {
            throw new \RuntimeException('正文不能重放');
        }
        $signature = new RequestSignature(str_repeat('test-signing-key-', 3));
        $signed = $signature->sign($canonical, $request->getMethod(), $body, 100);
        if (!$signature->verify($canonical, $request->getMethod(), $body, 100, $signed, 99)
            || $signature->verify($canonical, $request->getMethod(), $body . 'tampered', 100, $signed, 99)) {
            throw new \RuntimeException('规范化签名不一致');
        }
        $status = 200;
        $value = ['uri' => $canonical->uri(), 'client' => $canonical->clientIp(), 'subject' => $identity->subject(),
            'query' => $request->getQueryParams(), 'body' => $body, 'forwarded_visible' => $request->hasHeader('X-Forwarded-Host')];
        if ($request->getMethod() === 'POST') {
            try {
                (new Schema(['name' => Field::text()->required()->length(1, 100)]))->validate(Input::json($body));
            } catch (ValidationException $error) {
                $status = $error->status();
                $value = ['error' => $error->errorCode()];
            }
        }
        return $factory->createResponse($status)->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
