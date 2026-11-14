<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Orm\Database;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

/**
 * SQLite 反向生成必须保留索引、外键及可结构化的检查约束。
 * @internal
 */
final class SqliteMigrationDescribeTest extends TestCase
{
    public function testDescribeKeepsIndexesForeignKeysAndChecks(): void
    {
        $scope = new ExecutionScope();
        $database = new Database(new SqliteDriver(':memory:'), 1, 0);
        try {
            $connection = $database->connect($scope);
            $connection->execute('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
            $connection->execute("CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, code TEXT NOT NULL UNIQUE, UNIQUE (parent_id), CHECK (length(code) > 0), FOREIGN KEY (parent_id) REFERENCES parent(id))");
            $connection->execute('CREATE UNIQUE INDEX child_parent_unique ON child (parent_id, code)');

            $tables = $connection->driver()->migrations()->describe($connection);
            $child = array_values(array_filter($tables, static fn (array $table): bool => $table['name'] === 'child'))[0] ?? null;
            self::assertIsArray($child);
            self::assertContains(['name' => 'child_parent_unique', 'columns' => ['parent_id', 'code'], 'unique' => true], $child['indexes']);
            self::assertTrue(count(array_filter($child['indexes'], static fn (array $index): bool => $index['columns'] === ['code'] && $index['unique'])) === 1);
            self::assertCount(3, $child['indexes']);
            self::assertContains(['columns' => ['parent_id'], 'on' => 'parent', 'references' => ['id']], array_map(static function (array $foreign): array {
                unset($foreign['name'], $foreign['onDelete']);
                return $foreign;
            }, $child['foreign']));
            self::assertContains('CHECK (length(code) > 0)', $child['checks']);
            self::assertSame([], $child['unsupported']);
        } finally {
            $scope->close();
            $database->close();
        }
    }
}
