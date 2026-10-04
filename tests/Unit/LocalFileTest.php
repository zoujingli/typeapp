<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Log\Output;
use Type\Runtime\LocalFile;
use Type\Scheduler\FileStateStore;

/** 通过真实文件核对日志与调度状态的共用本地路径边界。 */
final class LocalFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__, 2) . '/build/local file test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** 平台本地路径、稳定锁和原子替换通过实际文件验证；Windows CI 使用真实盘符。 */
    public function testLocalLogAndSchedulerStoreUseSafeAbsolutePaths(): void
    {
        $path = $this->directory . '/scheduler.json';
        $store = new FileStateStore($path);
        $store->acquire();
        try {
            $state = $store->load();
            $state['cursors'] = ['test' => 42];
            $store->save($state);
            self::assertSame($state, $store->load());
            $store->save($state);
            try {
                (new FileStateStore($path))->acquire();
                self::fail('第二执行者取得了锁');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('BUSY', $error->getMessage());
            }
        } finally {
            $store->release();
        }
        $output = Output::file($this->directory . '/app.log');
        self::assertTrue($output->enqueue("test\n"));
        $output->stop();
        self::assertSame("test\n", file_get_contents($this->directory . '/app.log'));
        foreach (['relative.log', 'file://' . $path, $this->directory . '/../escaped', "invalid\0path", '//server/share/file'] as $invalid) {
            try {
                new FileStateStore($invalid);
                self::fail('状态存储接受了非法路径');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('TYPE_SCHEDULER_STORE', $error->getMessage());
            }
            try {
                LocalFile::path($invalid);
                self::fail('非法路径被接受');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            symlink($path, $this->directory . '/link');
            $original = file_get_contents($path);
            $linked = Output::file($this->directory . '/link');
            $linked->enqueue("must-not-write\n");
            $linked->stop(0.01);
            self::assertTrue($linked->stats()['failed']);
            self::assertSame($original, file_get_contents($path));
            $this->expectException(\InvalidArgumentException::class);
            LocalFile::path($this->directory . '/link');
        }
    }
}
