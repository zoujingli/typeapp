<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Orm\Database;
use Type\Orm\Helper\QueryHelper;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

/**
 * @internal
 */
final class QueryHelperLogicTest extends TestCase
{
    public function testOneInputCanMatchEitherDeclaredField(): void
    {
        $scope = new ExecutionScope();
        $database = new Database(new SqliteDriver(':memory:'), 1, 0);
        try {
            $connection = $database->connect($scope);
            $sql = (new QueryHelper($connection->table('users'), ['keyword' => '厨房']))
                ->like('name|login#keyword')->query()->toSql();
            self::assertStringContainsString('LIKE', $sql);
            self::assertStringContainsString('OR', $sql);
            self::assertStringContainsString('name', $sql);
            self::assertStringContainsString('login', $sql);
        } finally {
            $scope->close();
            $database->close();
        }
    }

    public function testCallbackReturnsTheRefinedHelper(): void
    {
        $scope = new ExecutionScope();
        $database = new Database(new SqliteDriver(':memory:'), 1, 0);
        try {
            $connection = $database->connect($scope);
            $sql = \_query($connection->table('users'), ['keyword' => '厨房', 'status' => 'active'], static function (QueryHelper $helper): QueryHelper {
                return $helper->like('name|login#keyword')->equal('status');
            })->query()->toSql();
            self::assertStringContainsString('LIKE', $sql);
            self::assertStringContainsString('status', $sql);
        } finally {
            $scope->close();
            $database->close();
        }
    }

    public function testCallbackMustReturnTheHelper(): void
    {
        $scope = new ExecutionScope();
        $database = new Database(new SqliteDriver(':memory:'), 1, 0);
        try {
            $connection = $database->connect($scope);
            $this->expectException(\InvalidArgumentException::class);
            \_query($connection->table('users'), [], static fn (QueryHelper $helper): mixed => null);
        } finally {
            $scope->close();
            $database->close();
        }
    }

    public function testLikeCanWrapASeparatedToken(): void
    {
        $scope = new ExecutionScope();
        $database = new Database(new SqliteDriver(':memory:'), 1, 0);
        try {
            $connection = $database->connect($scope);
            $query = (new QueryHelper($connection->table('foods'), ['tags' => '早餐']))->like('tags', ',')->query();
            self::assertContains('%,早餐,%', $query->bindings());
            self::assertStringContainsString("ESCAPE '!'", $query->toSql());
        } finally {
            $scope->close();
            $database->close();
        }
    }

    public function testLikeTreatsUserWildcardsAsLiterals(): void
    {
        $scope = new ExecutionScope();
        $database = new Database(new SqliteDriver(':memory:'), 1, 0);
        try {
            $connection = $database->connect($scope);
            $query = (new QueryHelper($connection->table('foods'), ['name' => '100%_']))->like('name')->query();
            self::assertContains('%100!%!_%', $query->bindings());
        } finally {
            $scope->close();
            $database->close();
        }
    }

    public function testCombinedFieldsRequireAnInputName(): void
    {
        $scope = new ExecutionScope();
        $database = new Database(new SqliteDriver(':memory:'), 1, 0);
        try {
            $connection = $database->connect($scope);
            $this->expectException(\InvalidArgumentException::class);
            (new QueryHelper($connection->table('users'), []))->equal('name|login');
        } finally {
            $scope->close();
            $database->close();
        }
    }
}
