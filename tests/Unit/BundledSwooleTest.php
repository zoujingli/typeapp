<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/support.php';

/** 内置模块通过内容、机器类型和适配身份核验，不能用占位文件替代原生扩展。 */
final class BundledSwooleTest extends TestCase
{
    public function testCommittedModulesHaveTheDeclaredContentAndMachineTypes(): void
    {
        $directory = dirname(__DIR__, 2) . '/plugin/type-build/resources/swoole';
        $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $machines = ['Darwin-arm64-8.5.10-zts' => 0x100000c, 'Linux-arm64-8.5.10-zts' => 183,
            'Linux-x64-8.5.10-zts' => 62, 'Windows-x64-8.5.10-zts' => 0x8664];
        self::assertSame(array_keys($machines), array_keys($manifest['modules']));
        foreach ($manifest['modules'] as $target => $module) {
            $file = $directory . '/' . $module['file'];
            self::assertSame($module['sha256'], hash_file('sha256', $file), $target);
            $header = file_get_contents($file, false, null, 0, 4096);
            if (str_starts_with($target, 'Linux-')) {
                self::assertSame("\x7fELF\x02\x01", substr($header, 0, 6));
                self::assertSame($machines[$target], unpack('v', substr($header, 18, 2))[1]);
            } elseif (str_starts_with($target, 'Darwin-')) {
                self::assertSame("\xcf\xfa\xed\xfe", substr($header, 0, 4));
                self::assertSame($machines[$target], unpack('V', substr($header, 4, 4))[1]);
            } else {
                self::assertSame('MZ', substr($header, 0, 2));
                $offset = unpack('V', substr($header, 60, 4))[1];
                self::assertSame("PE\0\0", substr($header, $offset, 4));
                self::assertSame($machines[$target], unpack('v', substr($header, $offset + 4, 2))[1]);
            }
        }
        foreach ($manifest['licenses'] as $file => $sha256) {
            self::assertSame($sha256, hash_file('sha256', $directory . '/' . $file), $file);
        }
    }

    public function testSelectionRejectsCorruptionMissingFilesWrongAbiAndStalePatches(): void
    {
        $root = dirname(__DIR__, 2);
        $work = $root . '/build/bundled swoole-' . bin2hex(random_bytes(6));
        $directory = $work . '/resources/swoole';
        $relative = 'fixture/php-8.5.10-zts/swoole.so';
        self::assertTrue(mkdir(dirname($directory . '/' . $relative), 0700, true));
        $manifest = json_decode(file_get_contents($root . '/plugin/type-build/resources/swoole/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $architecture = match (strtolower(php_uname('m'))) {
            'arm64', 'aarch64' => 'arm64',
            'amd64', 'x86_64', 'x64' => 'x64',
            default => php_uname('m'),
        };
        $target = PHP_OS_FAMILY . '-' . $architecture . '-' . PHP_VERSION . (PHP_ZTS ? '-zts' : '-nts');
        $manifest['modules'] = [$target => ['file' => $relative, 'sha256' => hash('sha256', 'controlled-module')]];
        try {
            self::assertTrue(mkdir($work . '/src', 0700));
            foreach (['BundledSwoole', 'SwooleThreadSource', 'SwooleHttpSource', 'SwooleSocketSource'] as $class) {
                self::assertTrue(copy($root . '/plugin/type-build/src/' . $class . '.php', $work . '/src/' . $class . '.php'));
            }
            $this->assertRejected($work, '缺少内置 Swoole 清单');
            file_put_contents($directory . '/' . $relative, 'controlled-module');
            file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            [$status, $stdout, $stderr] = $this->select($work);
            self::assertSame(0, $status, $stderr);
            $selected = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
            self::assertSame(realpath($directory . '/' . $relative), $selected['file']);
            self::assertSame(realpath($directory . '/manifest.json'), $selected['manifest']);
            file_put_contents($directory . '/' . $relative, 'corrupted-module');
            $this->assertRejected($work, '摘要不一致');
            unlink($directory . '/' . $relative);
            $this->assertRejected($work, '缺失');
            file_put_contents($directory . '/' . $relative, 'controlled-module');
            $invalid = $manifest;
            $invalid['modules'] = [];
            file_put_contents($directory . '/manifest.json', json_encode($invalid, JSON_THROW_ON_ERROR));
            $this->assertRejected($work, 'PHP ABI');
            $invalid = $manifest;
            $invalid['patches']['SwooleThreadSource'] = str_repeat('0', 64);
            file_put_contents($directory . '/manifest.json', json_encode($invalid, JSON_THROW_ON_ERROR));
            $this->assertRejected($work, '源码适配不一致');
            $invalid = $manifest;
            $invalid['modules'][$target]['file'] = '../outside.so';
            file_put_contents($directory . '/manifest.json', json_encode($invalid, JSON_THROW_ON_ERROR));
            $this->assertRejected($work, '路径');
        } finally {
            \removeTestDirectory($work);
        }
    }

    /** 在独立组件副本中调用同一入口，不能借用已加载的主仓类或注入其他资源路径。 */
    private function select(string $component): array
    {
        return \execute([PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-r',
            'require $argv[1]; echo json_encode((new Type\\Build\\BundledSwoole())->select(), JSON_THROW_ON_ERROR);',
            $component . '/src/BundledSwoole.php'], dirname($component));
    }

    private function assertRejected(string $component, string $message): void
    {
        [$status, $stdout, $stderr] = $this->select($component);
        self::assertNotSame(0, $status, '内置模块不匹配时必须拒绝');
        self::assertSame('', $stdout);
        self::assertStringContainsString($message, $stderr);
    }
}
