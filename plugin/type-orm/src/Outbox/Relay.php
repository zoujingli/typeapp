<?php

declare(strict_types=1);

namespace Type\Orm\Outbox;

use Closure;
use Throwable;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\DatabaseManager;
use Type\Orm\DatabaseException;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ExecutionScope;
use Type\Runtime\WorkLifecycle;

/** 事务 Outbox 转发器；外部投递期间不占用数据库事务或租约。 */
final class Relay
{
    private Database|DatabaseManager $database;
    private Store $store;
    private Publisher $publisher;
    private string $source;
    private int $executionMilliseconds;
    private WorkLifecycle $lifecycle;
    private ExecutionOwner $owner;

    /**
     * 组合指定来源与真实 Publisher，数据库关闭仍由应用所有者负责。
     * 单一 Database 只接受 default；命名源必须通过 DatabaseManager 提供。
     */
    public function __construct(Database|DatabaseManager $database, Store $store, Publisher $publisher, string $source = 'default', int $executionMilliseconds = 30000)
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $source) || ($database instanceof Database && $source !== 'default')
            || $executionMilliseconds < 1 || $executionMilliseconds > 3600000) {
            throw new DatabaseException('Outbox Relay 来源或执行预算无效');
        }
        if ($database instanceof DatabaseManager && $database->identity($source)['role'] !== 'writer') {
            throw new DatabaseException('Outbox Relay 必须访问指定来源的主库');
        }
        $this->database = $database;
        $this->store = $store;
        $this->publisher = $publisher;
        $this->source = $source;
        $this->executionMilliseconds = $executionMilliseconds;
        $this->lifecycle = new WorkLifecycle();
        $this->owner = new ExecutionOwner();
    }

    /**
     * 用短作用域领取并逐条外部投递，目标接受后重新借同来源连接登记凭据。
     * @param int $limit 本批领取 1 至 1000 条消息。
     * @return int 已成功登记接受凭据的条数；停止或异常时可能已有部分消息接受。
     */
    public function runOnce(int $limit = 100): int
    {
        $this->owner->assertCurrent();
        if (!$this->lifecycle->begin()) {
            return 0;
        }
        $deadline = new Deadline($this->executionMilliseconds / 1000.0);
        try {
            $records = $this->scoped($deadline, fn (ExecutionScope $scope): array => $this->store->claim($this->connect($scope), $limit));
            $published = 0;
            foreach ($records as $record) {
                if (!$this->lifecycle->ready()) {
                    break;
                }
                // 发布 scope 没有数据库租约；超时或失败保留领取事实，不假定目标未接受。
                $receipt = $this->scoped($deadline, fn (ExecutionScope $scope): string => $this->publisher->publish($record));
                $accepted = $this->scoped($deadline, fn (ExecutionScope $scope): bool => $this->store->accepted($this->connect($scope), $record, $receipt));
                if (!$accepted) {
                    throw new DatabaseException('发布完成但 Outbox token 已失效，消息可能重复，需要后续对账');
                }
                $published++;
            }
            return $published;
        } catch (Throwable $error) {
            $this->lifecycle->stop(0.0);
            throw $error;
        } finally {
            $this->lifecycle->finish();
        }
    }

    /** 撤销新批次与下一条投递，并缩短当前工作的排空预算；不撤销已发生的外部效果。 */
    public function stop(float $drainSeconds = 5.0): void
    {
        $this->lifecycle->stop($drainSeconds);
    }

    /** 是否允许开始新批次；不代表当前批次已经完成。 */
    public function ready(): bool
    {
        return $this->lifecycle->ready();
    }

    /** @return array<string, int|bool|string> 当前角色就绪、在途及排空状态。 */
    public function statistics(): array
    {
        return $this->lifecycle->statistics();
    }

    private function connect(ExecutionScope $scope): Connection
    {
        return $this->database instanceof DatabaseManager
            ? $this->database->connect($scope, $this->source)
            : $this->database->connect($scope);
    }

    /** @param Closure(ExecutionScope): mixed $operation 当前有界阶段，结束后不保留连接。 */
    private function scoped(Deadline $deadline, Closure $operation): mixed
    {
        $scope = new ExecutionScope($deadline);
        $this->lifecycle->attach($scope);
        $failure = null;
        $result = null;
        try {
            $result = $scope->run($operation);
            $scope->assertActive();
        } catch (Throwable $error) {
            $failure = $error;
        }
        try {
            $scope->close();
        } catch (Throwable $cleanup) {
            if ($failure !== null) {
                throw new DatabaseException('Outbox 阶段失败且资源收尾失败：' . $cleanup->getMessage(), 0, $failure);
            }
            throw $cleanup;
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }
}
