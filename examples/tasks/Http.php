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

/** 在工作执行单元中延迟建立示例数据库实例，观察请求子任务对租约的持有。 */
final class Connections
{
    private Driver $driver;
    private ?Database $database = null;
    /** 保存驱动声明，构造时不建立物理连接。 */
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }
    /** 首次使用才建立受限数据库实例，后续请求复用同一管理对象。 */
    public function get(): Database
    {
        $this->database ??= new Database($this->driver, 1, 1);
        return $this->database;
    }
}

/** 将资源开启和关闭写入专属迹线，用于核对迟完成任务的收尾顺序。 */
final class Trace implements ManagedResource
{
    private string $file;
    private string $marker;
    /** 登记迹线文件与单次执行标记，不在构造时写文件。 */
    public function __construct(string $file, string $marker)
    {
        $this->file = $file;
        $this->marker = $marker;
    }
    /** 追加开启记录，使外部测试观察资源已进入作用域。 */
    public function start(): void
    {
        file_put_contents($this->file, 'open:' . $this->marker . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    /** 追加关闭记录，使外部测试核对真实清理时刻。 */
    public function stop(): void
    {
        file_put_contents($this->file, 'close:' . $this->marker . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

/** 通过受管请求子任务制造完成、取消与延迟释放，验证数据库归属。 */
final class Endpoint implements RequestHandlerInterface
{
    private Connections $connections;
    private string $mode;
    private string $trace;
    /** 注入本执行单元连接来源、故障模式与专属迹线路径。 */
    public function __construct(Connections $connections, string $mode, string $trace)
    {
        $this->connections = $connections;
        $this->mode = $mode;
        $this->trace = $trace;
    }
    /** 在当前 HTTP 作用域中运行指定任务模式；不把子任务尚未结束视为已释放。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        $database = $this->connections->get();
        $factory = new Factory();
        if (!$scope instanceof ExecutionScope || ExecutionScope::current() !== $scope) {
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
