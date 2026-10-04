<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use TypeTests\Support\ModelFirstBoundary;

require_once dirname(__DIR__) . '/Support/ModelFirstBoundary.php';

/** 用真实解析的业务片段验证门禁，违规夹具不会混入生产源码。 */
final class ModelFirstBoundaryTest extends TestCase
{
    /** 别名连接参数、空安全调用、动态方法和底层静态调用都不能绕过检查。 */
    public function testRejectsAlternateConnectionAndSqlCallSyntax(): void
    {
        $prefix = '<?php namespace Demo; use Type\\Orm\\Connection as Sql; use Type\\Orm\\Db as Database; ';
        $cases = [
            'public_connection_parameter' => [
                'public function run(Sql $db): void {}',
                'public function run(?Sql $db): void {}',
                'public function run(Sql|false $db): void {}',
                'public function run((Sql&\\Stringable)|null $db): void {}',
            ],
            'public_connection_return' => ['public function run(): ?Sql {}'],
            'public_connection_property' => ['public Sql $connection;'],
            'direct_connection_construction' => [
                'public function run(): void { new \\PDO("sqlite::memory:"); }',
                'public function run(): void { new Sql($lease); }',
            ],
            'indirect_database_call' => [
                'public function run(): void { call_user_func([$db, "table"], "users"); }',
                'public function run(): void { call_user_func_array([$db, "raw"], ["DELETE FROM users"]); }',
                'public function run(): void { forward_static_call("Type\\\\Orm\\\\Connection::query", "SELECT * FROM users"); }',
            ],
            'unapproved_database_call' => [
                'public function run(): void { $db->table("users")->delete(); }',
                'public function run(): void { $db?->table("users"); }',
                'public function run(): void { Sql::query("SELECT * FROM users"); }',
                'public function run(): void { Database::table("users"); }',
                'public function run(): void { $db->query($sql); }',
                'public function run(): void { $db->prepare("DELETE FROM users"); }',
            ],
            'dynamic_method_call' => [
                'public function run(): void { $db->$method("users"); }',
                'public function run(): void { $db?->{"table"}("users"); }',
                'public function run(): void { Sql::$method("users"); }',
                'public function run(): void { $class::query("SELECT * FROM users"); }',
            ],
        ];
        foreach ($cases as $code => $bodies) {
            foreach ($bodies as $body) {
                $violations = (new ModelFirstBoundary())->violations(['fixture.php' => $prefix . 'final class Service { ' . $body . ' }']);
                self::assertCount(1, $violations, $body);
                self::assertStringStartsWith($code . ': ', $violations[0], $body);
            }
        }
    }

    /** Model 查询别名、普通静态业务方法与无连接事务入口继续可用。 */
    public function testAllowsModelFactoriesAndUnrelatedStaticMethods(): void
    {
        $source = <<<'PHP'
<?php
namespace Demo;
use Type\Orm\Db;
final class Service {
    private function query(object $request): array { return []; }
    public function run(): void {
        User::query('u')->where('enabled', '=', true)->get();
        User::create(['name' => '甲']);
        Report::execute();
        call_user_func([new Report(), 'render']);
        $this->query(new \stdClass());
        Db::transaction(static fn (): mixed => User::find(1));
    }
}
PHP;
        self::assertSame([], (new ModelFirstBoundary())->violations(['fixture.php' => $source]));
    }

    /** 例外必须同时匹配所有者、入口和完整表达式；删除访问后也不能留下永久豁免。 */
    public function testExceptionsAreExactAndMustRemainUsed(): void
    {
        $source = '<?php namespace Demo; final class Service { public function project(): void { $db->query("SELECT a.id FROM a JOIN b ON a.id = b.id"); } }';
        $exception = ['owner' => 'Demo\\Service::project', 'method' => 'query', 'argument' => 'SELECT a.id FROM a JOIN b ON a.id = b.id', 'reason' => '授权后跨模型投影'];
        $guard = new ModelFirstBoundary();
        self::assertSame([], $guard->violations(['fixture.php' => $source], [$exception]));
        foreach (['owner' => 'Demo\\Service::other', 'method' => 'table', 'argument' => 'SELECT a.id FROM a JOIN b ON a.id = b.id WHERE 1 = 1'] as $key => $changed) {
            $violations = $guard->violations(['fixture.php' => $source], [array_replace($exception, [$key => $changed])]);
            self::assertCount(2, $violations);
            self::assertStringStartsWith('unapproved_database_call: ', $violations[0]);
            self::assertStringStartsWith('unused_exception: ', $violations[1]);
        }
        self::assertStringStartsWith('unused_exception: ', $guard->violations(['fixture.php' => '<?php'], [$exception])[0]);
        self::assertStringStartsWith('duplicate_exception: ', $guard->violations(['fixture.php' => $source], [$exception, $exception])[0]);
        self::assertStringStartsWith('invalid_exception: ', $guard->violations(['fixture.php' => $source], [array_replace($exception, ['reason' => ''])])[0]);
    }

    /** 基础设施连接参数的单一豁免不能扩为其他方法、参数名或可空类型。 */
    public function testInfrastructureParameterExceptionDoesNotPermitWiderSignatures(): void
    {
        $source = '<?php namespace Demo; use Type\\Orm\\Connection as Sql; final class Service { public function snapshot(Sql $connection): void {} }';
        $exception = ['owner' => 'Demo\\Service::snapshot', 'method' => 'connection_parameter', 'argument' => '$connection: Type\\Orm\\Connection', 'reason' => '读取已有基础设施事务中的固定快照'];
        $guard = new ModelFirstBoundary();
        self::assertSame([], $guard->violations(['fixture.php' => $source], [$exception]));
        foreach (['snapshot' => 'query', '$connection' => '$other', 'Sql $connection' => '?Sql $connection'] as $before => $after) {
            $violations = $guard->violations(['fixture.php' => str_replace($before, $after, $source)], [$exception]);
            self::assertCount(2, $violations);
            self::assertStringStartsWith('public_connection_parameter: ', $violations[0]);
            self::assertStringStartsWith('unused_exception: ', $violations[1]);
        }
    }
}
