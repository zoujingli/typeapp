<?php

declare(strict_types=1);

function main(int $argc, array $argv): void
{
    if ($argc !== 2) {
        fwrite(STDERR, "需要资源文件路径。\n");
        exit(64);
    }
    $resource = file_get_contents((string) $argv[1]);
    if ($resource === false) {
        throw new RuntimeException('无法读取运行资源');
    }
    (new Psr\Log\NullLogger())->info('实际第三方日志接口已执行');
    echo (new Imported\Value())->text() . '/' . (new ImportedLegacy())->text() . '/' . imported_label()
        . '/' . imported_add(3, 4) . '/' . Psr\Log\LogLevel::INFO . '/' . trim($resource) . PHP_EOL;
}
