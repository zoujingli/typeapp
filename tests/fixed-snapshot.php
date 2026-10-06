<?php

declare(strict_types=1);

/** 导出固定 Git 对象并核对完整普通文件集合；调用者负责回收目录与归档。 */
function fixedSnapshot(string $root, string $object, string $directory): array
{
    expect(preg_match('/^[a-f0-9]{40}$/D', $object) === 1 && !file_exists($directory), '固定快照需要完整Git身份及新目录');
    $expected = [];
    foreach (explode("\0", rtrim(successful(['git', 'ls-tree', '-r', '-z', $object], $root), "\0")) as $entry) {
        expect(preg_match('/^100(?:644|755) blob ([a-f0-9]{40})\t(.+)$/sD', $entry, $match) === 1, '固定快照只允许普通文件');
        $expected[$match[2]] = $match[1];
    }
    expect(mkdir($directory, 0700), '无法创建固定快照');
    $tar = $directory . '.tar';
    TypeApp\Distribution\Process::output(['git', '-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'archive', '--format=tar', '--output=' . $tar, $object], $root);
    expect((new PharData($tar))->extractTo($directory), '无法导出固定快照');
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
        expect($entry->isFile() && !$entry->isLink(), '快照出现非普通文件');
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
        $content = file_get_contents($entry->getPathname());
        expect(isset($expected[$relative]) && hash('sha1', 'blob ' . strlen($content) . "\0" . $content) === $expected[$relative], 'Git 快照字节不一致');
        $files[$relative] = hash('sha256', $content);
    }
    ksort($files);
    ksort($expected);
    expect(array_keys($files) === array_keys($expected), 'Git 快照文件集合不一致');
    $archiveHash = hash_file('sha256', $tar);
    unlink($tar);
    return ['split' => $object, 'archive-sha256' => $archiveHash, 'files' => $files];
}
