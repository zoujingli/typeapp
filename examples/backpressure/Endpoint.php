<?php

declare(strict_types=1);

namespace TypeApp\Backpressure;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\HttpControl;
use Type\Core\Http\Message\Factory;
use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Orm\DatabaseManager;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

/** 用真实数据库等待、日志和部署预算观察 HTTP 背压及停止状态。 */
final class Endpoint implements RequestHandlerInterface
{
    private DatabaseManager $databases;
    private HttpControl $control;
    private DeploymentBudget $budget;
    /** 借用数据库、HTTP 控制与部署预算，不另行放大连接额度。 */
    public function __construct(DatabaseManager $databases, HttpControl $control, DeploymentBudget $budget)
    {
        $this->databases = $databases;
        $this->control = $control;
        $this->budget = $budget;
    }
    /** 按显式路径触发受控负载或统计查询；日志绑定当前请求作用域。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        $factory = new Factory();
        $path = $request->getUri()->getPath();
        $logs = new LogManager('backpressure', ['http' => new Channel(Output::file((string) getenv('TYPE_BACKPRESSURE_LOG')))], 0.02);
        $scope->open($logs);
        $logger = $logs->logger($scope, [], 'http');
        $logger->info('接收请求', ['path' => $path, 'worker' => getmypid()]);
        if ($path === '/state') {
            $data = ['http' => $this->control->statistics(), 'database' => $this->databases->statistics(), 'budget' => $this->budget->poolBudget()->statistics(),
                'deployment' => $this->budget->statistics(), 'memory' => memory_get_usage(true), 'pid' => getmypid()];
        } elseif ($path === '/stop') {
            $this->control->stop();
            $data = $this->control->statistics();
        } elseif ($path === '/hold') {
            \Swoole\Coroutine::sleep(0.25);
            $scope->assertActive();
            $data = ['done' => true];
        } elseif ($path === '/leak') {
            $databases = $this->databases;
            $task = $scope->spawn(static function (ExecutionScope $child) use ($databases): void {
                $databases->connect($child)->query('SELECT SLEEP(2)');
            });
            try {
                $task->await(0.1);
            } catch (\Type\Runtime\TaskException $error) {
                if ($error->errorCode() !== 'task_timeout') {
                    throw $error;
                }
            }
            $data = ['pid' => getmypid()];
        } else {
            $connection = $this->databases->connect($scope, $path === '/alternate' ? 'alternate' : 'default');
            file_put_contents((string) getenv('TYPE_BACKPRESSURE_TRACE'), $path . "\n", FILE_APPEND | LOCK_EX);
            if ($path === '/slow' || $path === '/alternate') {
                // Swoole 6.2 没有 MySQL PDO 钩子，查询里的 SLEEP 会占住唯一 worker。
                // 租约在协程休眠期间保持，后续借用才能撞上共享额度。
                \Swoole\Coroutine::sleep(0.25);
            }
            $data = $connection->query('SELECT SLEEP(' . ($path === '/deadline' ? '0.8' : '0') . ') AS waited')[0];
        }
        return $factory->createResponse()->withHeader('Content-Type', 'application/json')->withBody($factory->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
    }
}
