<?php

declare(strict_types=1);

namespace TypeApp\Rollout;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\Message\Factory;
use Type\Orm\Database;
use Type\Orm\Driver;

final class Endpoint implements RequestHandlerInterface
{
    private Driver $driver;
    private int $release;
    private int $schema;
    public function __construct(Driver $driver, int $release, int $schema)
    {
        $this->driver = $driver;
        $this->release = $release;
        $this->schema = $schema;
    }
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        $database = new Database($this->driver, 1, 0);
        $redis = Application::redis();
        try {
            $cache = Application::cache($redis, $scope, $this->release);
            $bypass = ($request->getQueryParams()['strong'] ?? '') === '1';
            $cached = $cache->get('user');
            $column = $this->schema === 3 ? 'nickname' : 'name';
            $name = $cache->remember('user', static fn (): string => (string) $database->connect($scope)->table('rollout_users')->where('id', '=', 'user')->first()[$column], null, $bypass);
            $messages = new Factory();
            return $messages->createResponse()->withHeader('Content-Type', 'application/json')
                ->withBody($messages->createStream(json_encode(['release' => $this->release, 'schema' => $this->schema, 'name' => $name, 'cache_hit' => !$bypass && $cached->hit()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        } finally {
            $database->close();
            $redis->close();
        }
    }
}
