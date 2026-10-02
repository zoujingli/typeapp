<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Type\Build\StaticRuntimeSdk;

require_once dirname(__DIR__) . '/support.php';

/** 静态输入的独立路径、ABI、内容和源码适配门禁；真实链接另由原生验收证明。 */
final class StaticRuntimeSdkTest extends TestCase
{
    public function testSdkSelectionRejectsDriftAndNeverFallsBackToDynamicLibraries(): void
    {
        // SDK公开路径统一使用斜线；Windows的__DIR__仍返回反斜线，夹具须按同一契约比较。
        $root = PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', dirname(__DIR__, 2)) : dirname(__DIR__, 2);
        $work = $root . '/build/static sdk-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($work . '/lib', 0700, true));
        $archiveName = PHP_OS_FAMILY === 'Windows' ? 'runtime.lib' : 'runtime.a';
        $archive = $work . '/lib/' . $archiveName;
        // 空 ar 归档仅验证清单协议，不能作为可运行 SDK 或原生成功证据。
        file_put_contents($archive, "!<arch>\n");
        $data = ['protocol' => 1, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS,
            'debug' => (bool) PHP_DEBUG, 'integer-size' => PHP_INT_SIZE, 'headers' => [],
            'archives' => [['file' => 'lib/' . $archiveName, 'sha256' => hash_file('sha256', $archive)]], 'patches' => [],
            'sources' => ['swoole' => StaticRuntimeSdk::swooleSource()]];
        $headers = [];
        foreach (['main/php.h', PHP_OS_FAMILY === 'Windows' ? 'main/config.w32.h' : 'main/php_config.h', 'Zend/zend.h', 'TSRM/TSRM.h'] as $name) {
            $path = $work . '/include/php/' . $name;
            if (!is_dir(dirname($path))) {
                self::assertTrue(mkdir(dirname($path), 0700, true));
            }
            file_put_contents($path, '/* ABI fixture: ' . $name . ' */');
            $headers[] = $path;
            $data['headers'][] = ['file' => 'include/php/' . $name, 'sha256' => hash_file('sha256', $path)];
        }
        foreach (['SwooleThreadSource', 'SwooleHttpSource', 'SwooleSocketSource', 'SwooleStaticSource', 'PhpxThreadSource', 'SwooleWindowsSource'] as $patch) {
            $data['patches'][$patch] = hash_file('sha256', $root . '/plugin/type-build/src/' . $patch . '.php');
        }
        $manifest = $work . '/manifest.json';
        $cwd = getcwd();
        try {
            chdir(sys_get_temp_dir());
            file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
            $sdk = new StaticRuntimeSdk($manifest);
            self::assertSame([$archive], $sdk->archives());
            self::assertSame([$manifest, $archive, ...$headers], $sdk->files());
            self::assertSame($work . '/include/php', $sdk->includeDirectory());
            foreach (['runtime-version' => '6.2.1', 'reference' => str_repeat('0', 40), 'archive-sha256' => str_repeat('0', 64), 'channel' => 'stable'] as $field => $value) {
                $invalid = $data;
                $invalid['sources']['swoole'][$field] = $value;
                $this->reject($manifest, $invalid, '源码或运行版本已过期');
            }
            $invalid = $data;
            unset($invalid['sources']);
            $this->reject($manifest, $invalid, '源码或运行版本已过期');
            $invalid = $data;
            $invalid['headers'][0]['sha256'] = str_repeat('0', 64);
            $this->reject($manifest, $invalid, '目标头文件');
            $invalid = $data;
            $invalid['headers'][0]['file'] = 'include/php/../other.h';
            $this->reject($manifest, $invalid, '头文件路径');
            self::assertTrue(mkdir($work . '/licenses', 0700));
            $license = $work . '/licenses/LICENSE';
            file_put_contents($license, "original notice\n");
            $data['notices'] = [$archiveName => ['component' => 'test-runtime', 'version' => '1.0.0', 'license' => 'MIT',
                'files' => [['file' => 'licenses/LICENSE', 'sha256' => hash_file('sha256', $license)]]]];
            file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
            $sdk = new StaticRuntimeSdk($manifest);
            self::assertSame([$manifest, $archive, ...$headers, $license], $sdk->files());
            self::assertSame(hash_file('sha256', $archive), $sdk->notices()[$archiveName]['binary-sha256']);
            self::assertSame($license, $sdk->notices()[$archiveName]['files'][0]['file']);
            foreach (['licenses/../lib/runtime.a', '/licenses/LICENSE', 'outside/LICENSE'] as $path) {
                $invalid = $data;
                $invalid['notices'][$archiveName]['files'][0]['file'] = $path;
                $this->reject($manifest, $invalid, '许可路径');
            }
            file_put_contents($license, 'changed');
            $this->reject($manifest, $data, '许可原文');
            file_put_contents($license, "original notice\n");
            $invalid = $data;
            $invalid['notices']['unknown.a'] = $invalid['notices'][$archiveName];
            $this->reject($manifest, $invalid, '未知归档');
            foreach (['php', 'architecture', 'os', 'debug', 'integer-size'] as $field) {
                $invalid = $data;
                $invalid[$field] = 'wrong';
                $this->reject($manifest, $invalid, 'ABI');
            }
            $invalid = $data;
            $invalid['patches']['SwooleStaticSource'] = str_repeat('0', 64);
            $this->reject($manifest, $invalid, '过期');
            foreach (['../outside.a', 'lib/../outside.a', 'lib/module.so', '/lib/runtime.a'] as $path) {
                $invalid = $data;
                $invalid['archives'][0]['file'] = $path;
                $this->reject($manifest, $invalid, '声明');
            }
            $invalid = $data;
            $invalid['archives'][] = $invalid['archives'][0];
            $this->reject($manifest, $invalid, '重复');
            file_put_contents($archive, 'shared-library');
            $this->reject($manifest, $data, '不是静态库');
            file_put_contents($archive, "!<arch>\nchanged");
            $this->reject($manifest, $data, '摘要');
            unlink($archive);
            $this->reject($manifest, $data, '缺失');
            file_put_contents($work . '/outside.a', "!<arch>\n");
            self::assertTrue(symlink($work . '/outside.a', $archive));
            $this->reject($manifest, $data, '符号链接');
        } finally {
            chdir($cwd);
            \removeTestDirectory($work);
        }
    }

    private function reject(string $manifest, array $data, string $message): void
    {
        file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
        try {
            new StaticRuntimeSdk($manifest);
            self::fail('无效静态输入不能被接受');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }
}
