<?php

declare(strict_types=1);

use Type\Build\NativePackage;
use Type\Testing\Process;

/** @param array<string,string>|null $environment 测试秘密只经进程环境转交。 */
function cleanPackageCommand(array $command, float $seconds = 30, ?array $environment = null): string
{
    $process = new Process($command, null, $environment, 4194304);
    try {
        $result = $process->wait($seconds);
        expect($result->successful(), '干净部署命令失败：' . $result->stderr);
        return $result->stdout;
    } finally {
        $process->stop();
    }
}

/** 从真实ELF64小端程序头读取加载器，不按宿主猜测。 */
function cleanPackageLoader(string $binary): string
{
    $header = file_get_contents($binary, false, null, 0, 64);
    expect(strlen($header) === 64 && substr($header, 0, 6) === "\x7fELF\x02\x01", '干净运行入口需要ELF64小端原生产物');
    $offset = unpack('P', substr($header, 32, 8))[1];
    $size = unpack('v', substr($header, 54, 2))[1];
    $count = unpack('v', substr($header, 56, 2))[1];
    expect($offset >= 64 && $size >= 56 && $size <= 256 && $count > 0 && $count <= 128 && $offset <= filesize($binary) - $size * $count, 'ELF程序头无效');
    for ($index = 0; $index < $count; $index++) {
        $entry = file_get_contents($binary, false, null, $offset + $index * $size, $size);
        if (unpack('V', substr($entry, 0, 4))[1] !== 3) {
            continue;
        }
        $start = unpack('P', substr($entry, 8, 8))[1];
        $length = unpack('P', substr($entry, 32, 8))[1];
        expect($start >= 0 && $length > 1 && $length <= 4096 && $start <= filesize($binary) - $length, 'ELF解释器范围无效');
        $path = file_get_contents($binary, false, null, $start, $length);
        expect(str_ends_with($path, "\0") && substr_count($path, "\0") === 1, 'ELF解释器不是单个路径');
        $name = basename(substr($path, 0, -1));
        expect(preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]*$/D', $name) === 1, 'ELF加载器名称无效');
        return $name;
    }
    throw new RuntimeException('原生产物没有显式ELF加载器');
}

/** 构造并逐项检查scratch运行镜像；成功后由调用者回收返回的唯一tag。 */
function cleanPackageImage(string $package, string $digest, string $base, int $uid, string $identity): array
{
    expect(preg_match('/^[a-f0-9]{12}$/D', $identity) === 1 && $uid > 0, '测试镜像身份必须有限且非root');
    $manifest = (new NativePackage())->verify($package, $digest);
    expect($manifest['runtime']['os'] === 'Linux', 'scratch运行镜像仅验证Linux');
    $payload = $base . '/payload';
    expect(!file_exists($payload) && mkdir($payload . '/runtime/empty', 0700, true), '不能覆盖既有镜像载荷');
    foreach ([...array_keys($manifest['files']), 'release.json'] as $relative) {
        expect(!preg_match('/\.(?:php[0-9]?|phtml|phar|inc|h|hpp|c|cc|cpp)$/iD', $relative), '发布载荷出现源码');
        $target = $payload . '/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        expect(copy($package . '/' . $relative, $target), '不能复制已核验载荷');
        chmod($target, fileperms($package . '/' . $relative) & 0777);
    }
    (new NativePackage())->verify($payload, $digest);
    $loader = cleanPackageLoader($payload . '/' . $manifest['artifact']['path']);
    expect(isset($manifest['files']['lib/' . $loader]), '加载器未纳入发布字节清单');
    $entrypoint = ['/app/lib/' . $loader, '--library-path', '/app/lib', '/app/' . $manifest['artifact']['path']];
    file_put_contents($base . '/Dockerfile', "FROM scratch\nCOPY --chown=" . $uid . ':' . $uid . " payload/ /app/\nUSER " . $uid . ':' . $uid . "\nWORKDIR /app\n"
        . "ENV PHPRC=/app/runtime/php.ini PHP_INI_SCAN_DIR=/app/runtime/empty TYPE_APP_RUNTIME_ROOT=/app\nENTRYPOINT "
        . json_encode($entrypoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $tag = 'type-native-clean-test:' . $identity;
    $inspection = 'type-native-clean-inspect-' . $identity;
    $built = false;
    $created = false;
    try {
        cleanPackageCommand(['docker', 'build', '--network=none', '--tag', $tag, $base], 120);
        $built = true;
        $image = trim(cleanPackageCommand(['docker', 'image', 'inspect', '--format', '{{.Id}}', $tag]));
        expect(preg_match('/^sha256:[a-f0-9]{64}$/D', $image) === 1, '镜像身份无效');
        $configuration = json_decode(cleanPackageCommand(['docker', 'image', 'inspect', $image]), true, 512, JSON_THROW_ON_ERROR)[0];
        expect($configuration['Config']['Entrypoint'] === $entrypoint && $configuration['Config']['User'] === $uid . ':' . $uid, '空白镜像入口或非root身份不符');
        cleanPackageCommand(['docker', 'create', '--name', $inspection, '--network=none', $image, 'help']);
        $created = true;
        cleanPackageCommand(['docker', 'export', '--output', $base . '/rootfs.tar', $inspection], 60);
        $archive = new PharData($base . '/rootfs.tar');
        $entries = 0;
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            $name = $entry->getFilename();
            expect(!preg_match('/\.(?:php[0-9]?|phtml|phar|inc|h|hpp|c|cc|cpp)$/iD', $name), '运行镜像包含业务/SDK源码');
            expect(!preg_match('/^(?:php(?:[0-9.]+)?|php-cgi|php-fpm|phpdbg|phpize|composer(?:\.json|\.lock|\.phar)?|gcc|g\+\+|clang\+\+|clang|cl\.exe|auth\.json|\.env)$/iD', $name), '运行镜像包含解释器、工具链或秘密');
            $entries++;
        }
        unset($archive);
        cleanPackageCommand(['docker', 'cp', $inspection . ':/app/' . $manifest['artifact']['path'], $base . '/verified-native-app']);
        expect(hash_file('sha256', $base . '/verified-native-app') === $manifest['artifact']['sha256'], '镜像内程序不是受信产物');
        cleanPackageCommand(['docker', 'rm', $inspection]);
        $created = false;
        return ['image' => $image, 'tag' => $tag, 'entries' => $entries, 'entrypoint' => $entrypoint, 'manifest' => $manifest];
    } catch (Throwable $error) {
        if ($created) {
            cleanPackageCommand(['docker', 'rm', $inspection]);
        }
        if ($built) {
            cleanPackageCommand(['docker', 'image', 'rm', $tag]);
        }
        throw $error;
    }
}
