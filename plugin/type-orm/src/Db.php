<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use Swoole\Coroutine;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 应用装配数据库管理器；活动连接只保存在当前协程的执行作用域中。 */
final class Db implements ManagedResource
{
    private const CONTEXT_KEY = 'type.orm.scopes';
    private static ?DatabaseManager $configured = null;
    private static int $active = 0;
    private array $sessions = [];
    private bool $closed = false;

    private function __construct(private DatabaseManager $manager, private ExecutionScope $scope)
    {
    }

    /** 在所属进程/线程启动期装配；管理器只有配置和池，不携带当前请求连接。 */
    public static function configure(DatabaseManager $manager): void
    {
        if (self::$active !== 0 && self::$configured !== $manager) {
            throw new ModelException('database_configuration_in_use', '活动作用域尚未结束，不能替换数据库管理器');
        }
        self::$configured = $manager;
    }

    /**
     * @param Closure(): mixed $operation 同数据源事务体，无连接参数。
     * @param string $mode default 或 SQLite 的 immediate；遵守底层事务模式限制。
     */
    public static function transaction(Closure $operation, string $database = 'default', string $mode = 'default'): mixed
    {
        $session = self::current()->session($database);
        return $session->transaction(static fn (Connection $connection): mixed => $operation(), $mode);
    }

    /** @param Closure(): void $operation 提交确认后执行，不自动重试。 */
    public static function afterCommit(Closure $operation, string $database = 'default'): void
    {
        self::connection($database, true)->afterCommit($operation);
    }

    /** @internal Model 与生成关系在执行时选路；底层连接不得逃逸当前作用域。 */
    public static function connection(string $database = 'default', bool $master = false): Connection
    {
        $session = self::current()->session($database);
        return $master ? $session->write() : $session->read();
    }

    private static function current(): self
    {
        $scope = ExecutionScope::current();
        if (self::$configured === null) {
            throw new ModelException('database_not_configured', '应用尚未装配数据库管理器');
        }
        $context = Coroutine::getContext();
        $id = spl_object_id($scope);
        $scopes = $context[self::CONTEXT_KEY] ?? [];
        if (!isset($scopes[$id])) {
            $binding = new self(self::$configured, $scope);
            $scope->open($binding);
            $scopes[$id] = $binding;
            $context[self::CONTEXT_KEY] = $scopes;
        }
        return $scopes[$id];
    }

    private function session(string $database): ReadWriteSession
    {
        $this->scope->assertActive();
        if ($this->closed) {
            throw new ModelException('database_scope_closed', '当前数据库作用域已经关闭');
        }
        foreach ($this->sessions as $name => $session) {
            if ($name !== $database && $session->inTransaction()) {
                throw new ModelException('cross_database_transaction', '活动事务内不能访问另一逻辑数据源');
            }
        }
        $this->sessions[$database] ??= $this->manager->session($this->scope, $database);
        return $this->sessions[$database];
    }

    /** @internal 由 ExecutionScope 登记，不能单独开始业务事务。 */
    public function start(): void
    {
        $this->scope->assertActive();
        self::$active++;
    }

    /** @internal 解除当前协程引用，物理租约仍由原作用域按真实在途状态收尾。 */
    public function stop(): void
    {
        $this->scope->assertOwner();
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->sessions = [];
        $context = Coroutine::getContext();
        $scopes = $context[self::CONTEXT_KEY] ?? [];
        unset($scopes[spl_object_id($this->scope)]);
        if ($scopes === []) {
            unset($context[self::CONTEXT_KEY]);
        } else {
            $context[self::CONTEXT_KEY] = $scopes;
        }
        self::$active--;
    }
}
