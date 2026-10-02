<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Type\Build\WindowsStaticBackend;
use TypePhp\Platform\Windows;

/** 验证锁定编译器的实际命令入口；真实 Windows 链接和运行另由静态 SDK 探针验收。 */
final class WindowsStaticBackendTest extends TestCase
{
    public function testCppAndNativeCUseTheSameStaticCrt(): void
    {
        $backend = new WindowsStaticBackend(new Windows(isZts: true));
        $cpp = $backend->buildCompileCommand('source with spaces.cc', 'object with spaces.obj', ['cpp_std' => 'c++20']);
        self::assertStringContainsString(' /MT', $cpp);
        self::assertStringNotContainsString(' /MD', $cpp);
        self::assertStringContainsString('/std:c++20', $cpp);
        self::assertStringContainsString('/DZTS', $cpp);
        $native = $backend->buildNativeCompileCommand('bridge.c', 'bridge.obj', ['cflags' => '/std:c11'], 'c');
        self::assertStringContainsString('/std:c11', $native);
        self::assertStringContainsString(' /MT', $native);
        self::assertStringNotContainsString(' /MD', $native);
        foreach ([$cpp, $native] as $command) {
            foreach (['/Gy', '/Gw', '/Zc:inline'] as $flag) {
                self::assertStringContainsString($flag, $command);
            }
            self::assertSame(1, substr_count($command, ' /MT'));
        }
        self::assertStringContainsString('/NODEFAULTLIB:MSVCRT', $backend->buildLinkOptions());
        self::assertStringNotContainsString('/NODEFAULTLIB:LIBCMT', $backend->buildLinkOptions());
        foreach (['/DEBUG:NONE', '/INCREMENTAL:NO', '/OPT:REF', '/OPT:ICF'] as $flag) {
            self::assertStringContainsString($flag, $backend->buildLinkOptions());
            self::assertSame(1, substr_count($backend->buildLinkOptions(), $flag));
        }
        self::assertStringNotContainsString('/DEBUG:NONE', $backend->buildLinkOptions(['debug' => true]));
    }

    public function testExplicitDynamicCrtDoesNotSilentlyChangeTheStaticTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CRT 参数');
        (new WindowsStaticBackend(new Windows()))->buildCompileOptions(['cxxflags' => '/MD']);
    }

    public function testCBridgeRejectsConflictingCrt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CRT 参数');
        (new WindowsStaticBackend(new Windows()))->buildCCompileCommand('bridge.c', 'bridge.obj', ['cflags' => '/MT']);
    }
}
