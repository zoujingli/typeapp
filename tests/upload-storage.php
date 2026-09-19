<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Core\Http\HttpError;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\UploadStorage;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

$parent = getenv('TYPE_UPLOAD_FAULT_DIRECTORY') ?: sys_get_temp_dir();
$directory = $parent . '/type_storage_' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$factory = new Factory();
$scope = new ExecutionScope();
try {
    $storage = new UploadStorage($directory, 65536, 2);
    $one = $storage->receive($factory->createStream('one'), $scope, 65536);
    $two = $storage->receive($factory->createStream('two'), $scope, 65536);
    $failed = false;
    try {
        $storage->receive($factory->createStream('three'), $scope, 65536);
    } catch (HttpError $error) {
        $failed = $error->errorCode() === 'upload_file_quota';
    }
    expect($failed && $storage->statistics()['files'] === 2, '文件数量配额没有生效');
    $lock = fopen($directory . '/.type-upload.lock', 'r+b');
    expect(flock($lock, LOCK_EX | LOCK_NB), '无法获取竞争锁');
    try {
        $scope->close();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    expect($storage->statistics()['files'] === 0, '锁竞争阻止临时文件清理');
    $expired = new ExecutionScope(new Deadline(0));
    $failed = false;
    try {
        $storage->receive($factory->createStream('expired'), $expired, 65536);
    } catch (Throwable) {
        $failed = true;
    }
    $expired->close();
    expect($failed && $storage->statistics()['files'] === 0, '截止后创建了上传文件');
    $scope = new ExecutionScope();
    $saved = $storage->receive($factory->createStream('saved'), $scope, 65536);
    $key = $saved->save();
    expect($key === $saved->save(), '重复保存不是同一存储键');
    $scope->close();
    expect((string) $storage->open($key) === 'saved', '显式保存没有转移所有权');
    $storage->remove($key);
    if (getenv('TYPE_UPLOAD_FAULT_DIRECTORY')) {
        $storage = new UploadStorage($directory, 16777216, 2);
        $scope = new ExecutionScope();
        $padding = fopen($directory . '/fault-padding', 'wb');
        while (@fwrite($padding, str_repeat('p', 16384)) === 16384) {
        } fclose($padding);
        $failed = false;
        try {
            $storage->receive($factory->createStream(str_repeat('z', 32768)), $scope, 65536);
        } catch (HttpError $error) {
            $failed = $error->status() === 507;
        }
        $scope->close();
        expect($failed && $storage->statistics()['pending'] === 0, '真实磁盘写满后误报成功或留下临时文件');
        echo "上传存储：真实小容量文件系统写满与失败清理通过。\n";
    }
    echo "上传存储：数量配额、锁竞争清理、截止预算、显式保存和资源所有权通过。\n";
} finally {
    $scope->close();
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isFile()) {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}
