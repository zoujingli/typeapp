<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use Composer\MetadataMinifier\MetadataMinifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeApp\Release\Packagist;

require_once dirname(__DIR__, 2) . '/tools/release/Packagist.php';

/** 静态索引必须按Composer差量协议还原，缓存中的详情页不参与版本资格判断。 */
final class PackagistTest extends TestCase
{
    /** @return iterable<string,array{string}> 当前与历史版本的公共索引边界。 */
    public static function metadataCases(): iterable
    {
        foreach (['plain', 'minified', 'missing', 'wrong-source', 'removed-source', 'duplicate', 'wrong-package', 'unknown-format'] as $case) {
            yield $case => [$case];
        }
    }

    /** 来源字段的继承和显式删除都应生效，不能把新版本记录冒充旧版本通过。 */
    #[DataProvider('metadataCases')]
    public function testStaticMetadataRetainsVersionAndSourceIdentity(string $case): void
    {
        $name = 'zoujingli/type-core';
        $source = str_repeat('a', 40);
        $entries = [
            ['name' => $name, 'version' => 'v1.0.0-rc.5', 'source' => ['reference' => $source]],
            ['name' => $name, 'version' => 'v1.0.0-rc.4', 'source' => ['reference' => $source]],
        ];
        if ($case === 'missing') {
            array_pop($entries);
        } elseif ($case === 'wrong-source') {
            $entries[1]['source']['reference'] = str_repeat('b', 40);
        } elseif ($case === 'removed-source') {
            unset($entries[1]['source']);
        } elseif ($case === 'duplicate') {
            $entries[] = $entries[1];
        } elseif ($case === 'wrong-package') {
            $entries[1]['name'] = 'zoujingli/type-runtime';
        }
        $document = ['packages' => [$name => $entries]];
        if ($case !== 'plain') {
            $document['minified'] = $case === 'unknown-format' ? 'future/unknown' : 'composer/2.0';
            $document['packages'][$name] = MetadataMinifier::minify($entries);
        }
        if (!in_array($case, ['plain', 'minified', 'missing'], true)) {
            $this->expectException(\RuntimeException::class);
        }
        $actual = Packagist::indexedVersion($document, $name, 'v1.0.0-rc.4', $source);
        self::assertSame($case === 'missing' ? null : ['package' => $name, 'version' => 'v1.0.0-rc.4', 'source' => $source], $actual);
    }
}
