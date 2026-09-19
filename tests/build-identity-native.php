<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$isolated = ($argv[1] ?? '') === '--chroot';
$container = ($argv[1] ?? '') === '--docker-image';
$artifact = $isolated || $container ? ($argv[3] ?? '') : ($argv[1] ?? $root . '/build/identity/type-app');
$manifest = (new Type\Build\ArtifactManifest())->read($artifact);
$command = $container ? cleanRuntimeCommand($argv[2] ?? '') : ($isolated ? nativeCommand($argv[2] ?? '', true) : nativeCommand($artifact));
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stderr === '', '构建身份入口失败或产生运行警告：' . $stdout . $stderr);
$response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
expect($response['build_id'] === $manifest['build-id'] && $response['version'] === '1.0.0-dev'
    && $response['capabilities']['schema']['main'] === [1, 2], '编译内嵌身份与 ELF 清单不一致');
if (!$isolated) {
    [$status, $stdout, $stderr] = execute([...$command, '--check-runtime']);
    expect($status === 0 && $stderr === '' && json_decode($stdout, true)['build_id'] === $manifest['build-id'], '原生产物的运行库校验失败：' . $stdout . $stderr);
}
if (!$isolated && !$container) {
    $report = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $workspace = $report['identity']['description']['facts']['workspace'];
    successful([PHP_BINARY, $workspace . '/vendor/bin/type', $workspace . '/docs/build-config/type-build-identity.json'], $workspace);
    $report = json_decode(file_get_contents($workspace . '/build/identity/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($report['cache']['hit'] === true && $report['build-id'] === $manifest['build-id'], '重复 TypePHP 构建没有复用已校验缓存');
}
echo $container ? "无源码镜像的编译内嵌身份、能力与实际运行库验证通过。\n"
    : ($isolated ? "隔离目录的编译内嵌身份与能力验证通过。\n" : "编译内嵌身份、能力、运行库与重复构建缓存验证通过。\n");
