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
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Runtime\ExecutionScope;

final class DatabaseAction implements RequestHandlerInterface
{
    private Driver $driver;
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;
    private Closure $action;

    public function __construct(Driver $driver, ResponseFactoryInterface $responses, StreamFactoryInterface $streams, Closure $action)
    {
        $this->driver = $driver;
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
        $database = new Database($this->driver, 1, 0);
        $connection = null;
        try {
            $connection = $database->connect($scope);
            $result = ($this->action)($connection);
        } finally {
            if ($connection !== null) {
                $connection->close();
            }
            $database->close();
        }
        return $this->responses->createResponse(200)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($result, JSON_THROW_ON_ERROR)));
    }
}
