<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ArtifactSize;
use Type\Build\WindowsStaticBackend;
use Type\Testing\Process;
use TypePhp\Platform\Windows;

// 在全量 AOT 前以同一 MSVC 发布参数确认 PE 门禁，避免用手工区段夹具代替真实链接。
expect(PHP_OS_FAMILY === 'Windows', '此验收需要 Windows MSVC');
$root = dirname(__DIR__);
$directory = $root . '/build/windows artifact ' . bin2hex(random_bytes(6));
expect(mkdir($directory, 0700), '无法创建本轮 PE 夹具目录');
try {
    $source = $directory . '/main.c';
    file_put_contents($source, "int main(void) { return 0; }\n");
    $flags = preg_split('/\s+/', trim((new WindowsStaticBackend(new Windows()))->buildLinkOptions(['debug' => false])));
    $program = $directory . '/release.exe';
    $result = (new Process(['cl.exe', '/nologo', '/O2', '/MT', $source, '/Fo' . $directory . '/release.obj', '/Fe' . $program,
        '/link', ...$flags], $directory))->wait(60);
    expect($result->successful(), '发布 PE 链接失败：' . $result->stdout . $result->stderr);
    $size = ArtifactSize::measure($program, [], []);
    expect($size['stripped'] && $size['symbols'] === 0, '真实发布 PE 未通过符号门禁');
    expect((new Process([$program], $directory))->wait(10)->successful(), '通过体积门禁的 PE 无法执行');
    $debugProgram = $directory . '/debug.exe';
    $result = (new Process(['cl.exe', '/nologo', '/Z7', '/MT', $source, '/Fo' . $directory . '/debug.obj', '/Fe' . $debugProgram,
        '/link', '/DEBUG:FULL', '/INCREMENTAL:NO', '/PDB:' . $directory . '/debug.pdb'], $directory))->wait(60);
    expect($result->successful(), '调试 PE 夹具链接失败：' . $result->stdout . $result->stderr);
    $rejected = false;
    try {
        ArtifactSize::measure($debugProgram, [], []);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'PDB');
    }
    expect($rejected, '真实 PDB 调试程序未被拒绝');
    echo json_encode(['status' => 'passed', 'release' => $size, 'debug-pdb-rejected' => true], JSON_THROW_ON_ERROR) . "\n";
} finally {
    removeTestDirectory($directory);
}
