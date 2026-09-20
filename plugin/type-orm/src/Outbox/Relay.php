<?php

declare(strict_types=1);

namespace Type\Orm\Outbox;

use Type\Orm\Database;
use Type\Orm\DatabaseManager;
use Type\Orm\DatabaseException;
use Type\Runtime\ExecutionScope;

final class Relay
{
    private Database|DatabaseManager $database;
    private Store $store;
    private Publisher $publisher;
    public function __construct(Database|DatabaseManager $database, Store $store, Publisher $publisher)
    {
        $this->database = $database;
        $this->store = $store;
        $this->publisher = $publisher;
    }
    public function runOnce(int $limit = 100): int
    {
        $scope = new ExecutionScope();
        try {
            $records = $this->store->claim($this->database->connect($scope), $limit);
        } finally {
            $scope->close();
        }
        $published = 0;
        foreach ($records as $record) {
            // 发布时没有数据库事务或租约占用，接受后重新短连接标记。
            $receipt = $this->publisher->publish($record);
            $scope = new ExecutionScope();
            try {
                if (!$this->store->accepted($this->database->connect($scope), $record, $receipt)) {
                    throw new DatabaseException('发布完成但 Outbox token 已失效，消息可能重复，需要后续对账');
                }
                $published++;
            } finally {
                $scope->close();
            }
        }
        return $published;
    }
}
