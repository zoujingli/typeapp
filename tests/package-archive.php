<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-package-sandbox.php';

use Type\Build\NativePackage;
use Type\Build\PackageArchive;
use Type\Testing\Process;

$directory = realpath($argv[1] ?? '');
$digest = $argv[2] ?? '';
expect($directory !== false, '需要实际发布目录与受信清单摘要');
$base = dirname(__DIR__) . '/build/archive-test-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建归档测试目录');
$secretFile = $directory . '/.archive-secret-sentinel';
expect(!file_exists($secretFile), '不能覆盖既有哨兵');
file_put_contents($secretFile, 'not-in-archive');
$records = [];
try {
    foreach (['zip', 'tar.gz'] as $format) {
        // 独立公开CLI固定使用128 MiB，捕获归档校验重新整体解压造成的内存回退。
        $creation = (new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__) . '/vendor/bin/type',
            'archive', $directory, $base . '/release.' . $format, $digest]))->wait(180);
        expect($creation->successful(), '128 MiB归档创建失败：' . $creation->stdout . $creation->stderr);
        $record = json_decode($creation->stdout, true, 512, JSON_THROW_ON_ERROR);
        $extracted = $base . '/extracted-' . str_replace('.', '-', $format);
        if ($format === 'tar.gz') {
            // 使用部署端原生tar独立解包，也避免控制端Phar再次整体缓存gzip。
            expect(mkdir($extracted, 0700), '无法创建归档解包目录');
            $extraction = (new Process(['tar', '-xzf', $record['file'], '-C', $extracted]))->wait(60);
            expect($extraction->successful(), '原生tar无法解包实际归档：' . $extraction->stderr);
        } else {
            $archive = new PharData($record['file']);
            expect($archive->extractTo($extracted), '无法解包实际归档');
            unset($archive);
        }
        expect(!file_exists($extracted . '/.archive-secret-sentinel') && !file_exists($extracted . '/.env')
            && !file_exists($extracted . '/var/app.sqlite'), '归档包含部署数据或秘密');
        (new NativePackage())->verify($extracted, $digest);
        $environment = getenv();
        $environment['TYPE_APP_RELEASE_SHA256'] = $digest;
        $command = PHP_OS_FAMILY === 'Windows' ? [(string) getenv('SystemRoot') . '/System32/cmd.exe', '/d', '/c', $extracted . '/run.cmd', 'help'] : [$extracted . '/run', 'help'];
        $isolated = PHP_OS_FAMILY === 'Darwin' || (PHP_OS_FAMILY === 'Linux' && getenv('TYPE_BWRAP_BINARY') !== false);
        if ($isolated) {
            $command = [...sandboxPackageCommand(dirname(__DIR__), $extracted), 'help'];
        }
        $run = (new Process($command, $extracted, $environment))->wait(10);
        $project = getenv('TYPE_PACKAGE_PROJECT');
        $helpMarker = ($project === false || $project === '') ? 'TypeApp 物联中心' : 'Type 业务应用';
        expect($run->successful() && $run->stderr === '' && str_contains($run->stdout, $helpMarker), '归档解包后启动失败：' . $run->stderr . $run->stdout);
        $rejected = false;
        try {
            (new PackageArchive())->create($directory, $record['file'], $digest);
        } catch (RuntimeException) {
            $rejected = true;
        }
        expect($rejected, '归档覆盖了既有版本');
        $records[] = $record;
    }
} finally {
    unlink($secretFile);
}
file_put_contents($base . '/verification.json', json_encode(['os' => PHP_OS_FAMILY, 'archives' => $records,
    'source-sdk-read-and-php-compiler-exec-denied' => $isolated,
    'checks' => ['archive-cli-128m', 'payload-only', 'archive-bytes-verified', 'extract-and-verify', 'native-help', 'no-overwrite']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
echo '归档、秘密排除、解包完整性与原生启动通过：' . $base . "/verification.json\n";
