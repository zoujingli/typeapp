<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RuleAliasTest extends TestCase
{
    public function testRuleAliasCanFollowTheRuleName(): void
    {
        $values = \_vali([
            'age.integer#years' => '年龄不正确',
            'age.required' => '年龄不能为空',
        ], ['years' => '12']);
        self::assertSame(['age' => 12], $values);
    }

    public function testAliasBeforeTheRuleRemainsValid(): void
    {
        $values = \_vali([
            'age#years.integer' => '年龄不正确',
            'age.required' => '年龄不能为空',
        ], ['years' => '8']);
        self::assertSame(['age' => 8], $values);
    }

    public function testDateRejectsOverflowAndConfirmedMatchesTheSiblingField(): void
    {
        $values = \_vali([
            'day.date' => '日期不正确',
            'day.required' => '日期不能为空',
            'password.required' => '密码不能为空',
            'password.confirmed' => '两次输入的密码不一致',
        ], ['day' => '2026-02-28', 'password' => 'secret', 'password_confirmation' => 'secret']);
        self::assertSame('2026-02-28', $values['day']);
        self::assertSame('secret', $values['password']);

        $this->expectException(\Type\Validate\ValidationException::class);
        \_vali(['day.date' => '日期不正确', 'day.required' => '日期不能为空'], ['day' => '2026-02-31']);
    }

    public function testRegexAndUrlFollowTheSharedRuleSyntax(): void
    {
        $values = \_vali([
            'phone.regex:/^1\d{10}$/' => '手机号格式错误',
            'phone.required' => '手机号不能为空',
            'avatar.url' => '网址不正确',
            'temperature.numeric' => '体温必须是数字',
            'enabled.boolean' => '状态不正确',
        ], [
            'phone' => '13800138000',
            'avatar' => 'https://example.com/a.png',
            'temperature' => '36.5',
            'enabled' => '1',
        ]);
        self::assertSame('13800138000', $values['phone']);
        self::assertSame('https://example.com/a.png', $values['avatar']);
        self::assertSame(36.5, $values['temperature']);
        self::assertTrue($values['enabled']);
    }

    public function testArrayAcceptsMapsWhileListStaysSequential(): void
    {
        $values = \_vali([
            'params.array' => '控制参数必须是数组',
            'params.required' => '控制参数不能为空',
            'devices.list' => '设备列表格式错误',
            'devices.required' => '设备列表不能为空',
        ], ['params' => ['speed' => 1], 'devices' => ['a', 'b']]);
        self::assertSame(['speed' => 1], $values['params']);
        self::assertSame(['a', 'b'], $values['devices']);

        $this->expectException(\Type\Validate\ValidationException::class);
        \_vali(['devices.list' => '设备列表格式错误'], ['devices' => ['id' => 'a']]);
    }

    public function testPlainFieldsAreCopiedAndBareKeysStayFixed(): void
    {
        $values = \_vali([
            'name',
            'code#barcode',
            'note',
            'realm' => 'system',
            'status.required' => '状态不能为空',
        ], ['name' => '设备', 'barcode' => 'A1', 'status' => 'on']);
        self::assertSame([
            'name' => '设备',
            'code' => 'A1',
            'note' => null,
            'realm' => 'system',
            'status' => 'on',
        ], $values);
    }

    public function testRuleTextIsReturnedWithTheStableCode(): void
    {
        $values = \_vali([
            ...\_version(),
            'name.required' => '名称不能为空',
        ], ['version' => '2', 'name' => '设备']);
        self::assertSame(2, $values['version']);
        self::assertSame('设备', $values['name']);
        try {
            \_vali(['name.required' => '名称不能为空'], []);
            self::fail('缺少名称时应拒绝');
        } catch (\Type\Validate\ValidationException $error) {
            self::assertSame(['name' => ['required']], $error->errors());
            self::assertSame(['name' => ['名称不能为空']], $error->body()['messages']);
        }
    }

    public function testMatchPatternKeepsHashCharacters(): void
    {
        $values = \_vali([
            'code.matches:/^a#b$/' => '编号不正确',
            'code.required' => '编号不能为空',
        ], ['code' => 'a#b']);
        self::assertSame(['code' => 'a#b'], $values);
    }
}
