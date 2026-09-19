<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ArtifactManifest;
use Type\Build\ArtifactCache;
use Type\Build\BuildIdentity;
use Type\Build\BuildPlatform;

$directory = realpath(sys_get_temp_dir()) . '/type-platform-' . bin2hex(random_bytes(8));
expect(mkdir($directory, 0700), '无法准备平台产物测试目录');
$platform = new BuildPlatform();
$fixture = PHP_OS_FAMILY === 'Windows' ? getenv('SystemRoot') . '/System32/whoami.exe' : '/bin/echo';
$arguments = PHP_OS_FAMILY === 'Windows' ? ['/user', '/fo', 'csv', '/nh'] : ['type-platform-ok'];
$artifact = $directory . '/echo' . $platform->executableSuffix();
try {
    $reader = new ArtifactManifest();
    $identity = (new BuildIdentity())->create(['fixture' => [$fixture]], []);
    $manifest = ['build-id' => $identity['id'], 'runtime' => ['os' => PHP_OS_FAMILY]];
    if (PHP_OS_FAMILY === 'Darwin') {
        file_put_contents($directory . '/main.cc', "#include <cstdio>\nint main(){std::puts(\"type-platform-ok\");return 0;}\n");
        file_put_contents($directory . '/identity.cc', $reader->machOSource($manifest));
        successful(['/usr/bin/clang++', $directory . '/main.cc', $directory . '/identity.cc', '-o', $artifact]);
        $fixture = $directory . '/fixture';
        expect(copy($artifact, $fixture) && chmod($fixture, 0755), '无法保留签名Mach-O测试夹具');
    } else {
        expect(copy($fixture, $artifact) && chmod($artifact, 0755), '无法复制本机可信原生命令');
    }
    [$originalStatus, $originalOutput, $originalError] = execute([$fixture, ...$arguments]);
    expect($originalStatus === 0 && $originalError === '', '可信原生命令在当前机器不能执行');
    $reader->seal($artifact, $manifest);
    expect($reader->read($artifact)['build-id'] === $identity['id'], '本机原生产物不能往返身份清单');
    [$status, $output, $error] = execute([...nativeCommand($artifact), ...$arguments]);
    expect($status === 0 && $output === $originalOutput && $error === '', '封装身份改变了本机原生产物可执行性：' . $status);
    $expectedFormat = ['Linux' => 'ELF', 'Darwin' => 'Mach-O', 'Windows' => 'PE'][PHP_OS_FAMILY];
    expect($reader->read($artifact)['binary-format'] === $expectedFormat, '清单没有记录真实本机二进制格式');
    $cache = new ArtifactCache($directory . '/cache');
    $compilations = 0;
    $compiler = static function (string $candidate) use ($fixture, &$compilations): void {
        $compilations++;
        copy($fixture, $candidate);
        chmod($candidate, 0755);
    };
    $first = $cache->materialize($identity, $manifest, $artifact, $compiler);
    $second = $cache->materialize($identity, $manifest, $artifact, $compiler);
    expect(!$first['hit'] && $second['hit'] && $compilations === 1, '平台产物缓存没有真实复用');
    [$cachedStatus, $cachedOutput, $cachedError] = execute([$artifact, ...$arguments]);
    expect($cachedStatus === 0 && $cachedOutput === $originalOutput && $cachedError === '', '缓存恢复损坏了平台原生产物');
    file_put_contents($artifact, 'invalid-tail', FILE_APPEND);
    $rejected = false;
    try {
        $reader->read($artifact);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '尾部被修改的跨平台产物没有拒绝');
    $windows = new BuildPlatform('Windows');
    expect($windows->absolute('C:\\work\\project') && $windows->absolute('D:/SDK') && !$windows->absolute('C:relative')
        && !$windows->absolute('\\\\server\\share') && !$windows->absolute('https://example.test'), 'Windows路径语义混入相对盘符或远程目录');
    expect($windows->output('build/tool') === 'build/tool.exe' && $windows->output('build/tool.exe') === 'build/tool.exe', 'Windows产物后缀不稳定');
    $environment = (new BuildPlatform())->environment('', '');
    expect(!isset($environment['COMPOSER_AUTH'], $environment['SSH_AUTH_SOCK'], $environment['GITHUB_TOKEN']), '平台环境继承了认证变量');
    echo "本机产物封装、实际执行、缓存复用、篡改拒绝与平台路径验证通过。\n";
} finally {
    if (is_file($artifact)) {
        unlink($artifact);
    }
    foreach (['fixture', 'main.cc', 'identity.cc'] as $temporary) {
        if (is_file($directory . '/' . $temporary)) {
            unlink($directory . '/' . $temporary);
        }
    }
    if (is_dir($directory . '/cache')) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/cache', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory . '/cache');
    }
    rmdir($directory);
}
