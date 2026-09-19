<?php

declare(strict_types=1);

namespace TypeApp\TaskExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\Message\Factory;
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;
use Type\Runtime\TaskException;

final class Connections
{
    private Driver $driver;
    private ?Database $database = null;
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }
    public function get(): Database
    {
        $this->database ??= new Database($this->driver, 1, 1);
        return $this->database;
    }
}

final class Trace implements ManagedResource
{
    private string $file;
    private string $marker;
    public function __construct(string $file, string $marker)
    {
        $this->file = $file;
        $this->marker = $marker;
    }
    public function start(): void
    {
        file_put_contents($this->file, 'open:' . $this->marker . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    public function stop(): void
    {
        file_put_contents($this->file, 'close:' . $this->marker . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

final class Endpoint implements RequestHandlerInterface
{
    private Connections $connections;
    private string $mode;
    private string $trace;
    public function __construct(Connections $connections, string $mode, string $trace)
    {
        $this->connections = $connections;
        $this->mode = $mode;
        $this->trace = $trace;
    }
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        $database = $this->connections->get();
        $factory = new Factory();
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('缺少请求作用域');
        }
        $status = 200;
        if ($this->mode === 'stats') {
            $result = $database->statistics();
        } elseif ($this->mode === 'probe') {
            try {
                $connection = $database->connect($scope, 0);
                $result = $connection->query('SELECT 7 AS marker')[0];
            } catch (\RuntimeException $error) {
                $status = 503;
                $result = ['error' => 'pool_busy'];
            }
        } else {
            $mode = $this->mode;
            $trace = $this->trace;
            $task = $scope->spawn(static function (ExecutionScope $child) use ($database, $mode, $trace): array {
                $child->open(new Trace($trace, $mode));
                $connection = $database->connect($child);
                return $connection->query('SELECT SLEEP(0.2) AS waited')[0];
            });
            try {
                $result = $task->await($this->mode === 'timeout' ? 0.02 : null);
            } catch (TaskException $error) {
                $status = 503;
                $result = ['error' => $error->errorCode()];
            }
        }
        return $factory->createResponse($status)->withHeader('Content-Type', 'application/json')->withBody($factory->createStream((string) json_encode($result, JSON_THROW_ON_ERROR)));
    }
}
