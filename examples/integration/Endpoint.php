<?php

declare(strict_types=1);

namespace TypeApp\Integration;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\Message\Factory;
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;

final class Endpoint implements RequestHandlerInterface
{
    private Driver $driver;
    private string $application;
    public function __construct(Driver $driver, string $application)
    {
        $this->driver = $driver;
        $this->application = $application;
    }
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $data = (new Schema(['id' => Field::integer()->from('query')->required()->cast()->range(1, PHP_INT_MAX)]))
            ->validate((new Input([]))->withQuery($request->getUri()->getQuery()));
        $id = $data->get('id');
        $scope = $request->getAttribute('type.scope');
        $database = new Database($this->driver, 1, 0);
        $redis = Scenario::redis();
        try {
            $cache = Scenario::cache($redis, $scope, $this->application);
            $view = $cache->remember('article:' . $id, static fn (): array => Reader::article($database->connect($scope), $id));
            $messages = new Factory();
            return $messages->createResponse()->withHeader('Content-Type', 'application/json')
                ->withBody($messages->createStream(json_encode($view, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        } finally {
            $database->close();
            $redis->close();
        }
    }
}
