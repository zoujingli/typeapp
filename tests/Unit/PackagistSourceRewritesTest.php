<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\SourceRewriter;
use Type\Build\SourceSet;

require_once dirname(__DIR__) . '/support.php';

/** 使用实际依赖源码验证公开索引处理后的适配声明，避免本地 path 安装掩盖分发差异。 */
final class PackagistSourceRewritesTest extends TestCase
{
    /** Packagist 元数据会移除字符串中的换行；公开安装与本地声明必须生成完全相同的源码。 */
    public function testPublishedCronMetadataPreservesExactAdaptedSources(): void
    {
        $root = dirname(__DIR__, 2);
        $packageRoot = $root . '/vendor/dragonmantank/cron-expression';
        $package = json_decode((string) file_get_contents($packageRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $provider = json_decode((string) file_get_contents($root . '/plugin/type-scheduler/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $original = $provider['extra']['type']['imports']['dragonmantank/cron-expression'];
        $indexed = $original;
        foreach ($indexed['rewrites'] as &$rewrite) {
            foreach ($rewrite['replacements'] as &$replacement) {
                // 此转换来自失败的公开 Composer 锁文件；不改变源码字节、摘要或匹配次数。
                $replacement['from'] = str_replace(["\r", "\n"], '', $replacement['from']);
                $replacement['to'] = str_replace(["\r", "\n"], '', $replacement['to']);
            }
            unset($replacement);
        }
        unset($rewrite);
        $directory = $root . '/build/packagist-rewrites-' . bin2hex(random_bytes(6));
        try {
            $rewriter = new SourceRewriter();
            $sourceSet = new SourceSet();
            $local = $sourceSet->describe($packageRoot, $package, $original);
            $public = $sourceSet->describe($packageRoot, $package, $indexed);
            $expected = $rewriter->apply($local['sources'], [$local], $directory . '/local');
            $actual = $rewriter->apply($public['sources'], [$public], $directory . '/public');
            self::assertCount(count($original['rewrites']), $actual['mapping']);
            foreach ($actual['mapping'] as $position => $item) {
                self::assertSame($expected['mapping'][$position]['generated-sha256'], $item['generated-sha256']);
                self::assertSame(file_get_contents($expected['mapping'][$position]['generated']), file_get_contents($item['generated']));
                self::assertSame($item['original-sha256'], hash_file('sha256', $item['source']));
            }
            self::assertSame('4065cecde603e89f91661772007be602202f75a4aa5a4d8da522342d9993135d', $actual['mapping'][0]['generated-sha256']);
        } finally {
            \removeTestDirectory($directory);
        }
    }
}
