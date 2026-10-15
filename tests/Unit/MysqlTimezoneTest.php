<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Type\Orm\DatabaseException;
use Type\Orm\Mysql\MysqlDriver;

/** 通过驱动公开配置与身份验证时区边界，不连接数据库。 */
final class MysqlTimezoneTest extends TestCase
{
    public function testExistingConstructorKeepsUtcAndOtherIdentityFields(): void
    {
        $driver = new MysqlDriver('127.0.0.1', 3306, 'fixture', 'tester', 'synthetic-password', 2, 'reader');
        self::assertSame(['timezone' => '+00:00', 'strict' => true], $driver->identity()['session']);
        self::assertSame(2, $driver->identity()['credential-generation']);
        self::assertSame('reader', $driver->identity()['role']);
        self::assertSame('disabled', $driver->identity()['tls']);
        self::assertStringNotContainsString('synthetic-password', json_encode($driver->identity(), JSON_THROW_ON_ERROR));
    }

    public function testTimezoneChangesOnlySessionIdentity(): void
    {
        $utc = new MysqlDriver('127.0.0.1', 3306, 'fixture', 'tester', '', 2, 'reader');
        $local = new MysqlDriver('127.0.0.1', 3306, 'fixture', 'tester', '', 2, 'reader', null, '+08:00');
        $expected = $utc->identity();
        $expected['session']['timezone'] = '+08:00';
        self::assertSame($expected, $local->identity());
    }

    #[DataProvider('validTimezones')]
    public function testAcceptsExplicitTimezone(string $input, string $expected): void
    {
        $driver = new MysqlDriver('127.0.0.1', 3306, 'fixture', 'tester', '', timeZone: $input);
        self::assertSame($expected, $driver->identity()['session']['timezone']);
    }

    /** @return list<array{string, string}> 固定格式、极值和 MySQL 的负零规范化。 */
    public static function validTimezones(): array
    {
        return [['SYSTEM', 'SYSTEM'], ['+00:00', '+00:00'], ['-00:00', '+00:00'], ['+08:00', '+08:00'],
            ['+05:45', '+05:45'], ['-05:30', '-05:30'], ['-13:59', '-13:59'], ['+13:59', '+13:59'], ['+14:00', '+14:00']];
    }

    #[DataProvider('invalidTimezones')]
    public function testRejectsInvalidTimezoneBeforeConnecting(string $input): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('MySQL 会话时区无效');
        new MysqlDriver('127.0.0.1', 1, 'unreachable', 'tester', '', timeZone: $input);
    }

    /** @return list<array{string}> 不依赖服务器时区表，不接受隐式修正、越界或 SQL 片段。 */
    public static function invalidTimezones(): array
    {
        return [[''], ['UTC'], ['Asia/Shanghai'], ['system'], [' SYSTEM'], ['SYSTEM '], ['08:00'], ['+8:00'],
            ['+08:0'], ['+00:60'], ['+14:01'], ['-14:00'], ['+24:00'], ["+08:00\n"], ["+08:00\0"], ["+08:00'; SELECT 1 --"]];
    }
}
