<?php

declare(strict_types=1);

namespace Type\Build;

use Phar;
use PharData;
use RuntimeException;

/** 只归档已验证的不可变发布载荷，不递归收集部署产生的秘密或数据。 */
final class PackageArchive
{
    /**
     * 创建不覆盖旧文件的发布归档，并复核归档实际载荷与受信清单。
     *
     * @return array{file:string, sha256:string, manifest-sha256:string, bytes:int}
     * @throws RuntimeException 发布、归档字节或目标路径无效。
     */
    public function create(string $directory, string $destination, string $manifestSha256): array
    {
        if (!class_exists(PharData::class)) {
            throw new RuntimeException('归档构建需要PHP Phar扩展，运行端不需要该扩展');
        }
        $directory = BuildPlatform::resolve($directory);
        $release = (new NativePackage())->verify($directory, $manifestSha256);
        $format = str_ends_with($destination, '.tar.gz') ? 'tar.gz' : (str_ends_with($destination, '.zip') ? 'zip' : '');
        if ($format === '') {
            throw new RuntimeException('发布归档只接受.tar.gz或.zip');
        }
        $parent = BuildPlatform::resolve(dirname($destination));
        $destination = $parent . '/' . basename($destination);
        BuildLock::path($destination);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('不能覆盖既有发布归档');
        }
        $stage = $parent . '/.type-archive-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('无法创建归档暂存目录');
        }
        $container = $stage . ($format === 'zip' ? '/payload.zip' : '/payload.tar');
        try {
            $archive = new PharData($container, 0, null, $format === 'zip' ? Phar::ZIP : Phar::TAR);
            $archive->addEmptyDir('runtime/empty');
            $paths = array_keys($release['files']);
            $paths[] = 'release.json';
            sort($paths);
            foreach ($paths as $relative) {
                $archive->addFile($directory . '/' . $relative, $relative);
                $archive[$relative]->chmod(fileperms($directory . '/' . $relative) & 0777);
            }
            if ($format === 'tar.gz') {
                $packed = $container . '.gz';
                $gzip = PHP_OS_FAMILY === 'Windows' ? null : (is_file('/usr/bin/gzip') ? '/usr/bin/gzip' : (is_file('/bin/gzip') ? '/bin/gzip' : null));
                if ($gzip !== null) {
                    // Phar::compress 会把完整 tar 留在内存；公开 CLI 固定 128 MiB，优先系统 gzip 流式落盘。
                    $null = '/dev/null';
                    $pipes = [];
                    $process = proc_open([$gzip, '-n', '-c', $container], [0 => ['file', $null, 'r'], 1 => ['file', $packed, 'wb'], 2 => ['pipe', 'w']], $pipes);
                    if (!is_resource($process)) {
                        throw new RuntimeException('无法启动 gzip 压缩发布归档');
                    }
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[2]);
                    $status = proc_close($process);
                    if ($status !== 0 || !is_file($packed) || filesize($packed) < 1) {
                        throw new RuntimeException('无法压缩发布归档：' . $stderr);
                    }
                } else {
                    $compressed = $archive->compress(Phar::GZ);
                    unset($compressed);
                }
            } else {
                $packed = $container;
            }
            unset($archive);
            if ($format === 'tar.gz') {
                // phar://读取gzip会把完整tar留在内存；流式核对压缩字节展开后与磁盘tar完全相同。
                $stream = gzopen($packed, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('无法读取压缩归档');
                }
                try {
                    $hash = hash_init('sha256');
                    $expandedBytes = hash_update_stream($hash, $stream);
                    if (!feof($stream) || $expandedBytes !== filesize($container)
                        || !hash_equals((string) hash_file('sha256', $container), hash_final($hash))) {
                        throw new RuntimeException('压缩归档字节与原始载荷不一致');
                    }
                } finally {
                    gzclose($stream);
                }
            }
            // 对真正写进归档的字节复核，而不是只核对复制前的输入。
            foreach ($paths as $relative) {
                $stream = fopen('phar://' . $container . '/' . $relative, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('归档遗漏发布文件');
                }
                try {
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    $expected = $relative === 'release.json' ? $manifestSha256 : $release['files'][$relative]['sha256'];
                    if (!hash_equals($expected, hash_final($hash))) {
                        throw new RuntimeException('归档字节与发布清单不一致');
                    }
                } finally {
                    fclose($stream);
                }
            }
            (new NativePackage())->verify($directory, $manifestSha256);
            $digest = (string) hash_file('sha256', $packed);
            $bytes = filesize($packed);
            // 同一父目录的硬链接发布不覆盖任何并发创建的既有目标。
            if (!link($packed, $destination)) {
                throw new RuntimeException('无法原子发布新归档，目标可能已存在');
            }
            return ['file' => $destination, 'sha256' => $digest, 'manifest-sha256' => $manifestSha256, 'bytes' => $bytes];
        } finally {
            foreach (['payload.tar.gz', 'payload.tar', 'payload.zip'] as $temporary) {
                if (is_file($stage . '/' . $temporary)) {
                    unlink($stage . '/' . $temporary);
                }
            }
            rmdir($stage);
        }
    }
}
