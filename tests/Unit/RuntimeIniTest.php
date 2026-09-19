<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Type\Build\RuntimeIni;

/** 核对既有INI字节、语义及注入拒绝，不依赖宿主环境配置。 */
final class RuntimeIniTest extends TestCase
{
    private const BASE = "expose_php=0\nenable_dl=0\nallow_url_include=0\nauto_prepend_file=\nauto_append_file=\nopcache.preload=\nuser_ini.filename=\ninclude_path=\nopcache.enable=0\nopcache.enable_cli=0\nswoole.enable_library=On\ndisplay_errors=stderr\ndisplay_startup_errors=1\nlog_errors=0\nmemory_limit=256M\ndate.timezone=UTC\n";

    public function testProbeAndPackageKeepTheirExistingBytes(): void
    {
        $ini = new RuntimeIni();
        $base = self::BASE . "swoole.enable_fiber_mock=On\n";
        self::assertSame($base . "extension_dir=\n", $ini->generate([]));
        self::assertSame(
            $base . "extension_dir=\nextension=\"/sdk/pdo_mysql.so\"\nextension=\"/sdk/redis.so\"\n",
            $ini->generate(['pdo_mysql' => '/sdk/pdo_mysql.so', 'redis' => '/sdk/redis.so'])
        );
        foreach (['lib', 'bin'] as $directory) {
            self::assertSame(
                $base . 'extension_dir="' . $directory . "\"\nextension=\"pdo_mysql.so\"\nextension=\"redis.so\"\n",
                $ini->generate(['pdo_mysql.so', 'redis.so'], $directory)
            );
        }
    }

    public function testQuotedPathsRoundTripWithoutChangingTheirMeaning(): void
    {
        foreach (['C:\\SDK folder\\php_redis.dll', '/tmp/模块 "one".so'] as $path) {
            // 实际INI加载使用普通扫描语义；RAW模式刻意保留转义，不适合作为路径还原判据。
            $result = parse_ini_string((new RuntimeIni())->generate([$path]), false, INI_SCANNER_NORMAL);
            self::assertSame($path, $result['extension']);
            self::assertSame('0', $result['enable_dl']);
            self::assertSame('', $result['auto_prepend_file']);
            self::assertSame('', $result['opcache.preload']);
        }
    }

    public function testControlCharactersAndInterpolationCannotEnterPaths(): void
    {
        foreach (["/tmp/a\nallow_url_include=1", "path\0name", 'path/${TOKEN}/module.so', "path\x7fname"] as $path) {
            foreach ([false, true] as $directory) {
                $rejected = false;
                try {
                    (new RuntimeIni())->generate($directory ? [] : [$path], $directory ? $path : '');
                } catch (RuntimeException) {
                    $rejected = true;
                }
                self::assertTrue($rejected);
            }
        }
        foreach ([[''], [false]] as $modules) {
            $rejected = false;
            try {
                (new RuntimeIni())->generate($modules);
            } catch (RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected);
        }
    }
}
