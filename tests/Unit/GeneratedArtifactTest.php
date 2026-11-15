<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\ArtifactManifest;
use Type\Build\BuildIdentity;

/**
 * 生成源码不进入运行包，产物身份仍必须完整绑定其相对路径和字节摘要。
 * @internal
 * @coversNothing
 */
final class GeneratedArtifactTest extends TestCase
{
    public function testCompiledFilesIdentityAcceptsEmptyAndNonEmptySets(): void
    {
        $reader = new ArtifactManifest();
        $accepted = 0;
        foreach ([
            [],
            ['generated-migrations.php' => hash('sha256', "<?php\n")],
        ] as $files) {
            $reader->verifyCompiledFiles([
                'compiled-files' => $files,
                'compiled-files-sha256' => BuildIdentity::digest($files),
                'declaration-generation' => str_repeat('a', 64),
            ], true);
            ++$accepted;
        }
        self::assertSame(2, $accepted);
    }

    public function testCompiledFilesIdentityRejectsPathAndDigestTampering(): void
    {
        $reader = new ArtifactManifest();
        $files = ['generated-migrations.php' => hash('sha256', "<?php\n")];
        $manifest = [
            'compiled-files' => $files,
            'compiled-files-sha256' => BuildIdentity::digest($files),
            'declaration-generation' => str_repeat('a', 64),
        ];
        $broken = $manifest;
        $broken['compiled-files-sha256'] = str_repeat('0', 64);
        $this->expectException(\RuntimeException::class);
        $reader->verifyCompiledFiles($broken, true);
    }

    public function testCompiledFilesIdentityRejectsTraversal(): void
    {
        $reader = new ArtifactManifest();
        $files = ['../generated.php' => hash('sha256', "<?php\n")];
        $this->expectException(\RuntimeException::class);
        $reader->verifyCompiledFiles([
            'compiled-files' => $files,
            'compiled-files-sha256' => BuildIdentity::digest($files),
            'declaration-generation' => str_repeat('a', 64),
        ], true);
    }
}
