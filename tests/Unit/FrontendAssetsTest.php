<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use app\common\service\FrontendAssets;
use app\common\service\FrontendPages;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Type\Build\EmbeddedResourceCompiler;
use Type\Core\Http\Message\Factory;

/** 通过真实文件和公开安装入口验证前端升级，不触及数据库或本机站点。 */
final class FrontendAssetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2) . '/build/frontend test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /** 首次、幂等及强制更新保留非托管数据；旧哈希资源在更新后删除。 */
    public function testInstallAndUpgradePreserveUnmanagedFiles(): void
    {
        $first = $this->assets(['index.html' => 'first', 'assets/old.js' => 'old']);
        self::assertTrue($first->install()['ready']);
        $first->verifyInstalled();
        self::assertSame([], $first->install()['changes']);
        mkdir($this->root . '/public/uploads');
        file_put_contents($this->root . '/public/uploads/user.txt', 'user');
        $next = $this->assets(['index.html' => 'next', 'assets/new.js' => 'new']);
        $preview = $next->install(false, true);
        self::assertSame(['assets/old.js', 'index.html'], $preview['conflicts']);
        self::assertSame(['add' => ['assets/new.js'], 'replace' => ['index.html'], 'delete' => ['assets/old.js']], $preview['actions']);
        self::assertSame('first', file_get_contents($this->root . '/public/index.html'));
        try {
            $next->install();
            self::fail('不同版本没有要求显式force');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('--force', $error->getMessage());
        }
        $next->install(true);
        $next->verifyInstalled();
        self::assertFileDoesNotExist($this->root . '/public/assets/old.js');
        self::assertSame('user', file_get_contents($this->root . '/public/uploads/user.txt'));
        self::assertSame('next', file_get_contents($this->root . '/public/index.html'));
    }

    /** 安装准备不改公开文件，读取失败也不丢失原页面；并发安装不会恢复其他事务。 */
    public function testPreparationFailureAndConcurrentInstallKeepTheOldVersion(): void
    {
        $this->assets(['index.html' => 'first'])->install();
        $next = $this->assets(['index.html' => 'next']);
        $next->prepare(true);
        try {
            self::assertSame('first', file_get_contents($this->root . '/public/index.html'));
            try {
                $this->assets(['index.html' => 'other'])->install(true);
                self::fail('并发安装取得了同一写锁');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('正在进行', $error->getMessage());
            }
        } finally {
            $next->close();
        }
        $bad = new FrontendAssets($this->root, ['index.html' => ['bytes' => 3, 'sha256' => hash('sha256', 'new')]], static fn (string $path, int $offset, int $length): string => 'bad');
        try {
            $bad->install(true);
            self::fail('错误摘要被安装');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('摘要', $error->getMessage());
        }
        self::assertSame('first', file_get_contents($this->root . '/public/index.html'));
    }

    /** HTTP只提供清单资源，校验页面、HEAD、缓存和API分流。 */
    public function testPagesDoNotExposeUnmanagedFilesOrReplaceApiRoutes(): void
    {
        $assets = $this->assets(['index.html' => '<html>test</html>', 'assets/app.js' => 'js']);
        $assets->install();
        file_put_contents($this->root . '/public/secret.txt', 'private');
        $pages = new FrontendPages($assets);
        $messages = new Factory();
        $get = $messages->createServerRequest('GET', 'http://localhost/');
        $response = $pages->respond($get, $messages);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<html>test</html>', (string) $response->getBody());
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame(304, $pages->respond($get->withHeader('If-None-Match', $response->getHeaderLine('ETag')), $messages)->getStatusCode());
        self::assertSame(200, $pages->respond($get->withMethod('HEAD'), $messages)->getStatusCode());
        self::assertSame('', (string) $pages->respond($get->withMethod('HEAD'), $messages)->getBody());
        self::assertSame(405, $pages->respond($get->withMethod('POST'), $messages)->getStatusCode());
        foreach (['/secret.txt', '/admin/users', '/public/site', '/assets/missing.js', '/%2e%2e/config'] as $path) {
            self::assertNull($pages->respond($messages->createServerRequest('GET', 'http://localhost' . $path), $messages));
        }
    }

    /** 预览不产生磁盘写入；符号链接目标不因force而获得覆盖许可。 */
    public function testDryRunDoesNotWriteAndSymlinksAreRejected(): void
    {
        $assets = $this->assets(['index.html' => 'first']);
        $assets->install(true, true);
        self::assertDirectoryDoesNotExist($this->root . '/public');
        self::assertDirectoryDoesNotExist($this->root . '/var');
        mkdir($this->root . '/outside');
        if (!@symlink($this->root . '/outside', $this->root . '/public')) {
            self::markTestSkipped('当前测试账户不能创建符号链接');
        }
        $this->expectException(RuntimeException::class);
        try {
            $assets->install(true);
        } finally {
            // Windows目录符号链接须用rmdir删除链接本身，目标仍由tearDown回收。
            if (PHP_OS_FAMILY === 'Windows') {
                rmdir($this->root . '/public');
            } else {
                unlink($this->root . '/public');
            }
        }
    }

    /** 目录收集拒绝秘密、路径跳转及大小写冲突；摘要随实际资源变动。 */
    public function testEmbeddedResourcesAreBoundToBytesAndRejectUnsafeInput(): void
    {
        mkdir($this->root . '/dist');
        file_put_contents($this->root . '/dist/index.html', 'first');
        $compiler = new EmbeddedResourceCompiler();
        $declarations = [['source' => 'dist', 'target' => 'web']];
        $first = $compiler->manifest($compiler->collect($this->root, $declarations));
        self::assertSame(hash('sha256', 'first'), $first['web/index.html']['sha256']);
        file_put_contents($this->root . '/dist/index.html', 'next');
        self::assertNotSame($first, $compiler->manifest($compiler->collect($this->root, $declarations)));
        file_put_contents($this->root . '/dist/.env', 'secret');
        $this->expectException(RuntimeException::class);
        $compiler->collect($this->root, $declarations);
    }

    /** 模拟已持久化日志后中断：恢复旧页面、回收新增文件，再执行普通幂等安装。 */
    public function testInterruptedCommitIsRecoveredBeforeTheNextInstall(): void
    {
        $old = $this->assets(['index.html' => 'first']);
        $old->install();
        $id = str_repeat('a', 24);
        $control = $this->root . '/var/web-install';
        mkdir($control . '/' . $id . '/old', 0700, true);
        file_put_contents($control . '/' . $id . '/old/index.html', 'first');
        file_put_contents($control . '/transaction.json', json_encode(['id' => $id, 'changes' => [
            'index.html' => ['old' => hash('sha256', 'first'), 'new' => hash('sha256', 'next')],
            'added.txt' => ['old' => null, 'new' => hash('sha256', 'added')],
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/public/index.html', 'next');
        file_put_contents($this->root . '/public/added.txt', 'added');
        self::assertTrue($old->install()['ready']);
        self::assertSame('first', file_get_contents($this->root . '/public/index.html'));
        self::assertFileDoesNotExist($this->root . '/public/added.txt');
        self::assertFileDoesNotExist($control . '/transaction.json');
        self::assertDirectoryDoesNotExist($control . '/' . $id);
        $old->verifyInstalled();
    }

    /** 实际终止持锁准备进程后，新进程可回收暂存并安装，不留下永久占用。 */
    public function testTerminatedInstallerReleasesItsLockAndStagingCanBeRecovered(): void
    {
        $this->assets(['index.html' => 'first'])->install();
        $script = $this->root . '/installer.php';
        file_put_contents($script, <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';
$base = $argv[2];
$assets = new \app\common\service\FrontendAssets($base, ['index.html' => ['bytes' => 4, 'sha256' => hash('sha256', 'next')]],
    static function (string $path, int $offset, int $length) use ($base): string {
        file_put_contents($base . '/ready', 'locked');
        sleep(20);
        return 'next';
    });
$assets->install(true);
PHP);
        $process = new \Type\Testing\Process([PHP_BINARY, $script, dirname(__DIR__, 2), $this->root], $this->root, getenv());
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($this->root . '/ready') && microtime(true) < $deadline && $process->running()) {
                usleep(10000);
            }
            self::assertFileExists($this->root . '/ready');
            try {
                $this->assets(['index.html' => 'other'])->install(true);
                self::fail('另一个进程绕过安装锁');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('正在进行', $error->getMessage());
            }
        } finally {
            $process->stop();
        }
        self::assertSame('first', file_get_contents($this->root . '/public/index.html'));
        self::assertTrue($this->assets(['index.html' => 'next'])->install(true)['ready']);
        self::assertSame([], glob($this->root . '/var/web-install/????????????????????????', GLOB_ONLYDIR));
    }

    /** 只读目录不能被安装器改权绕过；旧版本继续完整可用。 */
    public function testReadOnlyInstallationFailsWithoutChangingTheOldPage(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            self::markTestSkipped('此权限场景需要非root POSIX账户，Windows由平台部署验收覆盖');
        }
        $this->assets(['index.html' => 'first'])->install();
        chmod($this->root . '/var/web-install', 0500);
        set_error_handler(static fn (): bool => true);
        try {
            $this->assets(['index.html' => 'next'])->install(true);
            self::fail('只读安装目录未失败');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('安装目录', $error->getMessage());
            self::assertSame('first', file_get_contents($this->root . '/public/index.html'));
        } finally {
            restore_error_handler();
            chmod($this->root . '/var/web-install', 0700);
        }
    }

    /** 运行时与构建期均拒绝秘密、源码及跨平台冲突，避免开发模式放宽生产边界。 */
    public function testUnsafeManifestPathsCannotBeInstalledOrInspected(): void
    {
        foreach (['../escape', 'auth.json', 'secret.key', 'index.php', 'CON.txt', 'a/.env'] as $path) {
            foreach (['installer', 'compiler'] as $target) {
                try {
                    if ($target === 'installer') {
                        $this->assets(['index.html' => 'page', $path => 'private']);
                    } else {
                        (new EmbeddedResourceCompiler())->validate([$path => ['bytes' => 0, 'sha256' => hash('sha256', '')]]);
                    }
                    self::fail('危险资源路径未拒绝：' . $path);
                } catch (\RuntimeException $error) {
                    self::assertNotEmpty($error->getMessage());
                }
            }
        }
    }

    /** @param array<string,string> $contents 本轮测试控制的真实资源内容。 */
    private function assets(array $contents): FrontendAssets
    {
        $manifest = [];
        foreach ($contents as $path => $bytes) {
            $manifest[$path] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        return new FrontendAssets($this->root, $manifest, static fn (string $path, int $offset, int $length): string => substr($contents[$path], $offset, $length));
    }

    /** 仅回收本轮目录，链接本身可删除但不遍历目标。 */
    private function remove(string $directory): void
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->remove($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
