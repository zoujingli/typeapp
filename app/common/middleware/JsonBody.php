<?php

declare(strict_types=1);

namespace app\common\middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\HttpError;
use Type\Validate\ValidationException;

/** 保留既有业务 JSON 媒体类型和空正文错误；解析与有界读取归生成动作。 */
final class JsonBody implements MiddlewareInterface
{
    /** 不消费正文，避免与类型化动作重复读取或改变流位置。 */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        if ($request->getBody()->getSize() === 0) {
            throw new ValidationException(['body' => ['invalid_json']], 400, 'invalid_json');
        }
        return $handler->handle($request);
    }
}
