<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Testing\Process;

require_once dirname(__DIR__) . '/support.php';

/** 通过真实禁网安装验证独立工具链保留版本、来源提交及文件内容。 */
final class LocalComposerRepositoryTest extends TestCase
{
    public function testOfflineInstallKeepsLockedToolchainIdentity(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/offline composer-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        try {
            $toolchain = json_decode((string) file_get_contents($root . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
            $configuration = ['name' => 'type-tests/offline-toolchain',
                'require' => ['swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']],
                'repositories' => [...\localComposerRepositories($root), ['packagist.org' => false]],
                'config' => ['allow-plugins' => false]];
            file_put_contents($directory . '/composer.json', json_encode($configuration, JSON_THROW_ON_ERROR));
            $composer = (string) getenv('COMPOSER_BINARY');
            if ($composer === '' && PHP_OS_FAMILY === 'Windows') {
                $composer = (string) getenv('PHP_HOME') . '/composer.phar';
            }
            if ($composer === '') {
                $composer = trim(\successful(['which', 'composer']));
            }
            self::assertFileExists($composer);
            $environment = getenv();
            $environment['COMPOSER_HOME'] = $directory . '/composer-home';
            $environment['COMPOSER_CACHE_DIR'] = $directory . '/empty-cache';
            $environment['COMPOSER_DISABLE_NETWORK'] = '1';
            $process = new Process([PHP_BINARY, $composer, '--working-dir=' . $directory, 'install',
                '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'], $root, $environment);
            try {
                $result = $process->wait(60);
            } finally {
                $process->stop();
            }
            self::assertTrue($result->successful(), $result->stdout . $result->stderr);
            $installed = require $directory . '/vendor/composer/installed.php';
            foreach (['typephp', 'phpx'] as $tool) {
                $package = 'swoole/' . $tool;
                self::assertSame($toolchain[$tool]['version'], ltrim($installed['versions'][$package]['pretty_version'], 'v'));
                self::assertSame($toolchain[$tool]['reference'], $installed['versions'][$package]['reference'], $package);
                self::assertFalse(is_link($directory . '/vendor/' . $package));
                self::assertSame($this->files($root . '/vendor/' . $package), $this->files($directory . '/vendor/' . $package));
            }
        } finally {
            \removeTestDirectory($directory);
        }
    }

    /** @return array<string, string> 相对路径与摘要，用于核对独立复制安装的原始内容。 */
    private function files(string $directory): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertFalse($file->isLink());
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        return $files;
    }
}
