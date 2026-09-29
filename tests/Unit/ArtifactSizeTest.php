<?php

declare(strict_types=1);

namespace TypeAppTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\ArtifactSize;

final class ArtifactSizeTest extends TestCase
{
    /** PE32+ 的代码区计量来自区段表；即使程序仍可加载，也拒绝链接器残留的 PDB 目录。 */
    public function testPeCodeSectionAndPdbRejection(): void
    {
        $path = tempnam(dirname(__DIR__, 2) . '/build', 'pe-size-');
        try {
            $dos = substr_replace(str_pad('MZ', 64, "\0"), pack('V', 64), 60, 4);
            $coff = "PE\0\0" . pack('vvVVVvv', 0x8664, 1, 0, 0, 0, 168, 0);
            $optional = pack('v', 0x20b) . str_repeat("\0", 166);
            $section = str_pad('.text', 8, "\0") . pack('VVVVVVvvV', 16, 4096, 16, 296, 0, 0, 0, 0, 0x60000020);
            file_put_contents($path, $dos . $coff . $optional . $section . str_repeat('x', 16));
            $size = ArtifactSize::measure($path, [], []);
            self::assertSame(16, $size['code']);
            self::assertSame(312, $size['total']);
            $optional = substr_replace($optional, pack('VV', 4096, 28), 160, 8);
            file_put_contents($path, $dos . $coff . $optional . $section . str_repeat('x', 16));
            $this->expectExceptionMessage('PDB');
            ArtifactSize::measure($path, [], []);
        } finally {
            @unlink($path);
        }
    }

    /** Mach-O 的代码属性和调试属性分别校验，不因扩展名或文件大小推定已清理。 */
    public function testMachORejectsDwarfSection(): void
    {
        $path = tempnam(dirname(__DIR__, 2) . '/build', 'macho-size-');
        try {
            $header = pack('VVVVVVVV', 0xfeedfacf, 0x100000c, 0, 2, 1, 152, 0, 0);
            $segment = pack('VV', 0x19, 152) . str_pad('__TEXT', 16, "\0") . pack('PPPPVVVV', 4096, 4096, 0, 200, 5, 5, 1, 0);
            $section = str_pad('__text', 16, "\0") . str_pad('__TEXT', 16, "\0") . pack('PPVVVVVVVV', 4280, 16, 184, 4, 0, 0, 0x80000400, 0, 0, 0);
            file_put_contents($path, $header . $segment . $section . str_repeat('x', 16));
            self::assertSame(16, ArtifactSize::measure($path, [], [])['code']);
            $section = substr_replace($section, pack('V', 0x02000000), 64, 4);
            file_put_contents($path, $header . $segment . $section . str_repeat('x', 16));
            $this->expectExceptionMessage('调试区段');
            ArtifactSize::measure($path, [], []);
        } finally {
            @unlink($path);
        }
    }

    public function testSeparatesFrontendFromOtherEmbeddedData(): void
    {
        $path = tempnam(dirname(__DIR__, 2) . '/build', 'artifact-size-');
        self::assertIsString($path);
        try {
            // 最小 ELF64 区段夹具：16 字节代码和其余文件数据，保留真实区段表布局。
            $header = str_pad("\x7fELF\x02\x01", 64, "\0");
            $header = substr_replace($header, pack('P', 128), 40, 8);
            $header = substr_replace($header, pack('vvv', 64, 3, 2), 58, 6);
            $text = pack('VVPPPPVVPP', 1, 1, 6, 0, 64, 16, 0, 0, 16, 0);
            $strings = pack('VVPPPPVVPP', 7, 3, 0, 0, 80, 17, 0, 0, 1, 0);
            $elf = $header . str_repeat('x', 16) . str_pad("\0.text\0.shstrtab\0", 48, "\0") . str_repeat("\0", 64) . $text . $strings;
            self::assertSame(320, file_put_contents($path, $elf));
            $report = ArtifactSize::measure($path, [
                'web/index.html' => ['bytes' => 10, 'sha256' => str_repeat('a', 64)],
                'notices/dependencies.json' => ['bytes' => 5, 'sha256' => str_repeat('b', 64)],
            ], []);
            self::assertSame(320, $report['total']);
            self::assertSame(304, $report['data']);
            self::assertSame(16, $report['code']);
            self::assertSame(10, $report['frontend']);
            foreach (['.symtab', '.strtab', '.debug_info', '.zdebug_info', '.gnu_debuglink', '.gnu_debugaltlink', '.gnu_debugdata'] as $forbidden) {
                $names = "\0.text\0" . $forbidden . "\0";
                $namesSection = pack('VVPPPPVVPP', 7, 3, 0, 0, 80, strlen($names), 0, 0, 1, 0);
                file_put_contents($path, $header . str_repeat('x', 16) . str_pad($names, 48, "\0") . str_repeat("\0", 64) . $text . $namesSection);
                try {
                    ArtifactSize::measure($path, [], []);
                    self::fail('残余调试或符号区段被接受：' . $forbidden);
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString($forbidden, $error->getMessage());
                }
            }
        } finally {
            @unlink($path);
        }
    }
}
