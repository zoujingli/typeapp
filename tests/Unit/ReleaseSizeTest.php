<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use TypeApp\Release\Candidate;
use TypeApp\Release\SizeGate;

require_once dirname(__DIR__, 2) . '/tools/release/Candidate.php';
require_once dirname(__DIR__, 2) . '/tools/release/SizeGate.php';

final class ReleaseSizeTest extends TestCase
{
    public function testFirstProfileReleaseHasNoInventedBaseline(): void
    {
        $programs = array_fill_keys(Candidate::matrix(), ['bytes' => 100]);
        $result = SizeGate::verify($programs, null, ['protocol' => 1, 'explanations' => []], 'v1.0.0-rc.11');
        self::assertNull($result['baseline-version']);
        self::assertCount(12, $result['comparisons']);
    }

    public function testGrowthRequiresExplanationOnlyAboveFivePercent(): void
    {
        $programs = array_fill_keys(Candidate::matrix(), ['bytes' => 105]);
        $baseline = ['protocol' => 3, 'version' => 'v1.0.0-rc.11', 'source' => str_repeat('a', 40),
            'programs' => array_fill_keys(Candidate::matrix(), ['bytes' => 100])];
        $policy = ['protocol' => 1, 'explanations' => []];
        self::assertSame(105, SizeGate::verify($programs, $baseline, $policy, 'v1.0.0-rc.12')['comparisons']['linux-x64-sqlite']['bytes']);
        $programs['linux-x64-sqlite']['bytes'] = 106;
        try {
            SizeGate::verify($programs, $baseline, $policy, 'v1.0.0-rc.12');
            self::fail('Missing growth explanation accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('5%', $error->getMessage());
        }
        $policy['explanations']['v1.0.0-rc.12']['linux-x64-sqlite'] = '升级 TLS 依赖并加入已验收的证书解析功能';
        $receipt = SizeGate::verify($programs, $baseline, $policy, 'v1.0.0-rc.12');
        self::assertSame(100, $receipt['comparisons']['linux-x64-sqlite']['baseline-bytes']);
        self::assertSame($policy['explanations']['v1.0.0-rc.12']['linux-x64-sqlite'], $receipt['comparisons']['linux-x64-sqlite']['explanation']);
        $baseline['programs']['linux-x64-sqlite']['bytes'] = '100';
        $this->expectException(\RuntimeException::class);
        SizeGate::verify($programs, $baseline, $policy, 'v1.0.0-rc.12');
    }
}
