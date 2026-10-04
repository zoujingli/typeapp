<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Testing\Process;

require_once dirname(__DIR__) . '/support.php';

/** 通过真实禁网安装验证独立工具链保留版本、来源提交及文件内容。 */
final class LocalComposerRepositoryTest extends TestCase
{
    /** 禁网解析实际组件清单；开发分支沿用包内别名，旧 Runtime 必须在安装前被拒绝。 */
    public function testPdoDriversResolveOnlyCompatibleRuntimeVersions(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/runtime dependency-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        try {
            $composer = (string) getenv('COMPOSER_BINARY');
            if ($composer === '' && PHP_OS_FAMILY === 'Windows') {
                $composer = (string) getenv('PHP_HOME') . '/composer.phar';
            }
            if ($composer === '') {
                $composer = trim(\successful(['which', 'composer']));
            }
            self::assertFileExists($composer);
            $versions = ['zoujingli/type-orm' => '1.0.x-dev'];
            $requires = [];
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                $name = 'zoujingli/type-orm-' . $driver;
                $versions[$name] = '1.0.x-dev';
                $requires[$name] = '1.0.x-dev';
            }
            $environment = getenv();
            $environment['COMPOSER_HOME'] = $directory . '/composer-home';
            $environment['COMPOSER_CACHE_DIR'] = $directory . '/empty-cache';
            $environment['COMPOSER_DISABLE_NETWORK'] = '1';
            foreach (['1.0.0-rc.14' => false, '1.0.0-rc.15' => true, '1.0.x-dev' => true, 'dev-main' => true, '1.1.0' => false] as $version => $accepted) {
                $consumer = $directory . '/' . $version;
                self::assertTrue(mkdir($consumer, 0700));
                $configuration = ['name' => 'type-tests/runtime-dependency',
                    'require' => $requires + ['zoujingli/type-runtime' => $version],
                    'repositories' => [['type' => 'path', 'url' => $root . '/plugin/*',
                        'options' => ['versions' => $versions + ['zoujingli/type-runtime' => $version]]], ['packagist.org' => false]],
                    'minimum-stability' => 'dev', 'config' => ['allow-plugins' => false]];
                file_put_contents($consumer . '/composer.json', json_encode($configuration, JSON_THROW_ON_ERROR));
                // 此处只观察 Composer 依赖求解，不安装源码或声称验证当前平台的原生扩展。
                $process = new Process([PHP_BINARY, $composer, '--working-dir=' . $consumer, 'update',
                    '--no-install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress', '--no-audit', '--ignore-platform-reqs'], $root, $environment);
                try {
                    $result = $process->wait(60);
                } finally {
                    $process->stop();
                }
                self::assertFalse($result->timedOut, $version);
                self::assertSame($accepted, $result->successful(), $version . "\n" . $result->stdout . $result->stderr);
                if (!$accepted) {
                    self::assertStringContainsString('zoujingli/type-runtime', $result->stderr);
                    self::assertFileDoesNotExist($consumer . '/composer.lock');
                    continue;
                }
                $lock = json_decode((string) file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
                $packages = array_column($lock['packages'], null, 'name');
                self::assertSame($version, $packages['zoujingli/type-runtime']['version']);
                foreach (array_keys($requires) as $name) {
                    $source = json_decode((string) file_get_contents($root . '/plugin/' . substr($name, strlen('zoujingli/')) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame($source['require']['zoujingli/type-runtime'], $packages[$name]['require']['zoujingli/type-runtime']);
                }
            }
        } finally {
            \removeTestDirectory($directory);
        }
    }

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
