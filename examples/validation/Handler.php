<?php

declare(strict_types=1);

namespace TypeApp\ValidationExample;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Validate\Input;
use Type\Validate\ValidationException;

/** 将分源校验接入 HTTP，保留内容类型、解析和字段错误的不同状态。 */
final class Handler implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    /** 注入 PSR 响应与流工厂，校验规则不依赖 HTTP 运行时全局状态。 */
    public function __construct(ResponseFactoryInterface $responses, StreamFactoryInterface $streams)
    {
        $this->responses = $responses;
        $this->streams = $streams;
    }

    /** 只接受 JSON 内容类型，在预算内解析正文和 query，再输出有效数据或安全字段错误。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new ValidationException(['body' => ['json_required']], 415, 'unsupported_media_type');
            }
            $input = Input::json($request->getBody()->getContents(), 2048, 8)->withQuery($request->getUri()->getQuery());
            $data = UserInput::schema()->validate($input, 'default', $request->getMethod() === 'PATCH');
            $status = 200;
            $result = ['data' => $data->toArray()];
        } catch (ValidationException $error) {
            $status = $error->status();
            $result = ['error' => $error->errorCode(), 'fields' => $error->errors()];
        }
        return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streams->createStream((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
