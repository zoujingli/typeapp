<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$parser = new Type\Build\WindowsImports();
$table = "Dump of file C:\\Windows\\System32\\advapi32.dll\r\n  Image has the following dependencies:\r\n KERNELBASE.dll\r\n ntdll.dll\r\n  Image has the following delay load dependencies:\r\n CRYPTSP.dll\r\n ext-ms-win32-subsystem-query-l1-1-0.dll\r\n Summary\r\n 1000 .data\r\n";
expect($parser->parse($table) === ['required' => ['KERNELBASE.dll', 'ntdll.dll'],
    'delayed' => ['CRYPTSP.dll', 'ext-ms-win32-subsystem-query-l1-1-0.dll']], '延迟导入被误判为必需加载');
$rejected = false;
try {
    $parser->parse("Unrecognized heading:\n KERNEL32.dll\n");
} catch (RuntimeException) {
    $rejected = true;
}
expect($rejected, '未知导入表没有拒绝');
echo "Windows普通与延迟导入分区、未知格式拒绝通过。\n";
