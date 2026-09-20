<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Closure;
use Type\Orm\Connection;
use Type\Runtime\ExecutionScope;

final class DatabaseAction implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;
    private Closure $action;

    public function __construct(ResponseFactoryInterface $responses, StreamFactoryInterface $streams, Closure $action)
    {
        $this->responses = $responses;
        $this->streams = $streams;
        $this->action = $action;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new RuntimeException('数据操作请求缺少作用域');
        }
        $result = ($this->action)(\Type\Orm\Db::connection('default', true));
        return $this->responses->createResponse(200)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($result, JSON_THROW_ON_ERROR)));
    }
}
