<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Orm\ModelException;
use Type\Orm\ModelField;

/**
 * 64 位整数在所有 PHP 平台以十进制字符串校验，不经过浮点或宿主整型转换。
 * @internal
 * @coversNothing
 */
final class ExactIntegerRangeTest extends TestCase
{
    public function testFullSignedAndUnsignedEndpointsRemainExact(): void
    {
        $signed = new ModelField('value', 'bigint', integerRange: 'signed64');
        $unsigned = new ModelField('value', 'bigint', integerRange: 'unsigned64');
        self::assertSame('-9223372036854775808', $signed->normalize('-9223372036854775808'));
        self::assertSame('9223372036854775807', $signed->normalize('+009223372036854775807'));
        self::assertSame('18446744073709551615', $unsigned->normalize('18446744073709551615'));
        self::assertSame('0', $unsigned->normalize('-000'));
        foreach ([[$signed, '-9223372036854775809'], [$signed, '9223372036854775808'], [$unsigned, '-1'], [$unsigned, '18446744073709551616']] as [$field, $value]) {
            try {
                $field->normalize($value);
                self::fail('越界值没有拒绝：' . $value);
            } catch (ModelException $error) {
                self::assertSame('integer_out_of_range', $error->errorCode());
            }
        }
        self::assertFalse($signed->sameMapping($unsigned));
        self::assertSame('999999999999999999999999', (new ModelField('value', 'bigint'))->normalize('999999999999999999999999'));
    }
}
