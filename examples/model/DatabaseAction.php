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

/** 将明确的数据库验收回调接入 PSR HTTP，不通过动态文件或函数名分派。 */
final class DatabaseAction implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;
    private Closure $action;

    /**
     * 注入响应工厂和固定回调，回调仅使用当前作用域主连接。
     *
     * @param Closure(Connection): array<string, mixed> $action 要执行的显式数据库行为。
     */
    public function __construct(ResponseFactoryInterface $responses, StreamFactoryInterface $streams, Closure $action)
    {
        $this->responses = $responses;
        $this->streams = $streams;
        $this->action = $action;
    }

    /** 要求有效请求 Scope，再执行回调并编码 JSON；连接由作用域收尾。 */
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
