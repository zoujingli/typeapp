<?php

declare(strict_types=1);

$directory = getenv('TYPE_DISTRIBUTION_WRITER_DIRECTORY');
if ($directory === false) {
    exit(0);
}
if (!is_dir($directory) || is_link($directory) || !str_starts_with(basename($directory), 'tmp.')) {
    throw new RuntimeException('临时凭据目录不符合当前任务范围');
}
foreach (new DirectoryIterator($directory) as $entry) {
    if ($entry->isFile() && !$entry->isLink() && preg_match('/^(?:type-[a-z0-9-]+|config|known_hosts)$/D', $entry->getFilename())) {
        unlink($entry->getPathname());
    }
}
rmdir($directory);
